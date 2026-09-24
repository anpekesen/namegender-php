<?php

namespace NameGender\Tests;

use NameGender\Batches;
use NameGender\Client;
use NameGender\NameGenderException;
use PHPUnit\Framework\TestCase;

/**
 * Runs the file job methods over real HTTP against
 * tests/fixtures/batches-router.php served by PHP's built-in web server.
 * Sleeping is replaced by a recorder, so retries and polls take no time.
 */
final class BatchesTest extends TestCase
{
    private const KEY = 'ng_test_key_123';

    /** @var resource|null */
    private static $server = null;

    private static string $origin;

    private static string $log;

    private static string $state;

    /** @var list<int|float> */
    private array $slept = [];

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
        self::$state = tempnam(sys_get_temp_dir(), 'namegender-state-');

        $env = getenv();
        $env['NAMEGENDER_TEST_LOG'] = self::$log;
        $env['NAMEGENDER_TEST_STATE'] = self::$state;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        // Array form skips the shell, so proc_terminate() reaches php itself.
        // enable_post_data_reading=0 keeps a multipart body readable from
        // php://input instead of being parsed into $_POST and $_FILES.
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', "127.0.0.1:$port", __DIR__.'/fixtures/batches-router.php'],
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
        foreach ([self::$log ?? null, self::$state ?? null] as $file) {
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
        file_put_contents(self::$state, '');
        $this->slept = [];
    }

    private function batches(string $prefix = '/api/v1'): Batches
    {
        return new Batches(new Client(self::KEY, self::$origin.$prefix), function ($seconds) {
            $this->slept[] = $seconds;
        });
    }

    /**
     * Every request the stand-in API received, oldest first.
     *
     * @return list<array{method: string, uri: string, authorization: ?string, content_type: ?string, idempotency_key: ?string, raw_body: string}>
     */
    private function requests(): array
    {
        $lines = array_filter(explode("\n", file_get_contents(self::$log)));

        return array_values(array_map(function ($line) {
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $request['raw_body'] = base64_decode($request['raw_body']);

            return $request;
        }, $lines));
    }

    private function lastRequest(): array
    {
        $requests = $this->requests();
        $this->assertNotEmpty($requests, 'The stand-in API received no request');

        return end($requests);
    }

    public function test_client_batches_returns_the_file_job_api(): void
    {
        $this->assertInstanceOf(Batches::class, (new Client(self::KEY))->batches());
    }

