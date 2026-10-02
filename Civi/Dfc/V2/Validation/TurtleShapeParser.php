<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Validation;

/**
 * A Turtle parser for the SHACL subset {@see LocalShaclValidator} implements.
 *
 * ============================================================================
 * WHY NOT A FULL TURTLE PARSER
 * ============================================================================
 * Because the extension declares `ext-dom`, `ext-libxml`, `ext-mbstring` and
 * `ext-json` — and no RDF library. Pulling in a full Turtle/RDF stack to validate
 * one document against one fixture shape would put a large dependency into the unit
 * suite and, more importantly, would make the SHACL gate depend on a parser rather
 * than on a rule set.
 *
 * So this parses a documented SUBSET: `@prefix`, `a`, IRIs, CURIEs, string literals
 * with datatypes and language tags, integers, decimals, booleans, `;` and `,`,
 * blank-node property lists `[ ... ]`, and collections `( ... )`. Anything else is a
 * {@see ShaclShapeUnavailableException} carrying a line number.
 *
 * ============================================================================
 * THE ONE RULE THAT MAKES THIS SAFE: UNKNOWN `sh:` PREDICATES ARE FATAL
 * ============================================================================
 * This is the whole design. A permissive parser that skipped the constraints it did
 * not understand would produce a validator that reports "valid" for documents the
 * upstream shapes forbid — and the operator would have no way to tell, because the
 * only evidence is the absence of a violation.
 *
 * So every predicate on a shape is classified into exactly one of three buckets and
 * handled differently:
 *
 *   1. implemented   -> interpreted into a constraint (see {@see IMPLEMENTED_SHACL}).
 *   2. annotation    -> DISCARDED and recorded, because it carries no validation
 *                       semantics: `sh:message`, `sh:description`, `sh:name`, and the
 *                       annotation vocabularies (`rdfs:`, `owl:`, `skos:`, `dcterms:`,
 *                       `dc:`, `foaf:`, `prov:`, `xsd:`).
 *   3. anything else -> {@see ShaclShapeUnavailableException}.
 *
 * Case 3 is what makes it legitimate to ship a subset parser at all: pointing this at
 * the real `dfc_business.shacl.ttl` will either work or refuse loudly, and it will
 * never quietly validate less than the file says. Every shape carries
 * `rdf:type sh:NodeShape`, so a file of pure annotations is not a shape file either.
 *
 * ============================================================================
 * PREFIX REBINDING IS REFUSED
 * ============================================================================
 * A shape file that binds `dfc-b:` twice, to different IRIs, is a defect that would
 * make validation depend on which definition the scanner saw last. Refused at parse
 * time.
 *
 * ============================================================================
 * EVERY ERROR NAMES A LINE
 * ============================================================================
 * "your shapes are wrong" with no location is the error that costs an afternoon.
 *
 * @package Civi\Dfc
 */
final class TurtleShapeParser
{
    private const SHACL_NS = 'http://www.w3.org/ns/shacl#';

    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /**
     * `sh:` local names interpreted into constraints.
     *
     * @var list<string>
     */
    private const IMPLEMENTED_SHACL = [
        'targetClass',
        'targetNode',
        'closed',
        'ignoredProperties',
        'severity',
        'property',
        'path',
        'minCount',
        'maxCount',
        'datatype',
        'nodeKind',
        'class',
        'in',
        'pattern',
        'flags',
        'minLength',
        'maxLength',
        'hasValue',
    ];

    /**
     * `sh:` local names read and discarded: annotations, not constraints.
     *
     * @var list<string>
     */
    private const ANNOTATIONS_SHACL = [
        'message',
        'description',
        'name',
        'order',
        'group',
        'defaultValue',
    ];

