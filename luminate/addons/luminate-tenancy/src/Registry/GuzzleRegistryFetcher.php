<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Registry;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * GET {pull_url}, signed like every registry request. https only unless allow_http (dev). Redirects are not followed: a signed
 * request must not be replayed to a host the operator did not configure.
 */
final class GuzzleRegistryFetcher implements RegistryFetcher
{
    /** @param array{url:?string,allow_http:bool,timeout:int,connect_timeout:int} $settings */
    public function __construct(private readonly array $settings, private readonly RegistryAuth $auth)
    {
    }

    public function fetch(): array
    {
        $url = (string) ($this->settings['url'] ?? '');
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw RegistryRejected::misconfigured('pull url is not set or not a URL');
        }
        if ($parts['scheme'] !== 'https' && !($this->settings['allow_http'] && $parts['scheme'] === 'http')) {
            throw RegistryRejected::misconfigured('pull url must be https');
        }
        $path = ($parts['path'] ?? '/') === '' ? '/' : ($parts['path'] ?? '/');
        $ts = (string) time();

        try {
            $response = (new Client(['allow_redirects' => false, 'http_errors' => false]))->request('GET', $url, [
                'timeout' => (int) $this->settings['timeout'],
                'connect_timeout' => (int) $this->settings['connect_timeout'],
                'headers' => [
                    'Accept' => 'application/json',
                    RegistryAuth::TIMESTAMP => $ts,
                    RegistryAuth::SIGNATURE => $this->auth->sign($ts, 'GET', $path, ''),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('registry pull transport failure: ' . $e::class);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
            'timestamp' => $response->hasHeader(RegistryAuth::TIMESTAMP) ? $response->getHeaderLine(RegistryAuth::TIMESTAMP) : null,
            'signature' => $response->hasHeader(RegistryAuth::SIGNATURE) ? $response->getHeaderLine(RegistryAuth::SIGNATURE) : null,
        ];
    }
}
