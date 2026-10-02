<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity;

use Civi\Dfc\V2\Identity\Exception\InvalidConfigurationException;

/**
 * Immutable value object holding the configured DFC release contract.
 *
 * ============================================================================
 * PROVENANCE — WHERE EVERY VALUE COMES FROM, AND WHY IT IS NOT IN A MAPPER
 * ============================================================================
 *
 * 1. DFC version, context URL, ontology distribution base
 *      Source: `config/dfc-release.yaml` in the DFC-LinkML repository
 *      (Food-Data-Collaboration/DFC-LinkML). That file's own header calls itself
 *      the "single source of truth ... everything generated in this repo must be
 *      reproducible from the pins below." The pins this class consumes are:
 *
 *        dfc_ontology_version: "2.0.0"
 *        ontology.business_url: ".../v2.0.0/src/DFC_BusinessOntology.rdf"
 *        ontology.technical_url: ".../v2.0.0/src/DFC_TechnicalOntology.rdf"
 *
 *      The context URL is *derived*, never hand-written, using the exact shape
 *      the generated connectors use (`Connector::getContextUrl()`, and the
 *      documented template in docs/concepts/context-and-versioning.md):
 *
 *        {ONTOLOGY_BASE_URL}/v{version}/context/context_{version}.json
 *
 *      DfcReleaseConfigTest pins that derived value against upstream's own
 *      constant `Connector::DEFAULT_CONTEXT_URL`, so a divergence between this
 *      extension and the connector fails a test instead of reaching a consumer.
 *
 * 2. dfc-b: / dfc-t: prefixes
 *      Source: `config/dfc-default.yaml` in the same repository, under
 *      `prefixes:`. They are NOT in dfc-release.yaml, so the descriptor this
 *      extension ships carries them in an explicitly-marked `dfc_civicrm:`
 *      block copied from that file — see tests/fixtures/dfc-release.yaml.
 *
 *      Two consequences worth knowing, both load-bearing for URI stability:
 *
 *      (a) The prefix IRIs are UNVERSIONED. Upstream pins them to
 *          `https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#` — no
 *          `/v2.0.0/`. A DFC version bump therefore changes the `@context` URL
 *          but leaves every `dfc-b:` / `dfc-t:` CURIE byte-identical.
 *
 *      (b) The DFC standard's own namespace table
 *          (standard/technical-specifications/data-storage-and-discovery.md)
 *          lists `dfc-b` and `dfc-t` with EMPTY IRIs. The concrete IRIs are a
 *          LinkML generation artefact, not protocol truth. Treating them as
 *          upstream configuration rather than as protocol constants is why they
 *          are configurable at all.
 *
 * 3. Platform base URI, platform WebID, Identity Service, semantic base
 *      Source: THIS DEPLOYMENT, not the DFC release. These describe *this
 *      CiviCRM site*, so they come from the `platform_base_uri` argument (the
 *      site base URL joined with the `dfc_base_path` setting, default `dfc/v2`
 *      — see dfc_civicrm.php). No release descriptor can supply them, and this
 *      class will not invent them: fromDescriptorArray() fails if
 *      `platform_base_uri` is absent rather than falling back to a placeholder.
 *
 *      Default *shapes*, all overridable, follow the DFC standard's own examples
 *      verbatim: `https://platform.ex/webid`,
 *      `https://platform.ex/identity-service`, the `#me` subject,
`https://platform.ex/organizations/johns/` and its `index` resource.
 *
 * ============================================================================
 * THE RULES THAT MATTER FOR URI STABILITY
 * ============================================================================
 *
 * R1. No DFC version is embedded in any minted URI. The version travels in
 *     `@context`, which is what the DFC documentation actually instructs
 *     ("the version travels with the document — but only if you do not rewrite
 *     the context URL by hand"). Consequence: upgrading DFC 2.0.0 -> 2.0.1
 *     changes no `@id` this deployment has ever minted.
 *
 * R2. No display name, email, hostname or CiviCRM integer id is ever part of a
 *     minted URI. Only opaque identifiers, minted once and stored.
 *     See {@see UriFactory} and {@see IdentifierGeneratorInterface}.
 *
 * R3. The authority is NOT stable and cannot be made stable by this layer.
 *     See {@see withPlatformBaseUri()} and UriFactoryStabilityTest, which assert
 *     the actual behaviour instead of asserting the wish.
 *
 * @package Civi\Dfc
 *
 * @see UriFactory — the only class allowed to build a URI
 * @see ReleaseDescriptorParser — the restricted YAML reader
 */
