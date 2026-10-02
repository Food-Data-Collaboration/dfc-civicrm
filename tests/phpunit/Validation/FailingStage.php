<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationResult;
use Civi\Dfc\V2\Validation\ValidationStage;
use Civi\Dfc\V2\Validation\ValidationStageInterface;

/**
 * A stage that always fails, with a caller-supplied error.
 */
final class FailingStage implements \Civi\Dfc\V2\Validation\ValidationStageInterface
{
    private readonly ValidationStage $stage;

    private readonly ProtocolError $error;

    public function __construct(ValidationStage $stage, ?ProtocolError $error = null)
    {
        $this->stage = $stage;
        $this->error = $error ?? ProtocolError::invalidHeader();
    }

    public function stage(): ValidationStage
    {
        return $this->stage;
    }

    public function run(ValidationContext $context): ValidationResult
    {
        return ValidationResult::failed($this->stage, $this->error);
    }
}
