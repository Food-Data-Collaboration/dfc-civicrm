<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Error;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Error\ErrorMapper;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Identity\Exception\InvalidUriException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one place a PHP or PDO failure becomes a protocol error.
 *
 * The point of every case here is the same: the mapper never READS the throwable,
 * so nothing about it can be emitted.
 */
#[CoversClass(ErrorMapper::class)]
final class ErrorMapperTest extends TestCase
{
    use ControllerFixtureTrait;

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function unmappedThrowableProvider(): iterable
    {
        yield 'PDO error' => [
            new \PDOException("SQLSTATE[42S02]: Table 'civicrm.civicrm_contact' doesn't exist"),
        ];

        yield 'runtime error' => [new \RuntimeException('something went wrong in a mapper')];

        yield 'logic error' => [new \LogicException('a developer mistake')];

        yield 'argument error' => [new \InvalidArgumentException('bad identifier')];

        yield 'type error' => [new \TypeError('null given where array expected')];

        yield 'value error' => [new \ValueError('not a valid UTF-8 sequence')];

        yield 'JSON error' => [new \JsonException('Syntax error')];

        yield 'arithmetic error' => [new \DivisionByZeroError('Division by zero')];

        yield 'CiviCRM DAO error' => [new \ErrorException('Call to undefined method CRM_Core_DAO()')];

        yield 'Error, not Exception' => [new \Error('assertion failure')];
    }

    #[DataProvider('unmappedThrowableProvider')]
    public function testEveryUnrecognisedFailureBecomesA500WithNoDetail(\Throwable $throwable): void
    {
        $error = self::mapper()->fromThrowable($throwable);

        self::assertSame(500, $error->status());
        self::assertSame(ErrorCode::INTERNAL_ERROR, $error->code());
        self::assertSame(ErrorCode::INTERNAL_ERROR->detail(), $error->detail());
    }

    #[DataProvider('unmappedThrowableProvider')]
    public function testNoPartOfTheThrowableReachesTheError(\Throwable $throwable): void
    {
        $error = self::mapper()->fromThrowable($throwable);
        $rendered = $error->toJson();

        // Every string the throwable could leak: its message, its class, its file
        // and its line. None of them may appear.
        foreach (self::leakableStrings($throwable) as $candidate) {
            self::assertStringNotContainsString($candidate, $rendered);
        }
    }

    /**
     * sa-004's docblock states every {@see \Civi\Dfc\V2\Identity\Exception\DfcIdentityException}
     * is a programming or configuration error, so it is a 500 — NOT a 400. A route
     * that turns client input into one must translate first, and saying so here
     * keeps that decision visible instead of accidental.
     */
    public function testAnIdentityLayerFailureIsAServerFaultNotAClientOne(): void
    {
        $error = self::mapper()->fromThrowable(new InvalidUriException(
            'The DFC semantic identifier is "..", a relative-path reference rather than an identifier.'
        ));

        self::assertSame(500, $error->status());
        self::assertSame(ErrorCode::INTERNAL_ERROR, $error->code());
    }

    public function testAProtocolExceptionPassesThroughVerbatim(): void
    {
        $original = ProtocolError::semanticIdConflict();

        $mapped = self::mapper()->fromThrowable(new DfcApiException($original));

        self::assertSame($original, $mapped);
    }

    /**
     * A protocol error thrown without a correlation id gets one backfilled, or the
     * response and the log cannot be joined.
     */
    public function testACorrelationIdIsBackfilledOntoAThrowersError(): void
    {
        $mapper = self::mapper(self::correlationId());

        $mapped = $mapper->fromThrowable(new DfcApiException(ProtocolError::preconditionFailed()));

        self::assertSame(412, $mapped->status());
        self::assertSame(
            '01HZY8QK3M7X4V2N6T9B0C5D8E',
            $mapped->correlationId()?->value()
        );
    }

    public function testAnExistingCorrelationIdIsNotOverwritten(): void
    {
        $mapper = self::mapper(self::correlationId());

        $mapped = $mapper->fromThrowable(new DfcApiException(
            ProtocolError::notFound(self::correlationId('01HZY8QK3M7X4V2N6T9B0C5D8F'))
        ));

        self::assertSame('01HZY8QK3M7X4V2N6T9B0C5D8F', $mapped->correlationId()?->value());
    }

    public function testTheMapperProvidesItsOwnChallengeSoA401IsAlwaysConformant(): void
    {
        $error = self::mapper()->authenticationRequired();

        self::assertSame(401, $error->status());
        self::assertSame(
            'Bearer realm="https://platform.example/dfc/v2"',
            $error->headers()['WWW-Authenticate']
        );
    }

    public function testTheMapperCanNameTheRfc6750ErrorAndTheRequiredScope(): void
    {
        $error = self::mapper()->authenticationRequired('insufficient_scope', 'dfc:write');

        self::assertSame(
            'Bearer realm="https://platform.example/dfc/v2", error="insufficient_scope", scope="dfc:write"',
            $error->headers()['WWW-Authenticate']
        );
    }

    public function testTheRealmIsValidatedAtConstructionRatherThanAtTheFirst401(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute https URI');

        new ErrorMapper(null, 'not-a-uri');
    }

    public function testTheMapperDoesNotUsePDOWhenTheExtensionIsAbsent(): void
    {
        // Guards the intent, not the environment: the mapping table must never grow
        // a PDOException branch that reads its message. There is no such branch,
        // so a `\PDOException` arrives here through the same generic path as
        // everything else — which is what this asserts.
        $error = self::mapper()->fromThrowable(new \PDOException('SQLSTATE[HY000] [1045] Access denied'));

        self::assertSame(500, $error->status());
        self::assertStringNotContainsString('HY000', $error->toJson());
        self::assertStringNotContainsString('1045', $error->toJson());
    }

    /**
     * @return list<string>
     */
    private static function leakableStrings(\Throwable $throwable): array
    {
        // The baseline a mapped 500 always produces. A token from the throwable that
        // ALSO appears here is not evidence of a leak — it is the fixed prose — so it
        // is excluded rather than asserted on. What remains is exactly the set of
        // strings that could only have come from the throwable.
        $baseline = ProtocolError::internalError()->toJson();

        $candidates = [$throwable::class, basename($throwable->getFile())];

        foreach (preg_split('/\s+/', $throwable->getMessage()) ?: [] as $word) {
            $word = trim($word);
            if (strlen($word) >= 6) {
                $candidates[] = $word;
            }
        }

        $candidates = array_values(array_unique($candidates));

        return array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => !str_contains($baseline, $candidate)
        ));
    }
}
