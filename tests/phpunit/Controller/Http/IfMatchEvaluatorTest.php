<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Http;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Http\ETag;
use Civi\Dfc\V2\Controller\Http\IfMatchEvaluator;
use Civi\Dfc\V2\Controller\Http\IfMatchOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `If-Match` evaluation: match, mismatch, absent — and the two edge cases that
 * are easy to fold into "mismatch" and lose information a client needs.
 */
#[CoversClass(IfMatchEvaluator::class)]
#[CoversClass(IfMatchOutcome::class)]
final class IfMatchEvaluatorTest extends TestCase
{
    use ControllerFixtureTrait;

    private const CURRENT = '{"@id":"https://platform.example/dfc/v2/x/"}';

    private function current(): ETag
    {
        return ETag::strong(self::CURRENT);
    }

    // -- The three mandated outcomes ------------------------------------------

    public function testAMatchingStrongTagPermitsTheWrite(): void
    {
        $outcome = self::ifMatch()->evaluate($this->current()->value(), $this->current());

        self::assertSame(IfMatchOutcome::MATCH, $outcome);
        self::assertTrue($outcome->allowsWrite());
        self::assertTrue($outcome->wasConditional());
        self::assertTrue($outcome->isSatisfied());
    }

    public function testAMismatchingTagForbidsTheWrite(): void
    {
        $outcome = self::ifMatch()->evaluate(ETag::strong('something else')->value(), $this->current());

        self::assertSame(IfMatchOutcome::MISMATCH, $outcome);
        self::assertFalse($outcome->allowsWrite());
        self::assertTrue($outcome->wasConditional());
        self::assertFalse($outcome->isSatisfied());
    }

    /**
     * The hazard, made observable.
     *
     * An absent `If-Match` permits the write — that is what RFC 9110 requires and
     * what every generic HTTP client assumes — but it is a DISTINCT outcome from a
     * match, so a caller can always tell afterwards that the write was
     * unconditioned. That is what "say so in the API" means here.
     */
    public function testAnAbsentIfMatchPermitsTheWriteAndSaysItWasUnconditional(): void
    {
        $outcome = self::ifMatch()->evaluate(null, $this->current());

        self::assertSame(IfMatchOutcome::UNCONDITIONAL, $outcome);
        self::assertTrue($outcome->allowsWrite());
        self::assertFalse(
            $outcome->wasConditional(),
            'UNCONDITIONAL and MATCH must be distinguishable, or the lost-update hazard is invisible.'
        );
        self::assertFalse($outcome->isSatisfied());
    }

    public function testAnEmptyIfMatchIsTreatedAsAbsent(): void
    {
        // A proxy that emits an empty header has asked for nothing. Answering 400
        // would break it; answering as a match would be a lie.
        self::assertSame(
            IfMatchOutcome::UNCONDITIONAL,
            self::ifMatch()->evaluate('   ', $this->current())
        );
    }

    // -- The precondition-required policy -------------------------------------

    public function testADeploymentMayRequireAPreconditionAndThenAbsenceIs412(): void
    {
        $outcome = self::ifMatch(true)->evaluate(null, $this->current());

        self::assertSame(IfMatchOutcome::MISMATCH, $outcome);
        self::assertFalse($outcome->allowsWrite());
    }

    public function testRequiringAPreconditionDoesNotChangeTheMatchCase(): void
    {
        self::assertSame(
            IfMatchOutcome::MATCH,
            self::ifMatch(true)->evaluate($this->current()->value(), $this->current())
        );
    }

    // -- The star -------------------------------------------------------------

    public function testAStarMatchesAnyCurrentRepresentation(): void
    {
        $outcome = self::ifMatch()->evaluate('*', $this->current());

        self::assertSame(IfMatchOutcome::MATCH, $outcome);
        self::assertTrue($outcome->allowsWrite());
    }

