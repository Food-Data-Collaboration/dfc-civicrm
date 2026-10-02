<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Controller\Error;

use Civi\Dfc\Test\Controller\ControllerFixtureTrait;
use Civi\Dfc\Test\Controller\ControllerFixtures;
use Civi\Dfc\V2\Controller\Error\AuthenticateChallenge;
use Civi\Dfc\V2\Controller\Error\CorrelationId;
use Civi\Dfc\V2\Controller\Error\DfcApiException;
use Civi\Dfc\V2\Controller\Error\DfcPath;
use Civi\Dfc\V2\Controller\Error\DfcProtocolException;
use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Controller\Error\ErrorResponder;
use Civi\Dfc\V2\Controller\Error\LeakGuard;
use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Controller\Error\ValidationDiagnostic;
use Civi\Dfc\V2\Controller\Error\ValidationIssue;
use Civi\Dfc\V2\Controller\Http\ProtocolResponse;
use Civi\Dfc\V2\Controller\Negotiation\MediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single error model: every mandated status code, the invariants that hold
 * across all of them, and — the point of the whole layer — the proof that
 * internal detail cannot reach the wire.
 *
 * @see ErrorMapperTest for the throwable-to-error mapping
 * @see DfcPathTest for the token rules that make the primary control possible
 */
#[CoversClass(ProtocolError::class)]
#[CoversClass(ErrorCode::class)]
#[CoversClass(DfcApiException::class)]
#[CoversClass(ValidationDiagnostic::class)]
#[CoversClass(ValidationIssue::class)]
final class ProtocolErrorTest extends TestCase
{
    use ControllerFixtureTrait;

    /**
     * A message shaped exactly like the failures CP-1 forbids: a SQLSTATE, a
     * table name with its schema, a SELECT statement, two absolute file paths, two
     * line numbers, a stack-frame marker, a CiviCRM DAO class, and an integer id.
     */
    private const HOSTILE_EXCEPTION_MESSAGE = <<<'SQL'
        SQLSTATE[42S02]: Base table or view not found: 1146 Table
        'civicrm.civicrm_dfc_identity' doesn't exist
        SELECT semantic_identifier FROM civicrm_dfc_identity WHERE id = 12345
        #0 /var/www/html/sites/default/files/civicrm/db/civi.sql(214): CRM_Core_DAO->query()
        #1 {main}
          thrown in /var/www/html/sites/default/files/civicrm/Controller.php on line 214 for contact id 12345
        SQL;

    // -- Every mandated status code ------------------------------------------

    /**
     * @return iterable<string, array{ProtocolError, int, string}>
     */
    public static function statusCodeProvider(): iterable
    {
        $challenge = static fn (): AuthenticateChallenge => AuthenticateChallenge::bearer(
            ControllerFixtures::BASE_URI,
            'invalid_token'
        );

        yield '400 malformed JSON-LD' => [
            ProtocolError::malformedJsonLd(),
            400,
            ErrorCode::MALFORMED_JSON_LD->value,
        ];

        yield '400 malformed request' => [
            ProtocolError::invalidRequest(),
            400,
            ErrorCode::INVALID_REQUEST->value,
        ];

        yield '400 malformed header' => [
            ProtocolError::invalidHeader(),
            400,
            ErrorCode::INVALID_HEADER->value,
        ];

        yield '401 missing authentication' => [
            ProtocolError::authenticationRequired($challenge()),
            401,
            ErrorCode::AUTHENTICATION_REQUIRED->value,
        ];

        yield '401 unusable authentication' => [
            ProtocolError::authenticationUnusable($challenge()),
            401,
            ErrorCode::AUTHENTICATION_UNUSABLE->value,
        ];

        yield '403 authenticated but not authorized' => [
            ProtocolError::permissionDenied(),
            403,
            ErrorCode::PERMISSION_DENIED->value,
        ];

        yield '404 unknown resource' => [
            ProtocolError::notFound(),
            404,
            ErrorCode::RESOURCE_NOT_FOUND->value,
        ];

        yield '404 excluded product/order class' => [
            ProtocolError::outOfScope(),
            404,
            ErrorCode::RESOURCE_OUT_OF_SCOPE->value,
        ];

        yield '409 semantic-ID conflict' => [
            ProtocolError::semanticIdConflict(),
            409,
            ErrorCode::SEMANTIC_ID_CONFLICT->value,
        ];

        yield '409 relationship conflict' => [
            ProtocolError::relationshipConflict(),
            409,
            ErrorCode::RELATIONSHIP_CONFLICT->value,
        ];

        yield '412 failed If-Match' => [
            ProtocolError::preconditionFailed(),
            412,
            ErrorCode::PRECONDITION_FAILED->value,
        ];

        yield '415 unsupported media type' => [
            ProtocolError::unsupportedMediaType([MediaType::JSON_LD, MediaType::TURTLE]),
            415,
            ErrorCode::UNSUPPORTED_MEDIA_TYPE->value,
        ];

        yield '422 semantically invalid DFC resource' => [
            ProtocolError::unprocessable([
                new ValidationDiagnostic(DfcPath::root(), 'dfc-b:name', ValidationIssue::REQUIRED, 'minCount>=1'),
            ]),
            422,
            ErrorCode::VALIDATION_FAILED->value,
        ];

        yield '500 unexpected server failure' => [
            ProtocolError::internalError(),
            500,
            ErrorCode::INTERNAL_ERROR->value,
        ];

        yield '500 inconsistent membership' => [
            ProtocolError::membershipInconsistent(),
            500,
            ErrorCode::MEMBERSHIP_INCONSISTENT->value,
        ];
    }

