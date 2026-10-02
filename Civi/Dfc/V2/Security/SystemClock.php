<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * The wall clock, for production use.
 *
 * Exists only so that {@see ClockInterface} has a production implementation and
 * so that the set is closed: a caller cannot be handed "a clock" and have to
 * decide whether to build one. {@see ClockInterface} stays an interface for
 * exactly one reason — the tests.
 *
 * @package Civi\Dfc
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        // The explicit zone is the point. `new \DateTimeImmutable('now')` picks
        // date_default_timezone_get(), which is a per-deployment setting: the
        // behaviour of the OIDC layer would then depend on a php.ini line.
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}