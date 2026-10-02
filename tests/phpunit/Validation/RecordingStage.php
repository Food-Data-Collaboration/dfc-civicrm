<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationResult;
use Civi\Dfc\V2\Validation\ValidationStage;
use Civi\Dfc\V2\Validation\ValidationStageInterface;

/**
 * A stage that passes and records that it ran.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 */
final class RecordingStage implements \Civi\Dfc\V2\Validation\ValidationStageInterface
{
    private readonly ValidationStage $stage;

    private readonly ?\stdClass $recorder;

    public function __construct(ValidationStage $stage, ?\stdClass $recorder = null)
    {
        $this->stage = $stage;
        $this->recorder = $recorder;
    }

    public function stage(): ValidationStage
    {
        return $this->stage;
    }

    public function run(ValidationContext $context): ValidationResult
    {
        if ($this->recorder !== null) {
            $this->recorder->ran[] = $this->stage;
        }

        return ValidationResult::passed($this->stage);
    }
}
