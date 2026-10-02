<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ShaclShapeRepositoryInterface;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;

/**
 * A repository that returns one inline Turtle document under one graph name.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * Exists so a test can supply a shape document as a string, which is how the
 * fail-closed paths are reached without a second fixture file per case.
 */
final class InlineShapeRepository implements ShaclShapeRepositoryInterface
{
    public const GRAPH = 'inline';

    private readonly string $turtle;

    public function __construct(string $turtle)
    {
        $this->turtle = $turtle;
    }

    public function turtle(string $graph): string
    {
        if ($graph !== self::GRAPH) {
            throw new ShaclShapeUnavailableException(sprintf('No inline graph named "%s".', $graph));
        }

        return $this->turtle;
    }

    public function graphs(): array
    {
        return [self::GRAPH];
    }
}