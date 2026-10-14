<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/**
 * Where events go and how they are signed. Operator configuration (env), never tenant-supplied: the receiver URL is not
 * attacker-controlled, so there is no per-tenant SSRF surface. Fail closed: a half-configured module sends nothing.
 */
final class WebhookSettings
{
    public const MIN_SECRET_BYTES = 16;

    public function __construct(
        public readonly ?string $url,
        public readonly ?string $secret,
        public readonly bool $allowHttp = false,
        public readonly int $timeout = 10,
        public readonly int $connectTimeout = 5,
    ) {
    }

    /** @param array<string,mixed> $c the `luminate-tenancy.webhooks` config */
    public static function fromConfig(array $c): self
    {
        return new self(
            isset($c['url']) ? (string) $c['url'] : null,
            isset($c['secret']) ? (string) $c['secret'] : null,
            (bool) ($c['allow_http'] ?? false),
            (int) ($c['timeout'] ?? 10),
            (int) ($c['connect_timeout'] ?? 5),
        );
    }

    /** No receiver configured: the module is off (not an error). */
    public function enabled(): bool
    {
        return $this->url !== null && trim($this->url) !== '';
    }

    /** Null when events may be recorded and sent; otherwise the reason sending is refused. */
    public function unusable(): ?string
    {
        if (!$this->enabled()) {
            return 'webhook receiver URL is not configured';
        }
        if ($this->secret === null || strlen($this->secret) < self::MIN_SECRET_BYTES) {
            return 'webhook secret is missing or shorter than ' . self::MIN_SECRET_BYTES . ' bytes';
        }
        $parts = parse_url((string) $this->url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'webhook receiver URL is malformed';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'webhook receiver URL must not embed credentials';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowHttp)) {
            return 'webhook receiver URL must be https';
        }

        return null;
    }
}
