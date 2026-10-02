<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Policy;

use Civi\Dfc\Test\Security\JwtFactory;
use Civi\Dfc\Test\Security\JwtTestSupport;
use Civi\Dfc\Test\Security\OidcHarness;
use Civi\Dfc\V2\Policy\AuthorizationHeaderSource;
use Civi\Dfc\V2\Policy\AuthenticateStage;
use Civi\Dfc\V2\Policy\StaticAuthorizationHeader;
use Civi\Dfc\V2\Security\DfcAction;
use Civi\Dfc\V2\Security\DfcSurface;
use Civi\Dfc\V2\Security\JwksCache;
use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationResult;
use Civi\Dfc\V2\Validation\ValidationStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The AUTHENTICATION stage: the one line that joins the request pipeline to the
 * authenticator.
 *
 * Its reason for existing is that {@see \Civi\Dfc\V2\Security\BearerAuthenticator}
 * knows nothing about {@see ValidationContext}, and a stage that reads an HTTP
 * superglobal would be untestable without an HTTP request. The header arrives through
 * {@see AuthorizationHeaderSource} instead.
 *
 * @see \Civi\Dfc\Test\Security\BearerAuthenticatorTest for the authenticator itself
 */
#[CoversClass(AuthenticateStage::class)]
#[CoversClass(StaticAuthorizationHeader::class)]
#[CoversClass(AuthorizationHeaderSource::class)]
final class AuthenticateStageTest extends TestCase
{
    private const KID = 'authenticate-stage-kid';

    private OidcHarness $harness;

    protected function setUp(): void
    {
        $this->harness = OidcHarness::build(self::KID);
    }

    // -- The header source ----------------------------------------------------

    public function testAnAbsentHeaderIsAbsent(): void
    {
        self::assertFalse(StaticAuthorizationHeader::absent()->isPresent());
        self::assertNull(StaticAuthorizationHeader::absent()->headerFor('GET', 'https://platform.example/x'));
    }

    public function testAFixedHeaderIsReturnedForAnyRequest(): void
    {
        $source = StaticAuthorizationHeader::bearer('a.b.c');

        self::assertTrue($source->isPresent());
        self::assertSame('Bearer a.b.c', $source->headerFor('GET', 'https://platform.example/x'));
        self::assertSame('Bearer a.b.c', $source->headerFor('POST', 'https://platform.example/y'));
    }

    public function testTheSourceIsAskedForTheMethodAndUriOfTheRequestInHand(): void
    {
        // Which is what lets a deployment vary behaviour by request, which it needs to:
        // an anonymous-capable surface must not authenticate.
        $seen = new \stdClass();
        $seen->method = null;
        $seen->uri = null;

        $source = new class ($seen) implements AuthorizationHeaderSource {
            public function __construct(private \stdClass $seen)
            {
            }

            public function headerFor(string $method, string $requestUri): ?string
            {
                $this->seen->method = $method;
                $this->seen->uri = $requestUri;

                return 'Bearer ' . JwtFactory::accessToken(
                    // The SAME kid as every other test here: the harness publishes one
                    // key set, and a token under any other kid would be (correctly)
                    // rejected — which would make this test about key selection.
                    \Civi\Dfc\Test\Policy\AuthenticateStageTest::keyForStage(),
                    ['scope' => 'openid webid']
                );
            }
        };

        $stage = new AuthenticateStage($this->harness->authenticator, $source);
        $context = ValidationContext::read(OidcHarness::releaseConfig(), 'get', 'https://platform.example/dfc/v2/x');

        self::assertTrue($stage->run($context)->isPassed());
        self::assertSame('GET', $seen->method, 'The method is normalised by the context, not the source.');
        self::assertSame('https://platform.example/dfc/v2/x', $seen->uri);
    }

    /**
     * The kid the header source above mints tokens under.
     *
     * Public because a nested anonymous class cannot reach the enclosing instance, and
     * a protected property would be mutable state on a test object.
     */
    public static function keyForStage(): string
    {
        return 'authenticate-stage-kid';
    }

    // -- Success --------------------------------------------------------------

    public function testAValidTokenAuthenticatesAndIsThreadedOntoTheContext(): void
    {
        $stage = new AuthenticateStage(
            $this->harness->authenticator,
            StaticAuthorizationHeader::bearer(JwtFactory::accessToken(self::KID, [
                'scope' => 'openid ReadEnterprise webid',
            ]))
        );

        $context = ValidationContext::read(OidcHarness::releaseConfig(), 'GET', 'https://platform.example/x');
        $result = $stage->run($context);

        self::assertTrue($result->isPassed());
        self::assertSame(ValidationStage::AUTHENTICATION, $result->stage());

        $threaded = $result->nextContext();
        self::assertNotNull($threaded);

        $principal = $threaded->principal();
        self::assertNotNull($principal);
        self::assertSame(JwtTestSupport::SUBJECT, $principal->subject());
        self::assertTrue($principal->isAllowed(DfcSurface::WEBID, DfcAction::READ));
    }

