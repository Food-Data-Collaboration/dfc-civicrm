<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller;

/**
 * Constants shared by the Controller (protocol) test suite.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * PHP 8.1 forbids constants in traits, so these live in a class that the test
 * classes and the fixture trait both reference by name. This mirrors the
 * Identity suite's arrangement deliberately rather than sharing one: the two
 * suites must be able to diverge (a rename in one is not a rename in the other),
 * and the Identity fixtures are owned by sa-004.
 */
final class ControllerFixtures
{
    /**
     * The platform base URI every test starts from.
     *
     * Same value the Identity suite uses, but declared here so this layer's
     * tests do not depend on another lane's fixture file.
     */
    public const BASE_URI = 'https://platform.example/dfc/v2';

    /** A second deployment, for proving the context URL is never hardcoded. */
    public const OTHER_BASE_URI = 'https://other-platform.example/dfc/v2';

    /**
     * The context URL the fixture release descriptor derives.
     *
     * `DfcReleaseConfig` derives it from `ONTOLOGY_BASE_URL . '/v' . $version .
     * '/context/context_' . $version . '.json'`; sa-004 pins that against
     * upstream's own constant. Asserting the literal here proves the Controller
     * layer reads it rather than substituting one of its own.
     */
    public const CONTEXT_URL = 'https://w3id.org/dfc/ontology/v2.0.0/context/context_2.0.0.json';

    public const ORGANIZATION_KEY = '01HZY9B2W8R6K4M0P1Q3S5T7V';

    public const USER_KEY = '01HZY8QK3M7X4V2N6T9B0C5D8E';

    /** The DFC `index` leaf, which is a direct child of the org container. */
    public const INDEX_LEAF = 'index';

    /** A realistic ULID, for correlation ids. */
    public const CORRELATION_ID = '01HZY8QK3M7X4V2N6T9B0C5D8E';

    /**
     * The two media types every DFC resource offers, in the required order.
     *
     * `application/ld+json` FIRST is a contract requirement, not a preference:
     * {@see \Civi\Dfc\V2\Controller\Negotiation\ContentNegotiator} breaks the tie
     * by offer-list position when the client expresses no preference.
     *
     * @return list<\Civi\Dfc\V2\Controller\Negotiation\MediaType>
     */
    public static function standardOffers(): array
    {
        return [
            \Civi\Dfc\V2\Controller\Negotiation\MediaType::jsonLd(),
            \Civi\Dfc\V2\Controller\Negotiation\MediaType::turtle(),
        ];
    }

    private function __construct()
    {
        // Constants holder.
    }
}
