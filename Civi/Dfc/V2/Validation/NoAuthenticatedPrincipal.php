<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * A stage needed an authenticated identity and there was none.
 *
 * @package Civi\Dfc
 */
final class NoAuthenticatedPrincipal extends \LogicException implements PipelineInvariantViolation
{
}