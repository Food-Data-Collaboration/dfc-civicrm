<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Identity\Exception;

/**
 * An identifier or URI supplied to the URI factory cannot be turned into a
 * well-formed DFC URI.
 *
 * Thrown for an empty / whitespace-only / control-character-bearing identifier,
 * an over-long identifier, a path-traversal identifier (`.` or `..`), or a
 * collection / resource label that is not a safe relative label.
 *
 * WHY THROW INSTEAD OF SANITISING
 *   A URI minted from a bad identifier is a broken public identity: it is
 *   dereferenceable by third parties, it is written into other people's RDF
 *   stores, and it cannot be un-published. Failing loudly at mint time is the
 *   only safe behaviour. Silent "cleaning" of an identifier would also break
 *   the round-trip guarantee that `match*()` gives you: the inverse of
 *   `semanticResourceUri()` must return the identifier you put in.
 *
 * @package Civi\Dfc
 */
final class InvalidUriException extends \InvalidArgumentException implements DfcIdentityException
{
}