    /**
     * Namespaces whose predicates carry no validation semantics.
     *
     * Matched by NAMESPACE rather than by prefix name, because a shape file is free
     * to call the RDF Schema namespace whatever it likes — `rdfs:` is a convention,
     * not a requirement.
     *
     * @var list<string>
     */
    private const ANNOTATION_NAMESPACES = [
        'http://www.w3.org/2000/01/rdf-schema#',
        'http://www.w3.org/2002/07/owl#',
        'http://www.w3.org/2004/02/skos/core#',
        'http://purl.org/dc/terms/',
        'http://purl.org/dc/elements/1.1/',
        'http://xmlns.com/foaf/0.1/',
        'http://www.w3.org/ns/prov#',
        'http://www.w3.org/2001/XMLSchema#',
    ];

    /** Guard on blank-node nesting. */
    private const MAX_NESTING = 32;

    /** @var array<string, string> */
    private array $prefixes = [];

    /**
     * Subject IRI (or `_:label`) => a list of `[predicate IRI, object term]` pairs.
     *
     * A list of pairs rather than a predicate-keyed map, because a subject CAN carry the
     * same predicate twice — `sh:targetClass ex:A, ex:B` is two pairs — and a map would
     * have to be nested at write time to avoid losing one. {@see indexByPredicate()}
     * does the grouping, once, where it is needed.
     *
     * @var array<string, list<array{0: string, 1: array<string, mixed>}>>
     */
    private array $triples = [];

    /** @var list<string> */
    private array $ignoredAnnotations = [];

    private int $blankNodeCounter = 0;

    /**
     * @throws ShaclShapeUnavailableException on any unsupported or malformed shape.
     */
    public function parse(string $turtle): ShaclShapeSet
    {
        $this->prefixes = [];
        $this->triples = [];
        $this->ignoredAnnotations = [];
        $this->blankNodeCounter = 0;

        $tokens = $this->tokenise($turtle);
        $position = 0;
        $count = count($tokens);

        while ($position < $count) {
            $token = $tokens[$position];

            if ($token['type'] === 'keyword' && $token['value'] === '@prefix') {
                $this->readPrefixDirective($tokens, $position);

                continue;
            }

            $this->readStatement($tokens, $position);
        }

        return $this->interpret();
    }

    // -- Scanner --------------------------------------------------------------

    /**
     * @return list<array{type: string, value: string, line: int, extra: string}>
     */
    private function tokenise(string $turtle): array
    {
        $tokens = [];
        $length = strlen($turtle);
        $index = 0;
        $line = 1;

        while ($index < $length) {
            $char = $turtle[$index];

            if ($char === "\n") {
                $line++;
                $index++;

                continue;
            }

            if (ctype_space($char)) {
                $index++;

                continue;
            }

            if ($char === '#') {
                while ($index < $length && $turtle[$index] !== "\n") {
                    $index++;
                }

                continue;
            }

            if ($char === '<') {
                $close = strpos($turtle, '>', $index);
                if ($close === false) {
                    throw $this->error('An IRI reference is not closed.', $line);
                }

                $iri = substr($turtle, $index + 1, $close - $index - 1);
                if ($iri === '' || preg_match('/[\s<>"{}|\\^`]/', $iri) === 1) {
                    throw $this->error(sprintf('"<%s>" is not a usable IRI reference.', $iri), $line);
                }

                $tokens[] = ['type' => 'iri', 'value' => $iri, 'line' => $line, 'extra' => ''];
                $index = $close + 1;

                continue;
            }

            if ($char === '"') {
                $close = $index + 1;
                $escaped = false;

                while ($close < $length) {
                    $current = $turtle[$close];

                    if (!$escaped && $current === '"') {
                        break;
                    }

                    $escaped = $current === '\\' && !$escaped;
                    $close++;
                }

                if ($close >= $length) {
                    throw $this->error('A string literal is not closed.', $line);
                }

                $value = substr($turtle, $index + 1, $close - $index - 1);
                $index = $close + 1;

                $extra = '';
                if (($turtle[$index] ?? '') === '@') {
                    $tag = $this->readWhile($turtle, $index + 1, '/[A-Za-z0-9\-]/');
                    $extra = 'lang:' . $tag;
                    $index += 1 + strlen($tag);
                } elseif (substr($turtle, $index, 2) === '^^') {
                    // The datatype arrives as the NEXT token, so it goes through the
                    // same IRI/CURIE resolution as any other term.
                    $extra = '^^';
                    $index += 2;
                }

                $tokens[] = ['type' => 'literal', 'value' => $value, 'line' => $line, 'extra' => $extra];

                continue;
            }

            if (str_contains('.;,[]()', $char)) {
                $tokens[] = ['type' => 'punct', 'value' => $char, 'line' => $line, 'extra' => ''];
                $index++;

                continue;
            }

            if ($char === '@') {
                $directive = $this->readWhile($turtle, $index + 1, '/[A-Za-z]+/');
                if ($directive !== 'prefix') {
                    throw $this->error(sprintf('@%s is not supported by this shape parser.', $directive), $line);
                }

                $tokens[] = ['type' => 'keyword', 'value' => '@prefix', 'line' => $line, 'extra' => ''];
                $index += 1 + strlen($directive);

                continue;
            }

            if (preg_match('/\G[+-]?(?:\d+\.\d+(?:[eE][+-]?\d+)?|\d+[eE][+-]?\d+|\d+)/', $turtle, $m, 0, $index) === 1) {
                $tokens[] = ['type' => 'number', 'value' => $m[0], 'line' => $line, 'extra' => ''];
                $index += strlen($m[0]);

                continue;
            }

            $token = $this->readTermToken($turtle, $index, $line);
            if ($token !== null) {
                $tokens[] = ['type' => $token['type'], 'value' => $token['value'], 'line' => $line, 'extra' => ''];
                $index = $token['end'];
            }
        }

        return $tokens;
    }

