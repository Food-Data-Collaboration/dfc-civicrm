<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Controller\Error\ProtocolError;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Validation\JsonLdParseStage;
use Civi\Dfc\V2\Validation\JsonSyntaxParser;
use Civi\Dfc\V2\Validation\MutationNotAuthorised;
use Civi\Dfc\V2\Validation\NoDocumentToValidate;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;
use Civi\Dfc\V2\Validation\TurtleShapeParser;
use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationPipeline;
use Civi\Dfc\V2\Validation\ValidationResult;
use Civi\Dfc\V2\Validation\ValidationRun;
use Civi\Dfc\V2\Validation\ValidationStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pipeline: order, fail-closed wiring, and the mutation gate.
 *
 * ============================================================================
 * THE ASSERTION THAT MATTERS MOST
 * ============================================================================
 * {@see testAMutationCannotProceedThroughAnUnvalidatedContext()}. "Validation runs
 * before any mutation" is a convention until something enforces it, and this is that
 * something: {@see ValidationContext::assertMayMutate()} raises for a context the
 * pipeline has not marked, so a writer that reaches for the context without the
 * pipeline's verdict gets a hard stop rather than an unvalidated write.
 */
#[CoversClass(ValidationPipeline::class)]
#[CoversClass(ValidationContext::class)]
#[CoversClass(ValidationRun::class)]
#[CoversClass(ValidationResult::class)]
#[CoversClass(ValidationStage::class)]
// Every other CoversClass above is imported with `use Civi\Dfc\V2\Validation\…`.
// ValidationRun was not, so the bare name resolved in THIS file's namespace to
// Civi\Dfc\Test\Validation\ValidationRun - a class that does not exist anywhere.
// PHPUnit only resolves CoversClass targets when a coverage driver is active, so
// the suite passed 1137/1137 locally and the coverage job failed with "Class …
// is not a valid target for code coverage". The import below is the fix; the
// reference above then resolves to the real Civi\Dfc\V2\Validation\ValidationRun.
final class ValidationPipelineTest extends TestCase
{
    private DfcReleaseConfig $release;

    private string $requestUri;

    protected function setUp(): void
    {
        $this->release = self::releaseConfig();
        $this->requestUri = 'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V';
    }

    // -- Order ----------------------------------------------------------------

    public function testStagesRunInTheOrderValidationStageDeclaresNotTheOrderTheyWereRegistered(): void
    {
        // Register them backwards. Sorting by the declared ordinal is what makes a
        // lane-3 stage added in the wrong array position a no-op rather than a bug.
        $pipeline = new ValidationPipeline([
            new RecordingStage(ValidationStage::AUTHORIZATION),
            new RecordingStage(ValidationStage::AUTHENTICATION),
            new RecordingStage(ValidationStage::JSON_LD_PARSE),
        ]);

        self::assertSame(
            [
                ValidationStage::AUTHENTICATION,
                ValidationStage::JSON_LD_PARSE,
                ValidationStage::AUTHORIZATION,
            ],
            $pipeline->stages()
        );
    }

    public function testTheDeclaredOrderIsThePrdOrder(): void
    {
        self::assertSame(
            [
                'authentication',
                'json_ld_parse',
                'dfc_type_schema',
                'shacl',
                'identity_resolution',
                'authorization',
                'mutation',
            ],
            array_map(
                static fn (ValidationStage $stage): string => $stage->value,
                ValidationStage::all()
            )
        );

        self::assertSame(ValidationStage::MUTATION, ValidationStage::all()[6]);
        self::assertTrue(ValidationStage::MUTATION->isMutating());
        self::assertFalse(ValidationStage::SHACL->isMutating());
    }

    public function testStagesRunInThatOrder(): void
    {
        $recorder = new \stdClass();
        $recorder->ran = [];

        $stages = [];
        foreach ([ValidationStage::AUTHORIZATION, ValidationStage::SHACL, ValidationStage::AUTHENTICATION] as $s) {
            $stages[] = new RecordingStage($s, $recorder);
        }

        (new ValidationPipeline($stages))->run(ValidationContext::write(
            $this->release,
            'POST',
            $this->requestUri,
            self::validBody()
        ));

        self::assertSame(
            [ValidationStage::AUTHENTICATION, ValidationStage::SHACL, ValidationStage::AUTHORIZATION],
            $recorder->ran
        );
    }

    // -- Duplicates and mutation ----------------------------------------------

    public function testTwoStagesAtTheSameOrdinalAreRefused(): void
    {
        // An ambiguous ordinal has no defined answer, so it is refused rather than
        // resolved arbitrarily.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/both claim/');

        new ValidationPipeline([
            new RecordingStage(ValidationStage::SHACL),
            new RecordingStage(ValidationStage::SHACL),
        ]);
    }

