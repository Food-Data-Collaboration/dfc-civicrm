<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Ldp;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Controller\Http\ETag;
use Civi\Dfc\V2\Controller\Http\LinkRelation;
use Civi\Dfc\V2\Controller\Serialisation\CanonicalJson;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;

/**
 * An externally visible collection, as a DFC LDP Basic Container.
 *
 * Serialises to exactly:
 *
 *     {
 *       "@context": ["<DFC context URL>", {"ldp": "http://www.w3.org/ns/ldp#"}],
 *       "@id": "https://platform.example/dfc/v2/organizations/<key>/",
 *       "@type": "ldp:BasicContainer",
 *       "ldp:contains": [".../organizations/<key>/index", ...]
 *     }
 *
 * ============================================================================
 * CONTAINMENT IS ENFORCED, NOT ASSUMED
 * ============================================================================
 * The DFC standard makes LDP containment a MUST: a resource in container C has URI
 * `C . name`. {@see UriFactory::containedResourceUri()} guarantees that when a URI
 * is *minted*. This class guarantees the converse direction — that every member
 * *listed* here really is a direct child of this container:
 *
 *   - the member must share the container URI's full prefix;
 *   - the remainder must be exactly ONE non-empty path segment.
 *
 * A member from anywhere else is refused with `membership_inconsistent` rather
 * than listed. A containment triple that points outside the container breaks the
 * 1-1 correspondence between containment triples and the path hierarchy, and the
 * consequence is not cosmetic: the resource becomes an orphan that no container
 * claims, and LDP's "contained iff URI is container + name" inference breaks for
 * every client that relies on it. Refusing the page is the only safe response.
 *
 * The DFC organisation `index` resource satisfies this by construction: it is
 * `organizationContainer($key) . 'index'`.
 *
 * ============================================================================
 * MEMBERSHIP IS INDEPENDENT OF DISPLAY NAMES — STRUCTURALLY
 * ============================================================================
 * Nothing in this class, and nothing in {@see ContainerPage}, accepts a display
 * name, a title, a label, a description or an ordering hint. The only member
 * payload is a URI, and a URI comes from {@see UriFactory}, whose rule R2 is that
 * no display name, email, hostname or CiviCRM integer id ever reaches a minted
 * URI. So renaming an organisation cannot change its membership, cannot change
 * its page, and cannot change the page's ETag — a fact asserted directly rather
 * than argued.
 *
 * Order is source order and nothing else. The class neither sorts nor re-orders,
 * because re-ordering here would break the correspondence between this page's
 * offsets and the source's offsets, and that correspondence is what makes the
 * next/prev links correct.
 *
 * ============================================================================
 * `ldp:contains` IS ALWAYS A LIST
 * ============================================================================
 * JSON-LD permits a single-valued term to be a bare value or a one-element array,
 * and both mean the same thing. Emitting the bare value for a one-member container
 * would make the node object's SHAPE depend on its size: a client diffing
 * `ldp:contains` between page 1 and page 2 would see `string` become `array` and
 * could reasonably conclude the graph changed shape rather than that the container
 * shrank. RDF has no such distinction — membership is a set — so the wire form
 * should not invent one. Always a list, including `[]` for an empty container.
 *
 * ============================================================================
 * `@id` IS THE CONTAINER URI, AND ALWAYS HAS ITS TRAILING SLASH
 * ============================================================================
 * `.../organizations/<key>` and `.../organizations/<key>/` are different HTTP
 * resources; the second is the container. {@see ContainerPage} normalises it, so
 * the `@id` here cannot accidentally be the non-container URI. Getting this wrong
 * publishes a containment graph whose subject is not the container, and the
 * mismatch is invisible to every client that does not re-derive the container URI.
 *
 * @package Civi\Dfc
 */
final class LdpBasicContainer implements \JsonSerializable
{
    private readonly string $uri;

    private readonly ContainerPage $page;

    private readonly LdpVocabulary $vocabulary;

    private function __construct(string $uri, ContainerPage $page, LdpVocabulary $vocabulary)
    {
        $this->uri = $uri;
        $this->page = $page;
        $this->vocabulary = $vocabulary;
    }

