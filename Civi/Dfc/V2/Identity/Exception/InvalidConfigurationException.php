<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity\Exception;

/**
 * The supplied DFC release configuration is unusable.
 *
 * Thrown for a missing/unreadable release descriptor, a version that is not a
 * DFC version triple, a base URI that is not an absolute http(s) URI, or a
 * derived URI that does not sit under its base.
 *
 * These are caught by nobody in normal operation: they mean the extension was
 * installed or configured wrongly, and they must surface loudly at bootstrap
 * rather than degrade into wrong URIs at runtime.
 *
 * @package Civi\Dfc
 */
final class InvalidConfigurationException extends \InvalidArgumentException implements DfcIdentityException
{
}