<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * The shapes this deployment needs could not be loaded, so validation cannot run.
 *
 * ============================================================================
 * WHY THIS IS A SERVER FAULT AND NOT A 422
 * ============================================================================
 * The failure "I could not read the SHACL shapes" says nothing about the submitted
 * document. It is a deployment fault — an extension release missing a file, a
 * packaging step that did not run, a read error — and the client cannot fix it by
 * changing its request.
 *
 * So it is a {@see ShaclValidationException} and {@see ShaclValidationStage} turns
 * it into a 500. What it must NEVER become is a pass: "shapes unavailable, so
 * everything is valid" is the fail-OPEN failure, and it is the one that matters
 * here. See {@see ShaclValidationStage}'s docblock.
 *
 * @package Civi\Dfc
 */
final class ShaclShapeUnavailableException extends \RuntimeException
{
}