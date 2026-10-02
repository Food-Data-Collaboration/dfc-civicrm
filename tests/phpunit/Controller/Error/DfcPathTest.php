<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Error;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\V2\Controller\Error\DfcPath;
use Civi\Dfc\V2\Controller\Error\ValidationDiagnostic;
use Civi\Dfc\V2\Controller\Error\ValidationIssue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The token rules that make the primary no-leak control possible.
 *
 * If this class could be built from a string, the whole argument in
 * {@see \Civi\Dfc\V2\Controller\Error\ProtocolError} collapses, so the constraints
 * are pinned here individually.
 */
#[CoversClass(DfcPath::class)]
#[CoversClass(ValidationDiagnostic::class)]
final class DfcPathTest extends TestCase
{
    use ControllerFixtureTrait;

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTokenProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'a JSON pointer with separators' => ['organizations/0'];
        yield 'a SQL fragment' => ['SELECT * FROM civicrm_contact'];
        yield 'a leading hyphen' => ['-name'];
        yield 'a drive letter' => ['C:'];
        yield 'a bare leading dot' => ['.hidden'];
        yield 'a newline' => ["name\nmore"];
        yield 'a percent-encoded token' => ['na%20me'];
        yield 'a quoted SQL identifier' => ['`name`'];
        yield 'an over-long token' => [str_repeat('a', 129)];
    }

    #[DataProvider('refusedTokenProvider')]
    public function testAnUnusableTokenIsRefusedRatherThanSanitised(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DfcPath::fromTokens($token);
    }

    public function testUsableTokensAreAccepted(): void
    {
        $path = DfcPath::fromTokens('organizations', '0', 'address', 'dfc-b:streetAddress', '_internal', 'a.b-c');

        self::assertSame(
            ['organizations', '0', 'address', 'dfc-b:streetAddress', '_internal', 'a.b-c'],
            $path->tokens()
        );
        self::assertSame(6, $path->depth());
        self::assertSame('/organizations/0/address/dfc-b:streetAddress/_internal/a.b-c', $path->render());
    }

    /**
     * There is no string constructor. That is the whole reason a server file path
     * cannot land in a diagnostic by accident, so it is asserted structurally.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenFactoryProvider(): iterable
    {
        yield 'fromString' => ['fromString'];
        yield 'fromPointer' => ['fromPointer'];
        yield 'parse' => ['parse'];
        yield 'fromRawPath' => ['fromRawPath'];
    }

    #[DataProvider('forbiddenFactoryProvider')]
    public function testThereIsNoFactoryThatTakesAPathString(string $method): void
    {
        self::assertFalse(
            method_exists(DfcPath::class, $method),
            sprintf('DfcPath::%s() would reintroduce the free-text slot the no-leak guarantee relies on.', $method)
        );
    }

    public function testAppendIsImmutable(): void
    {
        $base = DfcPath::fromTokens('organizations');
        $deeper = $base->append('0', 'name');

        self::assertSame('/organizations', $base->render());
        self::assertSame('/organizations/0/name', $deeper->render());
        self::assertTrue($base->equals(DfcPath::fromTokens('organizations')));
        self::assertFalse($base->equals($deeper));
    }

    public function testAPathMayNotBeUnreasonablyDeep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 64 tokens');

        DfcPath::fromTokens(...array_fill(0, 65, 'name'));
    }

    public function testANonStringTokenIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');

        DfcPath::fromList(['organizations', 7]);
    }

    // -- Diagnostic predicate and constraint slots ----------------------------

    public function testAPredicateAcceptsANameOrACurie(): void
    {
        self::assertSame(
            'dfc-b:legalName',
            (new ValidationDiagnostic(DfcPath::root(), 'dfc-b:legalName', ValidationIssue::REQUIRED))->predicate()
        );
        self::assertSame(
            'legalName',
            (new ValidationDiagnostic(DfcPath::root(), 'legalName', ValidationIssue::REQUIRED))->predicate()
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPredicateProvider(): iterable
    {
        yield 'SQL' => ['SELECT 1'];
        yield 'a file path' => ['/var/www/html'];
        yield 'a curly-brace expression' => ['{$column}'];
        yield 'a newline' => ["name\r\nSet-Cookie: x"];
        yield 'an over-long predicate' => [str_repeat('p', 129)];
    }

    #[DataProvider('refusedPredicateProvider')]
    public function testAnUnusablePredicateIsRefused(string $predicate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('diagnostic predicate');

        new ValidationDiagnostic(DfcPath::root(), $predicate, ValidationIssue::REQUIRED);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedConstraintProvider(): iterable
    {
        yield 'a sentence' => ['the name must be present'];
        yield 'a SQL predicate' => ['name = 1 OR 1'];
        yield 'a path' => ['/etc/passwd'];
        yield 'a newline' => ["minCount>=1\nX"];
    }

    #[DataProvider('refusedConstraintProvider')]
    public function testAnUnusableConstraintIsRefused(string $constraint): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('diagnostic constraint');

        new ValidationDiagnostic(DfcPath::root(), 'dfc-b:name', ValidationIssue::REQUIRED, $constraint);
    }

    public function testAConstraintMayCarryAComparison(): void
    {
        $diagnostic = new ValidationDiagnostic(
            DfcPath::fromTokens('organizations', '0'),
            'dfc-b:name',
            ValidationIssue::TOO_LARGE,
            'maxLength<=255'
        );

        self::assertSame('maxLength<=255', $diagnostic->constraint());
        self::assertSame(ValidationIssue::TOO_LARGE, $diagnostic->issue());
        self::assertSame('/organizations/0', $diagnostic->path()->render());
    }

    /**
     * A diagnostic carries no observed value, and that is load-bearing: it is the
     * field a validation framework grows and the one that leaks submitted personal
     * data back out of a response or a log.
     */
    public function testADiagnosticCarriesNoObservedValue(): void
    {
        $keys = array_keys(
            (new ValidationDiagnostic(DfcPath::root(), 'dfc-b:name', ValidationIssue::REQUIRED))->toArray()
        );
        sort($keys);

        self::assertSame(['detail', 'issue', 'path', 'predicate'], $keys);
    }
}