    public function testAStarFailsWhenThereIsNoCurrentRepresentation(): void
    {
        // RFC 9110 §13.1.1: "*" fails when no current representation exists. Kept as
        // its own outcome rather than folded into MISMATCH because a client that
        // tried to create-then-conditionally-write deserves to know the difference.
        $outcome = self::ifMatch()->evaluate('*', null);

        self::assertSame(IfMatchOutcome::NO_REPRESENTATION, $outcome);
        self::assertFalse($outcome->allowsWrite());
    }

    public function testAConcreteTagWithNoCurrentRepresentationFails(): void
    {
        self::assertSame(
            IfMatchOutcome::NO_REPRESENTATION,
            self::ifMatch()->evaluate($this->current()->value(), null)
        );
    }

    // -- Lists and weakness ---------------------------------------------------

    public function testOneMatchInAListIsEnough(): void
    {
        $outcome = self::ifMatch()->evaluate(
            ETag::strong('old')->value() . ', ' . $this->current()->value(),
            $this->current()
        );

        self::assertSame(IfMatchOutcome::MATCH, $outcome);
    }

    public function testAWeakTagNeverSatisfiesIfMatch(): void
    {
        // RFC 9110 §8.8.3.2 strong comparison: a weak tag explicitly declines to
        // promise byte equality, which is the question If-Match asks.
        $outcome = self::ifMatch()->evaluate(ETag::weak(self::CURRENT)->value(), $this->current());

        self::assertSame(IfMatchOutcome::MISMATCH, $outcome);
    }

    public function testAWeakCurrentTagIsMatchedOnlyByAnotherWeakTagAndOnlyWithEquals(): void
    {
        $weak = ETag::weak('projection-3');

        self::assertSame(
            IfMatchOutcome::MISMATCH,
            self::ifMatch()->evaluate(ETag::weak('projection-3')->value(), $weak),
            'If-Match is a strong comparison, so two weak tags do not satisfy it.'
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'an unquoted tag' => ['sha256-abc'];
        yield 'an unterminated tag' => ['"sha256-abc'];
        yield 'only separators' => [',,,'];
    }

    #[DataProvider('malformedProvider')]
    public function testAMalformedIfMatchIsReportedRatherThanIgnored(string $header): void
    {
        // Ignoring it would silently convert a precondition into no precondition —
        // the precondition is the only protection the client had.
        self::assertSame(
            IfMatchOutcome::MALFORMED,
            self::ifMatch()->evaluate($header, $this->current())
        );
    }

    // -- The throwing form --------------------------------------------------

    public function testGuardReturnsTheOutcomeForAPermittedWrite(): void
    {
        self::assertSame(
            IfMatchOutcome::MATCH,
            self::ifMatch()->guard($this->current()->value(), $this->current())
        );
    }

    public function testGuardReportsAnUnconditionalWriteDistinctly(): void
    {
        self::assertSame(
            IfMatchOutcome::UNCONDITIONAL,
            self::ifMatch()->guard(null, $this->current())
        );
    }

    public function testGuardThrows412ForAMismatch(): void
    {
        try {
            self::ifMatch()->guard(ETag::strong('other')->value(), $this->current());
            self::fail('Expected a 412.');
        } catch (DfcApiException $exception) {
            self::assertSame(412, $exception->error()->status());
            self::assertSame(ErrorCode::PRECONDITION_FAILED, $exception->error()->code());
        }
    }

    public function testGuardThrows412WhenThereIsNoRepresentationToMatch(): void
    {
        $this->expectException(DfcApiException::class);
        $this->expectExceptionMessage('412');

        self::ifMatch()->guard('*', null);
    }

    public function testGuardThrows400ForAMalformedHeader(): void
    {
        try {
            self::ifMatch()->guard('sha256-abc', $this->current());
            self::fail('Expected a 400.');
        } catch (DfcApiException $exception) {
            self::assertSame(400, $exception->error()->status());
            self::assertSame(ErrorCode::INVALID_HEADER, $exception->error()->code());
        }
    }

    public function testThePolicyIsReadable(): void
    {
        self::assertFalse(self::ifMatch()->preconditionRequired());
        self::assertTrue(self::ifMatch(true)->preconditionRequired());
    }
}
