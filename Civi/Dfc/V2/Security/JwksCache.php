<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The signing-key cache: bounded TTL, refresh on an unknown `kid`, one retry.
 *
 * ============================================================================
 * THE THREE FAILURES THIS HAS TO AVOID, IN ORDER OF SEVERITY
 * ============================================================================
 *
 * 1. **Accepting a token signed with an unknown key.** Impossible here: the only
 *    path to verification is {@see signingKey()}, and it throws rather than
 *    returning nothing.
 *
 * 2. **Refusing a token signed with a NEW key during a rotation.** This is the
 *    availability half of the same problem and it is what {@code refresh-on-unknown-kid}
 *    exists for: an unknown `kid` means "the cached set may be stale", so the cache
 *    re-fetches and tries again. Exactly once — see below.
 *
 * 3. **Turning this server into a load generator aimed at the issuer.** Any cache
 *    that refreshes on unknown `kid` with no rate limit has this bug: an attacker
 *    sends N tokens with N made-up `kid`s and the issuer receives N requests. Two
 *    mechanisms bound it:
 *      - the forced refresh is rate-limited to {@see MIN_REFRESH_COOLDOWN_SECONDS},
 *        so a burst of unknown `kid`s inside the cooldown reuses the cached set and
 *        simply fails; and
 *      - the cooldown is also the {@see minTtlSeconds} floor on any fetch, so a
 *        misbehaving transport cannot drive this class into a loop.
 *    {@see stats()} counts the suppressions so the behaviour is observable rather
 *    than merely intended.
 *
 * ============================================================================
 * WHY THE REFRESH IS EXACTLY ONE RETRY
 * ============================================================================
 * The retry is not "refresh until it works". If a `kid` is still unknown after a
 * forced refresh, the token is rejected. A loop would let an attacker who controls
 * the token's `kid` also control how long this server keeps asking, and it would
 * turn a misconfiguration (an issuer that publishes keys under a `kid` this server
 * cannot match) into a self-inflicted outage rather than a diagnosable failure.
 *
 * ============================================================================
 * WHAT HAPPENS WHEN A REFRESH FAILS
 * ============================================================================
 *  - The cache is still within its TTL (this is a forced refresh, not an expired
 *    one): the previous set is KEPT and the lookup fails. A transient IdP blip must
 *    not invalidate keys that were working a second ago.
 *  - The cache has EXPIRED and the fetch fails: {@see JwksUnavailableException}.
 *    Stale keys past their freshness window would be an implicit trust extension —
 *    exactly the thing a rotation is meant to end.
 *
 * Both paths are counted, so "the API is returning 500s because the IdP is down"
 * and "the API is rejecting tokens because rotation happened" are distinguishable
 * from the outside by a single diagnostics call.
 *
 * ============================================================================
 * NOTHING HERE LOGS OR RETURNS KEY MATERIAL
 * ============================================================================
 * {@see stats()} and {@see describe()} carry counts and `kid` values — `kid` is a
 * public identifier and is safe to log, which is why {@see signingKey()}'s "unknown
 * kid" message omits it rather than interpolating attacker-controlled text into a
 * log line. {@see JsonWebKey::describe()} is the only key-shaped thing that is
 * loggable, and it cannot express `n`, `e` or `x5c`.
 *
 * @package Civi\Dfc
 */
final class JwksCache
{
    /** Never re-fetch more often than this, whatever the transport asks for. */
    public const MIN_REFRESH_COOLDOWN_SECONDS = 60;

    /** Never cache a key set for longer than this. */
    public const MAX_REFRESH_COOLDOWN_SECONDS = 86400;

    private readonly JwksFetcherInterface $fetcher;

    private readonly ClockInterface $clock;

    private readonly string $jwksUri;

    private readonly int $minTtlSeconds;

    private readonly int $maxTtlSeconds;

    private ?JsonWebKeySet $keys = null;

    /** The TTL the ISSUER asked for, before clamping. */
    private ?int $requestedTtlSeconds = null;

    private ?\DateTimeImmutable $fetchedAt = null;

