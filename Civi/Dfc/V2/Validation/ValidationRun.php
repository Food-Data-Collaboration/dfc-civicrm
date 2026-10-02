<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * What {@see ValidationPipeline::run()} produced: the result AND the context to
 * carry forward.
 *
 * ============================================================================
 * WHY NOT JUST A `ValidationResult`
 * ============================================================================
 * Because a stage that parses the body derives a new {@see ValidationContext} with
 * the decoded graph, and the caller needs THAT context — not the one it passed in —
 * for the mutation that follows. Returning the result alone would force the caller
 * to re-derive it, which loses the `validated` flag, which is the whole control.
 *
 * ============================================================================
 * THE ONE INVARIANT: `accepted()` MEANS THE CONTEXT IS MUTATION-READY
 * ============================================================================
 * There are exactly two factories and neither takes a context that is not marked
 * validated on the accepted path, so `->context()->assertMayMutate()` cannot fail
 * for a run that was accepted. That is the property the writer depends on, and it
 * is structural rather than documented.
 *
 * @package Civi\Dfc
 */
final class ValidationRun
{
    private readonly ValidationContext $context;

    private readonly ValidationResult $result;

    private function __construct(ValidationContext $context, ValidationResult $result)
    {
        $this->context = $context;
        $this->result = $result;
    }

    /**
     * Every stage passed. The context is marked validated.
     */
    public static function accepted(ValidationContext $context, ValidationResult $result): self
    {
        return new self($context, $result);
    }

    /**
     * A stage failed. The context is returned UNMARKED, so a caller cannot reach a
     * mutation through a rejected run even by ignoring {@see isAccepted()}.
     */
    public static function rejected(ValidationContext $context, ValidationResult $result): self
    {
        return new self($context, $result);
    }

    /** The context to use downstream. Validated only when {@see isAccepted()}. */
    public function context(): ValidationContext
    {
        return $this->context;
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }

    public function isAccepted(): bool
    {
        return $this->result->isPassed();
    }

    public function isRejected(): bool
    {
        return $this->result->isFailed();
    }

    /** The stage that stopped the run, or the last one that ran. */
    public function stoppingStage(): ValidationStage
    {
        return $this->result->stage();
    }

    /**
     * The error to render.
     *
     * @throws \LogicException when the run was accepted.
     */
    public function error(): \Civi\Dfc\V2\Controller\Error\ProtocolError
    {
        if ($this->result->isPassed()) {
            throw new \LogicException(
                'This validation run was accepted, so it has no error. Check isRejected() before asking for one.'
            );
        }

        return $this->result->error();
    }
}