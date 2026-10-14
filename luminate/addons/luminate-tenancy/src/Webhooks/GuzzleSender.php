<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

use GuzzleHttp\Client;

/** Redirects are NOT followed (a 3xx is a failure), the body of the response is never read, and errors never echo the URL. */
final class GuzzleSender implements Sender
{
    public function post(string $url, string $body, array $headers, int $timeout, int $connectTimeout): SendResult
    {
        try {
            $response = (new Client([
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ]))->post($url, ['headers' => $headers, 'body' => $body]);

            return new SendResult($response->getStatusCode());
        } catch (\Throwable $e) {
            return new SendResult(null, $e::class . ': ' . self::scrub($e->getMessage(), $url));
        }
    }

    private static function scrub(string $message, string $url): string
    {
        $message = str_replace($url, '<receiver>', $message);

        return mb_substr(preg_replace('/https?:\/\/\S+/i', '<url>', $message) ?? '', 0, 200);
    }
}
