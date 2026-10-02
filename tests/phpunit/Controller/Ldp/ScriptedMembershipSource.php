<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Ldp;

use Civi\Dfc\V2\Controller\Ldp\MembershipSourceInterface;

/**
 * A scriptable, strictly bounded {@see MembershipSourceInterface}.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * Its main job is to RECORD the (limit, offset) pairs it was asked for, so a test
 * can assert the bound was applied BEFORE the fetch rather than after it. A source
 * that quietly returns everything and lets the page slice is precisely the bug
 * PRD-002 §10 asks about, so the tests need to be able to see it happen.
 */
final class ScriptedMembershipSource implements MembershipSourceInterface
{
    /** @var list<array{container: string, limit: int, offset: int}> */
    private array $calls = [];

    private bool $countAvailable = true;

    /**
     * @param list<string> $members The full collection, in the order the real
     *                              query would return it.
     */
    public function __construct(
        private readonly array $members,
        private readonly ?int $forcedTotal = null
    ) {
    }

    /** Simulate a deployment that declines to run a COUNT query. */
    public function withoutCount(): self
    {
        $clone = clone $this;
        $clone->countAvailable = false;

        return $clone;
    }

    /**
     * @return list<array{container: string, limit: int, offset: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function largestLimitRequested(): int
    {
        $largest = 0;
        foreach ($this->calls as $call) {
            $largest = max($largest, $call['limit']);
        }

        return $largest;
    }

    public function page(string $containerUri, int $limit, int $offset): array
    {
        $this->calls[] = ['container' => $containerUri, 'limit' => $limit, 'offset' => $offset];

        return array_values(array_slice($this->members, $offset, $limit));
    }

    public function count(string $containerUri): ?int
    {
        if (!$this->countAvailable) {
            return null;
        }

        return $this->forcedTotal ?? count($this->members);
    }
}
