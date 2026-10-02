<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Negotiation;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * Chooses one media type for a response, and rejects a request body whose type
 * the endpoint does not accept.
 *
 * ============================================================================
 * THE SELECTION ALGORITHM, IN THE ORDER IT MATTERS
 * ============================================================================
 * For every concrete offer, find the most specific matching `Accept` range
 * (specificity: `type/subtype` = 2 > `type/*` = 1 > the wildcard-only range
 * `*&#47;*` = 0). Ties within one
 * specificity level go to the higher `q`. That `q` is the offer's score — and
 * that is where `q=0` is handled: a range whose most specific match carries
 * `q=0` marks the offer unacceptable, because RFC 9110 §12.5.1 gives the more
 * specific reference precedence over the less specific one.
 *
 * Then rank the acceptable offers by (`q` desc, specificity desc, position in the
 * offer list asc).
 *
 * The specificity-before-q ordering inside the first step is the part that is
 * easy to get wrong and is worth stating with an example:
 *
 *   Accept: application/ld+json;q=0.1, *&#47;*;q=0.9
 *
 * The `text/turtle` offer is matched only by the wildcard → 0.9. The ld+json
 * offer is
 * matched most specifically by `application/ld+json` → 0.1, and the wildcard
 * does not raise it. Turtle wins, even though the client named ld+json first.
 * This is not a quirk: the client explicitly deprioritised ld+json.
 *
 * And the mirror image:
 *
 *   Accept: *&#47;*;q=0.1, application/ld+json;q=0.9   →   ld+json wins (0.9)
 *   Accept: *&#47;*;q=0, application/ld+json           →   only ld+json is acceptable
 *   Accept: application/ld+json;q=0, *&#47;*;q=1       →   only text/turtle is acceptable
 *
 * The final tiebreaker — offer-list position — is what makes the server's own
 * preference matter. That is why every offer list in this extension starts with
 * `application/ld+json`: the DFC contract makes it the required serialisation.
 *
 * ============================================================================
 * ABSENT, EMPTY AND EMPTY-LIST `Accept` ARE THREE DIFFERENT THINGS
 * ============================================================================
 *   - header absent / not sent  → no preference; the server's first offer wins.
 *     This is what RFC 9110 §12.5.1 prescribes and what every generic HTTP client
 *     relies on.
 *   - header present but empty (`Accept:`) → the client asked for nothing, so
 *     nothing is acceptable → 415.
 *   - header present but only unparseable entries → also nothing acceptable →
 *     415.
 *
 * The second and third are the same outcome on purpose. Treating an empty `Accept`
 * as "no preference" is how a caching proxy or a strict-mode HTTP client turns
 * into a silent JSON fallback, and PRD-002's success criteria and this layer's
 * contract both say the server must never send a representation the client did
 * not ask for.
 *
 * ============================================================================
 * WHY A MALFORMED `q` IS 400 BUT A MISSING `/` IS SKIPPED
 * ============================================================================
 * Two different failures, two different answers:
 *
 *   - `q=2`, `q=abc`, `charset` with no `=` → the header is BROKEN. Its meaning
 *     is unknown, so it is answered 400 (`invalid_header`). Silently dropping the
 *     entry could hand a client a representation it explicitly refused.
 *   - an entry with no `/` at all → not a media type. RFC 9110 requires a server
 *     to ignore ranges it cannot parse. The real-world case is
 *     `Accept: gzip, deflate` from legacy browsers, where the codings were
 *     written into `Accept` instead of `Accept-Encoding`; answering those clients
 *     400 would be pedantry with no security value.
 *
 * Skipping never creates a match. If every entry is skipped, the result is 415,
 * not a fallback.
 *
 * ============================================================================
 * REQUEST BODIES
 * ============================================================================
 * {@see assertAcceptableRequestBody()} applies the same matching in the other
 * direction. An absent `Content-Type` is treated as `application/octet-stream`
 * per RFC 9110 §8.3 and therefore fails, which is the correct outcome for a
 * JSON-LD endpoint: an undeclared body is not a DFC document and treating it as
 * one is how a client ends up debugging a parse error three layers down.
 *
 * `application/json` is NOT accepted for DFC writes even though it is the same
 * bytes. Serving or accepting it would be exactly the silent format fallback this
 * class exists to prevent; a deployment that wants it can add it to the
 * endpoint's accepted list explicitly, which makes it a decision.
 *
 * @package Civi\Dfc
 */
final class ContentNegotiator
{
    /**
     * The value every response that varies by negotiation must advertise.
     *
     * Without this a shared cache will serve a Turtle document to a client that
     * only accepts JSON-LD, which is the content-negotiation equivalent of
     * serving the wrong record. `Authorization` rides along because the same
     * document differs by visibility policy.
     */
    public const VARY = ['Accept', 'Authorization'];

