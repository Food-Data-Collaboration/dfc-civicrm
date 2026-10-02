<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * The body is not a parseable JSON-LD document. 400, always.
 *
 * Deliberately a distinct type from every 422-producing type, so
 * {@see \Civi\Dfc\V2\Validation\JsonLdParseStage} cannot confuse the two statuses
 * and {@see \Civi\Dfc\V2\Controller\Error\ErrorCode}'s 400/422 distinction survives
 * as far as the type system.
 *
 * The message never includes any part of the body: a submitted document may contain
 * personal data, and a parse error that quotes the offending bytes writes it to a
 * log. `json_last_error_msg()` is likewise not used — it is free text from a C
 * library.
 *
 * @package Civi\Dfc
 */
final class JsonLdParseException extends \RuntimeException
{
}