    #[DataProvider('statusCodeProvider')]
    public function testEveryMandatedStatusCodeIsReachable(
        ProtocolError $error,
        int $expectedStatus,
        string $expectedCode
    ): void {
        self::assertSame($expectedStatus, $error->status());
        self::assertSame($expectedCode, $error->codeValue());
    }

    /**
     * The nine statuses PRD-002 CP-1 names, and nothing else.
     *
     * Asserted against the CODE enum rather than against the individual errors, so
     * adding a code without deciding its status — or quietly changing one — fails
     * here rather than at a review.
     */
    public function testTheMandatedStatusSetIsExactlyTheseNine(): void
    {
        $statuses = array_map(
            static fn (ErrorCode $code): int => $code->status(),
            ErrorCode::cases()
        );
        $unique = array_values(array_unique($statuses));
        sort($unique);

        self::assertSame([400, 401, 403, 404, 409, 412, 415, 422, 500], $unique);
    }

    /**
     * A client branches on the CODE, so the code must never be a restatement of the
     * status — and two codes sharing one status is the expected case, not a smell.
     */
    public function testTheCodeIsDistinctFromTheStatus(): void
    {
        foreach (ErrorCode::cases() as $code) {
            self::assertNotSame(
                (string) $code->status(),
                $code->value,
                sprintf('The code "%s" must not simply restate its status.', $code->value)
            );
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $code->value,
                'Error codes are lowercase snake_case tokens: they appear in client switch statements.'
            );
        }
    }

    public function testSeveralCodesShareAStatusBecauseTheyMeanDifferentThingsToAClient(): void
    {
        $byStatus = [];
        foreach (ErrorCode::cases() as $code) {
            $byStatus[$code->status()][] = $code->value;
        }

        // The whole reason the code exists separately from the status: two
        // different remedies under one number.
        self::assertCount(2, $byStatus[401]);
        self::assertCount(2, $byStatus[409]);
        self::assertCount(2, $byStatus[500]);
    }

    public function testEveryCodeHasADistinctProblemType(): void
    {
        $types = array_map(
            static fn (ErrorCode $code): string => $code->problemType(),
            ErrorCode::cases()
        );

        self::assertSame($types, array_values(array_unique($types)));
        self::assertStringStartsWith('urn:dfc-civicrm:error:', $types[0]);
    }

    public function testTitlesAndDetailsAreFixedTextWithNoInterpolation(): void
    {
        foreach (ErrorCode::cases() as $code) {
            // A `%` in a detail is an unfulfilled — or, worse, fulfilled —
            // sprintf placeholder waiting for a caller to supply the value.
            self::assertStringNotContainsString(
                '%',
                $code->title() . $code->detail(),
                sprintf('The text for "%s" must contain no format placeholders.', $code->value)
            );
        }
    }

    // -- 401 invariants -------------------------------------------------------

    public function testA401WithoutAChallengeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MUST carry a WWW-Authenticate challenge');

        new ProtocolError(ErrorCode::AUTHENTICATION_REQUIRED);
    }

    public function testA401CarriesTheChallengeAsAHeader(): void
    {
        $challenge = AuthenticateChallenge::bearer(ControllerFixtures::BASE_URI, 'invalid_token');
        $error = ProtocolError::authenticationUnusable($challenge);

        self::assertSame(
            ['WWW-Authenticate' => 'Bearer realm="' . ControllerFixtures::BASE_URI . '", error="invalid_token"'],
            $error->headers()
        );
    }

    public function testANon401MayNotCarryAChallenge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MUST NOT carry a WWW-Authenticate challenge');

        new ProtocolError(
            ErrorCode::PERMISSION_DENIED,
            null,
            [],
            null,
            AuthenticateChallenge::bearer(ControllerFixtures::BASE_URI)
        );
    }

    public function testANon401HasNoHeaders(): void
    {
        self::assertSame([], ProtocolError::notFound()->headers());
    }

    public function testTheChallengeRealmMustBeAnAbsoluteHttpsUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute https URI');

        AuthenticateChallenge::bearer('dfc/v2?realm=1');
    }

    public function testTheChallengeErrorMustComeFromTheRfc6750Set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RFC 6750 challenge error');

        AuthenticateChallenge::bearer(ControllerFixtures::BASE_URI, 'your_token_expired');
    }

    // -- THE INFORMATION-LEAK GUARANTEE ---------------------------------------

    /**
     * PRIMARY CONTROL: an exception's text never reaches the wire.
     *
     * This is the assertion PRD-002 CP-1 asks for by name. Every one of these
     * substrings is asserted ABSENT from the rendered response: the SQLSTATE, the
     * statement, the schema and table name, both absolute paths, both line
     * numbers, the frame marker, the DAO class name, the exception class name and
     * the integer id.
     */
    public function testThrowableTextNeverReachesTheWire(): void
    {
        $response = self::responder()->response(new \PDOException(self::HOSTILE_EXCEPTION_MESSAGE));
        $body = $response->body();
        $decoded = self::decode($body);

        self::assertSame(500, $response->status());
        self::assertSame(ErrorCode::INTERNAL_ERROR->value, $decoded['code']);
        self::assertSame(ErrorCode::INTERNAL_ERROR->detail(), $decoded['detail']);

        foreach (self::forbiddenSubstrings() as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $body,
                sprintf('"%s" must not appear in an error response.', $forbidden)
            );
        }
    }

    /**
     * SECONDARY CONTROL: even a deliberately hostile value in an otherwise-valid
     * slot is redacted by the serialiser, and the redaction is REPORTED.
     *
     * Every `DfcPath` token here is individually valid, which is what makes this a
     * real test of the serialiser stage rather than of the token validator.
     */
    public function testTheSerialiserRedactsInternalLookingContentAndSaysSo(): void
    {
        $error = ProtocolError::unprocessable([
            new ValidationDiagnostic(
                DfcPath::fromTokens('etc', 'civicrm', 'crm.ini'),
                'dfc-b:name',
                ValidationIssue::MALFORMED
            ),
        ]);

        $body = $error->toJson();

        self::assertTrue(
            self::decode($body)['redacted'],
            'The serialiser must report that it removed something, or the incident leaves no evidence.'
        );
        self::assertStringContainsString(LeakGuard::REDACTION, $body);
        self::assertStringNotContainsString('crm.ini', $body);
        self::assertStringNotContainsString('/etc/', $body);
    }

    /**
     * The redaction stage does not damage a legitimate DFC payload.
     *
     * This is the test that keeps the denylist honest, and it is why the absolute
     * path signature requires a FILE EXTENSION: `/organizations/0/name` is a real
     * DFC path, so a prefix-based path rule would redact correct output on every
     * 422. Requiring an extension means the rule fires only on something that is
     * certainly a filesystem reference.
     */
    public function testLegitimateDfcPayloadsSurviveTheGuardUnchanged(): void
    {
        $error = ProtocolError::unprocessable([
            new ValidationDiagnostic(
                DfcPath::fromTokens('organizations', '0', 'dfc-b:legalName'),
                'dfc-b:legalName',
                ValidationIssue::REQUIRED,
                'minCount>=1'
            ),
        ], DfcPath::fromTokens('organizations', '1'), self::correlationId());

        $decoded = self::decode($error->toJson());

        self::assertFalse($decoded['redacted']);
        self::assertSame('/organizations/0/dfc-b:legalName', $decoded['violations'][0]['path']);
        self::assertSame('dfc-b:legalName', $decoded['violations'][0]['predicate']);
        self::assertSame('minCount>=1', $decoded['violations'][0]['constraint']);
        self::assertSame('/organizations/1', $decoded['path']);
        self::assertSame(ControllerFixtures::CORRELATION_ID, $decoded['correlationId']);
    }

    /**
     * There is no slot in {@see ProtocolError} that accepts free text, and this is
     * how that is checked rather than merely claimed: reflection over the
     * constructor.
     *
     * If a future lane adds a `$message`/`$reason`/`$context`/`$debug` parameter,
     * this fails on the spot rather than at a security review.
     */
    public function testTheErrorTypeHasNoFreeTextConstructorParameter(): void
    {
        $constructor = (new \ReflectionClass(ProtocolError::class))->getConstructor();

        self::assertNotNull($constructor, 'ProtocolError must keep an explicit constructor.');

        $allowed = ['code', 'path', 'diagnostics', 'correlationId', 'challenge', 'supportedMediaTypes'];

        $parameters = $constructor->getParameters();

        self::assertSame(
            $allowed,
            array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters)
        );

        foreach ($parameters as $parameter) {
            self::assertNotContains(
                $parameter->getName(),
                ['message', 'reason', 'detail', 'context', 'debug', 'throwable', 'exception'],
                'ProtocolError gained a free-text slot. See the no-leak argument in the class docblock.'
            );
        }
    }

    /**
     * The one client-facing throwable also cannot be handed a string.
     */
    public function testTheClientFacingExceptionOnlyAcceptsAProtocolError(): void
    {
        $constructor = (new \ReflectionClass(DfcApiException::class))->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame(ProtocolError::class, $parameters[0]->getType()->getName());
        self::assertSame('Throwable', $parameters[1]->getType()->getName());
        self::assertTrue($parameters[1]->allowsNull());
        self::assertInstanceOf(DfcProtocolException::class, new DfcApiException(ProtocolError::notFound()));
    }

    // -- Structure ------------------------------------------------------------

    public function testDiagnosticsNameTheAffectedPathAndPredicate(): void
    {
        $decoded = self::decode(ProtocolError::unprocessable([
            new ValidationDiagnostic(
                DfcPath::fromTokens('organizations', '0', 'address'),
                'dfc-b:address',
                ValidationIssue::SHAPE,
                'maxItems>=1'
            ),
            new ValidationDiagnostic(
                DfcPath::fromTokens('organizations', '0', 'vatStatus'),
                'dfc-b:vatStatus',
                ValidationIssue::NOT_A_MEMBER
            ),
        ])->toJson());

        self::assertSame(422, $decoded['status']);
        self::assertCount(2, $decoded['violations']);
        self::assertSame('shape', $decoded['violations'][0]['issue']);
        self::assertSame('maxItems>=1', $decoded['violations'][0]['constraint']);
        self::assertArrayNotHasKey('constraint', $decoded['violations'][1]);
        self::assertSame('not_a_member', $decoded['violations'][1]['issue']);
    }

    public function testAnOversizedViolationListIsTruncatedAndSaysSo(): void
    {
        $violations = array_map(
            static fn (int $index): ValidationDiagnostic => new ValidationDiagnostic(
                DfcPath::fromTokens('organizations', (string) $index),
                'dfc-b:name',
                ValidationIssue::REQUIRED
            ),
            range(0, ProtocolError::MAX_DIAGNOSTICS + 9)
        );

        $error = ProtocolError::unprocessable($violations);
        $decoded = self::decode($error->toJson());

        self::assertCount(ProtocolError::MAX_DIAGNOSTICS, $decoded['violations']);
        self::assertTrue($error->wasTruncated());
        self::assertTrue($decoded['violationsTruncated']);
        // The EARLIEST violations survive, because a validator walks in order and
        // the first one is usually the root cause.
        self::assertSame('/organizations/0', $decoded['violations'][0]['path']);
    }

    public function testAFittingViolationListIsNotMarkedTruncated(): void
    {
        $error = ProtocolError::unprocessable([
            new ValidationDiagnostic(DfcPath::root(), 'dfc-b:name', ValidationIssue::REQUIRED),
        ]);

        self::assertFalse($error->wasTruncated());
        self::assertArrayNotHasKey('violationsTruncated', self::decode($error->toJson()));
    }

    public function testTheConstructorRefusesAnOversizedListSoTheBoundCannotBeBypassed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 32 diagnostics');

        new ProtocolError(ErrorCode::VALIDATION_FAILED, null, array_fill(
            0,
            ProtocolError::MAX_DIAGNOSTICS + 1,
            new ValidationDiagnostic(DfcPath::root(), 'dfc-b:name', ValidationIssue::REQUIRED)
        ));
    }

    public function testThe415BodyNamesTheAcceptableMediaTypes(): void
    {
        $decoded = self::decode(
            ProtocolError::unsupportedMediaType([MediaType::JSON_LD, MediaType::TURTLE])->toJson()
        );

        self::assertSame(415, $decoded['status']);
        self::assertSame(['application/ld+json', 'text/turtle'], $decoded['supportedMediaTypes']);
    }

    public function testAMediaTypeWithParametersIsRefusedAsAListEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bare media type');

        ProtocolError::unsupportedMediaType(['application/ld+json; charset=utf-8']);
    }

    public function testTheBodyIsCanonicalSoIdenticalFailuresProduceIdenticalBytes(): void
    {
        $first = ProtocolError::notFound()->toJson();
        $second = ProtocolError::notFound()->toJson();

        self::assertSame($first, $second);
        self::assertStringContainsString('{"code":"resource_not_found"', $first);
    }

    // -- Error response -------------------------------------------------------

    public function testTheErrorResponseIsProblemJsonAndUncacheable(): void
    {
        $response = self::responder()->response(new \RuntimeException('boom'));

        self::assertInstanceOf(ProtocolResponse::class, $response);
        self::assertTrue($response->isError());
        self::assertSame(ErrorCode::INTERNAL_ERROR->status(), $response->status());
        self::assertSame('application/problem+json', $response->contentType());
        self::assertSame('no-store', $response->headers()->first('Cache-Control'));
        self::assertSame([], $response->headers()->get('WWW-Authenticate'));
    }

    public function testTheErrorResponseCarriesTheChallenge(): void
    {
        $mapper = self::mapper();

        $response = (new ErrorResponder($mapper))->forError($mapper->authenticationRequired());

        self::assertSame(401, $response->status());
        self::assertSame(
            ['Bearer realm="' . ControllerFixtures::BASE_URI . '"'],
            $response->headers()->get('WWW-Authenticate')
        );
    }

    public function testTheErrorResponseEchoesTheCorrelationId(): void
    {
        $response = self::responder(self::correlationId())->response(new \RuntimeException('boom'));

        self::assertSame(
            ControllerFixtures::CORRELATION_ID,
            self::decode($response->body())['correlationId']
        );
    }

    public function testACorrelationIdThatIsNeitherUlidNorUuidIsRejected(): void
    {
        self::assertNull(CorrelationId::tryFromString('not-an-id'));

        $this->expectException(\InvalidArgumentException::class);

        CorrelationId::fromString('SELECT 1');
    }

    /**
     * @return list<string>
     */
    private static function forbiddenSubstrings(): array
    {
        return [
            'SQLSTATE',
            '42S02',
            'SELECT',
            'semantic_identifier',
            'civicrm_dfc_identity',
            'civicrm.civicrm',
            'var/www',
            'html/sites',
            'civi.sql',
            'Controller.php',
            '.php',
            'PDOException',
            'CRM_Core_DAO',
            '#0',
            '#1',
            'thrown in',
            'line 214',
            '(214)',
            '12345',
            '1146',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