    /**
     * A bare term: a boolean, `a`, a prefixed name, or the default prefix.
     *
     * @return array{type: string, value: string, end: int}|null
     */
    private function readTermToken(string $turtle, int $index, int $line): ?array
    {
        foreach (['true' => 4, 'false' => 5] as $boolean => $length) {
            if (substr($turtle, $index, $length) === $boolean
                && !$this->isNameChar($turtle[$index + $length] ?? ' ')
            ) {
                return ['type' => 'literal', 'value' => $boolean, 'end' => $index + $length];
            }
        }

        $name = $this->readWhile($turtle, $index, '/[A-Za-z_][A-Za-z0-9_\-]*/');

        if ($name === '') {
            if (($turtle[$index] ?? '') !== ':') {
                throw $this->error(
                    sprintf('Unexpected character "%s" in the shape file.', $turtle[$index] ?? '<end of file>'),
                    $line
                );
            }

            $local = $this->readWhile($turtle, $index + 1, '/[A-Za-z0-9_\-%.]*/');

            return ['type' => 'pname', 'value' => ':' . $local, 'end' => $index + 1 + strlen($local)];
        }

        $afterName = $index + strlen($name);

        if (($turtle[$afterName] ?? '') === ':') {
            $local = $this->readWhile($turtle, $afterName + 1, '/[A-Za-z0-9_\-%.]*/');

            return [
                'type' => 'pname',
                'value' => $name . ':' . $local,
                'end' => $afterName + 1 + strlen($local),
            ];
        }

        if ($name === 'a') {
            return ['type' => 'keyword', 'value' => 'a', 'end' => $afterName];
        }

        throw $this->error(
            sprintf('"%s" is neither "a", a boolean nor a prefixed name.', $name),
            $line
        );
    }

    private function isNameChar(string $char): bool
    {
        return preg_match('/[A-Za-z0-9_\-]/', $char) === 1;
    }

    private function readWhile(string $subject, int $index, string $pattern): string
    {
        if (preg_match($pattern, $subject, $m, \PREG_OFFSET_CAPTURE, $index) !== 1
            || $m[0][1] !== $index
        ) {
            return '';
        }

        return $m[0][0];
    }

