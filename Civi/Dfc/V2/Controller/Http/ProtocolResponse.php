<?php

declare(strict_types=1);

namespace Civi\Dfc\V2\Controller\Http;

/**
 * A framework-agnostic HTTP response: status, headers, body.
 *
 * PRD-002 §1 scopes out a parallel discovery protocol and a parallel URL
 * structure, and §3 CP-1 wants the protocol testable "without a CMS bootstrap".
 * That means the protocol layer must be able to say "this is the response" in
 * terms CiviCRM does not know about. A PSR-7 dependency would have been the
 * obvious choice and would have been the wrong one: CiviCRM core does not use
 * PSR-7, so adopting it would add a translation layer that only lane-2 would ever
 * exercise. Three PHP scalars are the whole abstraction the routes need, and
 * lane-2's route wiring turns them into whatever its dispatcher produces.
 *
 * @package Civi\Dfc
 */
final class ProtocolResponse
{
    private readonly int $status;

    private readonly ResponseHeaders $headers;

    private readonly string $body;

    /**
     * @param int                 $status  100..599.
     * @param string              $body    The exact bytes to send. Not an array:
     *                                     an array here is how a body gets
     *                                     re-encoded with different flags than the
     *                                     ETag was computed over.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(int $status, ResponseHeaders $headers, string $body = '')
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf(
                'An HTTP status must be between 100 and 599, got %d.',
                $status
            ));
        }

        if ($body !== '' && $headers->first('Content-Type') === null) {
            throw new \InvalidArgumentException(
                'A response with a body must declare a Content-Type. An undeclared body is served as '
                . 'application/octet-stream by most clients, which turns a DFC document into a download.'
            );
        }

        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): ResponseHeaders
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function contentType(): ?string
    {
        return $this->headers->first('Content-Type');
    }

    public function etag(): ?string
    {
        return $this->headers->first('ETag');
    }

    public function isError(): bool
    {
        return $this->status >= 400;
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers->toArray(),
            'body' => $this->body,
        ];
    }
}
