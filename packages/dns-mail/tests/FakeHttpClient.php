<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Tests;

use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string,string>, body: ?string}> */
    public array $requests = [];

    /** @param list<HttpResponse|callable> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function queue(int $status, array $payload): void
    {
        $this->responses[] = new HttpResponse($status, json_encode($payload));
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->responses) ?? new HttpResponse(500, '{}');

        return $next;
    }
}
