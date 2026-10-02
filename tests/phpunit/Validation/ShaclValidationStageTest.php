<?php

declare(strict_types=1);

namespace Civi\Dfc\Test\Validation;

use Civi\Dfc\V2\Controller\Error\ErrorCode;
use Civi\Dfc\V2\Identity\DfcReleaseConfig;
use Civi\Dfc\V2\Validation\FileShaclShapeRepository;
use Civi\Dfc\V2\Validation\JsonLdParseStage;
use Civi\Dfc\V2\Validation\JsonSyntaxParser;
use Civi\Dfc\V2\Validation\LocalShaclValidator;
use Civi\Dfc\V2\Validation\ShaclShapeUnavailableException;
use Civi\Dfc\V2\Validation\ShaclValidationStage;
use Civi\Dfc\V2\Validation\ValidationContext;
use Civi\Dfc\V2\Validation\ValidationPipeline;
use Civi\Dfc\V2\Validation\ValidationStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The SHACL stage's four ways of not passing, and its one way of passing.
 *
 * ============================================================================
 * WHY THIS SUITE IS MOSTLY ABOUT FAILURE
 * ============================================================================
 * A SHACL gate has exactly one catastrophic failure mode: a PASS that was decided by
 * an absence — no shapes loaded, no shapes parsed, no shapes found, nothing to check.
 * All four of those look identical in a response, so all four are asserted here, and
 * each must FAIL the request rather than accept the document.
 *
 * `testTheMissingShapeFileIsAFailureNotAPass` is the headline. The rest exist because a
 * single assertion could be satisfied by one broad catch.
 */
#[CoversClass(ShaclValidationStage::class)]
#[CoversClass(FileShaclShapeRepository::class)]
final class ShaclValidationStageTest extends TestCase
{
    private DfcReleaseConfig $release;

    private string $requestUri;

    protected function setUp(): void
    {
        $this->release = DfcReleaseConfig::fromDescriptorFile(
            __DIR__ . '/../../fixtures/dfc-release.yaml',
            ['platform_base_uri' => 'https://platform.example/dfc/v2']
        );
        $this->requestUri = 'https://platform.example/dfc/v2/organizations/01HZY9B2W8R6K4M0P1Q3S5T7V';
    }

    // -- The four fail-closed states -------------------------------------------

    public function testTheMissingShapeFileIsAFailureNotAPass(): void
    {
        // "Shapes unavailable, so everything is valid" is the fail-OPEN failure, and it
        // is the one that matters here.
        $stage = new ShaclValidationStage(new UnavailableShapeRepository(), new LocalShaclValidator());

        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/missing or unreadable/');

        $stage->run($this->write(self::bodyMissingName()));
    }

    public function testAShapeDocumentWithNoNodeShapesIsAFailureNotAPass(): void
    {
        // The third state: the file was readable and parsed, and yielded nothing to
        // validate against. Loading it and reporting success would be the fail-open
        // failure wearing a different hat.
        $stage = new ShaclValidationStage(new EmptyShapeRepository(), new LocalShaclValidator());

        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/contained no node shapes/');

        $stage->run($this->write(self::bodyMissingName()));
    }

    public function testARepositoryWithNoGraphsIsAFailureNotAPass(): void
    {
        // The fourth state, and the one a naive "loop over the graphs" implementation
        // turns into a silent pass: zero iterations reporting zero violations.
        $stage = new ShaclValidationStage(new NoGraphsShapeRepository(), new LocalShaclValidator());

        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/exposes no graphs/');

        $stage->run($this->write(self::bodyMissingName()));
    }

    public function testAnUnparseableShapeDocumentIsAFailureNotAPass(): void
    {
        // A shape file using a `sh:` constraint this subset does not implement must be
        // REFUSED. A parser that skipped it would report documents the shapes forbid as
        // valid, with nothing in the logs to say so.
        $stage = new ShaclValidationStage(
            new InlineShapeRepository(<<<'TTL'
                @prefix sh: <http://www.w3.org/ns/shacl#> .
                @prefix ex: <https://example.org/shapes/> .

                ex:S a sh:NodeShape ;
                    sh:targetClass ex:Thing ;
                    sh:property [ sh:path ex:field ; sh:or ex:Other ] .
                TTL),
            new LocalShaclValidator()
        );

        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        $stage->run($this->write(self::bodyMissingName()));
    }

    // -- The one way of passing -----------------------------------------------

    public function testAValidDocumentPasses(): void
    {
        $stage = new ShaclValidationStage(new FixtureShapeRepository(), new LocalShaclValidator());

        $result = $stage->run($this->write(self::validBody()));

        self::assertTrue($result->isPassed());
        self::assertSame(ValidationStage::SHACL, $result->stage());
    }

    public function testAViolatingDocumentIsA422WithDiagnostics(): void
    {
        $stage = new ShaclValidationStage(new FixtureShapeRepository(), new LocalShaclValidator());

        $result = $stage->run($this->write(self::bodyMissingName()));

        self::assertTrue($result->isFailed());
        self::assertSame(422, $result->error()->status());
        self::assertSame(ErrorCode::VALIDATION_FAILED, $result->error()->code());

        $diagnostics = $result->error()->diagnostics();
        self::assertNotSame([], $diagnostics);
        self::assertSame('dfc-b:name', $diagnostics[0]->predicate());
        self::assertSame('/dfc-b:name', $diagnostics[0]->path()->render());
        self::assertSame('required', $diagnostics[0]->issue()->value);
        self::assertSame('minCount>=1', $diagnostics[0]->constraint());
    }

