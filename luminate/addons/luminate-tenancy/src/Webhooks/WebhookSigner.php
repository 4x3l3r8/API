<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/**
 * Contract v4 §F signing: `sha256=<hex HMAC-SHA256(webhook_secret, "{delivery_id}.{timestamp}.{raw_body}")>`.
 *
 * The signature covers the RAW body bytes exactly as sent, binding payload + delivery id + first-send time. There is no
 * skew check on the receiving side (a retry may arrive hours later); replay protection is delivery-id dedup.
 */
final class WebhookSigner
{
    public static function signature(string $secret, string $deliveryId, int $timestamp, string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $deliveryId . '.' . $timestamp . '.' . $rawBody, $secret);
    }

    /** Constant-time check. Reference implementation for receivers and for our own tests. */
    public static function verify(string $header, string $secret, string $deliveryId, int $timestamp, string $rawBody): bool
    {
        return hash_equals(self::signature($secret, $deliveryId, $timestamp, $rawBody), $header);
    }
}
