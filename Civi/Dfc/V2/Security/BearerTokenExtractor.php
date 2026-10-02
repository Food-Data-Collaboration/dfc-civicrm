<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

use Civi\Dfc\V2\Controller\Error\AuthenticateChallenge;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;

/**
 * RFC 6750 §2.1 `Authorization` header extraction, and nothing else.
 *
 * ============================================================================
 * TWO OUTCOMES, AND WHICH ERROR CODE EACH ONE GETS
 * ============================================================================
 * sa-005's error model splits 401 into two codes and this class is where that split
 * is decided:
 *
 *   - {@see \Civi\Dfc\V2\Controller\Error\ErrorCode::AUTHENTICATION_REQUIRED}
 *     "No usable credential was presented." That covers a missing header AND a
 *     malformed one: a header that is not RFC 6750 shaped is not a credential, so
 *     nothing usable was presented.
 *   - {@see \Civi\Dfc\V2\Controller\Error\ErrorCode::AUTHENTICATION_UNUSABLE}
 *     "A credential WAS presented and could not be accepted." That belongs to
 *     {@see AccessTokenValidator} — a well-formed token that fails validation.
 *
 * Splitting them here rather than at the call site is the point: a controller
 * cannot pick a status, per {@see \Civi\Dfc\V2\Controller\Error\ErrorCode}.
 *
 * ============================================================================
 * WHICH RFC 6750 `error` GOES WITH WHICH OUTCOME
 * ============================================================================
 *  - Missing header -> a PLAIN challenge, no `error` parameter. RFC 6750 §3 says a
 *    resource server that receives no token "SHOULD NOT include an error code";
 *    emitting `invalid_request` here teaches clients to expect a recoverable
 *    protocol error when the real answer is "there was no credential".
 *  - Malformed header -> `error="invalid_request"`. RFC 6750 §3.1: the request is
 *    "otherwise malformed". This covers a non-Bearer scheme, a missing space, an
 *    empty credentials parameter, several credentials parameters, a token with
 *    characters outside `b64token`, and a header containing a control character.
 *  - A well-formed token that fails validation -> `error="invalid_token"`, set by
 *    {@see BearerAuthenticator}.
 *
 * There is deliberately no `error_description`, and no echo of what was wrong:
 * {@see AuthenticateChallenge} documents that omission, and it is the right call —
 * "is my token expired or is my audience wrong?" is exactly the question an error
 * description invites an attacker to ask one value at a time.
 *
 * ============================================================================
 * THE GRAMMAR IS ENFORCED, NOT APPROXIMATED
 * ============================================================================
 * RFC 6750 §2.1 defines the credentials as
 * `1*( ALPHA / DIGIT / "-" / "." / "_" / "~" / "+" / "/" ) *"="`. A regex over that
 * exact character set, with the scheme compared case-insensitively per RFC 9110
 * §11.1 ("Bearer" and "bearer" are the same scheme).
 *
 * Being liberal about what this accepts is the wrong instinct here: a header this
 * class cannot parse is a header whose meaning is unknown, and
 * {@see \Civi\Dfc\V2\Controller\Error\ErrorCode::INVALID_HEADER}'s whole rationale
 * is that an unknown meaning must not be guessed at.
 *
 * @package Civi\Dfc
 */
final class BearerTokenExtractor
{
    /**
     * RFC 6750 §2.1 `b64token`.
     *
     * The trailing `=` is optional and repeated, which is what distinguishes this
     * from strict base64 padding: a token is `b64token` with any number of trailing
     * equals signs, including none.
     */
    private const B64TOKEN = '[A-Za-z0-9\-._~+/]+=*';

    /**
     * The `b64token` pattern, delimited.
     *
     * `#` and not `/`: the character class contains a solidus (it is one of the six
     * "unreserved-ish" characters RFC 6750 lists), so a `/`-delimited pattern would be
     * terminated by its own alphabet and silently match nothing. This is the same trap
     * {@see \Civi\Dfc\V2\Controller\Error\LeakGuard} documents for its own patterns.
     */
    private const B64TOKEN_PATTERN = '#^' . self::B64TOKEN . '$#';

