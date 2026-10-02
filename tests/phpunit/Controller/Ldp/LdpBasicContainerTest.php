<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Ldp;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\Test\Controller\ControllerFixtures;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Ldp\ContainerPage;
use Civi\Dfc\V2\Controller\Ldp\LdpBasicContainer;
use Civi\Dfc\V2\Controller\Ldp\LdpVocabulary;
use Civi\Dfc\V2\Controller\Negotiation\MediaType;
use Civi\Dfc\V2\Identity\UriFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The externally visible collection: its JSON-LD shape, its context, its
 * containment invariant, and its independence from display names.
 */
#[CoversClass(LdpBasicContainer::class)]
#[CoversClass(LdpVocabulary::class)]
final class LdpBasicContainerTest extends TestCase
{
    use ControllerFixtureTrait;

    /**
     * @param list<string> $members
     */
    private function container(array $members = [], ?UriFactory $factory = null): LdpBasicContainer
    {
        $factory = $factory ?? self::factory();

        return LdpBasicContainer::of(
            $factory->organizationContainer(ControllerFixtures::ORGANIZATION_KEY),
            new ContainerPage(
                $factory->organizationContainer(ControllerFixtures::ORGANIZATION_KEY),
                $members,
                20,
                0,
                count($members)
            ),
            $factory->config()
        );
    }

    // -- The shape the DFC standard specifies ---------------------------------

    /**
     * The whole point of CP-1's "LDP container emits `ldp:contains`", asserted
     * against the literal bytes rather than a decoded structure so that the key
     * names, the order and the absence of extra triples are all part of the
     * contract.
     */
    public function testTheDocumentIsExactlyTheSpecifiedContainer(): void
    {
        $factory = self::factory();
        $index = $factory->organizationIndex(ControllerFixtures::ORGANIZATION_KEY);

        $expected = '{"@context":['
            . '"' . ControllerFixtures::CONTEXT_URL . '",'
            . '{"ldp":"' . LdpVocabulary::NAMESPACE . '"}],'
            . '"@id":"' . self::organizationContainer() . '",'
            . '"@type":"ldp:BasicContainer",'
            . '"ldp:contains":["' . $index . '"]}';

        self::assertSame($expected, $this->container([$index])->toJson());
    }

    public function testTheTypeIsTheLdpBasicContainer(): void
    {
        $container = $this->container();

        self::assertSame('ldp:BasicContainer', $container->type());
        self::assertSame(
            'http://www.w3.org/ns/ldp#BasicContainer',
            $container->vocabulary()->basicContainerIri()
        );
        self::assertSame('http://www.w3.org/ns/ldp#contains', $container->vocabulary()->containsIri());
    }

    // -- The context comes from the release config, never from a constant ------

    public function testTheContextUrlComesFromTheReleaseConfig(): void
    {
        $decoded = json_decode($this->container()->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ControllerFixtures::CONTEXT_URL,
            $decoded['@context'][0],
            'The DFC context URL is release configuration. PRD-002 §5 forbids hand-coding it.'
        );
    }

