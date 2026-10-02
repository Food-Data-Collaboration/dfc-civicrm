<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Security;

/**
 * One "this attribute grants that permission" statement, with the surface it is
 * scoped to and the reason it exists.
 *
 * ============================================================================
 * WHY THE NOTE IS PART OF THE VALUE AND NOT A COMMENT
 * ============================================================================
 * A scope → permission table is the single most consequential mapping in the
 * interface, and the questions an auditor actually asks about it are not "what
 * does `ReadEnterprise` map to" but "why does this row exist", "is this row still
 * right", and "who added it". All three are answered by a note attached to the
 * row, in the audit output, next to the grant itself — not in a comment 400 lines
 * away in a file nobody re-reads.
 *
 * The notes are therefore part of {@see toArray()}, which is what the audit record
 * and the diagnostics endpoint carry. They are fixed text authored here; nothing
 * from the token or from the registry's callers can reach them.
 *
 * ============================================================================
 * A NULL SURFACE MEANS "EVERY SURFACE"
 * ============================================================================
 * Not "every surface of one kind". `null` is the wildcard, and it is explicit: a
 * grant for `read dfc data` on `null` is a blanket read grant, which is a decision
 * an administrator makes deliberately and which is visible in {@see toArray()} as
 * `"surface": "*"` rather than as an omission.
 *
 * @package Civi\Dfc
 */
final class ScopeGrant
{
    private readonly DfcPermission $permission;

    private readonly ?DfcSurface $surface;

    private readonly string $note;

    private function __construct(DfcPermission $permission, ?DfcSurface $surface, string $note)
    {
        $this->permission = $permission;
        $this->surface = $surface;
        $this->note = $note;
    }

    /**
     * @param string $note Why this grant exists. Fixed text; see the class docblock.
     */
    public static function of(DfcPermission $permission, ?DfcSurface $surface, string $note): self
    {
        if (trim($note) === '') {
            throw new \InvalidArgumentException(
                'A scope grant must carry a note saying why it exists. A row in the authorisation table with no '
                . 'stated reason is one nobody can review.'
            );
        }

        return new self($permission, $surface, $note);
    }

    public function permission(): DfcPermission
    {
        return $this->permission;
    }

    /** Null means "every surface". */
    public function surface(): ?DfcSurface
    {
        return $this->surface;
    }

    public function appliesTo(DfcSurface $candidate): bool
    {
        return $this->surface === null || $this->surface === $candidate;
    }

    public function note(): string
    {
        return $this->note;
    }

    /**
     * @return array{permission: string, surface: string, note: string}
     */
    public function toArray(): array
    {
        return [
            'permission' => $this->permission->value,
            'surface' => $this->surface?->value ?? '*',
            'note' => $this->note,
        ];
    }
}