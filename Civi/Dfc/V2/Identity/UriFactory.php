<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity;

use Civi\Dfc\V2\Identity\Exception\InvalidUriException;

/**
 * The one and only place DFC URIs are constructed.
 *
 * ============================================================================
 * URI SHAPES, AND WHY EACH ONE IS SHAPED THAT WAY
 * ============================================================================
 * Defaults follow the DFC standard's own worked examples in
 * standard/technical-specifications/data-storage-and-discovery.md. With the
 * platform base `https://platform.example/dfc/v2/`:
 *
 *   platform WebID      https://platform.example/dfc/v2/webid
 *   platform subject    https://platform.example/dfc/v2/webid#me
 *   Identity Service    https://platform.example/dfc/v2/identity-service
 *   user WebID          https://platform.example/dfc/v2/users/<key>/webid
 *   user subject        https://platform.example/dfc/v2/users/<key>/webid#me
 *   org WebID           https://platform.example/dfc/v2/organizations/<key>/webid
 *   org container       https://platform.example/dfc/v2/organizations/<key>/
 *   org index resource  https://platform.example/dfc/v2/organizations/<key>/index
 *   semantic resource   https://platform.example/dfc/v2/semantic/address/<key>
 *
 * WHY SEMANTIC RESOURCES ARE NOT UNDER THE ORGANIZATION CONTAINER
 * The DFC standard says the organization container is the root for DFC *objects*
 * and enforces LDP containment (a resource in container C has URI `C . name`).
 * But `dfc-b:Address`, `dfc-b:PhoneNumber`, `dfc-b:SocialMedia` and the
 * `dfc-b:Place` family are nested property values inside a DFC object's JSON,
 * not LDP container members. Minting them under the organization container would
 * either break the containment invariant or collide with the container's own
 * children. So they get their own type-scoped namespace, keyed by DFC class:
 *
 *   /semantic/{dfc-class}/{key}
 *
 * The class is part of the path, so an Address URI and a PhoneNumber URI with
 * the same key are different resources — which is what lets the same opaque
 * identifier be reused per class without collision, and lets a client tell what
 * an `@id` denotes without dereferencing it.
 *
 * ============================================================================
 * THE STABILITY POLICY — STATED, NOT ASSUMED
 * ============================================================================
 *
 * GUARANTEED STABLE for the whole life of a record, because none of these inputs
 * can reach a minted URI (rules DfcReleaseConfig R2 and IdentifierGenerator I1):
 *
 *   - the opaque identifier itself;
 *   - the URI *shape* — every relative path in the table above;
 *   - the DFC version, which is deliberately absent from every minted URI, so
 *     upgrading DFC changes no `@id` (rule R1);
 *   - contact renames, merges, re-typing, email changes, phone changes,
 *     organisation renames, and a re-import of the same record under a new
 *     CiviCRM integer id.
 *
 * NOT STABLE — and this is a deliberate, documented answer, not a gap:
 *
 *   - THE AUTHORITY (scheme + host + port). If the site moves from
 *     https://old.example/ to https://new.example/, every URI this factory has
 *     ever minted changes, because the authority is part of the string.
 *
 *     There is no indirection available. A WebID is, by the WebID
 *     specification, an HTTP URI referring to an Agent; the DFC standard's
 *     platform WebID carries `dfc-t:supportedProtocolVersion` and
 *     `dfc-t:hasIdentityService` as absolute HTTP IRIs. There is no
 *     publisher-identifier indirection layer in the contract that this
 *     implementation could mint instead, so "identity survives a hostname change"
 *     is NOT achievable inside this layer.
 *
 *     What DOES survive is the opaque identifier, and therefore:
 *
 *       1. the previous authority must keep serving the previously published
 *          URIs — as a redirect or, better, a real alias endpoint that serves
 *          the same resource — for as long as a third party might hold one;
 *       2. a legacy DfcReleaseConfig/UriFactory pair built from the old base via
 *          DfcReleaseConfig::withPlatformBaseUri() must stay available so old
 *          URIs still resolve to the same identifiers;
 *       3. `recognises()` returns false for another authority's URIs, which is
 *          exactly the signal a reconciliation pass needs in order to notice a
 *          cross-authority identity instead of silently minting a second one.
 *
 *     A hostname change therefore creates a NEW SET of URIs, and preserving the
 *     old ones is a deployment obligation (keep the old host resolving) plus a
 *     data obligation (persist the base URI in force when a record was minted).
 *     Both obligations are stated here so that no lane assumes the URI layer
 *     solves them. UriFactoryStabilityTest asserts the behaviour, including the
 *     part where the two authority sets do NOT recognise each other.
 *
 * ============================================================================
 * ENCODING
 * ============================================================================
 * Every opaque identifier is percent-encoded with RFC 3986 pchar rules before it
 * touches a path. `rawurlencode()` is used because it leaves only the unreserved
 * set (`A-Za-z0-9-._~`) untouched, encodes space as `%20` rather than `+`, and
 * encodes `/` to `%2F`. Consequences, all asserted in UriFactoryTest:
 *
 *   - an identifier containing `/` becomes ONE path segment, never a new one;
 *   - `..` survives rawurlencode (dots are unreserved), so it is rejected
 *     explicitly rather than encoded — see below;
 *   - non-ASCII is encoded byte-wise per UTF-8, which is what RFC 3986 requires.
 *
 * Identifiers are validated, not sanitised, and rejected loudly:
 * empty, whitespace-only, longer than 255 bytes, containing C0 controls or DEL,
 * or exactly `.`/`..`. Sanitising would break the round-trip guarantee that
 * `match*()` provides.
 *
 * Collection and resource-name labels are a separate, stricter class: they are
 * developer-chosen path shape (`organizations`, `catalogs`, `index`), never user
 * data, so they are restricted to a conservative unreserved-label pattern and
 * never percent-encoded.
 *
 * @package Civi\Dfc
 */
