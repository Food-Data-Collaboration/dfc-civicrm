<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Validation\ShaclShapeRepositoryInterface;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;

/**
 * The committed SHACL fixture, served through the loading interface.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 */
final class FixtureShapeRepository implements ShaclShapeRepositoryInterface
{
    public const GRAPH = 'fixture';

    public function turtle(string $graph): string
    {
        if ($graph !== self::GRAPH) {
            throw new ShaclShapeUnavailableException(sprintf('No fixture graph named "%s".', $graph));
        }

        $contents = file_get_contents(
            __DIR__ . '/../../fixtures/security/shacl/organization-fixture.shacl.ttl'
        );

        if ($contents === false || trim($contents) === '') {
            throw new ShaclShapeUnavailableException('The fixture shape could not be read.');
        }

        return $contents;
    }

    public function graphs(): array
    {
        return [self::GRAPH];
    }
}
