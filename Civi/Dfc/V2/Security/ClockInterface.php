<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * Time, injected.
 *
 * ============================================================================
 * WHY THE OIDC LAYER NEEDS AN INJECTABLE CLOCK
 * ============================================================================
 * Three things in this namespace are only testable if the caller can decide what
 * "now" is:
 *
 *   1. `exp` / `nbf` evaluation. Testing an *expired* token otherwise means
 *      minting one with `exp` in the past and then asserting the maths is right
 *      — which is a test of the fixture, not of the validator. With an injected
 *      clock the test says "it is 12:00:00 and this token expires at 11:59:00",
 *      which is the assertion a reader can check by eye.
 *   2. Clock skew. The skew allowance is a *duration*, and "within skew" is a
 *      comparison against now. Sleeping for 30 seconds in a test suite is not a
 *      test design, it is a bill.
 *   3. JWKS cache TTL. Same argument: a cache whose freshness window is an hour
 *      cannot be tested for refresh behaviour without an hour.
 *
 * ============================================================================
 * WHY UTC IS MANDATED RATHER THAN DEFAULTED
 * ============================================================================
 * The JWT `NumericDate` claims are seconds since the UNIX epoch, so they carry no
 * zone; the comparison only works if "now" is the same instant the issuer meant.
 * An implementation returning a local-time `DateTimeImmutable` and comparing
 * formatted strings would be off by the offset in every deployment outside UTC.
 * Implementations MUST return a UTC instant. {@see SystemClock} does; a test
 * double must too.
 *
 * @package Civi\Dfc
 */
interface ClockInterface
{
    /**
     * The current instant, in UTC.
     *
     * Implementations MUST NOT return a `DateTimeImmutable` carrying a non-UTC
     * zone: the instant is what callers compare against `exp`/`nbf`, and the
     * zone is a source of off-by-hours bugs that only appear in some
     * deployments.
     */
    public function now(): \DateTimeImmutable;
}