final class UriFactory
{
    /** LDP collection that holds organization WebIDs and organization containers. */
    public const COLLECTION_ORGANIZATIONS = 'organizations';

    /** LDP collection that holds user WebIDs. */
    public const COLLECTION_USERS = 'users';

    /** The `index` resource describing an organization, per the DFC standard. */
    public const RESOURCE_INDEX = 'index';

    /** The subject fragment inside a WebID profile document. */
    public const SUBJECT_FRAGMENT = 'me';

    /** Upper bound on an opaque identifier, in bytes. */
    public const MAX_IDENTIFIER_BYTES = 255;

    /** Conservative label: no encoding, no separators, no traversal. */
    private const LABEL_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._~-]*)$/';

    /** DFC class segment under /semantic/. Lower-case so URIs stay tidy. */
    private const DFC_TYPE_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*)$/';

    private DfcReleaseConfig $config;

    private IdentifierGeneratorInterface $identifiers;

    public function __construct(
        DfcReleaseConfig $config,
        ?IdentifierGeneratorInterface $identifiers = null
    ) {
        $this->config = $config;
        $this->identifiers = $identifiers ?? new RandomIdentifierGenerator();
    }

    public function config(): DfcReleaseConfig
    {
        return $this->config;
    }

    // -- Platform-level URIs --------------------------------------------------

    /**
     * The platform WebID document. Advertised via `dfc-t:hasIdentityService` and
     * `foaf:primaryTopic` from every user and organization profile.
     */
    public function platformWebId(): string
    {
        return $this->config->platformWebIdUri();
    }

    /** The `dfc-t:Platform` agent inside the platform WebID document. */
    public function platformSubject(): string
    {
        return $this->subjectOf($this->platformWebId());
    }

    /**
     * The public Identity Service endpoint.
     *
     * A HEAD here with a valid DFC OIDC token MUST answer 303 to the user WebID
     * subject (sa-006 owns the endpoint; this class only owns its URI).
     */
    public function identityService(): string
    {
        return $this->config->identityServiceUri();
    }

    // -- User and organization identities ------------------------------------

    /**
     * Public WebID profile document for one DFC user.
     *
     * @param string $userKey Opaque, stored, rename-independent user identifier.
     *
     * @throws InvalidUriException
     */
    public function userWebId(string $userKey): string
    {
        return $this->join($this->config->platformBaseUri(), [
            $this->label(self::COLLECTION_USERS, 'user collection'),
            $this->identifier($userKey, 'user key'),
            $this->config->webIdLeaf(),
        ]);
    }

    /**
     * The `foaf:Agent` subject of a user WebID.
     *
     * This is the `Location` target of the Identity Service's 303 response and
     * the WebID other platforms federate with.
     */
    public function userSubject(string $userKey): string
    {
        return $this->subjectOf($this->userWebId($userKey));
    }

    /**
     * Public WebID profile document for one DFC Organization.
     *
     * The organization container and its `index` resource (see
     * {@see organizationIndex()}) are the LDP side of the same identity; this is
     * its WebID side. They are deliberately different documents, exactly as the
     * DFC standard separates a WebID from the organization container that a
     * private TypeIndex registers.
     */
    public function organizationWebId(string $organizationKey): string
    {
        return $this->join($this->config->platformBaseUri(), [
            $this->label(self::COLLECTION_ORGANIZATIONS, 'organization collection'),
            $this->identifier($organizationKey, 'organization key'),
            $this->config->webIdLeaf(),
        ]);
    }

    public function organizationSubject(string $organizationKey): string
    {
        return $this->subjectOf($this->organizationWebId($organizationKey));
    }

    /**
     * The root LDP container of an organization, e.g.
     * `https://platform.example/dfc/v2/organizations/<key>/`.
     *
     * Always ends in '/': an LDP container URI without a trailing slash is not
     * the container.
     */
    public function organizationContainer(string $organizationKey): string
    {
        return $this->ldpContainerUri(
            self::COLLECTION_ORGANIZATIONS,
            $this->identifier($organizationKey, 'organization key')
        );
    }

    /**
     * The `index` resource describing the organization, e.g.
     * `https://platform.example/dfc/v2/organizations/<key>/index`.
     *
     * This is the URI a private TypeIndex registers with `solid:instance`, and
     * the `@id` of the `dfc-b:Organization` node.
     */
    public function organizationIndex(string $organizationKey): string
    {
        return $this->containedResourceUri(
            $this->organizationContainer($organizationKey),
            self::RESOURCE_INDEX
        );
    }

    // -- Generic containers and resources ------------------------------------

    /**
     * Build an LDP container URI from a hierarchy of segments, with exactly one
     * trailing slash.
     *
     * Every segment is treated as OPAQUE DATA and percent-encoded. Fixed
     * collection names such as `organizations` or `catalogs` are already made of
     * unreserved characters, so they pass through unchanged — you can pass
     * `ldpContainerUri(self::COLLECTION_ORGANIZATIONS, $orgKey, 'catalogs')`
     * without needing a second API. Encoding everything means a caller cannot
     * accidentally create a new hierarchy level out of user data, and cannot
     * accidentally lose one either.
     *
     * @param string ...$segments At least one non-empty segment.
     *
     * @throws InvalidUriException
     */
    public function ldpContainerUri(string ...$segments): string
    {
        if ($segments === []) {
            throw new InvalidUriException(
                'An LDP container URI needs at least one path segment.'
            );
        }

        $encoded = [];
        foreach ($segments as $position => $segment) {
            $encoded[] = $this->identifier($segment, sprintf('container segment #%d', $position + 1));
        }

        return $this->join($this->config->platformBaseUri(), $encoded) . '/';
    }

    /**
     * Resolve a container member by name, honouring LDP containment.
     *
     * The DFC standard makes containment a MUST: "if the resource `resource` is
     * contained in the container `https://platform.ex/container/`, this resource
     * URI MUST be `https://platform.ex/container/resource`". Implementing it as
     * string concatenation is what guarantees the 1-1 correspondence between
     * containment triples and the path hierarchy, so no resource can be listed
     * by two containers and none can become an orphan.
     *
     * @param string $name A single resource-name LABEL (never user data). Use
     *                     {@see ldpContainerUri()} for opaque keys.
     *
     * @throws InvalidUriException
     */
    public function containedResourceUri(string $containerUri, string $name): string
    {
        $label = $this->label($name, 'resource name');

        if (!str_ends_with($containerUri, '/')) {
            // Tolerated rather than rejected: a caller holding a container URI
            // from a routing layer should not have to normalise it first.
            $containerUri .= '/';
        }

        if (preg_match('/[\s?#]/', $containerUri) === 1) {
            throw new InvalidUriException(sprintf(
                'The container URI "%s" contains whitespace, a query string or a fragment and cannot take a '
                . 'contained resource.',
                $containerUri
            ));
        }

        return $containerUri . $label;
    }

    /**
     * The DFC subject IRI for one record of one DFC class.
     *
     * @param string $dfcType           Lower-case class segment, e.g. `address`,
     *                                  `person`, `physical-place`. The DFC class
     *                                  name is not interpolated into the URI:
     *                                  `@type` carries that, and the class name is
     *                                  a schema artefact that a future DFC release
     *                                  may rename.
     * @param string $opaqueIdentifier Opaque, stored, rename-independent identifier.
     *
     * @throws InvalidUriException
     */
    public function semanticResourceUri(string $dfcType, string $opaqueIdentifier): string
    {
        if (preg_match(self::DFC_TYPE_PATTERN, $dfcType) !== 1) {
            throw new InvalidUriException(sprintf(
                'The DFC type segment must be a lower-case slug ([a-z0-9][a-z0-9-]*), got "%s". It is part of '
                . 'the path hierarchy, so it is restricted to a conservative label rather than percent-encoded.',
                $dfcType
            ));
        }

        return $this->join($this->config->semanticResourceBaseUri(), [
            $dfcType,
            $this->identifier($opaqueIdentifier, 'DFC semantic identifier'),
        ]);
    }

    /**
     * Append the `#me` subject fragment to a WebID profile document URI.
     *
     * Refuses a URI that already has a fragment: silently replacing one would
     * turn a caller's `.../webid#extended` into `.../webid#me` and quietly
     * publish a statement about the wrong subject.
     *
     * @throws InvalidUriException
     */
    public function subjectOf(string $profileUri): string
    {
        if ($profileUri === '' || preg_match('/[\s]/', $profileUri) === 1) {
            throw new InvalidUriException(sprintf(
                'A WebID profile URI must be a non-empty, whitespace-free URI. Got "%s".',
                $profileUri
            ));
        }

        if (str_contains($profileUri, '#')) {
            throw new InvalidUriException(sprintf(
                'A WebID profile URI must not already carry a fragment. Got "%s". Pass the document URI and let '
                . 'subjectOf() add "#%s".',
                $profileUri,
                self::SUBJECT_FRAGMENT
            ));
        }

        return $profileUri . '#' . self::SUBJECT_FRAGMENT;
    }

    // -- Identifier minting ---------------------------------------------------

    /**
     * Mint one opaque identifier through the injected generator.
     *
     * The generator is the only source of randomness in this layer, so a test
     * that injects a scripted generator gets fully deterministic URIs.
     *
     * @param string $kind Entity kind for the generator's own namespacing, e.g.
     *                     'contact'. Never a display name.
     */
    public function generateIdentifier(string $kind): string
    {
        $identifier = $this->identifiers->generate($kind);

        // A generator that returns something unusable is a defect in the
        // generator, and it must fail here rather than at the first read.
        $this->identifier($identifier, sprintf('identifier generated for kind "%s"', $kind));

        return $identifier;
    }

    /**
     * Mint a fresh identifier and return the semantic URI it produces.
     *
     * @param string $dfcType Lower-case class segment; @see semanticResourceUri()
     * @param string $kind    Entity kind for the generator, e.g. 'contact'.
     *
     * @throws InvalidUriException
     */
    public function newSemanticResourceUri(string $dfcType, string $kind): string
    {
        return $this->semanticResourceUri($dfcType, $this->generateIdentifier($kind));
    }

    // -- Recognition, for reconciliation and legacy authorities --------------

    /**
     * Is this URI, or this URI's `#me` subject, minted by THIS factory's base?
     *
     * Used by reconciliation to tell "a URI of ours, under the current base"
     * from "a URI of ours under a retired base" and from "a foreign URI". The
     * retired-base case returns false by design — it must be handled explicitly
     * with a factory built from the recorded legacy base, or the caller will
     * mint a duplicate identity for a record it already has.
     */
    public function recognises(string $uri): bool
    {
        $withoutFragment = $this->stripSubjectFragment($uri);
        $base = $this->config->platformBaseUri();

        return str_starts_with($withoutFragment, $base) && $withoutFragment !== $base;
    }

    /**
     * Recover the opaque user key from one of OUR user WebIDs, or null.
     *
     * The inverse guarantee that makes the identifier authoritative: whatever is
     * stored in the identity record is exactly what this returns, and
     * `userWebId(matchUserKey($u) ?? '') === $u` for every URI this factory can
     * mint. That is why identifiers are validated rather than sanitised.
     */
    public function matchUserKey(string $uri): ?string
    {
        return $this->matchKey(
            $uri,
            $this->config->platformBaseUri() . self::COLLECTION_USERS . '/',
            $this->config->webIdLeaf()
        );
    }

    /**
     * Recover the opaque organization key from one of OUR organization WebIDs, or
     * null.
     */
    public function matchOrganizationKey(string $uri): ?string
    {
        return $this->matchKey(
            $uri,
            $this->config->platformBaseUri() . self::COLLECTION_ORGANIZATIONS . '/',
            $this->config->webIdLeaf()
        );
    }

    /**
     * Recover the opaque identifier from one of OUR semantic resource URIs, or
     * null.
     *
     * The DFC type is NOT recovered here: it is a path segment, and callers that
     * need it know which class they are resolving. Returning the identifier alone
     * keeps this method honest about what makes an identity stable.
     */
    public function matchSemanticIdentifier(string $uri): ?string
    {
        $base = $this->config->semanticResourceBaseUri();
        $candidate = $this->stripSubjectFragment($uri);

        if (!str_starts_with($candidate, $base)) {
            return null;
        }

        $relative = substr($candidate, strlen($base));
        $segments = explode('/', $relative);
        if (count($segments) !== 2) {
            return null;
        }

        [$type, $identifier] = $segments;
        if (preg_match(self::DFC_TYPE_PATTERN, $type) !== 1) {
            return null;
        }

        return $this->decode($identifier, $base);
    }

    // -- Internals ------------------------------------------------------------

    /**
     * Validate an opaque identifier and percent-encode it as one path segment.
     *
     * @throws InvalidUriException
     */
    private function identifier(string $value, string $role): string
    {
        if ($value === '') {
            throw new InvalidUriException(sprintf(
                'The %s is empty. An empty segment would produce a URI that silently addresses its parent, '
                . 'which for a public identity means two records resolving to one URL.',
                $role
            ));
        }

        if (trim($value) === '') {
            throw new InvalidUriException(sprintf(
                'The %s is whitespace only. Rejected rather than encoded: a whitespace-only segment is never a '
                . 'real identifier.',
                $role
            ));
        }

        if (strlen($value) > self::MAX_IDENTIFIER_BYTES) {
            throw new InvalidUriException(sprintf(
                'The %s is %d bytes, over the %d-byte limit. Opaque identifiers are short; an over-long value '
                . 'usually means a display name, a JSON blob or an HTML fragment was passed by mistake.',
                $role,
                strlen($value),
                self::MAX_IDENTIFIER_BYTES
            ));
        }

        // Control characters and DEL. NUL in particular cannot be percent-encoded
        // into anything a server will route on.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidUriException(sprintf(
                'The %s contains a control character. Such characters cannot appear in a URI path.',
                $role
            ));
        }

        // `.` and `..` survive rawurlencode unchanged (dots are unreserved), so
        // they must be rejected explicitly or `..` would resolve to the parent
        // collection.
        if ($value === '.' || $value === '..') {
            throw new InvalidUriException(sprintf(
                'The %s is "%s", a relative-path reference rather than an identifier. Rejected: it would '
                . 'address a different resource than the one being named.',
                $role,
                $value
            ));
        }

        return rawurlencode($value);
    }

    /**
     * @throws InvalidUriException
     */
    private function label(string $value, string $role): string
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new InvalidUriException(sprintf(
                'The %s must be a non-empty label. Got "%s".',
                $role,
                $value
            ));
        }

        if (preg_match(self::LABEL_PATTERN, $value) !== 1) {
            throw new InvalidUriException(sprintf(
                'The %s "%s" is not a valid label. Labels are developer-chosen path shape, never user data, so '
                . 'they must match [A-Za-z0-9][A-Za-z0-9._~-]* and are never percent-encoded.',
                $role,
                $value
            ));
        }

        return $value;
    }

    /**
     * @param list<string> $segments Already-validated, already-encoded segments.
     */
    private function join(string $base, array $segments): string
    {
        // $base is normalised by DfcReleaseConfig to end in exactly one '/', and
        // every segment is non-empty and free of '/', so this concatenation can
        // neither lose nor duplicate a separator.
        $uri = $base . implode('/', $segments);

        $this->assertNoEmptySegment($uri);

        return $uri;
    }

    /**
     * Belt-and-braces invariant check on every URI this factory emits.
     *
     * @throws InvalidUriException
     */
    private function assertNoEmptySegment(string $uri): void
    {
        $schemeEnd = strpos($uri, '://');
        if ($schemeEnd === false) {
            throw new InvalidUriException(sprintf('Minted a URI without an absolute scheme: "%s".', $uri));
        }

        $firstSlash = strpos($uri, '/', $schemeEnd + 3);
        if ($firstSlash === false) {
            return;
        }

        $path = substr($uri, $firstSlash);
        if (str_contains($path, '//')) {
            throw new InvalidUriException(sprintf(
                'Minted a URI with an empty path segment (a double slash): "%s". That is the signature of a '
                . 'trailing-slash bug in the base URI, not of bad input.',
                $uri
            ));
        }
    }

    private function stripSubjectFragment(string $uri): string
    {
        $hash = strpos($uri, '#');

        return $hash === false ? $uri : substr($uri, 0, $hash);
    }

    /**
     * Match `<prefix><key>/<webIdLeaf>`, optionally with the `#me` fragment.
     *
     * @throws InvalidUriException
     */
    private function matchKey(string $uri, string $prefix, string $leaf): ?string
    {
        $candidate = $this->stripSubjectFragment($uri);

        $suffix = '/' . $leaf;
        if (!str_ends_with($candidate, $suffix)) {
            return null;
        }

        $withoutLeaf = substr($candidate, 0, -strlen($suffix));
        if (!str_starts_with($withoutLeaf, $prefix)) {
            return null;
        }

        $encoded = substr($withoutLeaf, strlen($prefix));
        if ($encoded === '' || str_contains($encoded, '/')) {
            return null;
        }

        return $this->decode($encoded, $uri);
    }

    /**
     * Reverse `rawurlencode()`.
     *
     * @throws InvalidUriException
     */
    private function decode(string $encoded, string $context): string
    {
        $decoded = rawurldecode($encoded);

        // rawurldecode() is total: it never throws and returns garbage for a
        // malformed sequence. Re-encoding and comparing is the only way to tell
        // a real round trip from a lossy one.
        if (rawurlencode($decoded) !== $encoded) {
            throw new InvalidUriException(sprintf(
                'The identifier in "%s" is not a valid percent-encoded segment; it does not round-trip.',
                $context
            ));
        }

        return $decoded;
    }
}