final class DfcReleaseConfig
{
    /**
     * Upstream ontology distribution root, mirroring `Connector::ONTOLOGY_BASE_URL`.
     *
     * This is a *derivation default* for the release contract, not a protocol
     * constant: an air-gapped site may mirror w3id.org, and `context_url` /
     * `ontology_file_base` exist as overrides for exactly that.
     */
    public const ONTOLOGY_BASE_URL = 'https://w3id.org/dfc/ontology';

    /** Leaf of the platform / user / organization WebID documents. */
    public const WEB_ID_LEAF = 'webid';

    /** Leaf of the platform Identity Service endpoint. */
    public const IDENTITY_SERVICE_LEAF = 'identity-service';

    /** Directory under the platform base where DFC subject IRIs are minted. */
    public const SEMANTIC_RESOURCE_LEAF = 'semantic';

    /** Upstream prefix names for the business and technical ontologies. */
    public const PREFIX_BUSINESS = 'dfc-b';

    public const PREFIX_TECHNICAL = 'dfc-t';

    private string $dfcVersion;

    private string $contextUrl;

    private string $platformBaseUri;

    private string $platformWebIdUri;

    private string $identityServiceUri;

    private string $semanticResourceBaseUri;

    private string $semanticResourcePath;

    private string $ontologyFileBase;

    private string $businessPrefixIri;

    private string $technicalPrefixIri;

    private string $businessPrefixName;

    private string $technicalPrefixName;

    private string $webIdLeaf;

    private string $identityServiceLeaf;

    /**
     * @param string $dfcVersion              e.g. "2.0.0"
     * @param string $contextUrl              absolute https URI of the JSON-LD context
     * @param string $platformBaseUri         absolute http(s) URI; normalised here
     * @param string $platformWebIdUri        one path segment below $platformBaseUri
     * @param string $identityServiceUri      one path segment below $platformBaseUri
     * @param string $semanticResourceBaseUri a directory below $platformBaseUri
     * @param string $ontologyFileBase        absolute https URI, one trailing slash
     * @param string $businessPrefixIri       absolute https IRI ending in '#' or '/'
     * @param string $technicalPrefixIri      absolute https IRI ending in '#' or '/'
     */
    public function __construct(
        string $dfcVersion,
        string $contextUrl,
        string $platformBaseUri,
        string $platformWebIdUri,
        string $identityServiceUri,
        string $semanticResourceBaseUri,
        string $ontologyFileBase,
        string $businessPrefixIri,
        string $technicalPrefixIri,
        string $businessPrefixName = self::PREFIX_BUSINESS,
        string $technicalPrefixName = self::PREFIX_TECHNICAL
    ) {
        $this->dfcVersion = self::assertVersion($dfcVersion);

        // Upstream namespaces are https-only: there is no legitimate reason for a
        // release pin to be served over plaintext, and silently allowing it is how
        // a pinned context becomes a downgrade vector.
        $this->contextUrl = self::assertHttpsUri($contextUrl, 'DFC context URL');
        $this->ontologyFileBase = self::normaliseDirectoryUri($ontologyFileBase, 'ontology file base');
        $this->businessPrefixIri = self::assertNamespaceIri($businessPrefixIri, $businessPrefixName . ': prefix IRI');
        $this->technicalPrefixIri = self::assertNamespaceIri($technicalPrefixIri, $technicalPrefixName . ': prefix IRI');
        $this->businessPrefixName = self::assertPrefixName($businessPrefixName, 'business prefix name');
        $this->technicalPrefixName = self::assertPrefixName($technicalPrefixName, 'technical prefix name');

        $this->platformBaseUri = self::normaliseDirectoryUri($platformBaseUri, 'platform base URI');
        // Document URIs are normalised WITHOUT a trailing slash: `.../webid` and
        // `.../webid/` are different HTTP resources, and the WebID advertised in
        // the DFC standard's own example is the former.
        $this->platformWebIdUri = self::normaliseUri($platformWebIdUri, 'platform WebID URI');
        $this->identityServiceUri = self::normaliseUri($identityServiceUri, 'identity service URI');
        $this->semanticResourceBaseUri = self::normaliseDirectoryUri($semanticResourceBaseUri, 'semantic resource base URI');

        $this->webIdLeaf = $this->requireSingleSegmentBelow(
            $this->platformWebIdUri,
            $this->platformBaseUri,
            'platform WebID URI'
        );
        $this->identityServiceLeaf = $this->requireSingleSegmentBelow(
            $this->identityServiceUri,
            $this->platformBaseUri,
            'identity service URI'
        );

        $this->semanticResourcePath = $this->requireDirectoryBelow(
            $this->semanticResourceBaseUri,
            $this->platformBaseUri,
            'semantic resource base URI'
        );
    }

