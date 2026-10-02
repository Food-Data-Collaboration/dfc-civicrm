<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationResult;
use Civi\Dfc\V2\Validation\ValidationStage;
use Civi\Dfc\V2\Validation\ValidationStageInterface;

/**
 * A stage that records the graph it was handed.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * Used to assert that the parse stage's output is THREADED forward rather than
 * re-derived, which is the property that stops a document being parsed N times and lets
 * every later stage see the same graph.
 */
final class GraphRecordingStage implements ValidationStageInterface
{
    private readonly \stdClass $recorder;

    public function __construct(\stdClass $recorder)
    {
        $this->recorder = $recorder;
    }

    public function stage(): ValidationStage
    {
        return ValidationStage::SHACL;
    }

    public function run(ValidationContext $context): ValidationResult
    {
        $this->recorder->graph = $context->graph();

        return ValidationResult::passed($this->stage());
    }
}
