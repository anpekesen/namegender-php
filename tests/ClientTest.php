<?php

namespace NameGender\Tests;

use NameGender\Client;
use NameGender\NameGenderException;
use PHPUnit\Framework\TestCase;

/**
 * Runs the client over real HTTP against tests/fixtures/router.php served by
 * PHP's built-in web server, and asserts on what actually went over the wire.
 */
final class ClientTest extends TestCase
{
    private const KEY = 'ng_test_key_123';

    /** @var resource|null */
    private static $server = null;

    private static string $origin;

    private static string $log;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::fail("Could not find a free port: $errstr");
        }
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$origin = "http://127.0.0.1:$port";
        self::$log = tempnam(sys_get_temp_dir(), 'namegender-test-');

        $env = getenv();
        $env['NAMEGENDER_TEST_LOG'] = self::$log;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        // Array form skips the shell, so proc_terminate() reaches php itself.
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__.'/fixtures/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            __DIR__.'/fixtures',
            $env,
        );
        if (! is_resource(self::$server)) {
            self::fail('Could not start the stand-in API server');
        }

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::tearDownAfterClass();
        self::fail("Stand-in API server did not start on port $port");
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        if (isset(self::$log) && is_file(self::$log)) {
            unlink(self::$log);
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    private function client(string $prefix = '/api/v1'): Client
    {
        return new Client(self::KEY, self::$origin.$prefix);
    }

    /**
     * The request the stand-in API last received, with the body decoded.
     *
     * @return array{method: string, path: string, authorization: ?string, content_type: ?string, raw_body: string, body: mixed}
     */
    private function lastRequest(): array
    {
        $contents = file_get_contents(self::$log);
        $this->assertNotSame('', $contents, 'The stand-in API received no request');
        $request = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $request['body'] = $request['raw_body'] === '' ? null : json_decode($request['raw_body'], true, 512, JSON_THROW_ON_ERROR);

        return $request;
    }

    private function assertSent(string $method, string $path, ?array $body): void
    {
        $request = $this->lastRequest();
        $this->assertSame($method, $request['method']);
        $this->assertSame($path, $request['path']);
        $this->assertSame('Bearer '.self::KEY, $request['authorization']);
        $this->assertSame($body, $request['body']);
        if ($body !== null) {
            $this->assertSame('application/json', $request['content_type']);
        }
    }

    private function assertResult(array $result): void
    {
        foreach (['query', 'name', 'gender', 'country', 'probability', 'sample_size', 'took_ms', 'confidence', 'source', 'matched_as'] as $field) {
            $this->assertArrayHasKey($field, $result);
        }
    }

    private function assertCredits(array $response, int $charged): void
    {
        $this->assertSame($charged, $response['credits_charged']);
        $this->assertSame(99, $response['credits_remaining']);
        $this->assertSame('2026.09', $response['data_version']);
        $this->assertSame('req_1', $response['request_id']);
    }

    public function test_constructor_rejects_an_empty_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function test_name_sends_only_the_name_when_nothing_else_is_given(): void
    {
        $result = $this->client()->name('Ayşe');

        $this->assertSent('POST', '/api/v1/gender', ['name' => 'Ayşe']);
        $this->assertResult($result);
        $this->assertCredits($result, 1);
        $this->assertSame('Ayşe', $result['query']);
        $this->assertSame('female', $result['gender']);
        $this->assertSame(99, $result['probability']);
        $this->assertSame(1234, $result['sample_size']);
        $this->assertNull($result['country']);
    }

    public function test_name_sends_country_and_options_under_their_api_names(): void
    {
        $result = $this->client()->name('Andrea', 'IT', ['ai_fallback' => true, 'best_guess' => false]);

        $this->assertSent('POST', '/api/v1/gender', ['name' => 'Andrea', 'country' => 'IT', 'ai_fallback' => true, 'best_guess' => false]);
        $this->assertResult($result);
        $this->assertSame('IT', $result['country']);
    }

    public function test_email(): void
    {
        $this->client()->email('ayse.yilmaz@example.com');
        $this->assertSent('POST', '/api/v1/gender/email', ['email' => 'ayse.yilmaz@example.com']);

        $result = $this->client()->email('ayse.yilmaz@example.com', 'TR', ['best_guess' => true]);
        $this->assertSent('POST', '/api/v1/gender/email', ['email' => 'ayse.yilmaz@example.com', 'country' => 'TR', 'best_guess' => true]);
        $this->assertResult($result);
        $this->assertCredits($result, 1);
        $this->assertSame('ayse.yilmaz@example.com', $result['query']);
    }

    public function test_username(): void
    {
        $this->client()->username('ayse_1990');
        $this->assertSent('POST', '/api/v1/gender/username', ['username' => 'ayse_1990']);

        $result = $this->client()->username('ayse_1990', 'TR', ['ai_fallback' => true]);
        $this->assertSent('POST', '/api/v1/gender/username', ['username' => 'ayse_1990', 'country' => 'TR', 'ai_fallback' => true]);
        $this->assertResult($result);
        $this->assertCredits($result, 1);
        $this->assertSame('ayse_1990', $result['query']);
    }

    public function test_bulk_sends_names_as_a_json_array_with_type(): void
    {
        $result = $this->client()->bulk(['Ayşe', 'John'], 'US', 'name', ['best_guess' => true]);

        $this->assertSent('POST', '/api/v1/gender/bulk', ['names' => ['Ayşe', 'John'], 'country' => 'US', 'type' => 'name', 'best_guess' => true]);
        $this->assertCredits($result, 2);
        $this->assertSame(5, $result['took_ms']);
        $this->assertSame(['total' => 2, 'identified' => 2, 'unknown' => 0, 'match_rate' => 100], $result['summary']);
        $this->assertCount(2, $result['results']);
        $this->assertResult($result['results'][0]);
        $this->assertSame('John', $result['results'][1]['query']);
    }

    public function test_bulk_with_one_name_or_non_list_keys_still_sends_a_json_array(): void
    {
        $this->client()->bulk(['Ayşe']);
        $this->assertSent('POST', '/api/v1/gender/bulk', ['names' => ['Ayşe'], 'type' => 'name']);
        $this->assertStringContainsString('"names":["Ay', $this->lastRequest()['raw_body']);

        $this->client()->bulk([3 => 'ayse@example.com', 'x' => 'john@example.com'], null, 'email');
        $this->assertSent('POST', '/api/v1/gender/bulk', ['names' => ['ayse@example.com', 'john@example.com'], 'type' => 'email']);
        $this->assertStringContainsString('"names":["ayse@example.com","john@example.com"]', $this->lastRequest()['raw_body']);
    }

    public function test_countries_sends_limit_only_when_given(): void
    {
        $this->client()->countries('Mehmet');
        $this->assertSent('POST', '/api/v1/gender/countries', ['name' => 'Mehmet']);

        $result = $this->client()->countries('Mehmet', 10);
        $this->assertSent('POST', '/api/v1/gender/countries', ['name' => 'Mehmet', 'limit' => 10]);

        $this->assertCredits($result, 1);
        $this->assertSame('Mehmet', $result['name']);
        $this->assertSame(['counted_sources', 'counted_countries', 'attested_countries', 'note'], array_keys($result['basis']));
        $this->assertCount(2, $result['registrations']);
        $this->assertSame(['country', 'count', 'share', 'gender', 'probability', 'source'], array_keys($result['registrations'][0]));
        $this->assertSame(58.97, $result['registrations'][0]['share']);
        $this->assertSame(['DE', 'NL', 'TR'], $result['attested_in']);
    }

    public function test_account_is_a_get_without_a_body(): void
    {
        $result = $this->client()->account();

        $this->assertSent('GET', '/api/v1/me', null);
        $this->assertSame('dev@example.com', $result['email']);
        $this->assertSame(99, $result['credits_remaining']);
        $this->assertSame(50, $result['purchased_credits']);
        $this->assertSame(49, $result['free_today']);
        $this->assertSame(50, $result['free_daily_limit']);
        $this->assertSame(1200, $result['lifetime_requests']);
        $this->assertSame('2026.09', $result['data_version']);
    }

    public function test_base_url_with_a_trailing_slash(): void
    {
        $this->client('/api/v1/')->name('Ayşe');
        $this->assertSent('POST', '/api/v1/gender', ['name' => 'Ayşe']);
    }

    public function test_non_2xx_throws_with_status_and_api_error(): void
    {
        try {
            $this->client('/nowhere')->name('Ayşe');
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(402, $e->status);
            $this->assertSame(402, $e->getCode());
            $this->assertSame('Out of credits.', $e->getMessage());
            $this->assertSame('no_credits', $e->body['error']);
            $this->assertSame('req_9', $e->body['request_id']);
        }
        $this->assertSent('POST', '/nowhere/gender', ['name' => 'Ayşe']);
    }

    public function test_non_json_error_body_throws_with_status_and_no_body(): void
    {
        try {
            $this->client('/html-error')->name('Ayşe');
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(502, $e->status);
            $this->assertSame('NameGender request failed', $e->getMessage());
            $this->assertNull($e->body);
        }
    }

    public function test_json_scalar_error_body_throws_the_client_exception(): void
    {
        try {
            $this->client('/scalar-error')->name('Ayşe');
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(500, $e->status);
            $this->assertNull($e->body);
        }
    }

    public function test_connection_failure_throws_with_status_zero(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        try {
            (new Client(self::KEY, "http://127.0.0.1:$closedPort/api/v1"))->account();
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(0, $e->status);
            $this->assertNull($e->body);
        }
    }

    public function test_control_characters_round_trip_as_valid_json(): void
    {
        $name = "Ay\tşe\u{0001}\"\\ John";

        $result = $this->client()->name($name);

        $request = $this->lastRequest();
        $this->assertSame(['name' => $name], $request['body']);
        $this->assertStringContainsString('\t', $request['raw_body']);
        $this->assertStringContainsString('\u0001', $request['raw_body']);
        $this->assertStringNotContainsString("\t", $request['raw_body']);
        $this->assertSame($name, $result['query']);
    }
}
