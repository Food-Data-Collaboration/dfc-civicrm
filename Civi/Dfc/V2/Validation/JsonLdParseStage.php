<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * The JSON_LD_PARSE stage.
 *
 * ============================================================================
 * WHY THIS STAGE IS WORTH HAVING WHEN THE PARSER ONLY CHECKS JSON SYNTAX
 * ============================================================================
 * Because the stage is where the 400/422 LINE is drawn, and it has to be drawn by
 * somebody. {@see JsonSyntaxParser} decides that a body with no `@context` is not
 * JSON-LD; this stage decides what that means to a client, which is
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorCode::MALFORMED_JSON_LD} and therefore a
 * 400. Without the stage, whoever calls the parser picks the status, and they pick
 * it wrong about half the time.
 *
 * It also owns the bodyless case: a mutating request with no body is a 400 here. A
 * protocol fact belongs to a stage, not to whichever route happened to notice it.
 *
 * ============================================================================
 * A BODY-LESS READ IS NOT A FAILURE
 * ============================================================================
 * {@see ValidationContext::read()} sets `requiresGraph()` false and
 * {@see ValidationContext::write()} sets it true. This stage passes a read without
 * looking at a body and fails a write that has none. Document-shaped LATER stages
 * ask {@see ValidationContext::requiresGraph()} the same way, so "no document" is one
 * consistent concept across the pipeline rather than a special case in each.
 *
 * ============================================================================
 * THE PARSER IS INJECTED, SO A PARSER FAULT IS VISIBLE AS A FAULT
 * ============================================================================
 * A {@see JsonLdParseException} is a 400 — the client sent something unreadable. Any
 * OTHER throwable from the parser is a server fault and propagates to
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper}, which turns it into a 500. The
 * distinction matters: the real parser (lane-3's, over the connector) will throw from
 * its own internals, and those must not reach the client as "your document is
 * malformed".
 *
 * ============================================================================
 * THE PARSED GRAPH IS HANDED FORWARD, NOT RE-PARSED
 * ============================================================================
 * {@see ValidationResult::passed()} carries the derived context, and
 * {@see ValidationPipeline} threads it. So the body is parsed exactly once no matter
 * how many later stages need it, and a stage cannot see a document that differs from
 * the one the parse stage produced.
 *
 * @package Civi\Dfc
 */
final class JsonLdParseStage implements ValidationStageInterface
{
    private readonly JsonLdParserInterface $parser;

    public function __construct(JsonLdParserInterface $parser)
    {
        $this->parser = $parser;
    }

    public function stage(): ValidationStage
    {
        return ValidationStage::JSON_LD_PARSE;
    }

    public function run(ValidationContext $context): ValidationResult
    {
        if (!$context->requiresGraph()) {
            return ValidationResult::passed($this->stage());
        }

        $body = $context->rawBody();
        if ($body === null || trim($body) === '') {
            return ValidationResult::failed($this->stage(), ProtocolError::malformedJsonLd());
        }

        try {
            $graph = $this->parser->parse($body, $context->release());
        } catch (JsonLdParseException $notJsonLd) {
            return ValidationResult::failed($this->stage(), ProtocolError::malformedJsonLd());
        }

        return ValidationResult::passed($this->stage(), $context->withGraph($graph));
    }
}