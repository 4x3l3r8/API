<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

interface Sender
{
    /** @param array<string,string> $headers */
    public function post(string $url, string $body, array $headers, int $timeout, int $connectTimeout): SendResult;
}