    // -- Statement reading ----------------------------------------------------

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     */
    private function readPrefixDirective(array $tokens, int &$position): void
    {
        $count = count($tokens);

        if ($position + 3 >= $count) {
            throw $this->error('@prefix needs a prefix name, a colon and an IRI.', $tokens[$position]['line']);
        }

        $nameToken = $tokens[$position + 1];
        $iriToken = $tokens[$position + 2];
        $position += 3;

        if ($nameToken['type'] !== 'pname' || !str_ends_with($nameToken['value'], ':')) {
            throw $this->error(
                '@prefix must be followed by `name:` and then an IRI reference.',
                $nameToken['line']
            );
        }

        if ($iriToken['type'] !== 'iri') {
            throw $this->error('@prefix must be followed by an IRI reference.', $iriToken['line']);
        }

        if (($tokens[$position]['type'] ?? '') === 'punct' && ($tokens[$position]['value'] ?? '') === '.') {
            $position++;
        }

        $prefix = substr($nameToken['value'], 0, -1);

        if (isset($this->prefixes[$prefix]) && $this->prefixes[$prefix] !== $iriToken['value']) {
            throw $this->error(sprintf(
                'The prefix "%s:" is declared twice, to different IRIs. Validation would then depend on which '
                . 'declaration was read last.',
                $prefix
            ), $nameToken['line']);
        }

        $this->prefixes[$prefix] = $iriToken['value'];
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     */
    private function readStatement(array $tokens, int &$position, int $depth = 0): void
    {
        if ($depth > self::MAX_NESTING) {
            throw $this->error('The shape file nests blank nodes more than 32 deep, which is not a shape file.');
        }

        $subject = $this->readSubject($tokens, $position, $depth);
        $this->readPredicateObjectList($tokens, $position, $subject, $depth);

        $next = $tokens[$position] ?? null;
        if ($next !== null && $next['type'] === 'punct' && $next['value'] === '.') {
            $position++;
        }
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     *
     * @return string The subject IRI or blank-node label.
     */
    private function readSubject(array $tokens, int &$position, int $depth): string
    {
        $token = $tokens[$position] ?? null;

        if ($token === null) {
            throw $this->error('The shape file ends where a subject was expected.');
        }

        if ($token['type'] === 'punct' && $token['value'] === '[') {
            $position++;
            $label = $this->newBlankNode();
            $this->readPredicateObjectList($tokens, $position, $label, $depth + 1);
            $this->expect($tokens, $position, ']');

            return $label;
        }

        if ($token['type'] === 'punct' && $token['value'] === '(') {
            throw $this->error('A collection cannot be the subject of a shape statement.', $token['line']);
        }

        $position++;

        return $this->resolveIri($token);
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     */
    private function readPredicateObjectList(
        array $tokens,
        int &$position,
        string $subject,
        int $depth
    ): void {
        $count = count($tokens);

        while ($position < $count) {
            $predicateToken = $tokens[$position];

            if ($predicateToken['type'] === 'punct'
                && ($predicateToken['value'] === '.' || $predicateToken['value'] === ']')
            ) {
                return;
            }

            if ($predicateToken['type'] === 'keyword' && $predicateToken['value'] === '@prefix') {
                return;
            }

            $predicate = $predicateToken['type'] === 'keyword' && $predicateToken['value'] === 'a'
                ? self::RDF_NS . 'type'
                : $this->resolveIri($predicateToken);

            $position++;
            $this->readObjectList($tokens, $position, $subject, $predicate, $depth);

            $next = $tokens[$position] ?? null;
            if ($next === null || $next['type'] !== 'punct' || $next['value'] !== ';') {
                return;
            }

            while (($tokens[$position] ?? null) !== null
                && $tokens[$position]['type'] === 'punct'
                && $tokens[$position]['value'] === ';'
            ) {
                $position++;
            }
        }
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     */
    private function readObjectList(
        array $tokens,
        int &$position,
        string $subject,
        string $predicate,
        int $depth
    ): void {
        while (true) {
            $this->readObject($tokens, $position, $subject, $predicate, $depth);

            $next = $tokens[$position] ?? null;
            if ($next === null || $next['type'] !== 'punct' || $next['value'] !== ',') {
                return;
            }

            $position++;
        }
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     */
    private function readObject(
        array $tokens,
        int &$position,
        string $subject,
        string $predicate,
        int $depth
    ): void {
        $token = $tokens[$position] ?? null;

        if ($token === null) {
            throw $this->error('The shape file ends where an object was expected.');
        }

        if ($token['type'] === 'punct' && ($token['value'] === '[' || $token['value'] === '(')) {
            $this->triples[$subject][] = [
                $predicate,
                $this->readCompoundObject($tokens, $position, $depth),
            ];

            return;
        }

        $this->triples[$subject][] = [$predicate, $this->readObjectTerm($tokens, $position, $depth)];
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     *
     * @return array<string, mixed>
     */
    private function readCompoundObject(array $tokens, int &$position, int $depth): array
    {
        if ($depth > self::MAX_NESTING) {
            throw $this->error('The shape file nests blank nodes or collections more than 32 deep.');
        }

        $opening = $tokens[$position]['value'];
        $position++;

        if ($opening === '[') {
            $label = $this->newBlankNode();
            $this->readPredicateObjectList($tokens, $position, $label, $depth + 1);
            $this->expect($tokens, $position, ']');

            return ['type' => 'bnode', 'value' => $label];
        }

        $items = [];
        $count = count($tokens);

        while ($position < $count) {
            $next = $tokens[$position];
            if ($next['type'] === 'punct' && $next['value'] === ')') {
                $position++;

                return ['type' => 'list', 'items' => $items];
            }

            if ($next['type'] === 'punct' && ($next['value'] === '[' || $next['value'] === '(')) {
                $items[] = $this->readCompoundObject($tokens, $position, $depth);

                continue;
            }

            $items[] = $this->readObjectTerm($tokens, $position, $depth);
        }

        throw $this->error('The shape file ends inside an unclosed collection.');
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     *
     * @return array<string, mixed>
     */
    private function readObjectTerm(array $tokens, int &$position, int $depth): array
    {
        $token = $tokens[$position] ?? null;

        if ($token === null) {
            throw $this->error('The shape file ends where a term was expected.');
        }

        $position++;

        if ($token['type'] === 'literal') {
            $datatype = null;
            $lang = null;

            if ($token['extra'] === '^^') {
                $typeToken = $tokens[$position] ?? null;
                if ($typeToken === null) {
                    throw $this->error('A "^^" datatype marker has no datatype after it.', $token['line']);
                }

                $position++;
                $datatype = $this->resolveIri($typeToken);
            } elseif (str_starts_with($token['extra'], 'lang:')) {
                $lang = substr($token['extra'], strlen('lang:'));
            }

            return [
                'type' => 'literal',
                'value' => $token['value'],
                'written' => $token['value'],
                'datatype' => $datatype,
                'lang' => $lang,
            ];
        }

        // Integers, decimals and booleans are LITERALS in Turtle, so `sh:minCount 5` is a
        // literal "5" rather than a prefixed name. Resolving them as terms is what makes
        // an entirely valid shape file unparseable.
        if ($token['type'] === 'number' || $token['type'] === 'boolean') {
            return [
                'type' => 'literal',
                'value' => $token['value'],
                'written' => $token['value'],
                'datatype' => null,
                'lang' => null,
            ];
        }

        // ============================================================================
        // WHY BOTH `value` AND `written` ARE KEPT
        // ============================================================================
        // `value` is the resolved absolute IRI, which is what MATCHING needs — a shape
        // file may write `dfc-b:Organization` while the document writes the full IRI.
        //
        // `written` is the CURIE the shape file actually used. That is what must be
        // reported: a violation diagnostic whose predicate is
        // `https://w3id.org/dfc/ontology/src/DFC_BusinessOntology.owl#name` is
        // unusable in an error body, because {@see \Civi\Dfc\V2\Controller\Error\ValidationDiagnostic}
        // accepts only short tokens — and `dfc-b:name` is what a developer needs to see.
        return [
            'type' => 'iri',
            'value' => $this->resolveIri($token),
            'written' => $token['value'],
        ];
    }

    /**
     * @param list<array{type: string, value: string, line: int, extra: string}> $tokens
     */
    private function expect(array $tokens, int &$position, string $punctuation): void
    {
        $token = $tokens[$position] ?? null;

        if ($token === null || $token['type'] !== 'punct' || $token['value'] !== $punctuation) {
            throw $this->error(
                sprintf('Expected "%s".', $punctuation),
                $token['line'] ?? null
            );
        }

        $position++;
    }

    /**
     * @param array{type: string, value: string, line: int, extra: string} $token
     */
    private function resolveIri(array $token): string
    {
        if ($token['type'] === 'iri') {
            return $token['value'];
        }

        $colon = strpos($token['value'], ':');

        if ($colon === false) {
            throw $this->error(
                sprintf(
                    '"%s" is not a prefixed name. Every IRI in a shape must be an absolute <IRI> or prefix:local.',
                    $token['value']
                ),
                $token['line']
            );
        }

        $prefix = substr($token['value'], 0, $colon);

        if (!isset($this->prefixes[$prefix])) {
            throw $this->error(
                sprintf('The prefix "%s:" is used but never declared.', $prefix),
                $token['line']
            );
        }

        return $this->prefixes[$prefix] . substr($token['value'], $colon + 1);
    }

    // -- Interpretation -------------------------------------------------------

    private function interpret(): ShaclShapeSet
    {
        $shapes = [];

        foreach ($this->triples as $subject => $statements) {
            $byPredicate = self::indexByPredicate($statements);

            if (!$this->isNodeShape($byPredicate)) {
                continue;
            }

            $shapes[] = $this->buildNodeShape($subject, $byPredicate);
        }

        return new ShaclShapeSet($shapes, $this->prefixes, $this->ignoredAnnotations);
    }

    /**
     * Group a subject's statements by predicate.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $statements
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function indexByPredicate(array $statements): array
    {
        $byPredicate = [];

        foreach ($statements as [$predicate, $term]) {
            $byPredicate[$predicate][] = $term;
        }

        return $byPredicate;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byPredicate
     */
    private function isNodeShape(array $byPredicate): bool
    {
        foreach ($byPredicate[self::RDF_NS . 'type'] ?? [] as $term) {
            if (($term['type'] ?? null) === 'iri' && $term['value'] === self::SHACL_NS . 'NodeShape') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byPredicate
     */
    private function buildNodeShape(string $subject, array $byPredicate): ShaclNodeShape
    {
        // Written form for reporting, resolved form for matching: see readObjectTerm().
        $targetClasses = [];
        $targetClassIris = [];
        foreach ($this->termsFor($byPredicate, 'sh:targetClass') as $term) {
            $targetClasses[] = (string) ($term['written'] ?? $term['value']);
            $targetClassIris[] = (string) $term['value'];
        }

        // Resolved: `sh:targetNode` is matched against a document's `@id`, which is
        // always an absolute IRI.
        $targetNodes = [];
        foreach ($this->termsFor($byPredicate, 'sh:targetNode') as $term) {
            $targetNodes[] = (string) $term['value'];
        }

        $properties = [];
        foreach ($this->termsFor($byPredicate, 'sh:property') as $term) {
            $label = (string) ($term['value'] ?? '');

            if (($term['type'] ?? null) !== 'bnode') {
                throw $this->error(sprintf(
                    'A sh:property must be an inline blank node in this subset. "%s" is an IRI reference, and '
                    . 'resolving it would need a shape catalogue this parser does not have.',
                    $label
                ));
            }

            if (!isset($this->triples[$label])) {
                throw $this->error('A sh:property blank node carries no statements.');
            }

            $properties[] = $this->buildPropertyShape($label);
        }

        $ignoredProperties = [];
        foreach ($this->termsFor($byPredicate, 'sh:ignoredProperties') as $term) {
            foreach ((array) ($term['items'] ?? [$term]) as $item) {
                $ignoredProperties[] = (string) ($item['value'] ?? '');
            }
        }

        return new ShaclNodeShape(
            $subject,
            $targetClasses,
            $targetClassIris,
            $targetNodes,
            $properties,
            $this->booleanFor($byPredicate, 'sh:closed'),
            $ignoredProperties
        );
    }

    private function buildPropertyShape(string $label): ShaclPropertyShape
    {
        $byPredicate = self::indexByPredicate($this->triples[$label]);

        $pathTerms = $this->termsFor($byPredicate, 'sh:path');
        if (count($pathTerms) !== 1) {
            throw $this->error('A sh:property must have exactly one sh:path.');
        }

        $classes = [];
        foreach ($this->termsFor($byPredicate, 'sh:class') as $term) {
            $classes[] = (string) $term['value'];
        }

        $vocabulary = [];
        foreach ($this->termsFor($byPredicate, 'sh:in') as $term) {
            if (($term['type'] ?? null) !== 'list') {
                throw $this->error('sh:in must be a collection `( ... )` in this subset.');
            }

            foreach ($term['items'] as $item) {
                $vocabulary[] = (string) ($item['value'] ?? '');
            }
        }

        $nodeKind = null;
        foreach ($this->termsFor($byPredicate, 'sh:nodeKind') as $term) {
            $nodeKind = self::localName((string) $term['value']);
        }

        $datatype = null;
        foreach ($this->termsFor($byPredicate, 'sh:datatype') as $term) {
            $datatype = self::localName((string) $term['value']);
        }

        $flags = null;
        foreach ($this->termsFor($byPredicate, 'sh:flags') as $term) {
            $flags = (string) $term['value'];
        }

        return new ShaclPropertyShape(
            // The CURIE the shape file wrote, because that is what a client can act on;
            // the resolved IRI, because that is what a document key may be.
            (string) ($pathTerms[0]['written'] ?? $pathTerms[0]['value']),
            (string) $pathTerms[0]['value'],
            $this->integerFor($byPredicate, 'sh:minCount'),
            $this->integerFor($byPredicate, 'sh:maxCount'),
            $datatype,
            $nodeKind,
            $classes,
            $vocabulary,
            $this->literalFor($byPredicate, 'sh:pattern'),
            $flags,
            $this->integerFor($byPredicate, 'sh:minLength'),
            $this->integerFor($byPredicate, 'sh:maxLength'),
            $this->literalFor($byPredicate, 'sh:hasValue'),
            $this->termFor($byPredicate, 'sh:severity') ?? 'Violation'
        );
    }

    /**
     * Read the terms for a `sh:` predicate, classifying everything else on the subject
     * as an annotation or refusing the shape.
     *
     * @param array<string, list<array<string, mixed>>> $byPredicate
     *
     * @return list<array<string, mixed>>
     */
    private function termsFor(array $byPredicate, string $curie): array
    {
        $iri = $this->expandCurie($curie);

        foreach (array_keys($byPredicate) as $predicate) {
            if ($predicate !== $iri) {
                $this->classify($predicate);

                continue;
            }

            foreach ($byPredicate[$predicate] as $term) {
                if ($term['type'] === 'list' && $curie !== 'sh:in' && $curie !== 'sh:ignoredProperties') {
                    throw $this->error(sprintf(
                        'The constraint "%s" does not take a collection in this subset.',
                        $curie
                    ));
                }
            }

            return $byPredicate[$predicate];
        }

        return [];
    }

    /**
     * Refuse anything whose silence would weaken validation.
     */
    private function classify(string $predicateIri): void
    {
        if (str_starts_with($predicateIri, self::SHACL_NS)) {
            $local = self::localName($predicateIri);

            if (in_array($local, self::IMPLEMENTED_SHACL, true)) {
                return;
            }

            if (in_array($local, self::ANNOTATIONS_SHACL, true)) {
                $this->ignoredAnnotations[] = 'sh:' . $local;

                return;
            }

            throw $this->error(sprintf(
                'The shape uses "sh:%s", which this SHACL subset does not implement. The shape is refused rather '
                . 'than the constraint being silently ignored: a validator that skips what it does not '
                . 'understand reports documents as valid that the shapes forbid.',
                $local
            ));
        }

        if (str_starts_with($predicateIri, self::RDF_NS)) {
            // rdf:type on the shape itself, and the rdf:first/rest/nil of a collection.
            $this->ignoredAnnotations[] = $this->curieOf($predicateIri);

            return;
        }

        foreach (self::ANNOTATION_NAMESPACES as $namespace) {
            if (str_starts_with($predicateIri, $namespace)) {
                $this->ignoredAnnotations[] = $this->curieOf($predicateIri);

                return;
            }
        }

        throw $this->error(sprintf(
            'The shape uses the predicate "%s", which is neither a sh: constraint this subset implements nor a '
            . 'known annotation namespace. Refused rather than ignored.',
            $predicateIri
        ));
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byPredicate
     */
    private function integerFor(array $byPredicate, string $curie): ?int
    {
        $value = $this->literalFor($byPredicate, $curie);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw $this->error(sprintf('%s must be a non-negative integer. Got "%s".', $curie, $value));
        }

        return (int) $value;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byPredicate
     */
    private function booleanFor(array $byPredicate, string $curie): bool
    {
        $value = $this->literalFor($byPredicate, $curie);

        if ($value === null) {
            return false;
        }

        if ($value !== 'true' && $value !== 'false') {
            throw $this->error(sprintf('%s must be true or false. Got "%s".', $curie, $value));
        }

        return $value === 'true';
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byPredicate
     */
    private function literalFor(array $byPredicate, string $curie): ?string
    {
        foreach ($this->termsFor($byPredicate, $curie) as $term) {
            if (($term['type'] ?? null) === 'literal') {
                return (string) $term['value'];
            }
        }

        return null;
    }

    /**
     * Read a term whose value may be a literal OR an IRI, reducing an IRI to its local
     * name.
     *
     * `sh:severity sh:Warning` is written as an IRI because that is how SHACL defines
     * the vocabulary, and a caller that only accepted literals would silently default
     * every severity to Violation — i.e. treat a warning as fatal.
     *
     * @param array<string, list<array<string, mixed>>> $byPredicate
     */
    private function termFor(array $byPredicate, string $curie): ?string
    {
        foreach ($this->termsFor($byPredicate, $curie) as $term) {
            if (($term['type'] ?? null) === 'literal') {
                return (string) $term['value'];
            }

            return self::localName((string) $term['value']);
        }

        return null;
    }

    // -- Small helpers --------------------------------------------------------

    private function expandCurie(string $curie): string
    {
        $colon = strpos($curie, ':');
        if ($colon === false) {
            throw $this->error(sprintf('"%s" is not a CURIE.', $curie));
        }

        $prefix = substr($curie, 0, $colon);

        if (!isset($this->prefixes[$prefix])) {
            throw $this->error(sprintf('The SHACL prefix "%s:" is not declared by the shape file.', $prefix));
        }

        return $this->prefixes[$prefix] . substr($curie, $colon + 1);
    }

    public static function localName(string $iri): string
    {
        $position = strrpos($iri, '#');
        if ($position === false) {
            $position = strrpos($iri, '/');
        }

        return $position === false ? $iri : substr($iri, $position + 1);
    }

    private function curieOf(string $iri): string
    {
        foreach ($this->prefixes as $prefix => $namespace) {
            if ($prefix !== '' && str_starts_with($iri, $namespace)) {
                return $prefix . ':' . self::localName($iri);
            }
        }

        return $iri;
    }

    private function newBlankNode(): string
    {
        return '_:b' . ++$this->blankNodeCounter;
    }

    private function error(string $message, ?int $line = null): ShaclShapeUnavailableException
    {
        return new ShaclShapeUnavailableException(sprintf(
            'SHACL shape line %d: %s',
            $line ?? 1,
            $message
        ));
    }
}