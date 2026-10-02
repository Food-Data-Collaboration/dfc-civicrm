<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Ldp;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;

/**
 * The LDP and RDF term vocabulary this layer emits — in one class, as constants.
 *
 * ============================================================================
 * WHY CONSTANTS AND NOT CONFIGURATION
 * ============================================================================
 * `ldp:BasicContainer` and `ldp:contains` are terms of the W3C Linked Data
 * Platform Recommendation, bound to `http://www.w3.org/ns/ldp#`. They do not
 * vary by deployment, by DFC version, or by site configuration. The DFC v2
 * standard itself writes them as CURIEs in its worked examples, and every
 * consumer of this API resolves them against that namespace.
 *
 * Making them configurable would therefore have exactly one effect: it would let
 * an administrator configure a server that emits an `ldp:contains` no DFC client
 * can resolve — an interoperable-by-accident deployment that fails silently in
 * every third-party tool. That is strictly worse than a code change a human
 * reviewed. Protocol constants stay constants.
 *
 * THE CONTRAST WITH THE `@context` URL IS THE POINT
 *   The JSON-LD `@context` is NOT a constant. It changes with a DFC release
 *   (`context_2.0.0.json` → `context_2.1.0.json`) and, on an air-gapped site, it
 *   is a mirrored local URL. So it is read from {@see DfcReleaseConfig}, which
 *   derives it from the upstream release descriptor — PRD-002 §5 requires exactly
 *   that, and no mapper or serialiser may hardcode it. The rule this class
 *   embodies: **release-scoped vocabulary comes from config; specification-scoped
 *   vocabulary comes from constants.**
 *
 * ============================================================================
 * WHY THE `ldp:` PREFIX IS DECLARED EXPLICITLY IN THE CONTEXT
 * ============================================================================
 * The container document emits its context as
 *
 *     [ "<DFC context URL>", { "ldp": "http://www.w3.org/ns/ldp#" } ]
 *
 * rather than relying on the DFC context already binding `ldp`. Two reasons:
 *
 *   1. correctness — if a future DFC context does not bind `ldp`, the document
 *      would otherwise contain CURIE-shaped keys that no processor can expand,
 *      which is not JSON-LD, it is decorative text;
 *   2. honesty about what is being claimed — the LDP terms in this document are
 *      LDP's, and the document says so. A DFC context that bound `ldp` to
 *      something else would be wrong, and this override makes the document
 *      correct rather than dependent.
 *
 * The override is idempotent when the DFC context agrees, which is the normal
 * case.
 *
 * @package Civi\Dfc
 */
final class LdpVocabulary
{
    /** W3C Linked Data Platform namespace. A Recommendation constant. */
    public const NAMESPACE = 'http://www.w3.org/ns/ldp#';

    public const PREFIX = 'ldp';

    /** The class of every container this layer serialises. */
    public const TYPE_BASIC_CONTAINER = self::PREFIX . ':BasicContainer';

    /** The membership predicate. */
    public const PREDICATE_CONTAINS = self::PREFIX . ':contains';

    /** RDF's own type predicate, used by every `@type` in a JSON-LD document. */
    public const RDF_TYPE = '@type';

    /** JSON-LD's own keyword for the subject of the node object. */
    public const JSON_LD_ID = '@id';

    /** JSON-LD's own keyword for the context. */
    public const JSON_LD_CONTEXT = '@context';

    private readonly DfcReleaseConfig $config;

    public function __construct(DfcReleaseConfig $config)
    {
        $this->config = $config;
    }

    public function config(): DfcReleaseConfig
    {
        return $this->config;
    }

    /**
     * The DFC JSON-LD context URL, straight from the release config.
     *
     * Never cached into a constant, never derived here, never defaulted. If the
     * config does not have one, the config would not have been constructible.
     */
    public function contextUrl(): string
    {
        return $this->config->contextUrl();
    }

    /**
     * The LDP prefix binding, as a JSON-LD context object.
     *
     * @return array<string, string>
     */
    public function ldpBinding(): array
    {
        return [self::PREFIX => self::NAMESPACE];
    }

    /**
     * The full `@context` of a container document.
     *
     * A list, so the DFC context is an ARRAY ENTRY rather than an object being
     * merged into: JSON-LD specifies that a later entry overrides an earlier one,
     * and that is exactly the semantics we want for the `ldp` binding above.
     *
     * @return list<string|array<string, string>>
     */
    public function documentContext(): array
    {
        return [$this->contextUrl(), self::ldpBinding()];
    }

    /** The absolute IRI of `ldp:BasicContainer`. */
    public function basicContainerIri(): string
    {
        return self::NAMESPACE . 'BasicContainer';
    }

    /** The absolute IRI of `ldp:contains`. */
    public function containsIri(): string
    {
        return self::NAMESPACE . 'contains';
    }

    /**
     * Is $curie one of the terms this layer emits?
     *
     * A guard for tests and for the diagnostics endpoint: it lets sa-015's
     * conformance suite assert that the emitted vocabulary is exactly the DFC
     * release's plus LDP's, with no third-party terms sneaking in from a mapper.
     */
    public function isKnownTerm(string $curie): bool
    {
        return in_array($curie, [self::TYPE_BASIC_CONTAINER, self::PREDICATE_CONTAINS], true);
    }
}
