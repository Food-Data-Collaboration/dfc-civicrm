<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Error\ValidationIssue;
use Civi\Dfc\V2\Controller\Http\ResponseHeaders;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Security\DfcVersionHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `x-dfc-version` header, which the PRD itself did not mention.
 *
 * PRD-002 "Upstream refresh" item 3 and `api-coverage-manifest.yaml`
 * `surfaces.dfc_version_header`: required on every operation, validated against the
 * configured release, 422 on disagreement, echoed on the response.
 *
 * The interesting assertions are about the DIAGNOSTIC, because the 422 body is
 * built from sa-005's closed vocabulary and therefore has to be machine-readable
 * rather than prose.
 */
#[CoversClass(DfcVersionHeader::class)]
final class DfcVersionHeaderTest extends TestCase
{
    private DfcReleaseConfig $release;

    protected function setUp(): void
    {
        $this->release = OidcHarness::releaseConfig();
    }

    // -- Matching -------------------------------------------------------------

    public function testAMatchingVersionIsAccepted(): void
    {
        $header = DfcVersionHeader::validate('2.0.0', $this->release);

        self::assertSame('2.0.0', $header->requestedValue());
        self::assertSame('2.0.0', $header->value());
        self::assertSame('x-dfc-version', $header->name());
    }

    public function testTheConfiguredValueComesFromTheReleaseConfigNotTheHeader(): void
    {
        // A successful validation proves the two are equal, so echoing the configured
        // value can never echo a client-supplied string.
        $header = DfcVersionHeader::validate('2.0.0', $this->release);

        self::assertSame($this->release->dfcVersion(), $header->value());
    }

    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $header = DfcVersionHeader::validate("  2.0.0\t", $this->release);

