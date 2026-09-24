<?php

namespace NameGender;

/**
 * Webhook signature check.
 *
 * Pass the body exactly as received (`file_get_contents('php://input')`, or
 * `$request->getContent()` in Laravel and Symfony). Decoding the JSON and
 * encoding it again changes the bytes, and the signature no longer matches.
 */
final class Webhooks
{
    /**
     * Check the `NameGender-Signature` header and return the decoded event.
     *
     * During a secret rotation the header carries two `v1` values; either one
     * matching is enough. `$tolerance` is how far, in seconds, the signed
     * timestamp may be from `$now` (the current time unless given).
     *
     * @return array{id: string, type: string, created_at: string, api_version: string, data: array{object: array}}
     *
     * @throws WebhookVerificationException
     */
    public static function verify(string $payload, ?string $signatureHeader, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('secret is required');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookVerificationException('Missing NameGender-Signature header');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new WebhookVerificationException('Malformed NameGender-Signature header');
        }

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            throw new WebhookVerificationException('Webhook timestamp is outside the tolerance window');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $matched = false;
        foreach ($signatures as $signature) {
            // No early exit, so the time taken does not depend on which one matched.
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (! $matched) {
            throw new WebhookVerificationException('Webhook signature does not match');
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            throw new WebhookVerificationException('Webhook body is not a JSON object');
        }

        return $event;
    }
}
