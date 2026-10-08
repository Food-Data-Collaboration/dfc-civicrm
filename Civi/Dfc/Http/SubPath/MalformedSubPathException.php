<?php

declare(strict_types=1);

namespace Civi\Dfc\Http\SubPath;

/**
 * The routed sub-path did not match the shape the handler expects.
 *
 * Distinct from a 404 (no such resource) and from a 400 (malformed request).
 * This is the route saying "I understood the request, and the path is not one
 * of my shapes" — which is a 404 in HTTP terms, because a path we cannot shape
 * is by definition not a resource we expose. Kept as its own type so a handler
 * cannot accidentally report it as a client error and invite a client to
 * "fix" a URI that never existed.
 */
final class MalformedSubPathException extends \RuntimeException
{
}