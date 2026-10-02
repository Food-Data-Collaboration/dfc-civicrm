<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * The SHACL stage: load the shapes, validate the document, fail closed.
 *
 * ============================================================================
 * THE FAIL-CLOSED ARGUMENT, IN FULL
 * ============================================================================
 * A SHACL gate has exactly one catastrophic failure mode, and it is not a bad
 * message — it is a PASS. Four ways this stage could pass a document nobody checked,
 * and each one is closed by construction:
 *
 *  1. **The repository cannot produce a graph.** -> {@see ShaclShapeUnavailableException}
 *     from the repository, which this stage does NOT catch into a pass. It becomes a
 *     500.
 *  2. **The shape document does not parse.** -> {@see ShaclShapeUnavailableException}
 *     from {@see TurtleShapeParser}, same outcome. This covers the subset parser
 *     refusing a `sh:or` it cannot evaluate, which is the whole reason a subset
 *     parser is defensible.
 *  3. **The parsed set contains no node shapes.** -> an empty set, which
 *     {@see LocalShaclValidator} refuses rather than validating against nothing.
 *  4. **The repository reports no graph names at all.** -> an empty loop. Checked
 *     explicitly below, because it is the one case where "loop over nothing, report
 *     nothing" would look exactly like success.
 *
 * In every case the request FAILS. None of them can produce a 2xx that was decided
 * by an absence.
 *
 * ============================================================================
 * WHY IT IS A 500 AND NOT A 422
 * ============================================================================
 * "I could not read the shapes" says nothing about the submitted document. It is a
 * deployment fault — a missing file in the release archive, a packaging step that
 * did not run — and the client cannot fix it by editing its request. Mapping it to
 * 422 would tell a DFC client its perfectly good document is invalid, which is both
 * false and the fastest way to lose a federation partner's trust.
 *
 * 503 would be the more precise status for a service this server depends on, but
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorCode}'s docblock assigns 503 to sa-014's
 * limits and audit work, so 500 `internal_error` is what this surface can express.
 * Recorded rather than hidden.
 *
 * ============================================================================
 * A BODY-LESS REQUEST SKIPS THE STAGE
 * ============================================================================
 * There is nothing to validate. The OUTBOUND direction — validating a stored
 * representation before it is served — belongs to the export path (lane-3/4) and is
 * a different stage, because it runs against a graph this layer does not build.
 *
 * @package Civi\Dfc
 */
final class ShaclValidationStage implements ValidationStageInterface
{
    private readonly ShaclShapeRepositoryInterface $shapes;

    private readonly ShaclValidatorInterface $validator;

    private readonly TurtleShapeParser $parser;

    public function __construct(
        ShaclShapeRepositoryInterface $shapes,
        ShaclValidatorInterface $validator,
        ?TurtleShapeParser $parser = null
    ) {
        $this->shapes = $shapes;
        $this->validator = $validator;
        $this->parser = $parser ?? new TurtleShapeParser();
    }

    public function stage(): ValidationStage
    {
        return ValidationStage::SHACL;
    }

    public function run(ValidationContext $context): ValidationResult
    {
        if (!$context->requiresGraph()) {
            return ValidationResult::passed($this->stage());
        }

        // ============================================================================
        // THE SHAPES ARE LOADED BEFORE THE DOCUMENT IS ASKED FOR, ON PURPOSE
        // ============================================================================
        // A missing document is a WIRING error (no parse stage ran) and a missing shape
        // file is a DEPLOYMENT error. Loading shapes first means the deployment error is
        // what gets reported, because it is the one that affects every request on the
        // interface; a wiring error in one route then surfaces as its own message rather
        // than being masked by an unrelated outage.
        $shapeSet = $this->loadShapes();

        $violations = $this->validator->validate($shapeSet, $context->requireGraph());

        if ($violations === []) {
            return ValidationResult::passed($this->stage());
        }

        return ValidationResult::failed(
            $this->stage(),
            ProtocolError::unprocessable($violations, null, null)
        );
    }

    /**
     * Every configured graph, parsed and merged, or a refusal.
     *
     * All four fail-closed cases live here, in one method, so that there is no
     * configuration of this stage in which it returns a shape set that validates
     * nothing:
     *
     *   1. the repository cannot produce a graph -> its exception propagates;
     *   2. a shape document does not parse         -> the parser's exception propagates;
     *   3. the repository advertises no graphs      -> refused here, because "loop over
     *      nothing, report nothing" is indistinguishable from success;
     *   4. the documents parsed but held no shapes  -> refused here.
     *
     * None is caught and converted into a pass.
     *
     * @throws ShaclShapeUnavailableException
     */
    private function loadShapes(): ShaclShapeSet
    {
        $graphs = $this->shapes->graphs();

        if ($graphs === []) {
            throw new ShaclShapeUnavailableException(
                'The SHACL shape repository exposes no graphs, so no shape could be loaded and no document '
                . 'could be validated. This is refused rather than reported as a valid document.'
            );
        }

        $parsed = [];

        foreach ($graphs as $graphName) {
            // No try/catch: cases 1 and 2 propagate and become a 500 via ErrorMapper.
            $parsed[] = $this->parser->parse($this->shapes->turtle($graphName));
        }

        $shapeSet = ShaclShapeSet::merge($parsed);

        if ($shapeSet->isEmpty()) {
            throw new ShaclShapeUnavailableException(
                'The SHACL shape documents were loaded but contained no node shapes, so there was nothing to '
                . 'validate against. Refused rather than treated as a pass.'
            );
        }

        return $shapeSet;
    }
}