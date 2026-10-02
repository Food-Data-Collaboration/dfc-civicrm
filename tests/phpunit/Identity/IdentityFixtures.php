<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Identity;

/**
 * Constants shared by the Identity test suite.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * PHP 8.1 forbids constants in traits, so these live in a class that the test
 * classes and the fixture trait both reference by name.
 */
final class IdentityFixtures
{
    /** Base URI used throughout the suite unless a test says otherwise. */
    public const DEFAULT_BASE_URI = 'https://platform.example/dfc/v2';

    /**
     * A realistic opaque identifier: Crockford base32, exactly what
     * {@see \Civi\Dfc\V2\Identity\RandomIdentifierGenerator} produces.
     */
    public const USER_KEY = '01HZY8QK3M7X4V2N6T9B0C5D8E';

    public const ORGANIZATION_KEY = '01HZY9B2W8R6K4M0P1Q3S5T7V';

    private function __construct()
    {
        // Constants holder.
    }
}