    /**
     * Pick the response media type, or throw.
     *
     * @param string|null   $acceptHeader The raw `Accept` value, or null when the
     *                                    header was not sent.
     * @param list<MediaType> $offers      What this resource can produce, most
     *                                    preferred FIRST. Wildcards are rejected:
     *                                    an offer must be a thing that exists.
     *
     * @throws DfcApiException 415 when nothing on offer is acceptable, 400 when
     *         the header itself is broken.
     * @throws \InvalidArgumentException when $offers is empty or contains a
     *         wildcard — a configuration defect, not a client error.
     */
    public function negotiateResponse(?string $acceptHeader, array $offers): MediaType
    {
        $offers = $this->validateOffers($offers);

        if ($acceptHeader === null) {
            return $offers[0];
        }

        $header = trim($acceptHeader);

        if ($header === '') {
            // Present but empty: the client asked for nothing. See the class
            // docblock; this is 415, not a silent fallback.
            throw DfcApiException::of(ProtocolError::unsupportedMediaType($this->mediaTypeNames($offers)));
        }

        $ranges = [];
        foreach (explode(',', $header) as $rawEntry) {
            $entry = trim($rawEntry);

            if ($entry === '') {
                continue;
            }

            if (!str_contains($entry, '/')) {
                continue;
            }

            try {
                $ranges[] = MediaType::parse($entry);
            } catch (\InvalidArgumentException) {
                throw DfcApiException::of(ProtocolError::invalidHeader());
            }
        }

        $candidates = [];

        foreach ($offers as $index => $offer) {
            $best = $this->bestRangeFor($ranges, $offer);

            if ($best === null || $best->quality() <= 0.0) {
                continue;
            }

            $candidates[] = [
                'offer' => $offer,
                'quality' => $best->quality(),
                'specificity' => $best->specificity(),
                'index' => $index,
            ];
        }

        if ($candidates === []) {
            throw DfcApiException::of(ProtocolError::unsupportedMediaType($this->mediaTypeNames($offers)));
        }

        usort(
            $candidates,
            static function (array $left, array $right): int {
                return ($right['quality'] <=> $left['quality'])
                    ?: ($right['specificity'] <=> $left['specificity'])
                    ?: ($left['index'] <=> $right['index']);
            }
        );

        /** @var MediaType $winner */
        $winner = $candidates[0]['offer'];

        return $winner;
    }

    /**
     * Validate a request body's `Content-Type` against what the endpoint accepts.
     *
     * @param string|null    $contentTypeHeader The raw value, or null when absent.
     * @param list<MediaType> $accepted          Concrete types the endpoint
     *                                            accepts. Wildcards are rejected
     *                                            for the same reason as offers: a
     *                                            write endpoint must state what it
     *                                            takes.
     *
     * @throws DfcApiException 415 when the body is not an accepted type.
     */
    public function assertAcceptableRequestBody(?string $contentTypeHeader, array $accepted): MediaType
    {
        $accepted = $this->validateOffers($accepted, 'accepted');

        $effective = ($contentTypeHeader === null || trim($contentTypeHeader) === '')
            ? MediaType::OCTET_STREAM
            : trim($contentTypeHeader);

        try {
            $sent = MediaType::parse($effective);
        } catch (\InvalidArgumentException) {
            throw DfcApiException::of(ProtocolError::unsupportedMediaType($this->mediaTypeNames($accepted)));
        }

        foreach ($accepted as $candidate) {
            if ($sent->matches($candidate) || $candidate->matches($sent)) {
                return $candidate;
            }
        }

        throw DfcApiException::of(ProtocolError::unsupportedMediaType($this->mediaTypeNames($accepted)));
    }

    /**
     * Render a media-type list for a header: `application/ld+json, text/turtle`.
     *
     * @param list<MediaType> $mediaTypes
     */
    public function renderList(array $mediaTypes): string
    {
        return implode(', ', array_map(
            static fn (MediaType $mediaType): string => $mediaType->toString(),
            $mediaTypes
        ));
    }

    /**
     * The most specific range matching $offer, ties broken by higher `q`.
     *
     * Returns the range even when its `q` is 0 — the caller needs it, because a
     * `q=0` on the MOST SPECIFIC match is what makes an offer unacceptable, and
     * only this function knows which range was most specific.
     *
     * @param list<MediaType> $ranges
     */
    private function bestRangeFor(array $ranges, MediaType $offer): ?MediaType
    {
        $best = null;
        $bestSpecificity = -1;
        $bestQuality = -1.0;

        foreach ($ranges as $range) {
            if (!$range->matches($offer)) {
                continue;
            }

            $specificity = $range->specificity();
            $quality = $range->quality();

            if ($specificity > $bestSpecificity
                || ($specificity === $bestSpecificity && $quality > $bestQuality)
            ) {
                $best = $range;
                $bestSpecificity = $specificity;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /**
     * @param list<MediaType> $mediaTypes
     *
     * @return list<MediaType>
     */
    private function validateOffers(array $mediaTypes, string $role = 'offered'): array
    {
        if ($mediaTypes === []) {
            throw new \InvalidArgumentException(sprintf(
                'A resource must have at least one %s media type. An endpoint that can produce nothing is a '
                . 'routing defect, and answering 415 for it would hide that defect behind a plausible '
                . 'client error.',
                $role
            ));
        }

        $seen = [];
        $validated = [];

        foreach ($mediaTypes as $position => $mediaType) {
            if (!$mediaType instanceof MediaType) {
                throw new \InvalidArgumentException(sprintf(
                    'Media type #%d must be a %s MediaType instance, got %s.',
                    $position + 1,
                    $role,
                    get_debug_type($mediaType)
                ));
            }

            if ($mediaType->isWildcard()) {
                throw new \InvalidArgumentException(sprintf(
                    'Media type #%d is the wildcard "%s". A server must offer or accept concrete types only.',
                    $position + 1,
                    $mediaType->essence()
                ));
            }

            $essence = $mediaType->essence();
            if (isset($seen[$essence])) {
                // De-duplicated rather than rejected: repeating a type in a
                // capability list is harmless, and failing on it would make an
                // assembled list brittle.
                continue;
            }

            $seen[$essence] = true;
            $validated[] = $mediaType;
        }

        return $validated;
    }

    /**
     * @param list<MediaType> $mediaTypes
     *
     * @return list<string>
     */
    private function mediaTypeNames(array $mediaTypes): array
    {
        return array_values(array_map(
            static fn (MediaType $mediaType): string => $mediaType->essence(),
            $mediaTypes
        ));
    }
}
