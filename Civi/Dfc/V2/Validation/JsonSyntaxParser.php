<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;

/**
 * A JSON parser that stops at JSON.
 *
 * ============================================================================
 * WHAT IT CHECKS, AND WHY EXACTLY THAT MUCH
 * ============================================================================
 *  1. The bytes decode as JSON, at a bounded depth. `JSON_THROW_ON_ERROR` so a
 *     failure is an exception rather than a `null` a caller forgets to test.
 *  2. The root is a JSON OBJECT. A JSON-LD document is a node map; a bare array or
 *     a scalar is not, and accepting one would mean the rest of the pipeline is
 *     walking a shape nothing else expects.
 *  3. `@context` is present and is either a string or an array. No context means not
 *     JSON-LD. This is the one semantic check, and it belongs here because PRD-002
 *     §4.2 makes the context the carrier of the DFC release — without it, a document
 *     cannot even say which version it is.
 *
 * NOT checked, on purpose: `@type`. A document with a context and no `@type` is
 * well-formed JSON-LD that does not describe a valid DFC resource, which is a 422
 * for the DFC_TYPE_SCHEMA stage (lane-3) — not a 400 for the parser.
 *
 * ============================================================================
 * WHY THE DEPTH IS BOUNDED
 * ============================================================================
 * `json_decode` takes a depth limit for a reason: a deeply nested body is a cheap
 * way to make a PHP process spend time and stack on one request, and
 * {@see \Civi\Dfc\V2\Controller\Serialisation\CanonicalJson} already refuses INF and
 * NAN for the same family of reasons. 64 is far beyond any DFC document — the
 * deepest real nesting is a Place inside an Address inside an Organization — and
 * small enough to bound.
 *
 * ============================================================================
 * NOT AN EXPANDER
 * ============================================================================
 * No `@context` resolution, no CURIE expansion, no blank-node labelling. See
 * {@see JsonLdParserInterface}: expansion is the connector's job (lane-3), and
 * PRD-002 §1 forbids a parallel implementation.
 *
 * @package Civi\Dfc
 */
final class JsonSyntaxParser implements JsonLdParserInterface
{
    private const MAX_DEPTH = 64;

    public function parse(string $body, DfcReleaseConfig $release): array
    {
        // The release is part of the contract but unused by a syntax-only parser.
        // Referenced explicitly so the parameter is not mistaken for an oversight:
        // the real parser needs it, and a deployment that swaps parsers must not
        // have to change the interface.
        unset($release);

        if (trim($body) === '') {
            throw new JsonLdParseException('The request body is empty.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalidJson) {
            throw new JsonLdParseException('The request body is not valid JSON.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new JsonLdParseException(
                'The request body is JSON but not a JSON object. A JSON-LD document is a node map.'
            );
        }

        /** @var array<string, mixed> $decoded */
        $this->requireContext($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function requireContext(array $document): void
    {
        if (!array_key_exists('@context', $document)) {
            throw new JsonLdParseException(
                'The document has no "@context", so it is not JSON-LD. PRD-002 requires the context, which is '
                . 'where the DFC release travels.'
            );
        }

        $context = $document['@context'];

        if (is_string($context)) {
            if (trim($context) === '') {
                throw new JsonLdParseException('The document\'s "@context" is an empty string.');
            }

            return;
        }

        // An OBJECT context is legal JSON-LD and is what an expanded or
        // prefix-defining producer emits. Refusing it would reject perfectly conformant
        // documents, which is an interoperability bug rather than a security control.
        if (is_array($context) && !array_is_list($context) && $context !== []) {
            return;
        }

        if (!is_array($context) || !array_is_list($context) || $context === []) {
            throw new JsonLdParseException(
                'The document\'s "@context" is neither a non-empty string, a non-empty array nor a '
                . 'non-empty object.'
            );
        }

        foreach ($context as $entry) {
            if ($entry === null) {
                // `[null]` explicitly discards the outer context. Legal JSON-LD, and
                // meaningful, so it passes.
                continue;
            }

            if (!is_string($entry) && !is_array($entry)) {
                throw new JsonLdParseException(
                    'An entry of the document\'s "@context" array is neither a string, an object nor null.'
                );
            }
        }
    }

    /**
     * The `@context` of a document, for a caller that wants it.
     *
     * @param array<string, mixed> $document
     */
    public static function contextOf(array $document): mixed
    {
        return $document['@context'] ?? null;
    }
}