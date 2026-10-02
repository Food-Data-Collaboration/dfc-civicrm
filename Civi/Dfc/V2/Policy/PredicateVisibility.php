<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Policy;

/**
 * The three answers to "may this predicate leave the server?", and why three.
 *
 * ============================================================================
 * WHY NOT A BOOLEAN
 * ============================================================================
 * Because a boolean cannot express the only distinction that matters operationally:
 * whether a predicate is absent because the policy withholds it, or absent because it
 * is JSON-LD syntax that was never a decision. An allow-list that could switch off
 * `@id` would corrupt a document; an allow-list that reported `@id` as "withheld"
 * would fill the audit log with 32 entries saying the same thing.
 *
 * So:
 *
 *   - STRUCTURAL — JSON-LD keywords. Not data, never policy's business, always
 *     present. {@see PublicFieldPolicy} cannot put one on its allow-list or its deny
 *     list; attempting it is a construction error.
 *   - PUBLIC     — explicitly on the allow-list. An affirmative, reviewable decision.
 *   - WITHHELD   — not on the allow-list. The DEFAULT, and the whole point.
 *
 * ============================================================================
 * WHY `STRUCTURAL` IS A LIST AND NOT A PROPERTY OF THE ENUM
 * ============================================================================
 * JSON-LD keywords are defined by RFC 8259 / the JSON-LD 1.1 specification, not by
 * DFC and not by this project. They are a constant here so that
 * {@see LocalShaclValidator}'s `sh:closed` check and {@see ExportProjection} agree on
 * the same list, but they are documented as borrowed rather than chosen.
 *
 * PRD-002 "Upstream refresh" item 4 adds a related fact: the published
 * `context_2.0.0.json` declares only `rdfs skos dfc dc dfc-b dfc-t dfc-m dfc-pt
 * dfc-f dfc-v ontosec`, while `dfc-ldp.yaml` uses `ldp:`, `foaf:`, `geojson:`, `cal:`,
 * `solid:` and `xsd:`. So a prefix we EMIT needs an inline `@context` entry — but
 * that is a serialisation concern, not a visibility one. A predicate that is not on
 * the allow-list is withheld whether or not the context declares it.
 *
 * @package Civi\Dfc
 */
enum PredicateVisibility: string
{
    /** JSON-LD syntax. Always emitted; never a policy decision. */
    case STRUCTURAL = 'structural';

    /** On the allow-list: this predicate is exported. */
    case PUBLIC = 'public';

    /** Not on the allow-list. The default, and CP-1's "defaults to not-exported". */
    case WITHHELD = 'withheld';

    /**
     * The JSON-LD keywords, in the order RFC 8259 §3 lists them.
     *
     * Borrowed from the JSON-LD 1.1 specification, not chosen here. `@graph` is
     * included because a document can carry one and it is still syntax rather than a
     * statement about the resource.
     *
     * @var list<string>
     */
    public const JSON_LD_KEYWORDS = ['@context', '@id', '@type', '@graph'];

    public static function isKeyword(string $predicate): bool
    {
        return in_array($predicate, self::JSON_LD_KEYWORDS, true);
    }

    /**
     * @return list<string>
     */
    public static function keywords(): array
    {
        return self::JSON_LD_KEYWORDS;
    }

    public function title(): string
    {
        return match ($this) {
            self::STRUCTURAL => 'JSON-LD syntax, always present',
            self::PUBLIC => 'exported',
            self::WITHHELD => 'withheld',
        };
    }
}