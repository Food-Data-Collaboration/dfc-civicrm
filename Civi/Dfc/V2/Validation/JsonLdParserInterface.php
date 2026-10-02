<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;

/**
 * Turns request bytes into a decoded JSON-LD node map.
 *
 * ============================================================================
 * WHY THIS IS AN INTERFACE AND NOT A JSON-LD EXPANSION
 * ============================================================================
 * PRD-002 §1 explicitly rules out "inventing a parallel discovery protocol", and
 * expansion is the part of JSON-LD that must NOT be reimplemented: the DFC-LinkML
 * PHP connector (`DataFoodConsortium\Connector`) already does it against the
 * shipped `context_2.0.0.json`, and PRD-002 §"Upstream refresh" item 6 records that
 * the connector *does* ship the context and the bundled v2.0.0 SKOS vocabularies, so
 * construct/export/import work offline.
 *
 * So lane-3 supplies the real parser behind this interface and this lane ships
 * {@see JsonSyntaxParser}, which does SYNTAX and nothing else. Both are honest
 * implementations of the same contract; only one of them knows what a JSON-LD
 * context means.
 *
 * ============================================================================
 * THE SPLIT OF RESPONSIBILITIES, WHICH IS DELIBERATE
 * ============================================================================
 * This interface reports "the body is not a JSON-LD document" — the 400. It must NOT
 * report "the document is not a valid DFC resource" — that is the 422, it belongs to
 * the DFC_TYPE_SCHEMA and SHACL stages, and {@see ErrorCode} draws the line exactly
 * there:
 *
 *     400 means "the bytes are not JSON-LD"
 *     422 means "the JSON-LD is not a DFC resource"
 *
 * A parser that reports a missing `@type` as a parse failure collapses that line and
 * loses the distinction PRD-002 §4 calls load-bearing. {@see JsonSyntaxParser}
 * therefore checks only what makes a document JSON-LD at all: it decodes, and it
 * requires a root object and a `@context`.
 *
 * @package Civi\Dfc
 */
interface JsonLdParserInterface
{
    /**
     * @param string         $body    The raw request body.
     * @param DfcReleaseConfig $release The configured release, so the parser can
     *                                 resolve the context URL the deployment
     *                                 advertises rather than one written into the
     *                                 document.
     *
     * @return array<string, mixed> A JSON-LD node map: `@context` plus at least one
     *                               subject's properties.
     *
     * @throws JsonLdParseException when the bytes are not a parseable JSON-LD
     *                               document. The caller turns that into a 400.
     */
    public function parse(string $body, DfcReleaseConfig $release): array;
}