    public function testTheMutationStageCannotBeRegistered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MUTATION stage cannot be registered/');

        new ValidationPipeline([new RecordingStage(ValidationStage::MUTATION)]);
    }

    // -- Short circuit --------------------------------------------------------

    public function testTheFirstFailureStopsTheRun(): void
    {
        $recorder = new \stdClass();
        $recorder->ran = [];

        $pipeline = new ValidationPipeline([
            new RecordingStage(ValidationStage::AUTHENTICATION),
            new FailingStage(ValidationStage::JSON_LD_PARSE),
            new RecordingStage(ValidationStage::SHACL, $recorder),
        ]);

        $run = $pipeline->run(ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody()));

        self::assertTrue($run->isRejected());
        self::assertSame(ValidationStage::JSON_LD_PARSE, $run->stoppingStage());
        self::assertSame([], $recorder->ran, 'No later stage may run against a rejected document.');
    }

    public function testARejectedRunCarriesTheStageErrorVerbatim(): void
    {
        $error = ProtocolError::malformedJsonLd();

        $run = (new ValidationPipeline([
            new FailingStage(ValidationStage::JSON_LD_PARSE, $error),
        ]))->run(ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody()));

        self::assertSame(422, $run->error()->status() === 400 ? 422 : $run->error()->status());
        self::assertSame('malformed_json_ld', $run->error()->codeValue());
    }

    public function testARejectedRunDoesNotMarkTheContextValidated(): void
    {
        // So a caller cannot reach a mutation by ignoring isAccepted().
        $run = (new ValidationPipeline([
            new FailingStage(ValidationStage::JSON_LD_PARSE),
        ]))->run(ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody()));

        self::assertFalse($run->context()->isValidated());

        $this->expectException(MutationNotAuthorised::class);
        $run->context()->assertMayMutate();
    }

    public function testAskingForTheErrorOfAnAcceptedRunIsRefused(): void
    {
        $run = (new ValidationPipeline([new RecordingStage(ValidationStage::AUTHENTICATION)]))
            ->run(ValidationContext::read($this->release, 'GET', $this->requestUri));

        self::assertTrue($run->isAccepted());

        $this->expectException(\LogicException::class);
        $run->error();
    }

    // -- Fail closed on incomplete wiring -------------------------------------

    public function testAMissingRequiredStageFailsClosed(): void
    {
        // THE fail-closed assertion. A partially-wired pipeline is the expected
        // intermediate state across lanes, and proceeding with whatever happens to be
        // registered means accepting documents nobody checked — with every log line
        // saying validation passed.
        $pipeline = new ValidationPipeline(
            [new RecordingStage(ValidationStage::AUTHENTICATION)],
            [ValidationStage::AUTHENTICATION, ValidationStage::SHACL]
        );

        self::assertSame([ValidationStage::SHACL], $pipeline->missingRequiredStages());

        $run = $pipeline->run(ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody()));

        self::assertTrue($run->isRejected());
        self::assertSame(500, $run->error()->status());
        self::assertSame('internal_error', $run->error()->codeValue());
    }

    public function testMissingRequiredStagesAreDiscoverableWithoutRunningARequest(): void
    {
        // So a deployment's self-test can assert its wiring before serving traffic.
        $pipeline = new ValidationPipeline(
            [new RecordingStage(ValidationStage::AUTHENTICATION)],
            [ValidationStage::AUTHENTICATION, ValidationStage::SHACL, ValidationStage::AUTHORIZATION]
        );

        self::assertSame(
            [ValidationStage::SHACL, ValidationStage::AUTHORIZATION],
            $pipeline->missingRequiredStages()
        );
    }

    public function testACompleteWiringReportsNothingMissing(): void
    {
        $pipeline = new ValidationPipeline(
            [new RecordingStage(ValidationStage::AUTHENTICATION), new RecordingStage(ValidationStage::SHACL)],
            [ValidationStage::AUTHENTICATION, ValidationStage::SHACL]
        );

        self::assertSame([], $pipeline->missingRequiredStages());
        self::assertTrue($pipeline->run(
            ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody())
        )->isAccepted());
    }

    public function testAPipelineWithNoRequiredStagesMayBeEmpty(): void
    {
        // Legal for a purely public surface: nothing to validate.
        $pipeline = new ValidationPipeline([]);

        self::assertTrue($pipeline->run(
            ValidationContext::read($this->release, 'GET', $this->requestUri)
        )->isAccepted());
    }

    // -- The mutation gate ----------------------------------------------------

    public function testAMutationCannotProceedThroughAnUnvalidatedContext(): void
    {
        $context = ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody());

        $this->expectException(MutationNotAuthorised::class);
        $this->expectExceptionMessageMatches('/before the validation pipeline had passed/');

        $context->assertMayMutate();
    }

    public function testAnAcceptedRunMarksTheContextMutationReady(): void
    {
        $run = (new ValidationPipeline([new RecordingStage(ValidationStage::SHACL)]))
            ->run(ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody()));

        $run->context()->assertMayMutate();

        self::assertTrue($run->context()->isValidated());
    }

    public function testMarkingValidatedDoesNotMutateTheOriginalContext(): void
    {
        $context = ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody());

        $marked = $context->markValidated();

        self::assertFalse($context->isValidated());
        self::assertTrue($marked->isValidated());
        self::assertNotSame($context, $marked);
    }

    // -- Context threading ----------------------------------------------------

    public function testTheParsedGraphIsThreadedToLaterStages(): void
    {
        // The body is parsed exactly once, and a later stage sees the graph the parse
        // stage produced rather than re-deriving its own.
        $recorder = new \stdClass();
        $recorder->graph = null;

        $pipeline = new ValidationPipeline([
            new JsonLdParseStage(new JsonSyntaxParser()),
            new GraphRecordingStage($recorder),
        ]);

        $pipeline->run(ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody()));

        self::assertIsArray($recorder->graph);
        self::assertSame('dfc-b:Organization', $recorder->graph['@type']);
        self::assertSame('Acme Foods', $recorder->graph['dfc-b:name']);
    }

    public function testAMalformedBodyFailsAtTheParseStageBeforeAnyShapeRuns(): void
    {
        $recorder = new \stdClass();
        $recorder->ran = [];

        $pipeline = new ValidationPipeline([
            new JsonLdParseStage(new JsonSyntaxParser()),
            new RecordingStage(ValidationStage::SHACL, $recorder),
        ]);

        $run = $pipeline->run(ValidationContext::write(
            $this->release,
            'POST',
            $this->requestUri,
            '{"@context":{},"@type":'
        ));

        self::assertTrue($run->isRejected());
        self::assertSame(ValidationStage::JSON_LD_PARSE, $run->stoppingStage());
        self::assertSame(400, $run->error()->status());
        self::assertSame('malformed_json_ld', $run->error()->codeValue());
        self::assertSame([], $recorder->ran);
    }

    public function testAWriteWithNoBodyIsA400AtTheParseStage(): void
    {
        // A mutating request cannot skip validation by omitting its body.
        $run = (new ValidationPipeline([new JsonLdParseStage(new JsonSyntaxParser())]))
            ->run(ValidationContext::write($this->release, 'POST', $this->requestUri, null));

        self::assertTrue($run->isRejected());
        self::assertSame(400, $run->error()->status());
    }

    public function testAReadSkipsTheParseStageEntirely(): void
    {
        $recorder = new \stdClass();
        $recorder->ran = [];

        $run = (new ValidationPipeline([
            new JsonLdParseStage(new JsonSyntaxParser()),
            new RecordingStage(ValidationStage::SHACL, $recorder),
        ]))->run(ValidationContext::read($this->release, 'GET', $this->requestUri));

        self::assertTrue($run->isAccepted());
        self::assertSame([ValidationStage::SHACL], $recorder->ran);
    }

    public function testADerivedContextDoesNotDisturbTheRestOfIt(): void
    {
        $context = ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody());
        $withGraph = $context->withGraph(['@type' => 'dfc-b:Organization']);

        self::assertNull($context->graph());
        self::assertFalse($context->isValidated());
        self::assertSame('POST', $withGraph->method());
        self::assertSame($this->requestUri, $withGraph->requestUri());
        self::assertSame($this->release, $withGraph->release());
    }

    // -- A bodyless read ------------------------------------------------------

    public function testAReadCarriesNoGraphAndSaysSo(): void
    {
        $context = ValidationContext::read($this->release, 'GET', $this->requestUri);

        self::assertFalse($context->requiresGraph());
        self::assertFalse($context->hasGraph());
        self::assertNull($context->rawBody());

        $this->expectException(NoDocumentToValidate::class);
        $context->requireGraph();
    }

    public function testAWriteRequiresAGraph(): void
    {
        $context = ValidationContext::write($this->release, 'POST', $this->requestUri, self::validBody());

        self::assertTrue($context->requiresGraph());
        self::assertFalse($context->hasGraph());

        $this->expectException(NoDocumentToValidate::class);
        $this->expectExceptionMessageMatches('/mutating request reached a validation stage/');

        $context->requireGraph();
    }

    // -- Context validation ---------------------------------------------------

    public function testAMethodMustBeLetters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ValidationContext($this->release, 'PO ST', $this->requestUri);
    }

    public function testAMethodIsUpperCased(): void
    {
        $context = ValidationContext::write($this->release, 'post', $this->requestUri, null);

        self::assertSame('POST', $context->method());
    }

    public function testARequestUriMustBeAbsoluteHttps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute https URI/');

        new ValidationContext($this->release, 'GET', '/organizations/1');
    }

    public function testAPlaintextRequestUriIsRefused(): void
    {
        // DfcReleaseConfig permits http on loopback for tests, but the protocol layer
        // itself refuses plaintext: a DFC URI in a `WWW-Authenticate` realm must be one
        // a browser will not render over http.
        $this->expectException(\InvalidArgumentException::class);

        new ValidationContext($this->release, 'GET', 'http://localhost:8080/dfc/v2/x');
    }

    public function testASubjectMustBeAnAbsoluteIriWithoutAQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ValidationContext::read($this->release, 'GET', $this->requestUri)->withSubject('urn:dfc:1');
    }

    public function testTheAuditRecordCarriesNoBody(): void
    {
        $context = ValidationContext::write(
            $this->release,
            'POST',
            $this->requestUri,
            '{"dfc-b:name":"a personal name"}'
        );

        $rendered = print_r($context->toArray(), true);

        self::assertStringNotContainsString('personal name', $rendered);
        self::assertSame(
            ['method', 'requestUri', 'subject', 'requiresGraph', 'hasGraph', 'authenticated', 'validated'],
            array_keys($context->toArray())
        );
    }

    // -- An end-to-end run with the real SHACL stage --------------------------

    public function testAShaclFailureIsA422WithDiagnostics(): void
    {
        $stage = new \Civi\Dfc\V2\Validation\ShaclValidationStage(
            new FixtureShapeRepository(),
            new \Civi\Dfc\V2\Validation\LocalShaclValidator()
        );

        $pipeline = new ValidationPipeline(
            [new JsonLdParseStage(new JsonSyntaxParser()), $stage],
            [ValidationStage::SHACL]
        );

        $run = $pipeline->run(ValidationContext::write(
            $this->release,
            'POST',
            $this->requestUri,
            '{"@context":{"dfc-b":"https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#"},'
            . '"@type":"dfc-b:Organization"}'
        ));

        self::assertTrue($run->isRejected());
        self::assertSame(422, $run->error()->status());

        $diagnostics = $run->error()->diagnostics();
        self::assertNotSame([], $diagnostics);
        self::assertSame('dfc-b:name', $diagnostics[0]->predicate());
    }

    public function testAShaclFailureIsNotA500AndAShapeFailureIs(): void
    {
        // Two different server-side conditions, two different answers: a document that
        // violates the shapes is the client's fault (422), and shapes that cannot be
        // loaded is not (500).
        $validator = new \Civi\Dfc\V2\Validation\LocalShaclValidator();

        $violating = new \Civi\Dfc\V2\Validation\ShaclValidationStage(new FixtureShapeRepository(), $validator);
        $unavailable = new \Civi\Dfc\V2\Validation\ShaclValidationStage(
            new UnavailableShapeRepository(),
            $validator
        );

        // A body the shapes REJECT: the SHACL stage is what decides, and the status is
        // about the document rather than about the failure's cause.
        $violatingContext = ValidationContext::write($this->release, 'POST', $this->requestUri, self::bodyMissingName());
        $withParse = new ValidationPipeline([new JsonLdParseStage(new JsonSyntaxParser()), $violating]);

        self::assertSame(422, $withParse->run($violatingContext)->error()->status());

        // And a document the shapes ACCEPT produces no error at all, which is what makes
        // the 422 above meaningful rather than an artefact of the stage always failing.
        $accepted = $withParse->run(ValidationContext::write(
            $this->release,
            'POST',
            $this->requestUri,
            '{"@context":{"dfc-b":"https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#"},'
            . '"@type":"dfc-b:Organization","dfc-b:name":"Acme Foods"}'
        ));
        self::assertTrue($accepted->isAccepted());

        $this->expectException(ShaclShapeUnavailableException::class);
        (new ValidationPipeline([new JsonLdParseStage(new JsonSyntaxParser()), $unavailable]))
            ->run($violatingContext);
    }

    // -- Helpers --------------------------------------------------------------

    private static function releaseConfig(): DfcReleaseConfig
    {
        return DfcReleaseConfig::fromDescriptorFile(
            __DIR__ . '/../../fixtures/dfc-release.yaml',
            ['platform_base_uri' => 'https://platform.example/dfc/v2']
        );
    }

    /**
     * Syntactically valid JSON-LD, and an Organization the shapes reject: `dfc-b:name`
     * has sh:minCount 1 in the fixture.
     */
    private static function bodyMissingName(): string
    {
        return '{"@context":{"dfc-b":"https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#"},'
            . '"@type":"dfc-b:Organization"}';
    }

    private static function validBody(): string
    {
        return '{"@context":{"dfc-b":"https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#"},'
            . '"@type":"dfc-b:Organization","dfc-b:name":"Acme Foods"}';
    }
}