    private ?\DateTimeImmutable $lastForcedRefreshAt = null;

    private int $forcedRefreshes = 0;

    private int $suppressedForcedRefreshes = 0;

    private int $fetchFailuresWithinTtl = 0;

    private int $lastSkippedKeyCount = 0;

    /**
     * @param JwksFetcherInterface $fetcher      How the document is obtained.
     * @param ClockInterface       $clock        Injected, so TTL is testable.
     * @param string               $jwksUri      From {@see OidcEndpoints::jwksUri()}.
     * @param int|null             $minTtlSeconds Null for {@see MIN_REFRESH_COOLDOWN_SECONDS}.
     * @param int|null             $maxTtlSeconds Null for 3600.
     */
    public function __construct(
        JwksFetcherInterface $fetcher,
        ClockInterface $clock,
        string $jwksUri,
        ?int $minTtlSeconds = null,
        ?int $maxTtlSeconds = null
    ) {
        $min = $minTtlSeconds ?? self::MIN_REFRESH_COOLDOWN_SECONDS;
        $max = $maxTtlSeconds ?? 3600;

        if ($min < 1) {
            throw new \InvalidArgumentException('The JWKS minimum TTL must be at least one second.');
        }

        if ($max < $min) {
            throw new \InvalidArgumentException(sprintf(
                'The JWKS maximum TTL (%d s) is below the minimum (%d s). The bounds are in that order because '
                . 'the minimum is the one that bounds requests to the issuer.',
                $max,
                $min
            ));
        }

        $this->fetcher = $fetcher;
        $this->clock = $clock;
        $this->jwksUri = $jwksUri;
        $this->minTtlSeconds = $min;
        $this->maxTtlSeconds = $max;
    }

    /**
     * The verification key for a token, refreshing once if the cached set has none.
     *
     * @param string|null $kid       The token header's `kid`, or null.
     * @param string      $algorithm The algorithm the token claims.
     *
     * @throws JwtDecodeException       when no key serves this token, after one
     *                                   refresh.
     * @throws JwksUnavailableException when the set is expired and cannot be
     *                                   refreshed.
     */
    public function signingKey(?string $kid, string $algorithm): JsonWebKey
    {
        $this->loadIfAbsentOrExpired();

        $key = $this->keys?->resolve($kid, $algorithm);
        if ($key !== null) {
            return $key;
        }

        // Unknown kid, or no single unambiguous candidate. One refresh, then one
        // retry — see the class docblock for why not more.
        $key = $this->refreshAndResolve($kid, $algorithm);
        if ($key !== null) {
            return $key;
        }

        throw new JwtDecodeException(
            $kid === null
                ? 'No single signing key in the issuer\'s key set serves this token. A token with no "kid" is '
                    . 'only acceptable when exactly one key could have signed it, and there is not exactly one.'
                : 'The token names a signing key the issuer does not currently publish, and the key set was '
                    . 'refreshed once and still does not contain it.'
        );
    }

    /**
     * Drop the cached set. The next lookup fetches.
     */
    public function invalidate(): void
    {
        $this->keys = null;
        $this->fetchedAt = null;
    }

    /**
     * Counters for the health endpoint. No key material, no URIs beyond the JWKS one.
     *
     * @return array<string, int|bool|null>
     */
    public function stats(): array
    {
        return [
            'jwks_uri' => $this->jwksUri,
            'loaded' => $this->keys !== null,
            'keyCount' => $this->keys?->count() ?? 0,
            'duplicateKids' => $this->keys?->duplicateKidCount() ?? 0,
            'skippedKeys' => $this->lastSkippedKeyCount,
            'forcedRefreshes' => $this->forcedRefreshes,
            'suppressedForcedRefreshes' => $this->suppressedForcedRefreshes,
            'fetchFailuresWithinTtl' => $this->fetchFailuresWithinTtl,
            'fetchedAt' => $this->fetchedAt?->format(\DATE_ATOM),
            'expiresAt' => $this->fetchedAt === null
                ? null
                : $this->fetchedAt->modify(sprintf('+%d seconds', $this->effectiveTtlSeconds()))->format(\DATE_ATOM),
        ];
    }

