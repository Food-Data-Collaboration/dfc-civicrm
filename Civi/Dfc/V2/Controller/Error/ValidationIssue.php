<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Error;

/**
 * The closed vocabulary of things that can be wrong with one part of a submitted
 * DFC document.
 *
 * WHY AN ENUM RATHER THAN A MESSAGE
 *   A validation message is the classic leak channel: it is the one place where
 *   a developer writes `sprintf('Unknown field "%s"', $field)` and a moment later
 *   `$field` is a column name or an internal id. Every value here is a literal,
 *   so a violation's text is fixed by the vocabulary, and the only caller-supplied
 *   parts of a violation are a validated {@see DfcPath} and a validated
 *   predicate name.
 *
 * WHY THESE AND NOT OTHERS
 *   Each case corresponds to a failure that the DFC v2 object model actually
 *   has. Adding a case is a contract change (clients may branch on it), so the
 *   set is deliberately small; `MALFORMED` and `TYPE_MISMATCH` exist as the
 *   honest catch-alls rather than as an excuse to keep adding adjectives.
 *
 * @package Civi\Dfc
 */
enum ValidationIssue: string
{
    /** A predicate the class makes mandatory is absent or empty. */
    case REQUIRED = 'required';

    /** The value's JSON type is not the one the DFC class declares. */
    case TYPE_MISMATCH = 'type_mismatch';

    /**
     * The value's JSON-LD *shape* is wrong — most often the scalar-vs-array
     * distinction PRD-002 §4.2 calls out explicitly for singular references.
     */
    case SHAPE = 'shape';

    /** The value is not a member of the controlled vocabulary it claims. */
    case NOT_A_MEMBER = 'not_a_member';

    /** The predicate is not defined for the declared DFC class. */
    case UNKNOWN_PREDICATE = 'unknown_predicate';

    /** A value that must be an IRI is a relative reference, or vice versa. */
    case NOT_ABSOLUTE_URI = 'not_absolute_uri';

    /**
     * An IRI that this platform minted has been presented under a different
     * authority or base — the signature of a cross-site import. sa-004's
     * `UriFactory::recognises()` is the check; this violation is its report.
     */
    case OUTSIDE_PLATFORM_BASE = 'outside_platform_base';

    /** The subject named in the body contradicts the subject of the request URI. */
    case SUBJECT_MISMATCH = 'subject_mismatch';

    /** A reference that resolves to the resource itself. */
    case SELF_REFERENCE = 'self_reference';

    /** A cycle in the submitted graph. */
    case CIRCULAR_REFERENCE = 'circular_reference';

    /** The value is syntactically unusable for its slot. */
    case MALFORMED = 'malformed';

    /** The value exceeds a declared size limit. */
    case TOO_LARGE = 'too_large';

    /** A short, stable explanation. Fixed text; see the class docblock. */
    public function title(): string
    {
        return match ($this) {
            self::REQUIRED => 'This predicate is required for the declared DFC class and is absent or empty.',
            self::TYPE_MISMATCH => 'The value has a JSON type the declared DFC class does not allow.',
            self::SHAPE => 'The value has the wrong JSON-LD shape. A singular reference must be a single '
                . 'value and a repeatable predicate an array, even when it holds one item.',
            self::NOT_A_MEMBER => 'The value is not a member of the controlled vocabulary this predicate uses.',
            self::UNKNOWN_PREDICATE => 'The predicate is not defined for the declared DFC class.',
            self::NOT_ABSOLUTE_URI => 'The value must be an absolute IRI.',
            self::OUTSIDE_PLATFORM_BASE => 'The IRI was minted under a different platform base than this '
                . 'one advertises. It was not re-pointed, because re-pointing an identity silently is worse '
                . 'than refusing it.',
            self::SUBJECT_MISMATCH => 'The subject in the document contradicts the subject of the request URI.',
            self::SELF_REFERENCE => 'The value refers to the resource that declares it.',
            self::CIRCULAR_REFERENCE => 'The submitted graph contains a cycle.',
            self::MALFORMED => 'The value is not usable in this position.',
            self::TOO_LARGE => 'The value exceeds a declared size limit for this predicate.',
        };
    }
}
