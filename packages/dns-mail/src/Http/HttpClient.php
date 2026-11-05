<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http;

/**
 * Minimal HTTP client so the package has no framework dependency and tests
 * can swap in a fake.
 */
interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
