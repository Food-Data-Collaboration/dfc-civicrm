<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ShaclShapeRepositoryInterface;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;

/**
 * A repository that behaves like a deployment missing its shape files.
 *
 * Exists because "the shapes cannot be loaded" is the failure the SHACL gate has to be
 * proven NOT to swallow, and it cannot be proven with a working fixture.
 */
final class UnavailableShapeRepository implements ShaclShapeRepositoryInterface
{
    public function turtle(string $graph): string
    {
        throw new ShaclShapeUnavailableException(sprintf(
            'The SHACL shape file "%s" is missing or unreadable. Validation cannot run without it.',
            $graph
        ));
    }

    public function graphs(): array
    {
        return ['business', 'technical'];
    }
}
