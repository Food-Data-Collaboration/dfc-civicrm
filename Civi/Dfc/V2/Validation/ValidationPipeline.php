<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * Runs the stages in {@see ValidationStage} order and stops at the first failure.
 *
 * ============================================================================
 * FAIL-CLOSED, IN TWO SEPARATE SENSES
 * ============================================================================
 *
 * 1. **A missing required stage fails the request.** `requiredStages` lists the
 *    stages that MUST be present. If one has no implementation registered,
 *    {@see run()} returns a 500 rather than running the rest and reporting success.
 *    The alternative — proceeding with whatever is wired up — is how a deployment
 *    that forgot to register the SHACL stage ends up accepting documents nobody
 *    checked, with every log line saying validation passed.
 *
 *    This is a real configuration hazard, not a hypothetical one: the stages are
 *    spread across lanes (see {@see ValidationStage}'s ownership table), so a
 *    partially-wired pipeline is the expected intermediate state, not an edge case.
 *
 * 2. **A stage that fails stops the pipeline.** There is no "collect all failures"
 *    mode, and there cannot be, because stage N+1's checks are not meaningful against
 *    a document stage N rejected. A stage that throws for a genuine server fault
 *    propagates and becomes a 500 via
 *    {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper}.
 *
 * ============================================================================
 * WHY THE ORDER IS SORTED, NOT INHERITED FROM THE CONSTRUCTOR ARGUMENT
 * ============================================================================
 * See {@see ValidationStage}. A stage added in the wrong array position would
 * otherwise run in the wrong order, and the bug would present as a validation gap
 * rather than as an ordering mistake. Sorting by the declared ordinal makes
 * registration order irrelevant; registering the same ordinal twice is a
 * construction error.
 *
 * ============================================================================
 * WHAT `run()` RETURNS ON SUCCESS
 * ============================================================================
 * The same {@see ValidationResult}, marked as belonging to the LAST stage that ran.
 * The caller also gets back the {@see ValidationContext} it should use, marked
 * validated — via {@see ValidationRun}. Returning a bare result would mean either
 * losing the derived context (so a caller would have to re-derive it and lose the
 * guarantee) or threading it out by reference (so a caller could forget).
 *
 * ============================================================================
 * MUTATION IS NOT RUN HERE
 * ============================================================================
 * {@see ValidationStage::MUTATION} is excluded from the run, always. A
 * {@see ValidationStageInterface} that claims to BE that stage is refused at
 * construction: the pipeline validates, and the writer that follows takes a
 * {@see ValidationContext} whose `assertMayMutate()` passes.
 *
 * @package Civi\Dfc
 */
final class ValidationPipeline
{
    /** @var list<ValidationStageInterface> */
    private readonly array $stages;

    /** @var list<ValidationStage> */
    private readonly array $requiredStages;

    /**
     * @param list<ValidationStageInterface> $stages
     * @param list<ValidationStage>           $requiredStages Stages that must be
     *                                                        registered for
     *                                                        {@see run()} to
     *                                                        succeed.
     *
     * @throws \InvalidArgumentException when two stages claim the same ordinal, or a
     *         stage claims to be MUTATION.
     */
    public function __construct(array $stages, array $requiredStages = [])
    {
        $byStage = [];

        foreach ($stages as $stage) {
            $ordinal = $stage->stage();

            if ($ordinal->isMutating()) {
                throw new \InvalidArgumentException(sprintf(
                    'The MUTATION stage cannot be registered in a validation pipeline. Mutation is what '
                    . 'validation exists to gate; run the pipeline, then write through a context whose '
                    . 'assertMayMutate() passes.'
                ));
            }

            if (isset($byStage[$ordinal->value])) {
                throw new \InvalidArgumentException(sprintf(
                    'Two stages both claim "%s". The pipeline runs stages in the order ValidationStage '
                    . 'declares, so an ambiguous ordinal has no defined answer and is refused rather than '
                    . 'resolved arbitrarily.',
                    $ordinal->value
                ));
            }

            $byStage[$ordinal->value] = $stage;
        }

        // Ordinal order, by declaration order in the enum.
        $ordered = [];
        foreach (ValidationStage::preMutationStages() as $ordinal) {
            if (isset($byStage[$ordinal->value])) {
                $ordered[] = $byStage[$ordinal->value];
            }
        }

        $this->stages = $ordered;
        $this->requiredStages = array_values($requiredStages);
    }

    /**
     * The stages that will run, in order.
     *
     * @return list<ValidationStage>
     */
    public function stages(): array
    {
        return array_map(
            static fn (ValidationStageInterface $stage): ValidationStage => $stage->stage(),
            $this->stages
        );
    }

    /**
     * The required stages that are NOT registered.
     *
     * Public so a deployment's self-test can assert the wiring before serving a
     * single request, rather than discovering the gap from a 500.
     *
     * @return list<ValidationStage>
     */
    public function missingRequiredStages(): array
    {
        $present = array_map(
            static fn (ValidationStage $stage): string => $stage->value,
            $this->stages()
        );

        $missing = [];
        foreach ($this->requiredStages as $required) {
            if (!in_array($required->value, $present, true)) {
                $missing[] = $required;
            }
        }

        return $missing;
    }

    /**
     * Run every registered stage, in order, stopping at the first failure.
     */
    public function run(ValidationContext $context): ValidationRun
    {
        $missing = $this->missingRequiredStages();
        if ($missing !== []) {
            // Fail closed: a pipeline that is missing a stage must not report
            // success. 500, because the client cannot fix this by editing its
            // request.
            return ValidationRun::rejected(
                $context,
                ValidationResult::failed(
                    ValidationStage::AUTHENTICATION,
                    ProtocolError::internalError()
                )
            );
        }

        $lastPassed = null;
        $current = $context;

        foreach ($this->stages as $stage) {
            $result = $stage->run($current);

            if ($result->isFailed()) {
                return ValidationRun::rejected($current, $result);
            }

            $current = $result->nextContext() ?? $current;
            $lastPassed = $result;
        }

        if ($lastPassed === null) {
            // No stages registered at all. With no requiredStages that is a legal
            // (if useless) configuration for a purely public surface; with them it
            // was already rejected above.
            $lastPassed = ValidationResult::passed(ValidationStage::AUTHENTICATION);
        }

        return ValidationRun::accepted($current->markValidated(), $lastPassed);
    }
}