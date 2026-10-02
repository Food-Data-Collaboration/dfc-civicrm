<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\IdentifierGeneratorInterface;
use Civi\Dfc\V2\Identity\Exception\InvalidUriException;

/**
 * Deterministic {@see IdentifierGeneratorInterface} for tests.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`, so PHPUnit's
 * `tests/phpunit` directory scan ignores it.
 *
 * WHY THIS EXISTS
 * UriFactoryStabilityTest asserts exact URI strings. That is only possible if
 * identifier minting is deterministic, which is why the generator is an injected
 * collaborator (IdentifierGeneratorInterface contract I5) rather than a
 * `random_bytes()` call buried in the factory.
 *
 * It is also the direct evidence that nothing in the URI layer calls random():
 * if the factory minted randomness internally, no injectable generator could
 * produce a predictable URI.
 */
final class ScriptedIdentifierGenerator implements IdentifierGeneratorInterface
{
    /** @var list<string> */
    private array $script;

    /** @var list<string> */
    private array $requestedKinds = [];

    /** @var callable(string): string */
    private $fallback;

    /**
     * @param list<string> $script Returned in order, one per generate() call.
     *                            Once exhausted, $fallback is used.
     * @param null|callable(string): string $fallback
     */
    public function __construct(array $script = [], ?callable $fallback = null)
    {
        $this->script = $script;
        $this->fallback = $fallback ?? static fn (string $kind): string => 'GENERATED-' . strtoupper($kind);
    }

    public function generate(string $kind): string
    {
        $this->requestedKinds[] = $kind;

        if ($this->script === []) {
            return ($this->fallback)($kind);
        }

        return array_shift($this->script);
    }

    /**
     * Entity kinds the code under test asked for, in order.
     *
     * @return list<string>
     */
    public function requestedKinds(): array
    {
        return $this->requestedKinds;
    }

    /**
     * A generator that returns a value the URI factory must reject.
     *
     * @return self
     */
    public static function returningUnusable(string $value): self
    {
        return new self([], static fn (): string => $value);
    }

    public static function throwing(): self
    {
        return new self([], static function (string $kind): string {
            throw new InvalidUriException(sprintf('scripted failure for kind "%s"', $kind));
        });
    }
}