<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Security;

use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Security\AuthenticatedPrincipal;
use Civi\Dfc\V2\Security\BearerAuthenticator;
use Civi\Dfc\V2\Security\DfcAction;
use Civi\Dfc\V2\Security\DfcSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Authorization` header in, principal or protocol error out.
 *
 * ============================================================================
 * THE CASES THIS ASSERTS ARE THE ONES CP-3 NAMES
 * ============================================================================
 * "insufficient scope" and "valid identity but unauthorized CiviCRM permission" — the
 * two ends of the authorisation story, and the distinction between them is a status
 * code: 403, never 401. A client with a valid token that lacks a scope must not be
 * told to get a new token, because a new token would not help.
 *
 * @see ScopePermissionRegistryTest for the mapping itself
 * @see AccessTokenValidatorTest for the claim checks
 */
#[CoversClass(BearerAuthenticator::class)]
#[CoversClass(AuthenticatedPrincipal::class)]
final class BearerAuthenticatorTest extends TestCase
{
    private const KID = 'authenticator-kid';

    private const READER_SCOPES = 'openid ReadEnterprise webid';

    // -- Success --------------------------------------------------------------

    public function testAValidTokenYieldsAPrincipalWithItsPermissions(): void
    {
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->authenticate(
            'Bearer ' . JwtFactory::accessToken(self::KID, ['scope' => self::READER_SCOPES])
        );

        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        self::assertSame(JwtTestSupport::SUBJECT, $principal->subject());
        self::assertSame(JwtTestSupport::REALM, $principal->issuer());

        self::assertTrue($principal->isAllowed(DfcSurface::ORGANIZATION, DfcAction::READ));
        self::assertTrue($principal->isAllowed(DfcSurface::WEBID, DfcAction::READ));
        self::assertFalse($principal->isAllowed(DfcSurface::ORGANIZATION, DfcAction::WRITE));
    }

    public function testThePermissionsComeFromTheRegistryNotFromTheToken(): void
    {
        // The principal must not be able to carry a permission the registry did not
        // derive from the token's scopes.
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->authenticate(
            'Bearer ' . JwtFactory::accessToken(self::KID, ['scope' => 'openid profile email'])
        );

        foreach (DfcSurface::all() as $surface) {
            self::assertFalse(
                $principal->isAllowed($surface, DfcAction::READ),
                $surface->value
            );
        }
    }

    // -- 401 versus 403, the distinction that matters -------------------------

    public function testAMissingCredentialIs401AuthenticationRequired(): void
    {
        $harness = OidcHarness::build(self::KID);

        $this->expectException(\Civi\Dfc\V2\Controller\Error\DfcApiException::class);

        $harness->authenticator->authenticate(null);
    }

    public function testAMalformedHeaderIs401AuthenticationRequired(): void
    {
        $harness = OidcHarness::build(self::KID);

        try {
            $harness->authenticator->authenticate('Basic dXNlcjpwYXNz');
            self::fail('A non-Bearer scheme must be rejected.');
        } catch (\Civi\Dfc\V2\Controller\Error\DfcApiException $rejected) {
            self::assertSame(401, $rejected->status());
            self::assertSame(ErrorCode::AUTHENTICATION_REQUIRED, $rejected->error()->code());
            self::assertStringContainsString(
                'invalid_request',
                $rejected->error()->challenge()?->headerValue() ?? ''
            );
        }
    }

    public function testARejectedTokenIs401AuthenticationUnusableWithInvalidToken(): void
    {
        // The distinction from a malformed header: something WAS presented and could not
        // be accepted, so the client's remedy is a fresh token.
        $harness = OidcHarness::build(self::KID);

        try {
            $harness->authenticator->authenticate(
                'Bearer ' . JwtFactory::accessToken(self::KID, ['iss' => 'https://evil.example/realms/dev'])
            );
            self::fail('A wrong issuer must be rejected.');
        } catch (\Civi\Dfc\V2\Controller\Error\DfcApiException $rejected) {
            self::assertSame(401, $rejected->status());
            self::assertSame(ErrorCode::AUTHENTICATION_UNUSABLE, $rejected->error()->code());

            $challenge = $rejected->error()->challenge();
            self::assertNotNull($challenge);
            self::assertSame('invalid_token', $challenge->error());
        }
    }

    public function testAnIdTokenIs401NotAccepted(): void
    {
        $harness = OidcHarness::build(self::KID);

        try {
            $harness->authenticator->authenticate(
                'Bearer ' . JwtFactory::accessToken(self::KID, null, ['typ' => 'ID'])
            );
            self::fail('An ID token must not be accepted as an API credential.');
        } catch (\Civi\Dfc\V2\Controller\Error\DfcApiException $rejected) {
            self::assertSame(401, $rejected->status());
            self::assertSame('authentication_unusable', $rejected->codeValue());
        }
    }

    /**
     * ============================================================================
     * MISSING SCOPE IS 403, NOT 401
     * ============================================================================
     * The single most consequential status decision on this surface, and the reason
     * {@see \Civi\Dfc\V2\Controller\Error\ErrorCode} splits 401 into two codes at all.
     *
     * A client whose token is perfectly valid but lacks `ReadEnterprise` must be told
     * "permission denied". If it were told 401, the correct client behaviour is to
     * fetch a new token and retry — which will produce the same token with the same
     * scopes, which will produce the same 401, forever.
     *
     * Note this authenticator does not itself emit the 403: it establishes the identity
     * and the grant, and the AUTHORIZATION stage (lane-3, which owns the CiviCRM
     * permission check) decides. What is asserted here is that a scope-less token
     * authenticates SUCCESSFULLY and arrives with an empty grant, so the 403 is
     * reachable rather than being short-circuited into a 401.
     */
    public function testAValidTokenWithNoDataScopeAuthenticatesButGrantsNothing(): void
    {
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->authenticate(
            'Bearer ' . JwtFactory::accessToken(self::KID, ['scope' => 'openid profile email'])
        );

        // Authentication SUCCEEDED — no 401, no exception.
        self::assertSame(JwtTestSupport::SUBJECT, $principal->subject());

        // And it grants nothing, so the AUTHORIZATION stage will produce the 403.
        self::assertTrue($principal->permissions()->isEmpty());
        self::assertFalse($principal->isAllowed(DfcSurface::ORGANIZATION, DfcAction::READ));
        self::assertFalse($principal->isAllowed(DfcSurface::WEBID, DfcAction::READ));
    }

    public function testAWebidOnlyTokenCannotReadOrganizations(): void
    {
        // The finer-grained case: a token that DOES grant something, but not on the
        // surface being asked about. Still authenticated; still no data.
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->authenticate(
            'Bearer ' . JwtFactory::accessToken(self::KID, ['scope' => 'openid webid'])
        );

        self::assertTrue($principal->isAllowed(DfcSurface::WEBID, DfcAction::READ));
        self::assertFalse($principal->isAllowed(DfcSurface::ORGANIZATION, DfcAction::READ));
    }

    public function testUnmappedScopesAreCarriedOntoThePrincipalForAudit(): void
    {
        // The "denied silently" failure mode: a client asking for a scope nobody mapped
        // must leave evidence.
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->authenticate(
            'Bearer ' . JwtFactory::accessToken(self::KID, [
                'scope' => 'openid webid a-scope-from-2030',
            ])
        );

        self::assertSame(['a-scope-from-2030'], $principal->permissions()->unmappedScopes());
        self::assertTrue($principal->isAllowed(DfcSurface::WEBID, DfcAction::READ));
    }

    // -- 500 when the issuer cannot be reached --------------------------------

    public function testAnUnobtainableKeySetIsA500WithNoChallenge(): void
    {
        // NOT a 401. This server cannot say the token is bad; it can only say it cannot
        // check it. Answering "invalid_token" would be a lie, and a useful one for an
        // attacker timing IdP outages.
        $harness = OidcHarness::build(self::KID);
        $harness->fetcher->failNext();

        try {
            $harness->authenticator->authenticate('Bearer ' . JwtFactory::accessToken(self::KID));
            self::fail('The token could not be verified, so it must not be accepted.');
        } catch (\Civi\Dfc\V2\Controller\Error\DfcApiException $rejected) {
            self::assertSame(500, $rejected->status());
            self::assertSame('internal_error', $rejected->codeValue());
            self::assertNull($rejected->error()->challenge(), 'A 500 must not carry a challenge.');
            self::assertStringNotContainsString('unavailable', $rejected->error()->toJson());
        }
    }

    // -- Anonymous-capable surfaces -------------------------------------------

    public function testOptionalReturnsNullWhenNoCredentialWasPresented(): void
    {
        $harness = OidcHarness::build(self::KID);

        self::assertNull($harness->authenticator->optional(null));
        self::assertNull($harness->authenticator->optional(''));
    }

    public function testOptionalStillRejectsABadCredentialThatWasPresented(): void
    {
        // Silently ignoring a claim that cannot be verified is how "this resource is
        // public" turns into "public unless you hold a token, in which case your token
        // is not checked".
        $harness = OidcHarness::build(self::KID);

        try {
            $harness->authenticator->optional('Bearer not.a.valid.jwt');
            self::fail('A presented credential must be checked.');
        } catch (\Civi\Dfc\V2\Controller\Error\DfcApiException $rejected) {
            self::assertSame(401, $rejected->status());
        }
    }

    public function testOptionalReturnsThePrincipalForAValidCredential(): void
    {
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->optional(
            'Bearer ' . JwtFactory::accessToken(self::KID, ['scope' => 'webid'])
        );

        self::assertNotNull($principal);
        self::assertTrue($principal->isAllowed(DfcSurface::WEBID, DfcAction::READ));
    }

    // -- The insufficient-scope challenge -------------------------------------

    public function testTheInsufficientScopeChallengeIsBuildableButNotAttachableToA403(): void
    {
        // RFC 6750 §3.1 defines `insufficient_scope` for exactly this surface, and it
        // cannot be used: ProtocolError only permits a WWW-Authenticate on a 401. The
        // challenge is therefore buildable for the 401 path and unreachable from the
        // 403 path — a documented asymmetry, reported rather than worked around.
        $harness = OidcHarness::build(self::KID);

        $challenge = $harness->authenticator->insufficientScopeChallenge();

        self::assertSame('insufficient_scope', $challenge->error());
        self::assertStringContainsString(
            'insufficient_scope',
            $challenge->headerValue()
        );

        // The 403 a route actually produces carries no challenge...
        $forbidden = \Civi\Dfc\V2\Controller\Error\ProtocolError::permissionDenied();
        self::assertSame(403, $forbidden->status());
        self::assertNull($forbidden->challenge());
        self::assertSame([], $forbidden->headers());

        // ...and attaching one to a non-401 is refused by the error model itself, not
        // merely left unattached. This is the invariant that makes the asymmetry above
        // structural rather than accidental.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MUST NOT carry a WWW-Authenticate/');

        new \Civi\Dfc\V2\Controller\Error\ProtocolError(
            \Civi\Dfc\V2\Controller\Error\ErrorCode::PERMISSION_DENIED,
            null,
            [],
            null,
            $challenge
        );
    }

    // -- No leakage ------------------------------------------------------------

    public function testNoRejectionBodyContainsTheTokenOrAnyServerDetail(): void
    {
        $harness = OidcHarness::build(self::KID);

        $rejections = [
            null,
            'Basic x',
            'Bearer ' . JwtFactory::accessToken(self::KID, ['iss' => 'https://evil.example/x']),
            'Bearer ' . JwtFactory::unsignedToken(JwtFactory::claims()),
            'Bearer not.a.jwt',
        ];

        foreach ($rejections as $header) {
            try {
                $harness->authenticator->authenticate($header);
                self::fail('Every one of these must be rejected.');
            } catch (\Civi\Dfc\V2\Controller\Error\DfcApiException $rejected) {
                $json = $rejected->error()->toJson();
                $rendered = $rejected->error()->toArray();

                self::assertJson($json);
                self::assertFalse($rendered['redacted'], 'Nothing here should have needed redaction.');
                self::assertStringNotContainsString('evil.example', $json);
                self::assertStringNotContainsString('openssl', $json);
                self::assertStringNotContainsString(self::KID, $json);
                self::assertStringNotContainsString(JwtTestSupport::SUBJECT, $json);
                self::assertStringNotContainsString('.php', $json);
            }
        }
    }

    public function testThePrincipalAuditRecordCarriesNoToken(): void
    {
        $harness = OidcHarness::build(self::KID);

        $principal = $harness->authenticator->authenticate(
            'Bearer ' . JwtFactory::accessToken(self::KID, ['scope' => self::READER_SCOPES])
        );

        $rendered = print_r($principal->toArray(), true);

        self::assertStringContainsString(JwtTestSupport::SUBJECT, $rendered);
        self::assertStringNotContainsString('.', substr($rendered, 0, 0) . 'eyJ');
        self::assertStringNotContainsString('BEGIN', $rendered);
    }
}