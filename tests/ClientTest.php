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

    public function test_locale_and_ip_are_sent_and_country_source_comes_back(): void
    {
        $result = $this->client()->name('Andrea', options: ['locale' => 'it-IT']);
        $this->assertSent('POST', '/api/v1/gender', ['name' => 'Andrea', 'locale' => 'it-IT']);
        $this->assertSame('IT', $result['country']);
        $this->assertSame('locale', $result['country_source']);

        $result = $this->client()->email('andrea@example.com', null, ['ip' => '203.0.113.7']);
        $this->assertSent('POST', '/api/v1/gender/email', ['email' => 'andrea@example.com', 'ip' => '203.0.113.7']);
        $this->assertSame('ip', $result['country_source']);

        $result = $this->client()->username('andrea_88', 'US', ['locale' => 'pt_BR', 'ip' => '203.0.113.7']);
        $this->assertSent('POST', '/api/v1/gender/username', ['username' => 'andrea_88', 'country' => 'US', 'locale' => 'pt_BR', 'ip' => '203.0.113.7']);
        $this->assertSame('US', $result['country']);
        $this->assertSame('country', $result['country_source']);

        $result = $this->client()->name('Andrea', options: ['locale' => 'en']);
        $this->assertNull($result['country']);
        $this->assertNull($result['country_source']);
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

    public function test_bulk_sends_locale_and_ip_and_returns_country_source_on_the_envelope(): void
    {
        $result = $this->client()->bulk(['Andrea', 'Luca'], null, 'name', ['locale' => 'it-IT', 'ip' => '203.0.113.7']);

        $this->assertSent('POST', '/api/v1/gender/bulk', ['names' => ['Andrea', 'Luca'], 'type' => 'name', 'locale' => 'it-IT', 'ip' => '203.0.113.7']);
        $this->assertSame('locale', $result['country_source']);
        $this->assertSame('IT', $result['results'][0]['country']);
        $this->assertArrayNotHasKey('country_source', $result['results'][0]);
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

    public function test_salutation_sends_only_the_options_that_are_set(): void
    {
        $result = $this->client()->salutation('Dr. Anna Müller', ['language' => 'de', 'country' => null, 'min_probability' => 80]);

        $this->assertSent('POST', '/api/v1/salutation', ['name' => 'Dr. Anna Müller', 'language' => 'de', 'min_probability' => 80]);
        $this->assertCredits($result, 1);
        $this->assertSame('gendered', $result['form']);
        $this->assertNull($result['reason']);
        $this->assertSame(['formal' => 'Sehr geehrte Frau Dr. Müller,', 'informal' => 'Liebe Anna,', 'neutral' => 'Guten Tag Dr. Anna Müller,'], $result['salutation']);
        $this->assertSame(['opening' => 'Sehr geehrte', 'courtesy' => 'Frau', 'academic' => 'Dr.', 'name' => 'Müller'], $result['parts']);
        $this->assertSame('female', $result['gender']);
        $this->assertSame('lookup', $result['gender_source']);
        $this->assertSame('personal', $result['name_type']);
    }

    public function test_salutation_with_first_and_last_name_and_every_option(): void
    {
        $result = $this->client()->salutation(null, [
            'first_name' => 'Andrea', 'last_name' => 'Rossi', 'language' => 'de', 'country' => 'IT', 'locale' => 'de-DE',
            'ip' => '203.0.113.7', 'gender' => 'neutral', 'min_probability' => 95, 'title' => 'Dr.',
        ]);

        $this->assertSent('POST', '/api/v1/salutation', [
            'first_name' => 'Andrea', 'last_name' => 'Rossi', 'language' => 'de', 'country' => 'IT', 'locale' => 'de-DE',
            'ip' => '203.0.113.7', 'gender' => 'neutral', 'min_probability' => 95, 'title' => 'Dr.',
        ]);
        $this->assertSame('country', $result['country_source']);
        $this->assertSame('neutral', $result['form']);
        $this->assertSame('below_min_probability', $result['reason']);
        $this->assertSame('Guten Tag Andrea Rossi,', $result['salutation']['formal']);
        $this->assertNull($result['parts']['courtesy']);
        $this->assertNull($result['parts']['academic']);
    }

    public function test_salutation_bulk_keeps_input_order_and_returns_the_summary(): void
    {
        $result = $this->client()->salutationBulk([2 => 'Dr. Anna Müller', 'Andrea Rossi', 'ACME GmbH'], ['language' => 'de']);

        $this->assertSent('POST', '/api/v1/salutation/bulk', ['names' => ['Dr. Anna Müller', 'Andrea Rossi', 'ACME GmbH'], 'language' => 'de']);
        $this->assertCredits($result, 3);
        $this->assertSame(4, $result['took_ms']);
        $this->assertSame('de', $result['language']);
        $this->assertSame(['total' => 3, 'gendered' => 1, 'neutral' => 1, 'organization' => 1], $result['summary']);
        $this->assertSame(['Dr. Anna Müller', 'Andrea Rossi', 'ACME GmbH'], array_column($result['results'], 'query'));
        $this->assertSame(['gendered', 'neutral', 'organization'], array_column($result['results'], 'form'));
        $this->assertNull($result['results'][2]['parts']['name']);
        $this->assertNull($result['results'][2]['gender']);
        $this->assertArrayNotHasKey('credits_charged', $result['results'][0]);
    }

    public function test_salutation_unsupported_language_throws_with_the_supported_list(): void
    {
        foreach ([fn () => $this->client()->salutation('Dr. Anna Müller', ['language' => 'xx']), fn () => $this->client()->salutationBulk(['Dr. Anna Müller'], ['language' => 'xx'])] as $call) {
            try {
                $call();
                $this->fail('Expected NameGenderException');
            } catch (NameGenderException $e) {
                $this->assertSame(422, $e->status);
                $this->assertSame('Unsupported language.', $e->getMessage());
                $this->assertSame('invalid_input', $e->body['error']);
                $this->assertSame('language', $e->body['field']);
                $this->assertSame(['en', 'de', 'tr'], $e->body['supported']);
            }
        }
    }

    public function test_name_check_sends_only_the_options_that_are_set(): void
    {
        $result = $this->client()->nameCheck('asdf qwerty', ['country' => null, 'locale' => 'de-DE']);

        $this->assertSent('POST', '/api/v1/name-check', ['name' => 'asdf qwerty', 'locale' => 'de-DE']);
        $this->assertCredits($result, 1);
        $this->assertSame('locale', $result['country_source']);
        $this->assertSame('implausible', $result['assessment']);
        $this->assertSame(0, $result['score']);
        $this->assertSame(['code' => 'keyboard_pattern', 'severity' => 'high', 'part' => 'first_name', 'value' => 'asdf'], $result['signals'][0]);
        $this->assertNull($result['signals'][2]['value']);
        $this->assertSame('Asdf', $result['first_name']);
        $this->assertSame('personal', $result['name_type']);
        $this->assertSame(['first_name_status' => 'not_found', 'first_name_counted_records' => 0], $result['evidence']);
    }

    public function test_name_check_with_first_and_last_name_and_every_option(): void
    {
        $result = $this->client()->nameCheck(null, ['first_name' => 'Jennifer', 'last_name' => 'Null', 'country' => 'US', 'locale' => 'en-US', 'ip' => '203.0.113.7']);

        $this->assertSent('POST', '/api/v1/name-check', ['first_name' => 'Jennifer', 'last_name' => 'Null', 'country' => 'US', 'locale' => 'en-US', 'ip' => '203.0.113.7']);
        $this->assertSame('country', $result['country_source']);
        $this->assertSame('plausible', $result['assessment']);
        $this->assertSame(96, $result['score']);
        $this->assertSame('positive', $result['signals'][0]['severity']);
        $this->assertSame('counted', $result['evidence']['first_name_status']);
    }

    public function test_name_check_bulk_keeps_input_order_and_returns_the_summary(): void
    {
        $result = $this->client()->nameCheckBulk([2 => 'asdf qwerty', 'Jennifer Null', 'Madonna'], ['ip' => '203.0.113.7']);

        $this->assertSent('POST', '/api/v1/name-check/bulk', ['names' => ['asdf qwerty', 'Jennifer Null', 'Madonna'], 'ip' => '203.0.113.7']);
        $this->assertCredits($result, 3);
        $this->assertSame(4, $result['took_ms']);
        $this->assertSame('ip', $result['country_source']);
        $this->assertSame(['total' => 3, 'plausible' => 1, 'suspicious' => 1, 'implausible' => 1], $result['summary']);
        $this->assertSame(['asdf qwerty', 'Jennifer Null', 'Madonna'], array_column($result['results'], 'query'));
        $this->assertSame(['implausible', 'plausible', 'suspicious'], array_column($result['results'], 'assessment'));
        $this->assertSame(['code' => 'single_name', 'severity' => 'low', 'part' => null, 'value' => null], $result['results'][2]['signals'][0]);
        $this->assertNull($result['results'][2]['evidence']['first_name_status']);
        $this->assertNull($result['results'][2]['first_name']);
        $this->assertArrayNotHasKey('credits_charged', $result['results'][0]);
    }

    public function test_name_check_without_a_name_throws_the_api_error(): void
    {
        try {
            $this->client()->nameCheck(null);
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSent('POST', '/api/v1/name-check', []);
            $this->assertSame(400, $e->status);
            $this->assertSame('missing_input', $e->body['error']);
            $this->assertSame('req_8', $e->body['request_id']);
        }
    }

    public function test_age_sends_only_the_options_that_are_set_and_parses_both_ranges(): void
    {
        $result = $this->client()->age('Brittany', ['gender' => null, 'country' => '', 'locale' => 'en-US']);

        $this->assertSent('POST', '/api/v1/age', ['name' => 'Brittany', 'locale' => 'en-US']);
        $this->assertSame(1, $result['credits_charged']);
        $this->assertSame(99, $result['credits_remaining']);
        $this->assertSame('req_1', $result['request_id']);
        $this->assertArrayNotHasKey('data_version', $result);
        $this->assertSame('locale', $result['country_source']);
        $this->assertSame('Brittany', $result['first_name']);
        $this->assertNull($result['gender']);
        $this->assertSame(36, $result['age']);
        $this->assertSame(['low' => 32, 'high' => 38], $result['age_range']);
        $this->assertSame(['low' => 28, 'high' => 41], $result['age_range_80']);
        $this->assertSame(1990, $result['birth_year']);
        $this->assertSame(353775, $result['sample_size']);
        $this->assertSame(361434, $result['births']);
        $this->assertSame('US', $result['country']);
        $this->assertSame('ssa', $result['source']);
        $this->assertSame('1880-2024', $result['series']);
        $this->assertSame(2026, $result['reference_year']);
        $this->assertNull($result['reason']);
    }

    public function test_age_with_every_option_and_no_hint_uses_the_default_country(): void
    {
        $result = $this->client()->age('Brittany', ['gender' => 'female', 'country' => 'US', 'locale' => 'en-US', 'ip' => '203.0.113.7']);
        $this->assertSent('POST', '/api/v1/age', ['name' => 'Brittany', 'gender' => 'female', 'country' => 'US', 'locale' => 'en-US', 'ip' => '203.0.113.7']);
        $this->assertSame('country', $result['country_source']);
        $this->assertSame('female', $result['gender']);

        $result = $this->client()->age('Brittany');
        $this->assertSent('POST', '/api/v1/age', ['name' => 'Brittany']);
        $this->assertSame('default', $result['country_source']);
    }

    public function test_age_for_a_country_not_covered_is_a_normal_answer_with_no_credit(): void
    {
        $result = $this->client()->age('Brittany', ['country' => 'DE']);

        $this->assertSent('POST', '/api/v1/age', ['name' => 'Brittany', 'country' => 'DE']);
        $this->assertSame(0, $result['credits_charged']);
        $this->assertNull($result['age']);
        $this->assertNull($result['age_range']);
        $this->assertNull($result['age_range_80']);
        $this->assertNull($result['birth_year']);
        $this->assertNull($result['source']);
        $this->assertSame('country_not_covered', $result['reason']);
    }

    public function test_age_bulk_keeps_input_order(): void
    {
        $result = $this->client()->ageBulk([3 => 'Brittany', 'Zzyzx'], ['gender' => 'female', 'ip' => null]);

        $this->assertSent('POST', '/api/v1/age/bulk', ['names' => ['Brittany', 'Zzyzx'], 'gender' => 'female']);
        $this->assertSame(2, $result['credits_charged']);
        $this->assertSame(99, $result['credits_remaining']);
        $this->assertSame('req_1', $result['request_id']);
        $this->assertSame('default', $result['country_source']);
        $this->assertSame(['Brittany', 'Zzyzx'], array_column($result['results'], 'name'));
        $this->assertSame([36, null], array_column($result['results'], 'age'));
        $this->assertSame(['low' => 28, 'high' => 41], $result['results'][0]['age_range_80']);
        $this->assertSame('not_found', $result['results'][1]['reason']);
        $this->assertArrayNotHasKey('credits_charged', $result['results'][0]);
    }

    public function test_age_errors_throw_the_api_error(): void
    {
        try {
            $this->client('/no-such-prefix')->age('Brittany');
            $this->fail('Expected NameGenderException');
        } catch (NameGenderException $e) {
            $this->assertSame(402, $e->status);
            $this->assertSame('no_credits', $e->body['error']);
        }
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
