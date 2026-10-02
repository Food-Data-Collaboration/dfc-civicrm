<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

use Civi\Dfc\V2\Controller\Error\ProtocolError;

/**
 * The outcome of one stage: passed, or a classified protocol error.
 *
 * ============================================================================
 * WHY THE ERROR IS CARRIED RATHER THAN PRODUCED LATER
 * ============================================================================
 * Because the stage is the only thing that knows WHAT went wrong, and
 * {@see \Civi\Dfc\V2\Controller\Error\ProtocolError} is immutable. A pipeline that
 * collected "something failed" and then tried to reconstruct the error would have to
 * re-derive the code, the diagnostics and the challenge — i.e. exactly the
 * per-controller decision PRD-002 §9 warns about, moved one layer up.
 *
 * ============================================================================
 * THE TWO FACTORIES ARE THE ONLY CONSTRUCTORS
 * ============================================================================
 * `new ValidationResult(...)` is private, so there is no way to build one that both
 * passes and carries an error, or one that fails with no error for
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorResponder} to render. A stage that tries
 * to is stopped by the type system rather than by a review comment.
 *
 * ============================================================================
 * WHY A RESULT ALSO CARRIES THE NEXT CONTEXT
 * ============================================================================
 * Because stages are pure functions of the context and one of them TRANSFORMS it:
 * the parse stage produces the decoded document, and every later stage needs that
 * document. An immutable context plus a result that carries no context would force
 * one of three bad shapes — a mutable context, a stage that hands its output back
 * out of band, or the caller re-running the parse for every stage that wanted it.
 *
 * So a stage that derives a context passes it with its result, and
 * {@see ValidationPipeline} threads it into the next stage. The pipeline is the only
 * place the chain is assembled, so the order in which transformations apply is the
 * stage order and nothing else.
 *
 * @package Civi\Dfc
 */
final class ValidationResult
{
    private readonly ValidationStage $stage;

    private readonly bool $passed;

    private readonly ?ProtocolError $error;

    private readonly ?ValidationContext $nextContext;

    private function __construct(
        ValidationStage $stage,
        bool $passed,
        ?ProtocolError $error,
        ?ValidationContext $nextContext
    ) {
        $this->stage = $stage;
        $this->passed = $passed;
        $this->error = $error;
        $this->nextContext = $nextContext;
    }

    /**
     * The stage passed.
     *
     * @param ValidationContext|null $nextContext The context later stages should see,
     *                                           when this stage transformed it.
     */
    public static function passed(ValidationStage $stage, ?ValidationContext $nextContext = null): self
    {
        return new self($stage, true, null, $nextContext);
    }

    /**
     * @param ProtocolError $error Must be non-null. A failure with no error cannot be
     *                              rendered, and
     *                              {@see \Civi\Dfc\V2\Controller\Error\ErrorResponder}
     *                              must never be asked to guess one.
     */
    public static function failed(ValidationStage $stage, ProtocolError $error): self
    {
        return new self($stage, false, $error, null);
    }

    /**
     * The context later stages should see, or null when this stage did not transform
     * the context.
     */
    public function nextContext(): ?ValidationContext
    {
        return $this->nextContext;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isFailed(): bool
    {
        return !$this->passed;
    }

    /** Which stage produced this result. */
    public function stage(): ValidationStage
    {
        return $this->stage;
    }

    /**
     * The error to render.
     *
     * @throws \LogicException when the result passed. The invariant is enforced by
     *         the private constructor rather than by an assertion, so it cannot be
     *         violated by a caller who forgets to check.
     */
    public function error(): ProtocolError
    {
        if ($this->error === null) {
            throw new \LogicException(sprintf(
                'The %s stage passed, so it has no error. Calling error() on a passing result is how a '
                . 'pipeline starts rendering a 200 as if it were a 422.',
                $this->stage->value
            ));
        }

        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }
}