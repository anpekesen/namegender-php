<?php

namespace NameGender\Tests;

use NameGender\NameGenderException;
use NameGender\WebhookVerificationException;
use NameGender\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The vector is shared with the server and the other SDKs: a change that
 * breaks it breaks every receiver.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_vector';

    private const BODY = '{"id":"evt_1","type":"webhook.test"}';

    private const SIGNATURE = '857fcddfea47617c448b7a8e6537bbd59c9922a37c5273b2709812fbadb29e50';

    private const NOW = 1700000000;

    public function test_shared_vector_verifies_and_returns_the_event(): void
    {
        $event = Webhooks::verify(self::BODY, 't=1700000000,v1='.self::SIGNATURE, self::SECRET, now: self::NOW);

        $this->assertSame(['id' => 'evt_1', 'type' => 'webhook.test'], $event);
    }

    public function test_any_matching_v1_is_enough_during_a_rotation(): void
    {
        $header = 't=1700000000,v1='.str_repeat('0', 64).',v1='.self::SIGNATURE;

        $this->assertSame('evt_1', Webhooks::verify(self::BODY, $header, self::SECRET, now: self::NOW)['id']);
    }

    public function test_timestamp_inside_the_tolerance_passes(): void
    {
        $this->assertSame('evt_1', Webhooks::verify(self::BODY, 't=1700000000,v1='.self::SIGNATURE, self::SECRET, now: self::NOW + 300)['id']);
    }

    /**
     * @return array<string, array{string, ?string, string, int}>
     */
    public static function rejected(): array
    {
        $header = 't=1700000000,v1='.self::SIGNATURE;

        return [
            'tampered body' => [str_replace('webhook.test', 'batch.completed', self::BODY), $header, self::SECRET, self::NOW],
            'wrong secret' => [self::BODY, $header, 'whsec_other', self::NOW],
            'too old' => [self::BODY, $header, self::SECRET, self::NOW + 301],
            'from the future' => [self::BODY, $header, self::SECRET, self::NOW - 301],
            'missing header' => [self::BODY, null, self::SECRET, self::NOW],
            'empty header' => [self::BODY, '', self::SECRET, self::NOW],
            'malformed header' => [self::BODY, 't=abc,v1=', self::SECRET, self::NOW],
            'no signature' => [self::BODY, 't=1700000000', self::SECRET, self::NOW],
            'upper-case hex' => [self::BODY, 't=1700000000,v1='.strtoupper(self::SIGNATURE), self::SECRET, self::NOW],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects(string $body, ?string $header, string $secret, int $now): void
    {
        try {
            Webhooks::verify($body, $header, $secret, now: $now);
            $this->fail('Expected WebhookVerificationException');
        } catch (WebhookVerificationException $e) {
            $this->assertInstanceOf(NameGenderException::class, $e);
        }
    }

    public function test_an_empty_secret_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhooks::verify(self::BODY, 't=1700000000,v1='.self::SIGNATURE, '', now: self::NOW);
    }
}