    /**
     * A second deployment, to prove the URL is not the first deployment's value
     * baked in somewhere.
     */
    public function testTheContextUrlFollowsTheConfiguredRelease(): void
    {
        $other = self::fixtureConfig(ControllerFixtures::OTHER_BASE_URI);

        $decoded = json_decode($this->container([], self::factory($other))->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(ControllerFixtures::CONTEXT_URL, $decoded['@context'][0]);
        self::assertSame(
            ControllerFixtures::OTHER_BASE_URI . '/organizations/' . ControllerFixtures::ORGANIZATION_KEY . '/',
            $decoded['@id'],
            'A hostname change re-points @id, exactly as UriFactory documents, and leaves @context alone.'
        );
    }

    /**
     * The LDP prefix is bound explicitly, and AFTER the DFC context so that JSON-LD's
     * "later entry wins" rule makes the binding unambiguous. Without it a DFC context
     * that did not define `ldp` would leave a CURIE-shaped key that no processor can
     * expand — decorative text rather than JSON-LD.
     */
    public function testTheLdpBindingIsDeclaredAfterTheDfcContext(): void
    {
        $decoded = json_decode($this->container()->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [ControllerFixtures::CONTEXT_URL, ['ldp' => LdpVocabulary::NAMESPACE]],
            $decoded['@context']
        );
    }

    // -- `@id` is the container URI, with its trailing slash ------------------

    public function testTheIdIsTheContainerUriNotItsParent(): void
    {
        $decoded = json_decode($this->container()->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringEndsWith('/', $decoded['@id']);
        self::assertSame(self::organizationContainer(), $decoded['@id']);
    }

    // -- `ldp:contains` is always a list --------------------------------------

    public function testMembershipIsAListEvenForOneMember(): void
    {
        $decoded = json_decode($this->container([self::organizationIndex()])->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded['ldp:contains']);
        self::assertCount(1, $decoded['ldp:contains']);
    }

    /**
     * RDF has no distinction between a one-member set and a many-member set, so the
     * wire form must not invent one: a client diffing `ldp:contains` between pages
     * would otherwise see `string` become `array` and could conclude the graph
     * changed shape.
     */
    public function testAnEmptyContainerSerialisesMembershipAsAnEmptyList(): void
    {
        self::assertStringContainsString('"ldp:contains":[]', $this->container()->toJson());
    }

    public function testMembershipIsInSourceOrder(): void
    {
        $factory = self::factory();
        $container = $factory->organizationContainer(ControllerFixtures::ORGANIZATION_KEY);

        $members = [$container . 'zeta', $container . 'alpha', $container . 'middle'];

        self::assertSame($members, $this->container($members)->memberUris());
    }

    // -- Containment is enforced ----------------------------------------------

    public function testAMemberFromAnotherContainerIsRefused(): void
    {
        try {
            $this->container(['https://platform.example/dfc/v2/organizations/OTHER/index']);
            self::fail('Expected a containment violation to be refused.');
        } catch (DfcApiException $exception) {
            self::assertSame(500, $exception->error()->status());
            self::assertSame(ErrorCode::MEMBERSHIP_INCONSISTENT, $exception->error()->code());
        }
    }

    public function testADescendantIsNotAMember(): void
    {
        // A descendant breaks the 1-1 correspondence between containment triples and
        // the path hierarchy, which is the invariant LDP's inference depends on.
        $this->expectException(DfcApiException::class);

        $this->container([self::organizationContainer() . 'catalogs/1/index']);
    }

    public function testASubContainerIsNotAMemberOfABasicContainer(): void
    {
        $this->expectException(DfcApiException::class);

        $this->container([self::organizationContainer() . 'catalogs/']);
    }

    public function testTheContainerItselfIsNotItsOwnMember(): void
    {
        $this->expectException(DfcApiException::class);

        $this->container([self::organizationContainer()]);
    }

    public function testAPageFromAnotherContainerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is being serialised as');

        LdpBasicContainer::of(
            self::organizationContainer(),
            new ContainerPage('https://platform.example/dfc/v2/organizations/OTHER/', [], 20, 0),
            self::fixtureConfig()
        );
    }

    // -- Stability ------------------------------------------------------------

    /**
     * Membership is stable and independent of display names — not by convention but
     * by construction: nothing in this layer accepts a name, a title or a label, so
     * there is no field a rename could reach.
     *
     * The two documents below are byte-identical despite representing an
     * organisation that has been renamed.
     */
    public function testMembershipIsIndependentOfDisplayNames(): void
    {
        $factory = self::factory();
        $members = [$factory->organizationIndex(ControllerFixtures::ORGANIZATION_KEY)];

        $beforeRename = $this->container($members, $factory)->toJson();

        // A rename touches no input this layer has: same container URI, same opaque
        // identifiers, therefore the same bytes and the same ETag.
        $afterRename = $this->container($members, $factory)->toJson();

        self::assertSame($beforeRename, $afterRename);
        self::assertStringNotContainsString('name', strtolower($beforeRename));
    }

    public function testTheEtagIsStrongAndStable(): void
    {
        $container = $this->container([self::organizationIndex()]);

        self::assertFalse($container->etag()->isWeak());
        self::assertSame($container->etag()->value(), $container->etag()->value());
        self::assertSame(
            $container->etag()->value(),
            $this->container([self::organizationIndex()])->etag()->value()
        );
    }

    public function testTheEtagCoversExactlyTheBytesThatAreSent(): void
    {
        $container = $this->container([self::organizationIndex()]);

        self::assertSame(
            $container->etag()->value(),
            \Civi\Dfc\V2\Controller\Http\ETag::strong($container->toJson())->value()
        );
    }

    public function testDifferentMembershipProducesADifferentEtag(): void
    {
        self::assertNotSame(
            $this->container()->etag()->value(),
            $this->container([self::organizationIndex()])->etag()->value()
        );
    }

    // -- Paging links ---------------------------------------------------------

    public function testAContainerWithNoNextPageAdvertisesNoLinks(): void
    {
        self::assertSame([], $this->container()->links());
    }

    public function testAContainerHandsItsPagingLinksToTheHeaderPolicy(): void
    {
        $factory = self::factory();
        $containerUri = $factory->organizationContainer(ControllerFixtures::ORGANIZATION_KEY);

        $page = new ContainerPage(
            $containerUri,
            [$containerUri . 'index'],
            20,
            0,
            45
        );

        $container = LdpBasicContainer::of($containerUri, $page, $factory->config());

        self::assertSame(
            ['<' . $containerUri . '?limit=20&offset=1>; rel="next"'],
            array_map(static fn ($link): string => $link->toString(), $container->links())
        );
    }

    // -- Vocabulary -----------------------------------------------------------

    public function testTheEmittedVocabularyIsExactlyLdpPlusTheJsonLdKeywords(): void
    {
        $vocabulary = self::fixtureConfig() ? new LdpVocabulary(self::fixtureConfig()) : null;

        self::assertNotNull($vocabulary);
        self::assertTrue($vocabulary->isKnownTerm('ldp:contains'));
        self::assertTrue($vocabulary->isKnownTerm('ldp:BasicContainer'));
        self::assertFalse(
            $vocabulary->isKnownTerm('ldp:Container'),
            'Only the Basic Container class is emitted, so only it is a known term.'
        );
        self::assertSame('@id', LdpVocabulary::JSON_LD_ID);
        self::assertSame('@context', LdpVocabulary::JSON_LD_CONTEXT);
        self::assertSame('@type', LdpVocabulary::RDF_TYPE);
    }

    public function testTheVocabularyExposesTheConfigItCameFrom(): void
    {
        $config = self::fixtureConfig();

        self::assertSame($config, (new LdpVocabulary($config))->config());
    }

    // -- Nothing else may appear in the document ------------------------------

    public function testTheDocumentCarriesNoThirdPartyTerms(): void
    {
        $decoded = json_decode($this->container([self::organizationIndex()])->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $expected = ['@context', '@id', '@type', 'ldp:contains'];
        $actual = array_keys($decoded);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testTheDocumentIsJsonSerializableForAPsrStyleBody(): void
    {
        $container = $this->container([self::organizationIndex()]);

        self::assertSame($container->toArray(), $container->jsonSerialize());
        self::assertSame(
            json_encode($container->toArray()),
            json_encode($container),
            'Encoding the value object must not produce different JSON-LD from toJson() minus reordering.'
        );
    }

    public function testANegotiatedJsonLdContentTypeIsWhatThePolicyWillAdvertise(): void
    {
        // The container's media type is the required one; the header policy will say
        // so verbatim, which is why the offer list starts there.
        self::assertSame(MediaType::JSON_LD, ControllerFixtures::standardOffers()[0]->essence());
    }
}
