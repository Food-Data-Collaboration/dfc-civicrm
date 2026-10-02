<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * A stage needed a decoded document and there was none.
 *
 * @package Civi\Dfc
 */
final class NoDocumentToValidate extends \LogicException implements PipelineInvariantViolation
{
}