        self::assertSame('2.0.0', $header->requestedValue());
    }

    public function testTheComparisonIsCaseSensitive(): void
    {
        // A version is a version: `2.0.0` and `2.0.0` with any other case are not the
        // same release, and accepting them would make the header's meaning ambiguous.
        $this->expectException(DfcApiException::class);

        DfcVersionHeader::validate('2.0.0-SNAPSHOT', $this->release);
    }

    // -- Missing --------------------------------------------------------------

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function missingHeaders(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \t "];
    }

    #[DataProvider('missingHeaders')]
    public function testAMissingHeaderIs422(?string $header): void
    {
        // See the class docblock: missing and mismatched are both 422 because they have
        // the same remedy. A client that got 400 for one and 422 for the other would
        // implement two error paths for one mistake.
        try {
            DfcVersionHeader::validate($header, $this->release);
            self::fail('A missing required header must be rejected.');
        } catch (DfcApiException $rejected) {
            $error = $rejected->error();

            self::assertSame(422, $error->status());
            self::assertSame(ErrorCode::VALIDATION_FAILED, $error->code());

            $diagnostics = $error->diagnostics();
            self::assertCount(1, $diagnostics);
            self::assertSame(ValidationIssue::REQUIRED, $diagnostics[0]->issue());
            self::assertSame('required', $diagnostics[0]->constraint());
            self::assertSame('x-dfc-version', $diagnostics[0]->predicate());
            self::assertSame('/x-dfc-version', $diagnostics[0]->path()->render());
        }
    }

    // -- Mismatched -----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function mismatchedVersions(): iterable
    {
        // The published specs and the manifest both say the header is REQUIRED on every
        // operation, so these are the versions a real client could plausibly send.
        yield 'older patch' => ['2.0.1'];
        yield 'older minor' => ['1.9.0'];
        yield 'v1 of the major' => ['1.0.0'];
        yield 'a different major' => ['3.0.0'];
        yield 'the next minor' => ['2.1.0'];
        yield 'nonsense' => ['not-a-version'];
        yield 'a bare major' => ['2'];
        yield 'a pre-release of this version' => ['2.0.0-rc1'];
    }

    #[DataProvider('mismatchedVersions')]
    public function testAMismatchedVersionIs422WithTheExpectedVersionInTheBody(string $version): void
    {
        try {
            DfcVersionHeader::validate($version, $this->release);
            self::fail(sprintf('"%s" must be rejected.', $version));
        } catch (DfcApiException $rejected) {
            $error = $rejected->error();

            self::assertSame(422, $error->status());
            self::assertSame(ErrorCode::VALIDATION_FAILED, $error->code());

            $diagnostics = $error->diagnostics();
            self::assertCount(1, $diagnostics);
            self::assertSame(ValidationIssue::NOT_A_MEMBER, $diagnostics[0]->issue());
            self::assertSame(
                'equals=2.0.0',
                $diagnostics[0]->constraint(),
                'The body must name the release this deployment serves.'
            );
        }
    }

    public function testTheMismatchBodyEchoesNothingTheClientSent(): void
    {
        // The 422 body is built from validated value objects, so the client's own
        // version string cannot appear in it. Which means a client cannot discover
        // which versions this deployment DOES serve by probing — it has to read the
        // `equals=` constraint.
        try {
            DfcVersionHeader::validate('9.9.9-probe', $this->release);
            self::fail('Must be rejected.');
        } catch (DfcApiException $rejected) {
            $json = $rejected->error()->toJson();

            self::assertStringNotContainsString('9.9.9', $json);
            self::assertStringContainsString('equals=2.0.0', $json);
        }
    }

    // -- Ambiguous ------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousHeaders(): iterable
    {
        // RFC 9110's list syntax means these really do arrive, and picking one of them
        // would be a guess about which release the client meant.
        yield 'comma separated' => ['2.0.0,1.9.0'];
        yield 'space separated' => ['2.0.0 1.9.0'];
        yield 'trailing comma' => ['2.0.0,'];
        yield 'leading comma' => [',2.0.0'];
        yield 'two matching' => ['2.0.0, 2.0.0'];
    }

    #[DataProvider('ambiguousHeaders')]
    public function testAnAmbiguousHeaderIs422RatherThanAGuess(string $header): void
    {
        try {
            DfcVersionHeader::validate($header, $this->release);
            self::fail(sprintf('"%s" is ambiguous and must be rejected.', $header));
        } catch (DfcApiException $rejected) {
            self::assertSame(422, $rejected->status());

            $diagnostics = $rejected->error()->diagnostics();
            self::assertCount(1, $diagnostics);
            self::assertSame(ValidationIssue::MALFORMED, $diagnostics[0]->issue());
            self::assertSame('single-token', $diagnostics[0]->constraint());
        }
    }

    // -- Echo -----------------------------------------------------------------

    public function testTheEchoHeaderIsAddedToAResponse(): void
    {
        $header = DfcVersionHeader::validate('2.0.0', $this->release);

        $headers = $header->echoOn(ResponseHeaders::create()->with('Content-Type', 'application/ld+json'));

        self::assertSame('2.0.0', $headers->first('x-dfc-version'));
        self::assertSame('application/ld+json', $headers->first('Content-Type'));
        self::assertSame('2.0.0', $headers->first('X-DFC-VERSION'), 'Lookups are case-insensitive.');
    }

    public function testEchoingDoesNotMutateTheOriginalHeaderSet(): void
    {
        // The same reason every other value object here returns a clone: a policy that
        // has computed a header must not be mutable by a caller that kept a reference.
        $header = DfcVersionHeader::validate('2.0.0', $this->release);
        $original = ResponseHeaders::create();

        $header->echoOn($original);

        self::assertNull($original->first('x-dfc-version'));
    }

    public function testTheConfiguredValueIsAvailableWithoutValidating(): void
    {
        // Error responses and unauthenticated discovery still advertise what this
        // deployment speaks, so the header is never absent from a reply.
        self::assertSame('2.0.0', DfcVersionHeader::configuredValue($this->release));
    }

    // -- The body -------------------------------------------------------------

    public function testTheRejectionIsRenderableAndLeaksNothing(): void
    {
        try {
            DfcVersionHeader::validate(null, $this->release);
            self::fail('Must be rejected.');
        } catch (DfcApiException $rejected) {
            $body = $rejected->error()->toArray();

            self::assertSame(422, $body['status']);
            self::assertSame('validation_failed', $body['code']);
            self::assertSame('/x-dfc-version', $body['path']);
            self::assertFalse($body['redacted']);
            self::assertJson($rejected->error()->toJson());

            // No challenge: a 422 is not a 401.
            self::assertNull($rejected->error()->challenge());
        }
    }

    public function testTheDiagnosticTextIsALiteralFromTheClosedVocabulary(): void
    {
        // There is no place in a ValidationDiagnostic for a sentence, which is the
        // point: the client gets one of three fixed texts plus a machine-readable
        // discriminator, never a server-authored explanation.
        try {
            DfcVersionHeader::validate('9.9.9', $this->release);
            self::fail('Must be rejected.');
        } catch (DfcApiException $rejected) {
            $diagnostic = $rejected->error()->diagnostics()[0];

            self::assertSame(
                ValidationIssue::NOT_A_MEMBER->title(),
                $diagnostic->toArray()['detail']
            );
        }
    }

    public function testTheAuditRecordNamesBothVersions(): void
    {
        $header = DfcVersionHeader::validate('2.0.0', $this->release);

        self::assertSame(
            ['name' => 'x-dfc-version', 'requested' => '2.0.0', 'configured' => '2.0.0'],
            $header->toArray()
        );
    }

    // -- Why the version is not in the URI either -----------------------------

    public function testTheVersionDoesNotAppearInAnyPlatformUri(): void
    {
        // DfcReleaseConfig rule R1: the version travels in `@context` and in this
        // header, never in a minted URI. Which is why a mismatch cannot be discovered
        // by resolving one — and why this header is not optional in practice.
        $uris = [
            $this->release->platformBaseUri(),
            $this->release->platformWebIdUri(),
            $this->release->identityServiceUri(),
            $this->release->semanticResourceBaseUri(),
        ];

        foreach ($uris as $uri) {
            // `dfc/v2` in the base path is the API VERSION of this interface, a
            // deployment routing decision — not the DFC release. The release is the
            // three-part triple, and that is what must not appear.
            self::assertStringNotContainsString('2.0.0', $uri);
        }

        // It IS in the context URL, which is where upstream puts it.
        self::assertStringContainsString('/v2.0.0/', $this->release->contextUrl());
    }
}