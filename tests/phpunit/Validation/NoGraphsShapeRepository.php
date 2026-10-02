<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ShaclShapeRepositoryInterface;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;

/**
 * A repository that advertises NO graphs at all.
 *
 * The fourth fail-closed state — the one where "loop over nothing" would look exactly
 * like success.
 */
final class NoGraphsShapeRepository implements ShaclShapeRepositoryInterface
{
    public function turtle(string $graph): string
    {
        throw new ShaclShapeUnavailableException('This repository has no graphs.');
    }

    public function graphs(): array
    {
        return [];
    }
}