    // -- Construction from the release descriptor -----------------------------

    /**
     * Build the config from an upstream release descriptor.
     *
     * @param array<string, mixed> $descriptor Parsed `dfc-release.yaml`.
     * @param array<string, string> $overrides
     *        Deployment values and pinned-version escape hatches. Recognised keys:
     *          - `platform_base_uri`          (REQUIRED) this site's public DFC base
     *          - `context_url`                pin a mirrored JSON-LD context
     *          - `ontology_file_base`         pin a mirrored ontology distribution
     *          - `semantic_resource_base_uri` where subject IRIs are minted
     *          - `platform_web_id`            path of the WebID document under the base
     *          - `identity_service`           path of the Identity Service under the base
     *
     * Everything else is derived from the descriptor. An unknown override key is
     * an error, not a no-op: a typo'd setting that silently does nothing is how a
     * site ends up serving the wrong namespace.
     *
     * @throws InvalidConfigurationException
     */
    public static function fromDescriptorArray(array $descriptor, array $overrides = []): self
    {
        $known = [
            'platform_base_uri',
            'context_url',
            'ontology_file_base',
            'semantic_resource_base_uri',
            'platform_web_id',
            'identity_service',
        ];
        foreach (array_keys($overrides) as $key) {
            if (!in_array((string) $key, $known, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'Unknown DFC release override "%s". Known overrides: %s.',
                    (string) $key,
                    implode(', ', $known)
                ));
            }
        }

        $version = self::requireDescriptorString($descriptor, 'dfc_ontology_version');
        self::assertDescriptorVersionConsistency($descriptor, $version);

        // Derived, never hand-coded: this is upstream's documented template and
        // upstream's own Connector::getContextUrl() formula.
        $contextUrl = $overrides['context_url']
            ?? self::ONTOLOGY_BASE_URL . '/v' . $version . '/context/context_' . $version . '.json';
        $ontologyFileBase = $overrides['ontology_file_base']
            ?? self::ONTOLOGY_BASE_URL . '/v' . $version . '/';

        $prefixes = $descriptor['dfc_civicrm']['prefixes'] ?? null;
        if (!is_array($prefixes)) {
            throw new InvalidConfigurationException(
                'The release descriptor carries no `dfc_civicrm.prefixes` map. The dfc-b: / dfc-t: IRIs are '
                . 'defined by DFC-LinkML in config/dfc-default.yaml, not in config/dfc-release.yaml, so the '
                . 'descriptor this extension ships copies them into a `dfc_civicrm:` block. Refusing to guess.'
            );
        }

        // Normalise BEFORE concatenating. "$base . 'webid'" on a raw base without
        // a trailing slash would silently produce ".../dfc/v2webid".
        $base = self::normaliseDirectoryUri(
            self::requireOverride($overrides, 'platform_base_uri'),
            'platform base URI'
        );

