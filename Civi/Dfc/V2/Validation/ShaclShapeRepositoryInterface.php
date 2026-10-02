<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * Where the SHACL shapes come from.
 *
 * ============================================================================
 * WHY THIS IS AN ABSTRACTION AND NOT A `file_get_contents`
 * ============================================================================
 * Because "how does this deployment ship and read its shapes" is a packaging
 * question, and the validation pipeline must not own it. It also has to be an
 * interface for a hermetic suite: the upstream `shacl/dfc_business.shacl.ttl` is
 * 210 KB and lives in another repository (PRD-002 §5), so a test that read it would
 * depend on a checkout that does not exist here.
 *
 * The production implementation is {@see FileShaclShapeRepository}; a test supplies
 * its own.
 *
 * ============================================================================
 * UPSTREAM PROVENANCE, AND WHAT IS DELIBERATELY NOT VENDORED
 * ============================================================================
 * PRD-002 §5: "SHACL shapes — `dfc_business.shacl.ttl` (210 KB) +
 * `dfc_technical.shacl.ttl`, maintained by `scripts/add_enum_shacl.py`", in
 * `Food-Data-Collaboration/DFC-LinkML`, paths `shacl/dfc_business.shacl.ttl` and
 * `shacl/dfc_technical.shacl.ttl`.
 *
 * Those filenames are the repository's defaults. The 210 KB of Turtle itself is NOT
 * vendored into this extension: it would go stale the moment upstream regenerates it,
 * two copies of an ontology in one release archive is two sources of truth, and
 * lane-2's packaging (BLK-004's "vendor the connector" decision) already owns the
 * question of what ships. What ships instead is the FILENAME CONVENTION plus a
 * repository that reads it — so pointing a deployment at a pinned upstream checkout
 * is a configuration change.
 *
 * ============================================================================
 * THE CONTRACT IS FAIL-LOUD, BECAUSE FAILING OPEN IS THE DANGEROUS DIRECTION
 * ============================================================================
 * {@see ShaclShapeRepositoryInterface::turtle()} either returns the document or
 * throws {@see ShaclShapeUnavailableException}. It must never return an empty string
 * and must never return a partial document: "no shapes" and "the shapes file is
 * missing" both have to reach {@see ShaclValidationStage}, which turns them into a
 * 500 rather than a pass. See that class's docblock.
 *
 * @package Civi\Dfc
 */
interface ShaclShapeRepositoryInterface
{
    /**
     * The raw Turtle for one named shape graph.
     *
     * @param string $graph A repository-defined name, e.g. `business` or
     *                      `technical`. Not a filename: the mapping from name to
     *                      file is this repository's business, so a deployment can
     *                      rename its files without touching the validator.
     *
     * @throws ShaclShapeUnavailableException when the document cannot be produced.
     */
    public function turtle(string $graph): string;

    /**
     * Every graph name this repository can produce, for the validator to iterate.
     *
     * An empty list is a legal answer only for a repository that will never be asked
     * to validate; {@see ShaclValidationStage} treats it as "shapes unavailable".
     *
     * @return list<string>
     */
    public function graphs(): array;
}