    public function testTheDiagnosticsAreTruncatedRatherThanUnbounded(): void
    {
        // A malformed submission can produce thousands; ProtocolError caps the list and
        // says so, so the amplification vector is closed at the error layer.
        $stage = new ShaclValidationStage(new FixtureShapeRepository(), new LocalShaclValidator());

        $document = ['@context' => ['dfc-b' => 'x'], '@type' => 'dfc-b:Organization'];
        for ($i = 0; $i < 100; $i++) {
            $document['dfc-b:not-a-real-predicate-' . $i] = 'x';
        }

        $result = $stage->run($this->write($document));

        self::assertTrue($result->isFailed());
        self::assertLessThanOrEqual(
            \Civi\Dfc\V2\Controller\Error\ProtocolError::MAX_DIAGNOSTICS,
            count($result->error()->diagnostics())
        );
    }

    // -- A read skips the stage -----------------------------------------------

    public function testAReadSkipsTheStageEntirely(): void
    {
        // Nothing to validate, and no shapes are loaded — so this is not a case where a
        // repository failure could be mistaken for a pass.
        $stage = new ShaclValidationStage(new UnavailableShapeRepository(), new LocalShaclValidator());

        self::assertTrue($stage->run(ValidationContext::read(
            $this->release,
            'GET',
            $this->requestUri
        ))->isPassed());
    }

    // -- The file repository --------------------------------------------------

    public function testTheFileRepositoryReadsAGraph(): void
    {
        $repository = new FileShaclShapeRepository(
            __DIR__ . '/../../fixtures/security/shacl',
            ['fixture' => 'organization-fixture.shacl.ttl']
        );

        self::assertSame(['fixture'], $repository->graphs());
        self::assertStringContainsString('sh:NodeShape', $repository->turtle('fixture'));
    }

    public function testTheFileRepositoryDefaultsAreTheUpstreamFilenames(): void
    {
        // PRD-002 §5 names them, in Food-Data-Collaboration/DFC-LinkML.
        self::assertSame('dfc_business.shacl.ttl', FileShaclShapeRepository::DEFAULT_BUSINESS_FILE);
        self::assertSame('dfc_technical.shacl.ttl', FileShaclShapeRepository::DEFAULT_TECHNICAL_FILE);
        self::assertSame('business', FileShaclShapeRepository::GRAPH_BUSINESS);
        self::assertSame('technical', FileShaclShapeRepository::GRAPH_TECHNICAL);
    }

    public function testTheDefaultRepositoryAdvertisesBothUpstreamGraphs(): void
    {
        $repository = new FileShaclShapeRepository('/some/path');

        self::assertSame(['business', 'technical'], $repository->graphs());
    }

    public function testAMissingFileIsReportedWithItsName(): void
    {
        $repository = new FileShaclShapeRepository('/no/such/directory');

        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/dfc_business\.shacl\.ttl/');

        $repository->turtle('business');
    }

    public function testAnUnconfiguredGraphIsReported(): void
    {
        $repository = new FileShaclShapeRepository(__DIR__ . '/../../fixtures/security/shacl', [
            'fixture' => 'organization-fixture.shacl.ttl',
        ]);

        $this->expectException(ShaclShapeUnavailableException::class);
        $this->expectExceptionMessageMatches('/No SHACL shape graph named "business"/');

        $repository->turtle('business');
    }

    public function testAPathTraversalInAFilenameIsRefused(): void
    {
        // The filenames are configuration, but they must not become a way to read
        // outside the configured directory.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a bare filename/');

        new FileShaclShapeRepository('/shacl', ['business' => '../../etc/passwd']);
    }

    public function testAnEmptyDirectoryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FileShaclShapeRepository('   ');
    }

    public function testAnEmptyShapeFileIsRefused(): void
    {
        // An empty file that loaded would "validate" nothing while appearing to have
        // validated successfully.
        $directory = sys_get_temp_dir() . '/dfc-shacl-empty-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/empty.shacl.ttl', "\n  \n");

        try {
            $repository = new FileShaclShapeRepository($directory, ['empty' => 'empty.shacl.ttl']);

            $this->expectException(ShaclShapeUnavailableException::class);
            $this->expectExceptionMessageMatches('/is empty/');

            $repository->turtle('empty');
        } finally {
            @unlink($directory . '/empty.shacl.ttl');
            @rmdir($directory);
        }
    }

    // -- The stage with the parse stage in front ------------------------------

    public function testTheFullInboundPathForAMutatingRequest(): void
    {
        $pipeline = new ValidationPipeline(
            [
                new JsonLdParseStage(new JsonSyntaxParser()),
                new ShaclValidationStage(new FixtureShapeRepository(), new LocalShaclValidator()),
            ],
            [ValidationStage::JSON_LD_PARSE, ValidationStage::SHACL]
        );

        $run = $pipeline->run($this->write(self::bodyMissingName()));

        self::assertTrue($run->isRejected());
        self::assertSame(422, $run->error()->status());
        self::assertFalse($run->context()->isValidated(), 'A rejected run must not be mutation-ready.');
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A mutating context carrying an ALREADY-PARSED document.
     *
     * The graph is attached here rather than left to a parse stage, because this suite
     * is about the SHACL stage's own behaviour: a pipeline that did not run the parse
     * stage is a different failure, and {@see testTheFullInboundPathForAMutatingRequest()}
     * covers the pair.
     *
     * @param array<string, mixed>|string $body
     */
    private function write(array|string $body): ValidationContext
    {
        $bytes = is_array($body) ? (string) json_encode($body, JSON_THROW_ON_ERROR) : $body;

        /** @var array<string, mixed> $graph */
        $graph = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);

        return ValidationContext::write($this->release, 'POST', $this->requestUri, $bytes)
            ->withGraph($graph);
    }

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