    public function testTheOriginalContextIsNotMutated(): void
    {
        $stage = new AuthenticateStage(
            $this->harness->authenticator,
            StaticAuthorizationHeader::bearer(JwtFactory::accessToken(self::KID, ['scope' => 'webid']))
        );

        $context = ValidationContext::read(OidcHarness::releaseConfig(), 'GET', 'https://platform.example/x');
        $stage->run($context);

        self::assertNull($context->principal());
    }

    // -- Failure --------------------------------------------------------------

    public function testAMissingCredentialIsA401ResultRatherThanAnException(): void
    {
        // The pipeline short-circuits through one path, so a caller needs one code path
        // rather than two.
        $stage = new AuthenticateStage($this->harness->authenticator, StaticAuthorizationHeader::absent());

        $result = $stage->run(ValidationContext::read(
            OidcHarness::releaseConfig(),
            'GET',
            'https://platform.example/x'
        ));

        self::assertTrue($result->isFailed());
        self::assertSame(ValidationStage::AUTHENTICATION, $result->stage());
        self::assertSame(401, $result->error()->status());
        self::assertSame('authentication_required', $result->error()->codeValue());
    }

    public function testARejectedTokenIsA401WithInvalidToken(): void
    {
        $stage = new AuthenticateStage(
            $this->harness->authenticator,
            StaticAuthorizationHeader::bearer(JwtFactory::accessToken(self::KID, [
                'iss' => 'https://evil.example/realms/dev',
            ]))
        );

        $result = $stage->run(ValidationContext::read(
            OidcHarness::releaseConfig(),
            'GET',
            'https://platform.example/x'
        ));

        self::assertTrue($result->isFailed());
        self::assertSame(401, $result->error()->status());
        self::assertSame('authentication_unusable', $result->error()->codeValue());
        self::assertSame('invalid_token', $result->error()->challenge()?->error());
    }

    public function testAnIdTokenIsRejectedAtTheStage(): void
    {
        $stage = new AuthenticateStage(
            $this->harness->authenticator,
            StaticAuthorizationHeader::bearer(JwtFactory::accessToken(self::KID, null, ['typ' => 'ID']))
        );

        $result = $stage->run(ValidationContext::read(
            OidcHarness::releaseConfig(),
            'GET',
            'https://platform.example/x'
        ));

        self::assertTrue($result->isFailed());
        self::assertSame('authentication_unusable', $result->error()->codeValue());
    }

    public function testARejectedResultCarriesNoNextContext(): void
    {
        $stage = new AuthenticateStage($this->harness->authenticator, StaticAuthorizationHeader::absent());

        $result = $stage->run(ValidationContext::read(
            OidcHarness::releaseConfig(),
            'GET',
            'https://platform.example/x'
        ));

        self::assertNull($result->nextContext(), 'A rejected stage threads nothing forward.');
    }

    // -- The 403 this layer owns ---------------------------------------------

    public function testThePermissionDeniedErrorIsA403WithNoChallenge(): void
    {
        // "Authenticated but not authorised" is produced by the same layer that
        // authenticated, so ProtocolError::permissionDenied() is the only 403 on this
        // surface — and it is deliberately NOT a 404.
        $stage = new AuthenticateStage($this->harness->authenticator, StaticAuthorizationHeader::absent());

        $forbidden = $stage->permissionDenied();

        self::assertSame(403, $forbidden->status());
        self::assertSame('permission_denied', $forbidden->codeValue());
        self::assertNull($forbidden->challenge());
        self::assertSame([], $forbidden->headers());
    }

    // -- The 401 versus 403 distinction, at the stage -------------------------

    public function testAMissingScopeAuthenticatesAndArrivesWithNoGrant(): void
    {
        // THE 401/403 boundary. A valid token with no data scope is AUTHENTICATED, so the
        // run passes and the AUTHORIZATION stage produces the 403. Answering 401 here
        // would send the client to fetch a new token, which would have the same scopes,
        // which would produce the same 401, forever.
        $stage = new AuthenticateStage(
            $this->harness->authenticator,
            StaticAuthorizationHeader::bearer(JwtFactory::accessToken(self::KID, [
                'scope' => 'openid profile email',
            ]))
        );

        $context = ValidationContext::read(OidcHarness::releaseConfig(), 'GET', 'https://platform.example/x');
        $result = $stage->run($context);

        self::assertTrue($result->isPassed(), 'Authentication succeeded: this must not be a 401.');
        self::assertTrue($result->nextContext()?->principal()?->permissions()->isEmpty());
    }

    public function testTheJwksCacheIsSharedSoAStageDoesNotRefetch(): void
    {
        // Asserted rather than assumed: the harness's cache is the one every request
        // uses, and its counters are public for exactly this.
        $stage = new AuthenticateStage(
            $this->harness->authenticator,
            StaticAuthorizationHeader::bearer(JwtFactory::accessToken(self::KID, ['scope' => 'webid']))
        );

        for ($i = 0; $i < 3; $i++) {
            $stage->run(ValidationContext::read(
                OidcHarness::releaseConfig(),
                'GET',
                'https://platform.example/x'
            ));
        }

        self::assertSame(1, $this->harness->fetcher->callCount());
        self::assertInstanceOf(JwksCache::class, $this->harness->jwks);
    }
}