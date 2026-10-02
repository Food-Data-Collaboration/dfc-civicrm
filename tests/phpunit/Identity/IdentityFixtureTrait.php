<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Identity\ReleaseDescriptorParser;

/**
 * Shared fixture helpers for the Identity test suite.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * NOTE: PHP 8.1 does not allow constants in traits (that arrived in 8.2), so the
 * default base URI and the sample opaque keys live in {@see IdentityFixtures}.
 */
trait IdentityFixtureTrait
{
    protected static function fixturePath(string $name = 'dfc-release.yaml'): string
    {
        return __DIR__ . '/../../fixtures/' . $name;
    }

    /**
     * The config every test starts from: the upstream-shaped fixture plus this
     * deployment's public base.
     */
    protected static function fixtureConfig(?string $baseUri = null): DfcReleaseConfig
    {
        return DfcReleaseConfig::fromDescriptorFile(
            self::fixturePath(),
            ['platform_base_uri' => $baseUri ?? IdentityFixtures::DEFAULT_BASE_URI]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixtureDescriptor(): array
    {
        return ReleaseDescriptorParser::fromFile(self::fixturePath());
    }
}