    private readonly string $realm;

    /**
     * @param DfcReleaseConfig $config Used only for the challenge realm, which
     *                                RFC 9110 §11.6.1 requires and which
     *                                {@see \Civi\Dfc\V2\Controller\Error\ErrorMapper}
     *                                also uses. The token itself is not
     *                                DFC-version-aware.
     */
    public function __construct(DfcReleaseConfig $config)
    {
        // Validated once, here, because it is echoed into a response header on
        // every 401 this interface produces.
        $this->realm = AuthenticateChallenge::bearer($config->platformBaseUri())->realm();
    }

    /** The `realm` parameter of every challenge this extractor produces. */
    public function realm(): string
    {
        return $this->realm;
    }

    /**
     * Extract the bearer token, or fail with a protocol error.
     *
     * @param string|null $authorizationHeader The raw `Authorization` field value,
     *                                         or null when the header is absent.
     *
     * @throws DfcApiException 401 when no usable credential was presented.
     */
    public function extract(?string $authorizationHeader): BearerToken
    {
        if ($authorizationHeader === null || trim($authorizationHeader) === '') {
            // A plain challenge: no `error`. See the class docblock.
            throw DfcApiException::of(ProtocolError::authenticationRequired(
                AuthenticateChallenge::bearer($this->realm)
            ));
        }

        return BearerToken::of($this->readToken($authorizationHeader));
    }

    /**
     * Is there an `Authorization` header at all?
     *
     * For the anonymous-capable surfaces (a public WebID read), which must not turn
     * "no header" into a 401.
     */
    public function isAbsent(?string $authorizationHeader): bool
    {
        return $authorizationHeader === null || trim($authorizationHeader) === '';
    }

    /**
     * @throws DfcApiException
     */
    private function readToken(string $header): string
    {
        // RFC 9110 §5.5: a field value may be surrounded by optional whitespace (OWS),
        // which is SP and HTAB only. Strip exactly that, then refuse anything else
        // below 0x20 — a CR, LF or NUL inside a value is response-splitting or
        // request-smuggling material, and it cannot be a legitimate Bearer credential.
        //
        // Refused rather than sanitised: stripping a mid-value control character would
        // change the meaning of a header the client believes it sent correctly, and
        // "Bearer abc<TAB>def" must not become "Bearer abcdef".
        $value = trim($header, " \t");

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw $this->malformed();
        }

        // Exactly one SP, exactly two tokens. `^(\S+) (\S+)$` also rejects "Bearer",
        // "Bearer ", "Bearer  a", "Bearer a b" and "Bearer a, Bearer b", which are all
        // "more than one way to present a token" or "no token".
        //
        // RFC 7230 §3.2.2 allows multiple SP between the scheme and the token, so
        // collapsing runs of spaces before this test is a tolerance, not a laxity: the
        // result still has to be exactly one scheme and one b64token.
        $value = (string) preg_replace('/ +/', ' ', $value);

        if (preg_match('/^(\S+) (\S+)$/', $value, $m) !== 1) {
            throw $this->malformed();
        }

        [, $scheme, $credentials] = $m;

        // RFC 9110 §11.1: authentication schemes are case-insensitive.
        if (strcasecmp($scheme, 'Bearer') !== 0) {
            throw $this->malformed();
        }

        if (preg_match(self::B64TOKEN_PATTERN, $credentials) !== 1) {
            throw $this->malformed();
        }

        return $credentials;
    }

    /**
     * The single 401 for every malformed-header case.
     *
     * One place, so the challenge is byte-identical whatever the malformation: two
     * different malformed headers producing two different challenge bytes would let
     * a client (or an attacker) enumerate which malformation they hit.
     */
    private function malformed(): DfcApiException
    {
        return DfcApiException::of(ProtocolError::authenticationRequired(
            AuthenticateChallenge::bearer($this->realm, 'invalid_request')
        ));
    }
}