    /**
     * Wrap a page as a container.
     *
     * @param string            $containerUri Must equal the page's container URI;
     *                                       a mismatch means the caller fetched
     *                                       the wrong page, which is checked
     *                                       rather than trusted.
     * @param DfcReleaseConfig  $config       Supplies the JSON-LD `@context` URL.
     *
     * @throws DfcApiException 500 `membership_inconsistent` when a member is not a
     *         contained child of this container.
     */
    public static function of(
        string $containerUri,
        ContainerPage $page,
        DfcReleaseConfig $config
    ): self {
        $container = self::normaliseContainerUri($containerUri);

        if ($page->containerUri() !== $container) {
            throw new \InvalidArgumentException(sprintf(
                'The page was fetched for "%s" but is being serialised as "%s". A page and the container it '
                . 'belongs to are the same subject; combining two is how `ldp:contains` triples end up '
                . 'attributed to the wrong collection.',
                $page->containerUri(),
                $container
            ));
        }

        self::assertContainment($container, $page->members());

        return new self($container, $page, new LdpVocabulary($config));
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function page(): ContainerPage
    {
        return $this->page;
    }

    public function vocabulary(): LdpVocabulary
    {
        return $this->vocabulary;
    }

    /** `ldp:BasicContainer`, the CURIE form used in the serialisation. */
    public function type(): string
    {
        return LdpVocabulary::TYPE_BASIC_CONTAINER;
    }

    /** @return list<string> */
    public function memberUris(): array
    {
        return $this->page->members();
    }

    /**
     * The document, as a PHP array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            LdpVocabulary::JSON_LD_CONTEXT => $this->vocabulary->documentContext(),
            LdpVocabulary::JSON_LD_ID => $this->uri,
            LdpVocabulary::RDF_TYPE => $this->type(),
            // Always a list, including empty. See the class docblock.
            LdpVocabulary::PREDICATE_CONTAINS => $this->page->members(),
        ];
    }

    /**
     * The exact response bytes.
     *
     * Canonical, so the same page always produces the same bytes — which is what
     * makes {@see etag()} meaningful and lets a test assert the document as a
     * literal string rather than as a decoded structure.
     */
    public function toJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /**
     * A STRONG entity tag over {@see toJson()}.
     *
     * Strong, not weak, because this serialisation is canonical: the bytes are a
     * pure function of the page. A weak tag would be an admission that it is not,
     * and it would make `If-Match` on a container unusable.
     *
     * The route must send exactly {@see toJson()} for this tag to describe the
     * body it actually sent.
     */
    public function etag(): ETag
    {
        return ETag::strong($this->toJson());
    }

    /**
     * `next` / `prev` paging relations for a `Link` header.
     *
     * Empty for a collection that fits on one page, so a client can distinguish
     * "single page" from "page 1 of many" without parsing the body.
     *
     * @return list<LinkRelation>
     */
    public function links(): array
    {
        return $this->page->links();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Every listed member must be a DIRECT child of the container.
     *
     * @param list<string> $members
     *
     * @throws DfcApiException 500 `membership_inconsistent`
     */
    private static function assertContainment(string $containerUri, array $members): void
    {
        foreach ($members as $member) {
            if (!str_starts_with($member, $containerUri)) {
                throw DfcApiException::of(ProtocolError::membershipInconsistent());
            }

            $remainder = substr($member, strlen($containerUri));

            // One non-empty segment and nothing else: no trailing slash (that
            // would be a sub-container, which this Basic Container does not
            // declare — see the note below), no further "/" (a descendant is not
            // a member), not empty (that would be the container itself).
            if ($remainder === '' || str_contains($remainder, '/')) {
                throw DfcApiException::of(ProtocolError::membershipInconsistent());
            }
        }
    }

    private static function normaliseContainerUri(string $containerUri): string
    {
        $candidate = trim($containerUri);

        if (!str_ends_with($candidate, '/')) {
            $candidate .= '/';
        }

        return $candidate;
    }
}
