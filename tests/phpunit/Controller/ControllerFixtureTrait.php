<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller;

use Civi\Dfc\V2\Controller\Error\CorrelationId;
use Civi\Dfc\V2\Controller\Error\ErrorMapper;
use Civi\Dfc\V2\Controller\Error\ErrorResponder;
use Civi\Dfc\V2\Controller\Http\HeaderPolicy;
use Civi\Dfc\V2\Controller\Http\IfMatchEvaluator;
use Civi\Dfc\V2\Controller\Negotiation\ContentNegotiator;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Identity\ReleaseDescriptorParser;
use Civi\Dfc\V2\Identity\UriFactory;

/**
 * Shared fixture helpers for the Controller (protocol) test suite.
 *
 * NOT A TEST CLASS — the file name does not end in `Test.php`.
 *
 * The release descriptor lives in `tests/fixtures/dfc-release.yaml`, the same
 * file the Identity suite reads, because the context URL under test is the one
 * the real config derives. Only the helpers are local, so this layer's tests
 * cannot be moved by sa-004's fixtures changing shape.
 */
trait ControllerFixtureTrait
{
    protected static function fixturePath(string $name = 'dfc-release.yaml'): string
    {
        return __DIR__ . '/../../fixtures/' . $name;
    }

    /**
     * The release config every test starts from: the upstream-shaped fixture plus
     * this deployment's public base.
     */
    protected static function fixtureConfig(?string $baseUri = null): DfcReleaseConfig
    {
        return DfcReleaseConfig::fromDescriptorFile(
            self::fixturePath(),
            ['platform_base_uri' => $baseUri ?? ControllerFixtures::BASE_URI]
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixtureDescriptor(): array
    {
        return ReleaseDescriptorParser::fromFile(self::fixturePath());
    }

    /** A deterministic URI factory — no random identifier generation. */
    protected static function factory(?DfcReleaseConfig $config = null): UriFactory
    {
        return new UriFactory($config ?? self::fixtureConfig());
    }

    protected static function negotiator(): ContentNegotiator
    {
        return new ContentNegotiator();
    }

    protected static function headerPolicy(): HeaderPolicy
    {
        return new HeaderPolicy();
    }

    protected static function ifMatch(bool $preconditionRequired = false): IfMatchEvaluator
    {
        return IfMatchEvaluator::create($preconditionRequired);
    }

    protected static function correlationId(?string $value = null): CorrelationId
    {
        return CorrelationId::fromString($value ?? ControllerFixtures::CORRELATION_ID);
    }

    protected static function mapper(?CorrelationId $correlationId = null): ErrorMapper
    {
        return new ErrorMapper($correlationId, ControllerFixtures::BASE_URI);
    }

    protected static function responder(?CorrelationId $correlationId = null): ErrorResponder
    {
        return new ErrorResponder(self::mapper($correlationId));
    }

    /** The organisation container URI used throughout the LDP tests. */
    protected static function organizationContainer(?DfcReleaseConfig $config = null): string
    {
        return self::factory($config)->organizationContainer(ControllerFixtures::ORGANIZATION_KEY);
    }

    /** The `index` resource — a valid direct child of the organisation container. */
    protected static function organizationIndex(?DfcReleaseConfig $config = null): string
    {
        return self::factory($config)->organizationIndex(ControllerFixtures::ORGANIZATION_KEY);
    }
}
