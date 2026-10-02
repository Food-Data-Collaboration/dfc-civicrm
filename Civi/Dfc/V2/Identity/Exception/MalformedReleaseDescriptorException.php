<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity\Exception;

/**
 * The DFC release descriptor could not be read.
 *
 * The reader in {@see \Civi\Dfc\V2\Identity\ReleaseDescriptorParser} supports a
 * deliberately small subset of YAML and rejects everything else rather than
 * guessing. Every message it throws names the line, because a release
 * descriptor that upstream is free to change shape is exactly the file where a
 * silent misparse would do the most damage.
 *
 * @package Civi\Dfc
 */
final class MalformedReleaseDescriptorException extends \InvalidArgumentException implements DfcIdentityException
{
}