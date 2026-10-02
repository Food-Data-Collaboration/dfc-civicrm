<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * Something attempted to mutate before every check had passed.
 *
 * The single most important exception in this namespace, because it is the one that
 * turns "validation runs first" from a convention into a control. See
 * {@see ValidationContext::assertMayMutate()}.
 *
 * @package Civi\Dfc
 */
final class MutationNotAuthorised extends \LogicException implements PipelineInvariantViolation
{
}