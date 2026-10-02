<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ShaclShapeRepositoryInterface;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;

/**
 * A repository that advertises graphs and then produces documents with no shapes in
 * them.
 *
 * The third fail-closed state, distinct from "cannot load" and from "loaded and the
 * document is valid": a shape file that parsed but yielded no node shapes.
 */
final class EmptyShapeRepository implements ShaclShapeRepositoryInterface
{
    public function turtle(string $graph): string
    {
        return "# A shape file that parses to nothing.\n@prefix sh: <http://www.w3.org/ns/shacl#> .\n";
    }

    public function graphs(): array
    {
        return ['business'];
    }
}
