<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * One check in the request pipeline.
 *
 * ============================================================================
 * WHY A STAGE RETURNS A RESULT RATHER THAN THROWING
 * ============================================================================
 * A validation failure is a PROTOCOL OUTCOME, not an exception:
 * {@see \Civi\Dfc\V2\Controller\Error\ProtocolError} is a value object precisely so
 * that "this request was rejected with 422 and these three violations" can be
 * carried without an exception being in flight. A stage therefore RETURNS
 * {@see ValidationResult::failure()} carrying that error, and
 * {@see ValidationPipeline} returns it up.
 *
 * Throwing is reserved for the two things that really are exceptional: a programming
 * error (a stage that both passes and fails), and a genuine server fault. Those
 * propagate and {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper} turns them into 500.
 *
 * ============================================================================
 * WHAT A STAGE MUST NOT DO
 * ============================================================================
 *  - **Decide a status.** It picks a {@see ValidationResult}, and the code in that
 *    result comes from {@see \Civi\Dfc\V2\Controller\Error\ErrorCode}. See that
 *    enum's docblock: it is the only status-code policy on this surface.
 *  - **Mutate anything.** No stage writes. MUTATION is not a stage anyone
 *    implements inside the pipeline.
 *  - **Depend on CiviCRM.** These are framework-agnostic abstractions and the unit
 *    suite runs with no CMS bootstrap.
 *
 * @package Civi\Dfc
 */
interface ValidationStageInterface
{
    /**
     * Which ordinal this stage occupies. See {@see ValidationStage}.
     */
    public function stage(): ValidationStage;

    /**
     * Run the check.
     *
     * @param ValidationContext $context Immutable; use the `with*()` methods to
     *                                  derive the context for the next stage.
     *
     * @return ValidationResult Pass or fail, carrying the protocol error on failure.
     */
    public function run(ValidationContext $context): ValidationResult;
}