    public function test_create_uploads_the_file_and_settings_as_multipart(): void
    {
        $csv = "id,first_name\n1,Ayşe\n";
        $path = sys_get_temp_dir().'/customers-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $csv);

        try {
            $job = $this->batches()->create($path, [
                'name_column' => 'first_name',
                'country_column' => 'country',
                'country' => null,
                'ai_fallback' => false,
                'best_guess' => true,
            ]);
        } finally {
            unlink($path);
        }

        $this->assertSame('B-NEW0000001', $job['id']);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/api/v1/batches', $request['uri']);
        $this->assertSame('Bearer '.self::KEY, $request['authorization']);
        $this->assertMatchesRegularExpression('/^multipart\/form-data; boundary=\S+$/', $request['content_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request['idempotency_key']);

        $body = $request['raw_body'];
        foreach (['name_column' => 'first_name', 'country_column' => 'country', 'ai_fallback' => 'false', 'best_guess' => 'true', 'start' => 'true'] as $field => $value) {
            $this->assertStringContainsString("Content-Disposition: form-data; name=\"$field\"\r\n\r\n$value\r\n", $body);
        }
        $this->assertStringNotContainsString('name="country"', $body);
        $this->assertStringContainsString('name="file"; filename="'.basename($path).'"', $body);
        $this->assertStringContainsString("\r\n\r\n$csv\r\n", $body);
    }

    public function test_create_with_bytes_or_a_stream_needs_a_filename(): void
    {
        try {
            $this->batches()->create("id,first_name\n1,Ayşe\n");
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('filename', $e->getMessage());
        }
        $this->assertSame([], $this->requests());

        $this->batches()->create("id,first_name\n1,Ayşe\n", ['filename' => 'people.csv', 'start' => false, 'idempotency_key' => 'my-key-1']);
        $request = $this->lastRequest();
        $this->assertSame('my-key-1', $request['idempotency_key']);
        $this->assertStringContainsString('filename="people.csv"', $request['raw_body']);
        $this->assertStringContainsString("name=\"start\"\r\n\r\nfalse\r\n", $request['raw_body']);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "id,first_name\n");
        rewind($stream);
        $this->batches()->create($stream, ['filename' => 'stream.csv', 'name_column' => 'first_name']);
        $this->assertStringContainsString("filename=\"stream.csv\"\r\nContent-Type: application/octet-stream\r\n\r\nid,first_name\n\r\n", $this->lastRequest()['raw_body']);
    }

    public function test_create_retries_a_503_with_the_same_idempotency_key(): void
    {
        $job = $this->batches('/flaky')->create("id\n", ['filename' => 'a.csv', 'name_column' => 'id']);

        $this->assertSame('B-NEW0000001', $job['id']);
        $requests = $this->requests();
        $this->assertCount(2, $requests);
        $this->assertNotNull($requests[0]['idempotency_key']);
        $this->assertSame($requests[0]['idempotency_key'], $requests[1]['idempotency_key']);
        $this->assertSame($requests[0]['raw_body'], $requests[1]['raw_body']);
        $this->assertSame([1], $this->slept);
    }

    public function test_create_gives_up_after_the_retries_with_backoff(): void
    {
        try {
            $this->batches('/down')->create("id\n", ['filename' => 'a.csv', 'name_column' => 'id']);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertCount(3, $this->requests());
        $this->assertCount(1, array_unique(array_column($this->requests(), 'idempotency_key')));
        $this->assertSame([1, 2], $this->slept);
    }

    public function test_create_does_not_retry_a_4xx(): void
    {
        try {
            $this->batches('/nowhere')->create("id\n", ['filename' => 'a.csv', 'name_column' => 'id']);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(402, $e->status);
            $this->assertSame('no_credits', $e->body['error']);
        }
        $this->assertCount(1, $this->requests());
        $this->assertSame([], $this->slept);
    }

    public function test_create_retries_a_connection_failure(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $batches = new Batches(new Client(self::KEY, "http://127.0.0.1:$closedPort/api/v1"), function ($seconds) {
            $this->slept[] = $seconds;
        });
        try {
            $batches->create("id\n", ['filename' => 'a.csv', 'name_column' => 'id', 'retries' => 1]);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame([1], $this->slept);
    }

    public function test_create_takes_a_timeout_option_and_defaults_to_a_long_one(): void
    {
        try {
            $this->batches('/slow')->create("id\n", ['filename' => 'a.csv', 'name_column' => 'id', 'retries' => 0, 'timeout' => 0.5]);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertStringNotContainsString('name="timeout"', $this->lastRequest()['raw_body']);

        // Longer than the 1.5 s the stand-in stays silent: well inside 300 s.
        $this->assertSame('B-NEW0000001', $this->batches('/slow')->create("id\n", ['filename' => 'a.csv', 'name_column' => 'id', 'retries' => 0])['id']);
    }

    public function test_download_takes_a_timeout_and_defaults_to_a_long_one(): void
    {
        try {
            $this->batches('/slow')->download('B-NEW0000001', timeout: 0.5);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(0, $e->status);
        }

        $this->assertSame("id\n1\n", $this->batches('/slow')->download('B-NEW0000001'));
    }

    public function test_start_posts_json_and_encodes_the_id(): void
    {
        $job = $this->batches()->start('B/1', ['name_column' => 'first_name', 'country_column' => 'country', 'country' => null]);

        $this->assertSame('B/1', $job['id']);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/api/v1/batches/B%2F1/start', $request['uri']);
        $this->assertSame('application/json', $request['content_type']);
        $this->assertSame(['name_column' => 'first_name', 'country_column' => 'country'], json_decode($request['raw_body'], true));
    }

    public function test_start_requires_name_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->batches()->start('B-NEW0000001', ['country' => 'TR']);
    }

    public function test_wait_polls_until_finished_honouring_poll_after_seconds(): void
    {
        $seen = [];
        $job = $this->batches()->wait('B-SLOW000001', onProgress: function (array $job) use (&$seen) {
            $seen[] = $job['status'];
        });

        $this->assertSame('completed', $job['status']);
        $this->assertSame(['male' => 1, 'female' => 2, 'unknown' => 0, 'from_llm' => 0], $job['summary']);
        $this->assertSame(['queued', 'processing', 'completed'], $seen);
        $this->assertSame([2, 7], $this->slept);
        $this->assertSame(['GET'], array_unique(array_column($this->requests(), 'method')));
        $this->assertSame('/api/v1/batches/B-SLOW000001', $this->lastRequest()['uri']);
    }

    public function test_wait_returns_a_failed_job(): void
    {
        $job = $this->batches()->wait('B-FAIL000001');

        $this->assertSame('failed', $job['status']);
        $this->assertSame('name_column_missing', $job['error']['code']);
        $this->assertSame([], $this->slept);
    }

    public function test_wait_throws_when_the_next_poll_would_pass_the_timeout(): void
    {
        try {
            $this->batches()->wait('B-STUCK00001', timeout: 3);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('B-STUCK00001', $e->getMessage());
            $this->assertSame('processing', $e->body['status']);
        }
    }

    public function test_list_sends_only_the_given_query(): void
    {
        $this->batches()->list();
        $this->assertSame('/api/v1/batches', $this->lastRequest()['uri']);

        $result = $this->batches()->list(limit: 5, page: 2);
        $request = $this->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/api/v1/batches?limit=5&page=2', $request['uri']);
        $this->assertSame(2, $result['page']);
        $this->assertSame('B-NEW0000001', $result['data'][0]['id']);
    }

    public function test_cancel_is_a_delete_that_accepts_an_empty_204(): void
    {
        $this->assertNull($this->batches()->cancel('B-NEW0000001'));

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('/api/v1/batches/B-NEW0000001', $request['uri']);
        $this->assertSame('', $request['raw_body']);
    }

    public function test_download_returns_the_bytes_or_writes_them_to_a_path(): void
    {
        $content = $this->batches()->download('B-NEW0000001');

        $this->assertSame("\u{FEFF}id,first_name,gender\n1,Ayşe,female\n", $content);
        $this->assertSame('GET', $this->lastRequest()['method']);
        $this->assertSame('/api/v1/batches/B-NEW0000001/result', $this->lastRequest()['uri']);

        $path = sys_get_temp_dir().'/result-'.bin2hex(random_bytes(4)).'.csv';
        try {
            $this->assertSame($path, $this->batches()->download('B-NEW0000001', $path));
            $this->assertSame($content, file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_download_error_throws_with_the_api_error(): void
    {
        try {
            $this->batches()->download('B-GONE000001');
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(402, $e->status);
            $this->assertSame('no_credits', $e->body['error']);
        }
    }
}