    /**
     * Audit-safe view of the cached keys. Empty when nothing is cached.
     *
     * @return array<int, array<string, string|bool>>
     */
    public function describe(): array
    {
        return $this->keys?->describe() ?? [];
    }

    /**
     * @return list<JsonWebKey>
     */
    public function cachedKeys(): array
    {
        return $this->keys?->keys() ?? [];
    }

    // -- Internals ------------------------------------------------------------

    private function loadIfAbsentOrExpired(): void
    {
        if ($this->keys !== null && $this->fetchedAt !== null) {
            $age = $this->clock->now()->getTimestamp() - $this->fetchedAt->getTimestamp();

            if ($age < $this->effectiveTtlSeconds()) {
                return;
            }
        }

        $document = $this->fetcher->fetch($this->jwksUri);
        $this->store($document);
    }

    private function refreshAndResolve(?string $kid, string $algorithm): ?JsonWebKey
    {
        if (!$this->mayForceRefresh()) {
            $this->suppressedForcedRefreshes++;

            return $this->keys?->resolve($kid, $algorithm);
        }

        $this->lastForcedRefreshAt = $this->clock->now();

        try {
            $document = $this->fetcher->fetch($this->jwksUri);
        } catch (JwksUnavailableException $unavailable) {
            // The cached set is still authoritative while it is within its TTL. A
            // failed refresh must not invalidate keys that were working.
            if ($this->keys !== null && $this->fetchedAt !== null && $this->isWithinTtl()) {
                $this->fetchFailuresWithinTtl++;

                return null;
            }

            throw $unavailable;
        }

        $this->forcedRefreshes++;
        $this->store($document);

        return $this->keys?->resolve($kid, $algorithm);
    }

    private function store(JwksDocument $document): void
    {
        $fresh = JsonWebKeySet::of($document->keys());

        // Merge so a key set that is mid-rotation — old and new keys overlapping —
        // resolves for a token signed with either. The newer document wins on a
        // `kid` collision; see JsonWebKeySet::mergedWith() for why nothing
        // outlives the issuer's current statement.
        $this->keys = $this->keys === null ? $fresh : $this->keys->mergedWith($fresh);
        $this->fetchedAt = $this->clock->now();
        $this->requestedTtlSeconds = $document->maxAgeSeconds();
        $this->lastSkippedKeyCount = $document->skippedKeyCount();
    }

    private function isWithinTtl(): bool
    {
        if ($this->fetchedAt === null) {
            return false;
        }

        $age = $this->clock->now()->getTimestamp() - $this->fetchedAt->getTimestamp();

        return $age < $this->effectiveTtlSeconds();
    }

    private function mayForceRefresh(): bool
    {
        if ($this->lastForcedRefreshAt === null) {
            return true;
        }

        $elapsed = $this->clock->now()->getTimestamp() - $this->lastForcedRefreshAt->getTimestamp();

        return $elapsed >= $this->minTtlSeconds;
    }

    /**
     * The TTL actually in force: the issuer's hint, clamped to the bounds.
     *
     * The FLOOR is the load-protection knob and the CEILING is the trust knob —
     * both directions matter, and an issuer sending `max-age=0` or `max-age=31536000`
     * must not be believed.
     */
    private function effectiveTtlSeconds(): int
    {
        return $this->clampTtl($this->requestedTtlSeconds);
    }

    /**
     * Apply the clamp to a document's own hint.
     *
     * Public so the clamp is a single, directly testable expression rather than
     * arithmetic repeated at each use site. An issuer sending `max-age=0` is
     * telling this server to re-fetch on every request; the floor refuses it. An
     * issuer sending `max-age=31536000` is asking for a year of trust in a key it
     * may retire in an hour; the ceiling refuses that too.
     */
    public function clampTtl(?int $requestedTtlSeconds): int
    {
        if ($requestedTtlSeconds === null) {
            return $this->minTtlSeconds;
        }

        return max($this->minTtlSeconds, min($this->maxTtlSeconds, $requestedTtlSeconds));
    }
}