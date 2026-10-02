<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\ClockInterface;

/**
 * A clock the test moves by hand.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * Exists because {@see ClockInterface} is what makes `exp`/`nbf` and the JWKS TTL
 * testable without sleeping, and a test that sleeps is a test nobody runs.
 *
 * ============================================================================
 * WHY IT REFUSES TO GO BACKWARDS WITHOUT SAYING SO
 * ============================================================================
 * {@see \Civi\Dfc\V2\Security\JwksCache} has a refresh cooldown that is only
 * meaningful if time is monotonic. A clock that could be wound back would let a test
 * "prove" a suppression rule that cannot happen in production. `setTo()` therefore
 * refuses to move backwards, and {@see jumpBackwards()} exists as a separate, loudly
 * named method for the one test that genuinely needs it.
 *
 * @package Civi\Dfc
 */
final class TestClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $instant = JwtTestSupport::NOON_UTC)
    {
        $this->now = new \DateTimeImmutable($instant, new \DateTimeZone('UTC'));
    }

    public static function at(string $instant): self
    {
        return new self($instant);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Move forward. Refuses to move backwards; see the class docblock.
     */
    public function advance(int $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                'TestClock::advance() will not move backwards. Use jumpBackwards() if that is really the '
                . 'scenario under test.'
            );
        }

        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }

    public function advanceTo(string $instant): void
    {
        $candidate = new \DateTimeImmutable($instant, new \DateTimeZone('UTC'));

        if ($candidate->getTimestamp() < $this->now->getTimestamp()) {
            $this->jumpBackwards($candidate->getTimestamp() - $this->now->getTimestamp());
        }

        $this->now = $candidate;
    }

    /**
     * Move backwards, deliberately. Named so a grep finds every use.
     */
    public function jumpBackwards(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%d seconds', -abs($seconds)));
    }

    /** Seconds since the epoch, which is what `exp`/`nbf`/`iat` are compared against. */
    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }
}