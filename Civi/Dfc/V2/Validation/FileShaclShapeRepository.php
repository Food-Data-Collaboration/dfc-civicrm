<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * Reads SHACL shapes from a directory on disk.
 *
 * ============================================================================
 * THE UPSTREAM FILENAMES ARE THE DEFAULT, NOT A CONSTANT OF THE PROTOCOL
 * ============================================================================
 * PRD-002 §5 names them:
 * `shacl/dfc_business.shacl.ttl` and `shacl/dfc_technical.shacl.ttl`, in
 * `Food-Data-Collaboration/DFC-LinkML`. Those are this constructor's defaults so the
 * common case needs no configuration, and they are overridable so a deployment that
 * pins a different checkout — or renames the files — can say so.
 *
 * What is NOT here is the shapes themselves. See {@see ShaclShapeRepositoryInterface}
 * on why 210 KB of upstream Turtle must not be vendored into this repository.
 *
 * ============================================================================
 * READING IS FAIL-CLOSED, AND THE MESSAGE SAYS WHY
 * ============================================================================
 * A missing file, an unreadable file, an empty file, or a file larger than
 * {@see MAX_TURTLE_BYTES} all produce a {@see ShaclShapeUnavailableException}. None
 * of them produces an empty string, because an empty string would let
 * {@see ShaclValidationStage} believe it had loaded a shape set and validated
 * nothing against it — which is a 200 for a document nobody checked.
 *
 * The bound on size is a second fail-closed control. A 210 KB shape file is
 * plausible; a 50 MB one is an error page or a decompression bomb, and this class
 * runs on the request path.
 *
 * @package Civi\Dfc
 */
final class FileShaclShapeRepository implements ShaclShapeRepositoryInterface
{
    /** Upstream `Food-Data-Collaboration/DFC-LinkML` filenames. */
    public const DEFAULT_BUSINESS_FILE = 'dfc_business.shacl.ttl';

    public const DEFAULT_TECHNICAL_FILE = 'dfc_technical.shacl.ttl';

    public const GRAPH_BUSINESS = 'business';

    public const GRAPH_TECHNICAL = 'technical';

    /** Generous next to the real 210 KB file; a bound is what makes it a check. */
    public const MAX_TURTLE_BYTES = 4194304;

    private readonly string $directory;

    /** @var array<string, string> */
    private readonly array $filenames;

    /**
     * @param string                        $directory Absolute path holding the `.ttl` files.
     * @param array<string, string>|null    $filenames  Graph name -> filename; null for
     *                                             the upstream defaults.
     */
    public function __construct(string $directory, ?array $filenames = null)
    {
        if (trim($directory) === '') {
            throw new \InvalidArgumentException(
                'The SHACL shape directory must be a non-empty path.'
            );
        }

        $this->directory = rtrim($directory, '/');

        $this->filenames = $filenames ?? [
            self::GRAPH_BUSINESS => self::DEFAULT_BUSINESS_FILE,
            self::GRAPH_TECHNICAL => self::DEFAULT_TECHNICAL_FILE,
        ];

        foreach ($this->filenames as $graph => $filename) {
            if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\')) {
                throw new \InvalidArgumentException(sprintf(
                    'The SHACL shape filename for graph "%s" must be a bare filename in the configured '
                    . 'directory. Got "%s".',
                    $graph,
                    $filename
                ));
            }
        }
    }

    public function turtle(string $graph): string
    {
        $filename = $this->filenames[$graph] ?? null;

        if ($filename === null) {
            throw new ShaclShapeUnavailableException(sprintf(
                'No SHACL shape graph named "%s" is configured. Configured graphs: %s.',
                $graph,
                implode(', ', array_keys($this->filenames))
            ));
        }

        $path = $this->directory . '/' . $filename;

        if (!is_file($path) || !is_readable($path)) {
            throw new ShaclShapeUnavailableException(sprintf(
                'The SHACL shape file "%s" is missing or unreadable. Validation cannot run without it and '
                . 'this server will not accept a document it could not check.',
                $filename
            ));
        }

        $size = filesize($path);
        if ($size !== false && $size > self::MAX_TURTLE_BYTES) {
            throw new ShaclShapeUnavailableException(sprintf(
                'The SHACL shape file "%s" is %d bytes, over the %d-byte limit this server will read. That is '
                . 'not a shape set.',
                $filename,
                $size,
                self::MAX_TURTLE_BYTES
            ));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ShaclShapeUnavailableException(sprintf(
                'The SHACL shape file "%s" could not be read.',
                $filename
            ));
        }

        if (trim($contents) === '') {
            throw new ShaclShapeUnavailableException(sprintf(
                'The SHACL shape file "%s" is empty. An empty shape set would validate nothing while appearing '
                . 'to validate successfully, so it is refused rather than loaded.',
                $filename
            ));
        }

        return $contents;
    }

    public function graphs(): array
    {
        return array_keys($this->filenames);
    }
}