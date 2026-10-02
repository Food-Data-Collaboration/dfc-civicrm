<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Security\JsonWebKey;
use Civi\Dfc\V2\Security\JwksDocument;
use Civi\Dfc\V2\Security\JwksFetcherInterface;
use Civi\Dfc\V2\Security\JwksUnavailableException;

/**
 * A {@see JwksFetcherInterface} that hands out scripted key sets.
 *
 * NOT A TEST CLASS — it shares a file with {@see OidcHarness} because it exists only
 * to serve it.
 *
 * Two behaviours matter:
 *
 *   - **Scripted rotation.** Each call returns the next document; the last one
 *     repeats. That is how "the key arrived between two fetches" is expressed.
 *   - **Scripted failure.** {@see failNext()} makes the next call throw
 *     {@see JwksUnavailableException}, which is how "the IdP blipped" and "the IdP is
 *     down" are told apart.
 *
 * {@see callCount()} is asserted in the tests, because "the cache did not re-fetch" is
 * a claim about load and claims about load should be measured.
 */
final class ScriptedJwksFetcher implements JwksFetcherInterface
{
    /** @var list<JwksDocument> */
    private array $documents;

    private int $index = 0;

    private int $calls = 0;

    private ?JwksUnavailableException $failure = null;

    /** @var list<string> */
    private array $requestedUris = [];

    /**
     * @param list<list<array<string, mixed>>> $keySets Each element is one `keys` array.
     */
    public function __construct(array $keySets)
    {
        if ($keySets === []) {
            $keySets = [[]];
        }

        foreach ($keySets as $keySet) {
            $this->documents[] = JwksDocument::of(
                array_map(
                    static fn (array $jwk): \Civi\Dfc\V2\Security\JsonWebKey => \Civi\Dfc\V2\Security\JsonWebKey::fromArray($jwk),
                    $keySet
                )
            );
        }
    }

    public function fetch(string $uri): JwksDocument
    {
        $this->calls++;
        $this->requestedUris[] = $uri;

        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        $document = $this->documents[min($this->index, count($this->documents) - 1)];
        if ($this->index < count($this->documents) - 1) {
            $this->index++;
        }

        return $document;
    }

    /**
     * Make the next call throw, modelling an IdP that is briefly unreachable.
     */
    public function failNext(string $reason = 'scripted IdP outage'): void
    {
        $this->failure = new JwksUnavailableException($reason);
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /**
     * @return list<string>
     */
    public function requestedUris(): array
    {
        return $this->requestedUris;
    }
}