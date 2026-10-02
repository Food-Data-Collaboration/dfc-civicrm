<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Http;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\Test\Controller\ControllerFixtures;
use Civi\Dfc\V2\Controller\Http\ETag;
use Civi\Dfc\V2\Controller\Http\HeaderPolicy;
use Civi\Dfc\V2\Controller\Http\LinkRelation;
use Civi\Dfc\V2\Controller\Http\ProtocolResponse;
use Civi\Dfc\V2\Controller\Http\ResourceCapabilities;
use Civi\Dfc\V2\Controller\Http\ResponseDecision;
use Civi\Dfc\V2\Controller\Http\ResponseHeaders;
use Civi\Dfc\V2\Controller\Negotiation\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Allow`, `Accept-Post`, `Accept-Patch`, `Link` and `Vary`, derived from one
 * capability declaration, plus the header map they are written into.
 */
#[CoversClass(HeaderPolicy::class)]
#[CoversClass(ResourceCapabilities::class)]
#[CoversClass(LinkRelation::class)]
#[CoversClass(ResponseDecision::class)]
#[CoversClass(ResponseHeaders::class)]
#[CoversClass(ProtocolResponse::class)]
final class HeaderPolicyTest extends TestCase
{
    use ControllerFixtureTrait;

    // -- Allow ----------------------------------------------------------------

    public function testAllowComesFromTheCapabilitiesInACanonicalOrder(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::writableContainer());

        // Declared out of order on purpose: the output must be deterministic so a
        // capability change is visible as a diff.
        $capabilities = new ResourceCapabilities(
            ['DELETE', 'PATCH', 'GET', 'POST', 'HEAD', 'OPTIONS'],
            ControllerFixtures::standardOffers(),
            true,
            [MediaType::jsonLd()]
        );

        self::assertSame(
            'GET, HEAD, POST, PATCH, DELETE, OPTIONS',
            self::headerPolicy()->describe($capabilities)->first('Allow')
        );
        self::assertSame(
            'GET, HEAD, POST, PATCH, OPTIONS',
            $headers->first('Allow'),
            'The writable container preset advertises POST and PATCH, which is what makes Accept-Post meaningful.'
        );
    }

    public function testAReadOnlyContainerAdvertisesNoWriteMethod(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::readOnlyContainer());

        self::assertSame('GET, HEAD, OPTIONS', $headers->first('Allow'));
        self::assertFalse($headers->has('Accept-Post'));
        self::assertFalse($headers->has('Accept-Patch'));
    }

    public function testMethodsAreExposedForAnAuthorisationDecisionBeforeAnyFetch(): void
    {
        $capabilities = ResourceCapabilities::document(['GET', 'HEAD', 'PUT']);

        self::assertTrue($capabilities->allows('put'));
        self::assertTrue($capabilities->allows(' PUT '));
        self::assertFalse($capabilities->allows('DELETE'));
        self::assertSame(['GET', 'HEAD', 'PUT'], $capabilities->methods());
    }

    public function testAMethodOutsideTheCanonicalOrderIsStillAdvertised(): void
    {
        // A table of known methods that silently truncates an unfamiliar one would
        // make a capability set lie about what the resource supports.
        $capabilities = ResourceCapabilities::document(['GET', 'QUERY']);

        self::assertSame(['GET', 'QUERY'], $capabilities->methods());
        self::assertSame('GET, QUERY', self::headerPolicy()->describe($capabilities)->first('Allow'));
    }

    public function testAnEmptyMethodListIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one HTTP method');

        ResourceCapabilities::document([]);
    }

    // -- Accept-Post ----------------------------------------------------------

    public function testAcceptPostListsWhatAContainerAccepts(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::writableContainer(
            [],
            [MediaType::jsonLd(), MediaType::of(MediaType::MERGE_PATCH_JSON)]
        ));

        self::assertSame('application/ld+json, application/merge-patch+json', $headers->first('Accept-Post'));
    }

    public function testAcceptPatchIsAdvertisedOnlyWhenTheContainerSupportsIt(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::writableContainer(
            [],
            [MediaType::jsonLd()],
            [MediaType::of(MediaType::MERGE_PATCH_JSON)]
        ));

        self::assertSame('application/merge-patch+json', $headers->first('Accept-Patch'));
    }

    /**
     * Advertising `Accept-Post` on something that is not a container would promise a
     * capability the resource cannot honour, so the declaration is refused rather
     * than quietly dropped.
     */
    public function testANonContainerMayNotDeclareAcceptPost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CONTAINER features');

        new ResourceCapabilities(
            ['GET'],
            ControllerFixtures::standardOffers(),
            false,
            [MediaType::jsonLd()]
        );
    }

    // -- Link -----------------------------------------------------------------

    public function testALinkIsRenderedWithItsRelationAndType(): void
    {
        $headers = self::headerPolicy()->describe(
            ResourceCapabilities::readOnlyContainer([
                LinkRelation::typeRelation(self::organizationIndex(), MediaType::JSON_LD),
            ])
        );

        self::assertSame(
            [
                '<' . self::organizationIndex() . '>; rel="type"; type="application/ld+json"',
            ],
            $headers->get('Link')
        );
    }

    public function testSeveralRelationsOnOneLinkValueArePreserved(): void
    {
        $link = new LinkRelation(['type', 'describedby'], self::organizationIndex(), MediaType::JSON_LD);

        self::assertSame(
            '<' . self::organizationIndex() . '>; rel="type describedby"; type="application/ld+json"',
            $link->toString()
        );
        self::assertTrue($link->hasRelation('type'));
        self::assertTrue($link->hasRelation('describedby'));
        self::assertFalse($link->hasRelation('next'));
    }

    public function testARelationMayBeAnAbsoluteIri(): void
    {
        $link = LinkRelation::named(
            'http://www.w3.org/ns/ldp#type',
            self::organizationIndex()
        );

        self::assertStringContainsString('rel="http://www.w3.org/ns/ldp#type"', $link->toString());
    }

    /**
     * `title` is the one Link field RFC 8288 offers that is free text, and free text
     * in a header is where a display name — that is, personal data — would end up in
     * every proxy log on the path. It is omitted rather than sanitised.
     */
    public function testThereIsNoTitleParameterOnALink(): void
    {
        $constructor = (new \ReflectionClass(LinkRelation::class))->getConstructor();
        $names = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters()
        );

        self::assertSame(['relations', 'target', 'type'], $names);

        $link = LinkRelation::typeRelation(self::organizationIndex());

        self::assertStringNotContainsString('title', $link->toString());
    }

    public function testARelationTargetMustBeAbsolute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute http(s) URI');

        LinkRelation::typeRelation('/organizations/1/index');
    }

    public function testAFreeTextRelationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('registered relation token');

        LinkRelation::named("John's main index", self::organizationIndex());
    }

    public function testResponseLinksFollowCapabilityLinksInDeclarationOrder(): void
    {
        $capabilities = ResourceCapabilities::readOnlyContainer([
            LinkRelation::typeRelation(self::organizationIndex(), MediaType::JSON_LD),
        ]);

        $headers = self::headerPolicy()->describe($capabilities, new ResponseDecision(
            MediaType::jsonLd(),
            ETag::strong('{}'),
            [LinkRelation::paging('next', self::organizationContainer() . '?limit=20&offset=20')]
        ));

        self::assertSame(
            [
                '<' . self::organizationIndex() . '>; rel="type"; type="application/ld+json"',
                '<' . self::organizationContainer() . '?limit=20&offset=20>; rel="next"',
            ],
            $headers->get('Link')
        );
    }

    public function testADuplicateLinkIsCollapsed(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::readOnlyContainer([
            LinkRelation::typeRelation(self::organizationIndex(), MediaType::JSON_LD),
            LinkRelation::typeRelation(self::organizationIndex(), MediaType::JSON_LD),
        ]));

        self::assertCount(1, $headers->get('Link'));
    }

    // -- Vary, Content-Type, ETag --------------------------------------------

    public function testVaryIsAlwaysAdvertised(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::readOnlyContainer());

        self::assertSame('Accept, Authorization', $headers->first('Vary'));
    }

    public function testACapabilityOnlyResponseHasNoContentTypeOrEtag(): void
    {
        $headers = self::headerPolicy()->describe(ResourceCapabilities::readOnlyContainer());

        // An OPTIONS response has no representation, so declaring one would be a lie.
        self::assertFalse($headers->has('Content-Type'));
        self::assertFalse($headers->has('ETag'));
    }

    public function testTheSelectedRepresentationAndItsTagArePassedThrough(): void
    {
        $etag = ETag::strong('{"@id":"x"}');

        $headers = self::headerPolicy()->describe(
            ResourceCapabilities::document(['GET', 'HEAD']),
            new ResponseDecision(MediaType::turtle(), $etag)
        );

        self::assertSame('text/turtle', $headers->first('Content-Type'));
        self::assertSame($etag->value(), $headers->first('ETag'));
    }

    public function testThePolicyCanBuildItsOwnDecision(): void
    {
        $policy = new HeaderPolicy();
        $decision = $policy->respond(
            ResourceCapabilities::document(['GET']),
            MediaType::jsonLd(),
            ETag::strong('{}'),
            [LinkRelation::paging('prev', self::organizationContainer())]
        );

        self::assertSame('application/ld+json', $decision->contentType()?->toString());
        self::assertCount(1, $decision->links());
    }

    public function testALocationIsCarriedForTheIdentityServiceRedirect(): void
    {
        $headers = self::headerPolicy()->describe(
            ResourceCapabilities::document(['GET', 'HEAD']),
            new ResponseDecision(null, null, [], 'https://platform.example/dfc/v2/users/abc/webid#me')
        );

        self::assertSame(
            'https://platform.example/dfc/v2/users/abc/webid#me',
            $headers->first('Location')
        );
    }

    public function testARelativeLocationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute http(s) URI');

        new ResponseDecision(null, null, [], '/users/abc/webid#me');
    }

    public function testAnUnrecognisedCacheControlDirectiveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no-store, no-cache');

        new ResponseDecision(null, null, [], null, 'public, s-maxage=100, stale-while-revalidate=60');
    }

    // -- The header map -------------------------------------------------------

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $headers = ResponseHeaders::create()->with('Content-Type', 'application/ld+json');

        self::assertTrue($headers->has('content-type'));
        self::assertSame('application/ld+json', $headers->first('CONTENT-TYPE'));
        self::assertSame(['Content-Type' => ['application/ld+json']], $headers->toArray());
    }

    public function testHeaderValuesAppendAndReplace(): void
    {
        $headers = ResponseHeaders::create()
            ->with('Link', '<a>; rel="next"')
            ->with('Link', '<b>; rel="prev"');

        self::assertCount(2, $headers->get('Link'));

        $replaced = $headers->withOnly('Link', '<c>; rel="first"');

        self::assertSame(['<c>; rel="first"'], $replaced->get('Link'));
        self::assertCount(2, $headers->get('Link'), 'withOnly() must not mutate the original.');
    }

    /**
     * Response splitting is the vulnerability this check exists for.
     */
    public function testACarriageReturnOrNewlineInAHeaderValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control character');

        ResponseHeaders::create()->with('Link', "<https://example.test/>; rel=\"next\"\r\nSet-Cookie: a=b");
    }

    public function testANulByteInAHeaderValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResponseHeaders::create()->with('ETag', "abc\0def");
    }

    public function testAnInvalidHeaderNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid HTTP header name');

        ResponseHeaders::create()->with('Link: injected', 'value');
    }

    public function testTheHeaderMapIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 32 distinct header fields');

        $headers = ResponseHeaders::create();
        for ($index = 0; $index < 33; $index++) {
            $headers = $headers->with('X-Test-' . $index, 'v');
        }
    }

    public function testAnEmptyHeaderValueIsLegal(): void
    {
        self::assertSame([''], ResponseHeaders::create()->with('X-Empty', '')->get('X-Empty'));
    }

    public function testHeadersRenderForLogging(): void
    {
        $headers = ResponseHeaders::create()
            ->with('Allow', 'GET, HEAD')
            ->with('Link', '<a>; rel="next"', '<b>; rel="prev"');

        self::assertSame(
            ['Allow: GET, HEAD', 'Link: <a>; rel="next"', 'Link: <b>; rel="prev"'],
            $headers->render()
        );
    }

    // -- ProtocolResponse -----------------------------------------------------

    public function testAResponseWithABodyMustDeclareAContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a Content-Type');

        new ProtocolResponse(200, ResponseHeaders::create(), '{"a":1}');
    }

    public function testAnEmptyBodyNeedsNoContentType(): void
    {
        $response = new ProtocolResponse(204, ResponseHeaders::create()->with('ETag', '"sha256-a"'));

        self::assertSame('', $response->body());
        self::assertSame('"sha256-a"', $response->etag());
        self::assertFalse($response->isError());
        self::assertNull($response->contentType());
    }

    public function testAnImpossibleStatusIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProtocolResponse(999, ResponseHeaders::create());
    }
}
