<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests run WITHOUT a CiviCRM bootstrap on purpose. The protocol layer is
 * designed so services can be tested without a CMS bootstrap; only integration
 * and conformance suites need a running CiviCRM instance.
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../../vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(
        STDERR,
        "Dependencies not installed. Run: composer install\n"
    );
    exit(1);
}

require_once $autoload;