        return new self(
            $version,
            $contextUrl,
            $base,
            $base . self::relativeTo($overrides['platform_web_id'] ?? self::WEB_ID_LEAF),
            $base . self::relativeTo($overrides['identity_service'] ?? self::IDENTITY_SERVICE_LEAF),
            $overrides['semantic_resource_base_uri'] ?? $base . self::SEMANTIC_RESOURCE_LEAF . '/',
            $ontologyFileBase,
            self::requireDescriptorString($prefixes, self::PREFIX_BUSINESS),
            self::requireDescriptorString($prefixes, self::PREFIX_TECHNICAL),
        );
    }

    /**
     * Build the config by reading a release descriptor from disk.
     *
     * @param string $path Absolute path to a `dfc-release.yaml`-shaped file.
     * @param array<string, string> $overrides @see fromDescriptorArray()
     *
     * @throws InvalidConfigurationException
     * @throws Exception\MalformedReleaseDescriptorException
     */
    public static function fromDescriptorFile(string $path, array $overrides = []): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidConfigurationException(sprintf(
                'DFC release descriptor not found or not readable: %s. It has to be shipped inside the '
                . 'extension release archive: the published siol-data/linkml-connector package contains only '
                . 'src/, contexts/ and vocabularies/, NOT config/dfc-release.yaml.',
                $path
            ));
        }

        $yaml = file_get_contents($path);
        if ($yaml === false) {
            throw new InvalidConfigurationException(sprintf('Could not read DFC release descriptor: %s', $path));
        }

        return self::fromDescriptorArray(ReleaseDescriptorParser::parse($yaml), $overrides);
    }

    // -- Accessors ------------------------------------------------------------

    public function dfcVersion(): string
    {
        return $this->dfcVersion;
    }

    public function contextUrl(): string
    {
        return $this->contextUrl;
    }

    /** Always ends in exactly one '/'. */
    public function platformBaseUri(): string
    {
        return $this->platformBaseUri;
    }

    /** Never ends in '/': it is a document, not a directory. */
    public function platformWebIdUri(): string
    {
        return $this->platformWebIdUri;
    }

    /** Never ends in '/': it is a document, not a directory. */
    public function identityServiceUri(): string
    {
        return $this->identityServiceUri;
    }

    /** Always ends in exactly one '/'. */
    public function semanticResourceBaseUri(): string
    {
        return $this->semanticResourceBaseUri;
    }

    /** Always ends in exactly one '/'. */
    public function ontologyFileBase(): string
    {
        return $this->ontologyFileBase;
    }

    public function businessPrefixName(): string
    {
        return $this->businessPrefixName;
    }

    public function technicalPrefixName(): string
    {
        return $this->technicalPrefixName;
    }

    public function businessPrefixIri(): string
    {
        return $this->businessPrefixIri;
    }

    public function technicalPrefixIri(): string
    {
        return $this->technicalPrefixIri;
    }

    /** Path of the WebID document relative to the platform base, e.g. "webid". */
    public function webIdLeaf(): string
    {
        return $this->webIdLeaf;
    }

    /** Path of the Identity Service relative to the platform base. */
    public function identityServiceLeaf(): string
    {
        return $this->identityServiceLeaf;
    }

    // -- Derived vocabularies -------------------------------------------------

    /**
     * Expand a business-ontology local name to its absolute IRI.
     *
     * Use the IRI (not the CURIE) when comparing, keying a map or storing;
     * use {@see curie()} when emitting JSON-LD, which is a separate concern owned
     * by the export layer, not by this one.
     */
    public function businessOntologyIri(string $localName): string
    {
        return $this->businessPrefixIri . self::assertLocalName($localName, 'business ontology local name');
    }

    public function technicalOntologyIri(string $localName): string
    {
        return $this->technicalPrefixIri . self::assertLocalName($localName, 'technical ontology local name');
    }

    /**
     * Render `prefix:localName` using a configured prefix name.
     *
     * @throws InvalidConfigurationException on an unconfigured prefix or local name
     */
    public function curie(string $prefixName, string $localName): string
    {
        if ($prefixName === $this->businessPrefixName) {
            return $prefixName . ':' . self::assertLocalName($localName, 'business ontology local name');
        }
        if ($prefixName === $this->technicalPrefixName) {
            return $prefixName . ':' . self::assertLocalName($localName, 'technical ontology local name');
        }

        throw new InvalidConfigurationException(sprintf(
            'Unknown DFC prefix "%s". Configured prefixes: %s, %s.',
            $prefixName,
            $this->businessPrefixName,
            $this->technicalPrefixName
        ));
    }

    /**
     * Flat view for diagnostics, the health endpoint and audit context.
     *
     * Contains public identifiers and configuration only. There is deliberately
     * no way to get a contact-derived value into this array — that is rule R2.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'dfc_version' => $this->dfcVersion,
            'context_url' => $this->contextUrl,
            'platform_base_uri' => $this->platformBaseUri,
            'platform_web_id' => $this->platformWebIdUri,
            'identity_service' => $this->identityServiceUri,
            'semantic_resource_base_uri' => $this->semanticResourceBaseUri,
            'ontology_file_base' => $this->ontologyFileBase,
            'business_prefix' => $this->businessPrefixName . ': ' . $this->businessPrefixIri,
            'technical_prefix' => $this->technicalPrefixName . ': ' . $this->technicalPrefixIri,
        ];
    }

    // -- Hostname / base-URI changes -----------------------------------------

    /**
     * Return an equivalent config re-pointed at a different public base URI.
     *
     * THIS IS THE HOSTNAME-CHANGE MACHINERY, and it deliberately does exactly
     * one thing: swap the authority. The DFC version, context URL, prefixes and
     * every derived relative path are carried over untouched, so:
     *
     *   - opaque identifiers are unchanged (they never contained a host),
     *   - the URI *shape* is unchanged (same paths, new authority),
     *   - old and new URIs are different strings and neither is a prefix of the
     *     other, so the old ones stop resolving the moment the old authority
     *     stops serving them.
     *
     * In other words a hostname change mints a NEW set of URIs. Nothing in this
     * layer can keep the old ones alive; only a redirect/alias on the old
     * authority can. See UriFactoryStabilityTest for the asserted behaviour.
     *
     * @throws InvalidConfigurationException
     */
    public function withPlatformBaseUri(string $platformBaseUri): self
    {
        // Normalise before concatenating, for the same reason
        // fromDescriptorArray() does: ".../dfc/v2" . "webid" would be
        // ".../dfc/v2webid".
        $base = self::normaliseDirectoryUri($platformBaseUri, 'platform base URI');

        return new self(
            $this->dfcVersion,
            $this->contextUrl,
            $base,
            $base . $this->webIdLeaf,
            $base . $this->identityServiceLeaf,
            $base . $this->semanticResourcePath,
            $this->ontologyFileBase,
            $this->businessPrefixIri,
            $this->technicalPrefixIri,
            $this->businessPrefixName,
            $this->technicalPrefixName,
        );
    }

    // -- Normalisation (public because the tests pin the policy directly) -----

    /**
     * Normalise an absolute http(s) URI: lowercase the scheme and authority,
     * collapse repeated slashes in the path, preserve path case, and leave the
     * trailing-slash policy to the caller.
     *
     * PATH CASE IS PRESERVED. Hosts are case-insensitive so they are folded;
     * paths are not, and folding them would silently break a base path such as
     * `/DFC/v2`. Repeated slashes are collapsed because an empty path segment is
     * meaningless and is always a typo — an administrator typing
     * `https://site.example//dfc/v2///` must not get `...//dfc/v2///webid`.
     *
     * @throws InvalidConfigurationException
     */
    public static function normaliseUri(string $uri, string $label = 'URI'): string
    {
        $parts = self::splitAbsoluteHttpUri($uri, $label);

        $path = $parts['path'] === '' ? '' : (string) preg_replace('#/{2,}#', '/', $parts['path']);

        return $parts['scheme'] . '://' . $parts['authority'] . $path;
    }

    /**
     * Normalise a base or directory URI: {@see normaliseUri()} plus exactly one
     * trailing slash.
     *
     * @throws InvalidConfigurationException
     */
    public static function normaliseDirectoryUri(string $uri, string $label = 'base URI'): string
    {
        $normalised = self::normaliseUri($uri, $label);
        if (str_ends_with($normalised, '/')) {
            return $normalised;
        }
        if (!self::hasPath($normalised)) {
            // No path at all: "https://site.example" is itself the root.
            return $normalised . '/';
        }

        return $normalised . '/';
    }

    /**
     * @return array{scheme: string, authority: string, path: string}
     *
     * @throws InvalidConfigurationException
     */
    private static function splitAbsoluteHttpUri(string $uri, string $label): array
    {
        $trimmed = trim($uri);
        if ($trimmed === '' || $trimmed !== $uri) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must be a non-empty, whitespace-free absolute URI. Got %s.',
                $label,
                $uri === '' ? '(empty string)' : '"' . $trimmed . '"'
            ));
        }

        // The path character class deliberately excludes '?' and '#', so a query
        // string or fragment fails to match rather than being silently dropped:
        // a base URI with either is not a base.
        $matched = preg_match(
            '#^(?<scheme>[A-Za-z][A-Za-z0-9+.\-]*)://(?<authority>[^/?\#]*)(?<path>/[^?\#]*)?$#',
            $trimmed,
            $m
        );
        if ($matched !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must be an absolute http(s) URI with no query string, no fragment and no userinfo. '
                . 'Got "%s".',
                $label,
                $trimmed
            ));
        }

        $scheme = strtolower($m['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidConfigurationException(sprintf(
                'The %s must use http or https, got "%s". A DFC identity published under another scheme '
                . '(urn:, did:, ftp:) is not a WebID and cannot be dereferenced.',
                $label,
                $scheme
            ));
        }

        $authority = strtolower($m['authority']);
        if (str_contains($authority, '@')) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must not contain userinfo. Got "%s".',
                $label,
                $trimmed
            ));
        }

        [$host, $port] = self::splitAuthority($authority, $label, $trimmed);
        if ($scheme === 'http' && !self::isLoopbackHost($host)) {
            throw new InvalidConfigurationException(sprintf(
                'The %s uses http on a non-loopback host ("%s"). DFC WebIDs are public identifiers; publishing '
                . 'them over plaintext lets anyone on the path mint or mutate identity documents. Use https '
                . '(loopback is allowed so tests can run).',
                $label,
                $host
            ));
        }

        return [
            'scheme' => $scheme,
            'authority' => $host . $port,
            'path' => $m['path'] ?? '',
        ];
    }

    /**
     * @return array{0: string, 1: string} host (with brackets for IPv6) and port suffix
     */
    private static function splitAuthority(string $authority, string $label, string $original): array
    {
        if ($authority === '') {
            throw new InvalidConfigurationException(sprintf('The %s has an empty host. Got "%s".', $label, $original));
        }

        if (preg_match('#^\[(?<v6>[^\]]*)\](?::(?<port>\d+))?$#', $authority, $pm) === 1) {
            if ($pm['v6'] === '') {
                throw new InvalidConfigurationException(sprintf('The %s has an empty IPv6 host. Got "%s".', $label, $original));
            }

            $port = isset($pm['port']) && $pm['port'] !== '' ? ':' . $pm['port'] : '';

            return ['[' . $pm['v6'] . ']', $port];
        }

        $colon = strpos($authority, ':');
        if ($colon === false) {
            return [$authority, ''];
        }

        $host = substr($authority, 0, $colon);
        $port = substr($authority, $colon + 1);
        if ($host === '' || preg_match('#^\d+$#', $port) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'The %s has a malformed host:port authority. Got "%s".',
                $label,
                $original
            ));
        }

        return [$host, ':' . $port];
    }

    private static function isLoopbackHost(string $host): bool
    {
        $bare = trim($host, '[]');

        return $bare === 'localhost'
            || $bare === '::1'
            || preg_match('#^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$#', $bare) === 1;
    }

    /**
     * A prefix IRI must end in a delimiter, or `dfc-b:Organization` would expand
     * to `...DFC_BusinessOntology.owlOrganization`.
     *
     * @throws InvalidConfigurationException
     */
    private static function assertNamespaceIri(string $iri, string $label): string
    {
        $delimiter = '';
        $body = $iri;
        if (str_ends_with($iri, '#')) {
            $delimiter = '#';
            $body = substr($iri, 0, -1);
        } elseif (str_ends_with($iri, '/')) {
            $delimiter = '/';
            $body = rtrim(substr($iri, 0, -1), '/');
        } else {
            throw new InvalidConfigurationException(sprintf(
                'The %s must end in "#" or "/". Got "%s" — a namespace without a delimiter yields concatenated '
                . 'garbage once a local name is appended.',
                $label,
                $iri
            ));
        }

        $normalised = self::normaliseUri($body, $label);
        if (!self::isHttps($normalised)) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must be https. Got "%s". Upstream namespace artefacts are never served over plaintext.',
                $label,
                $iri
            ));
        }

        return $normalised . $delimiter;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function assertHttpsUri(string $uri, string $label): string
    {
        $normalised = self::normaliseUri($uri, $label);
        if (!self::isHttps($normalised)) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must be https. Got "%s". Upstream release artefacts are never served over plaintext.',
                $label,
                $uri
            ));
        }

        return $normalised;
    }

    private static function isHttps(string $normalised): bool
    {
        return str_starts_with($normalised, 'https://');
    }

    private static function hasPath(string $normalised): bool
    {
        $schemeEnd = strpos($normalised, '://');
        if ($schemeEnd === false) {
            return false;
        }

        return strpos($normalised, '/', $schemeEnd + 3) !== false;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function assertPrefixName(string $name, string $label): string
    {
        if (preg_match('#^[A-Za-z][A-Za-z0-9.\-]*$#', $name) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must be a CURIE prefix name ([A-Za-z][A-Za-z0-9.-]*). Got "%s".',
                $label,
                $name
            ));
        }

        return $name;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function assertLocalName(string $localName, string $label): string
    {
        if ($localName === '' || preg_match('#^[A-Za-z_][A-Za-z0-9_.\-]*$#', $localName) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'The %s must be a non-empty, NCName-like local name. Got "%s".',
                $label,
                $localName
            ));
        }

        return $localName;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function assertVersion(string $version): string
    {
        if (preg_match('#^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$#', $version) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'The DFC version must be a semver-shaped triple such as "2.0.0". Got "%s".',
                $version
            ));
        }

        return $version;
    }

    /**
     * Guard against a half-bumped descriptor.
     *
     * If `dfc_ontology_version` moves but the versioned ontology URLs do not,
     * either the descriptor was hand-edited or two pins were merged from
     * different releases. Either way every URI this extension emits would
     * advertise a version its own ontology documents contradict, so refuse.
     *
     * @param array<string, mixed> $descriptor
     *
     * @throws InvalidConfigurationException
     */
    private static function assertDescriptorVersionConsistency(array $descriptor, string $version): void
    {
        $ontology = $descriptor['ontology'] ?? null;
        if (!is_array($ontology)) {
            return;
        }

        foreach (['business_url', 'technical_url'] as $key) {
            $url = $ontology[$key] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (!str_contains($url, '/v' . $version . '/')) {
                throw new InvalidConfigurationException(sprintf(
                    'The DFC release descriptor is internally inconsistent: dfc_ontology_version is "%s" but '
                    . 'ontology.%s is "%s", which does not carry /v%s/. Refusing to publish identity documents '
                    . 'that advertise a version their own ontology URLs contradict.',
                    $version,
                    $key,
                    $url,
                    $version
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $descriptor
     *
     * @throws InvalidConfigurationException
     */
    private static function requireDescriptorString(array $descriptor, string $key): string
    {
        $value = $descriptor[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidConfigurationException(sprintf(
                'The DFC release descriptor is missing a non-empty string at "%s".',
                $key
            ));
        }

        return trim($value);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @throws InvalidConfigurationException
     */
    private static function requireOverride(array $overrides, string $key): string
    {
        $value = $overrides[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidConfigurationException(sprintf(
                'The override "%s" is required: the public platform base URI is a property of this deployment '
                . 'and cannot be derived from a DFC release descriptor. Supply it as the site base URL joined '
                . 'with the dfc_base_path setting (default "dfc/v2").',
                $key
            ));
        }

        return $value;
    }

    /**
     * Normalise a caller-supplied relative path so a leading slash or a missing
     * trailing slash cannot produce `https://site.example//webid`.
     *
     * @throws InvalidConfigurationException
     */
    private static function relativeTo(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidConfigurationException('A relative path override must not be empty.');
        }

        return ltrim((string) preg_replace('#/{2,}#', '/', $path), '/');
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function requireSingleSegmentBelow(string $uri, string $baseUri, string $label): string
    {
        if (!str_starts_with($uri, $baseUri)) {
            throw new InvalidConfigurationException(sprintf(
                'The %s ("%s") must sit below the platform base URI ("%s").',
                $label,
                $uri,
                $baseUri
            ));
        }

        $relative = substr($uri, strlen($baseUri));
        if ($relative === '' || str_contains($relative, '/')) {
            throw new InvalidConfigurationException(sprintf(
                'The %s ("%s") must be exactly one path segment below the platform base URI ("%s"). '
                . 'Consumers such as the WebID profile rely on that single-segment shape.',
                $label,
                $uri,
                $baseUri
            ));
        }

        return $relative;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function requireDirectoryBelow(string $uri, string $baseUri, string $label): string
    {
        if (!str_starts_with($uri, $baseUri)) {
            throw new InvalidConfigurationException(sprintf(
                'The %s ("%s") must sit below the platform base URI ("%s"). Identity minted outside the '
                . 'advertised base is undiscoverable from the platform WebID.',
                $label,
                $uri,
                $baseUri
            ));
        }

        $relative = substr($uri, strlen($baseUri));
        if ($relative === '' || !str_ends_with($relative, '/')) {
            throw new InvalidConfigurationException(sprintf(
                'The %s ("%s") must be a directory below the platform base URI ("%s"), i.e. end in "/".',
                $label,
                $uri,
                $baseUri
            ));
        }

        return $relative;
    }
}