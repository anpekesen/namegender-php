<?php

/**
 * Stand-in for the NameGender API, served by `php -S` during the tests.
 *
 * Every request is written to $NAMEGENDER_TEST_LOG (method, path,
 * Authorization header, raw body) so the test can assert what the client
 * actually sent. Responses mirror the shapes the real API returns.
 */

$raw = file_get_contents('php://input');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$body = $raw === '' ? null : json_decode($raw, true);

file_put_contents(getenv('NAMEGENDER_TEST_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'authorization' => $headers['authorization'] ?? null,
    'content_type' => $headers['content-type'] ?? null,
    'raw_body' => $raw,
], JSON_THROW_ON_ERROR));

function respond(int $status, mixed $payload, string $contentType = 'application/json'): void
{
    http_response_code($status);
    header('Content-Type: '.$contentType);
    echo is_string($payload) && $contentType !== 'application/json' ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
}

function credits(int $charged): array
{
    return ['credits_charged' => $charged, 'credits_remaining' => 99, 'data_version' => '2026.09', 'request_id' => 'req_1'];
}

function result(string $query, ?string $country): array
{
    return [
        'query' => $query,
        'name' => 'ayse',
        'gender' => 'female',
        'country' => $country,
        'probability' => 99,
        'sample_size' => 1234,
        'took_ms' => 3,
        'confidence' => 'high',
        'source' => 'database',
        'matched_as' => 'first_name',
    ];
}

// Country priority as the API applies it: country > locale (its region) > ip.
// Any ip stands in for Germany here.
$country = is_array($body) ? ($body['country'] ?? null) : null;
$countrySource = $country !== null ? 'country' : null;
if ($country === null && is_array($body) && isset($body['locale']) && preg_match('/^[a-z]{2,3}[-_]([a-z]{2})$/i', $body['locale'], $m)) {
    [$country, $countrySource] = [strtoupper($m[1]), 'locale'];
}
if ($country === null && is_array($body) && isset($body['ip'])) {
    [$country, $countrySource] = ['DE', 'ip'];
}

// Salutations for the few names the tests use: one gendered (with an
// academic title), one whose gender is not certain, one organization.
function salutation(string $query): array
{
    return match ($query) {
        'Dr. Anna Müller' => [
            'query' => $query, 'language' => 'de', 'form' => 'gendered', 'reason' => null,
            'salutation' => ['formal' => 'Sehr geehrte Frau Dr. Müller,', 'informal' => 'Liebe Anna,', 'neutral' => 'Guten Tag Dr. Anna Müller,'],
            'parts' => ['opening' => 'Sehr geehrte', 'courtesy' => 'Frau', 'academic' => 'Dr.', 'name' => 'Müller'],
            'gender' => 'female', 'gender_source' => 'lookup', 'probability' => 99, 'confidence' => 'high',
            'first_name' => 'Anna', 'last_name' => 'Müller', 'name_type' => 'personal', 'country' => 'DE',
        ],
        'Andrea Rossi' => [
            'query' => $query, 'language' => 'de', 'form' => 'neutral', 'reason' => 'below_min_probability',
            'salutation' => ['formal' => 'Guten Tag Andrea Rossi,', 'informal' => 'Hallo Andrea,', 'neutral' => 'Guten Tag Andrea Rossi,'],
            'parts' => ['opening' => 'Guten Tag', 'courtesy' => null, 'academic' => null, 'name' => 'Andrea Rossi'],
            'gender' => 'male', 'gender_source' => 'lookup', 'probability' => 62, 'confidence' => 'low',
            'first_name' => 'Andrea', 'last_name' => 'Rossi', 'name_type' => 'personal', 'country' => null,
        ],
        default => [
            'query' => $query, 'language' => 'de', 'form' => 'organization', 'reason' => null,
            'salutation' => ['formal' => 'Sehr geehrte Damen und Herren,', 'informal' => 'Hallo,', 'neutral' => 'Sehr geehrte Damen und Herren,'],
            'parts' => ['opening' => 'Sehr geehrte Damen und Herren', 'courtesy' => null, 'academic' => null, 'name' => null],
            'gender' => null, 'gender_source' => null, 'probability' => null, 'confidence' => null,
            'first_name' => null, 'last_name' => null, 'name_type' => 'organization', 'country' => null,
        ],
    };
}

// Name checks for the few names the tests use: one implausible (keyboard
// pattern), one plausible, one given only as a first name.
function nameCheck(string $query): array
{
    return match ($query) {
        'asdf qwerty' => [
            'query' => $query, 'assessment' => 'implausible', 'score' => 0,
            'signals' => [
                ['code' => 'keyboard_pattern', 'severity' => 'high', 'part' => 'first_name', 'value' => 'asdf'],
                ['code' => 'keyboard_pattern', 'severity' => 'high', 'part' => 'last_name', 'value' => 'qwerty'],
                ['code' => 'first_name_not_found', 'severity' => 'medium', 'part' => 'first_name', 'value' => null],
            ],
            'first_name' => 'Asdf', 'last_name' => 'Qwerty', 'name_type' => 'personal',
            'evidence' => ['first_name_status' => 'not_found', 'first_name_counted_records' => 0],
        ],
        'Jennifer Null' => [
            'query' => $query, 'assessment' => 'plausible', 'score' => 96,
            'signals' => [['code' => 'first_name_attested', 'severity' => 'positive', 'part' => 'first_name', 'value' => 'Jennifer']],
            'first_name' => 'Jennifer', 'last_name' => 'Null', 'name_type' => 'personal',
            'evidence' => ['first_name_status' => 'counted', 'first_name_counted_records' => 1468723],
        ],
        default => [
            'query' => $query, 'assessment' => 'suspicious', 'score' => 45,
            'signals' => [['code' => 'single_name', 'severity' => 'low', 'part' => null, 'value' => null]],
            'first_name' => null, 'last_name' => null, 'name_type' => 'personal',
            'evidence' => ['first_name_status' => null, 'first_name_counted_records' => 0],
        ],
    };
}

// Ages for the few names the tests use: one found (Brittany), one in a
// country the age data does not cover (any non-US/FR/NO country), one not
// found. Age responses carry no data_version, and with no country hint the
// API uses US data and says country_source "default".
function age(string $name, ?string $country, ?string $gender): array
{
    $country ??= 'US';
    if (! in_array($country, ['US', 'FR', 'NO'], true)) {
        return [
            'name' => $name, 'first_name' => $name, 'gender' => $gender, 'age' => null,
            'age_range' => null, 'age_range_80' => null, 'birth_year' => null,
            'sample_size' => 0, 'births' => 0, 'country' => $country,
            'source' => null, 'series' => null, 'reference_year' => 2026, 'reason' => 'country_not_covered',
        ];
    }

    return match ($name) {
        'Brittany' => [
            'name' => $name, 'first_name' => $name, 'gender' => $gender, 'age' => 36,
            'age_range' => ['low' => 32, 'high' => 38], 'age_range_80' => ['low' => 28, 'high' => 41], 'birth_year' => 1990,
            'sample_size' => 353775, 'births' => 361434, 'country' => $country,
            'source' => 'ssa', 'series' => '1880-2024', 'reference_year' => 2026, 'reason' => null,
        ],
        default => [
            'name' => $name, 'first_name' => null, 'gender' => $gender, 'age' => null,
            'age_range' => null, 'age_range_80' => null, 'birth_year' => null,
            'sample_size' => 0, 'births' => 0, 'country' => $country,
            'source' => null, 'series' => null, 'reference_year' => 2026, 'reason' => 'not_found',
        ],
    };
}

$unsupportedLanguage = ['error' => 'invalid_input', 'message' => 'Unsupported language.', 'request_id' => 'req_7', 'field' => 'language', 'supported' => ['en', 'de', 'tr']];

switch ($path) {
    case '/api/v1/gender':
        respond(200, credits(1) + ['country_source' => $countrySource] + result((string) ($body['name'] ?? ''), $country));
        break;

    case '/api/v1/gender/email':
        respond(200, credits(1) + ['country_source' => $countrySource] + result((string) ($body['email'] ?? ''), $country));
        break;

    case '/api/v1/gender/username':
        respond(200, credits(1) + ['country_source' => $countrySource] + result((string) ($body['username'] ?? ''), $country));
        break;

    case '/api/v1/gender/bulk':
        $names = (array) ($body['names'] ?? []);
        respond(200, credits(count($names)) + [
            'took_ms' => 5,
            'country_source' => $countrySource,
            'summary' => ['total' => count($names), 'identified' => count($names), 'unknown' => 0, 'match_rate' => 100],
            'results' => array_map(fn ($n) => result((string) $n, $country), $names),
        ]);
        break;

    case '/api/v1/gender/countries':
        respond(200, credits(1) + [
            'took_ms' => 4,
            'name' => (string) ($body['name'] ?? ''),
            'basis' => [
                'counted_sources' => ['insee', 'ssa'],
                'counted_countries' => 2,
                'attested_countries' => 3,
                'note' => 'Shares cover counted countries only.',
            ],
            'registrations' => [
                ['country' => 'FR', 'count' => 3775, 'share' => 58.97, 'gender' => 'male', 'probability' => 99, 'source' => 'insee'],
                ['country' => 'US', 'count' => 2627, 'share' => 41.03, 'gender' => 'male', 'probability' => 98, 'source' => 'ssa'],
            ],
            'attested_in' => ['DE', 'NL', 'TR'],
        ]);
        break;

    case '/api/v1/salutation':
        if (($body['language'] ?? null) === 'xx') {
            respond(422, $unsupportedLanguage);
            break;
        }
        $query = isset($body['name']) ? (string) $body['name'] : trim(($body['first_name'] ?? '').' '.($body['last_name'] ?? ''));
        respond(200, credits(1) + ['country_source' => $countrySource] + salutation($query));
        break;

    case '/api/v1/salutation/bulk':
        if (($body['language'] ?? null) === 'xx') {
            respond(422, $unsupportedLanguage);
            break;
        }
        $results = array_map(fn ($n) => salutation((string) $n), (array) ($body['names'] ?? []));
        $forms = array_count_values(array_column($results, 'form'));
        respond(200, credits(count($results)) + [
            'took_ms' => 4,
            'country_source' => $countrySource,
            'language' => 'de',
            'summary' => ['total' => count($results), 'gendered' => $forms['gendered'] ?? 0, 'neutral' => $forms['neutral'] ?? 0, 'organization' => $forms['organization'] ?? 0],
            'results' => $results,
        ]);
        break;

    case '/api/v1/name-check':
        if (! isset($body['name']) && ! isset($body['first_name']) && ! isset($body['last_name'])) {
            respond(400, ['error' => 'missing_input', 'message' => 'Provide name, or first_name and last_name.', 'request_id' => 'req_8']);
            break;
        }
        $query = isset($body['name']) ? (string) $body['name'] : trim(($body['first_name'] ?? '').' '.($body['last_name'] ?? ''));
        respond(200, credits(1) + ['country_source' => $countrySource] + nameCheck($query));
        break;

    case '/api/v1/name-check/bulk':
        $results = array_map(fn ($n) => nameCheck((string) $n), (array) ($body['names'] ?? []));
        $assessments = array_count_values(array_column($results, 'assessment'));
        respond(200, credits(count($results)) + [
            'took_ms' => 4,
            'country_source' => $countrySource,
            'summary' => ['total' => count($results), 'plausible' => $assessments['plausible'] ?? 0, 'suspicious' => $assessments['suspicious'] ?? 0, 'implausible' => $assessments['implausible'] ?? 0],
            'results' => $results,
        ]);
        break;

    case '/api/v1/age':
        $result = age((string) ($body['name'] ?? ''), $country, $body['gender'] ?? null);
        $charged = $result['reason'] === 'country_not_covered' ? 0 : 1;
        respond(200, ['credits_charged' => $charged, 'credits_remaining' => 99, 'request_id' => 'req_1']
            + array_slice($result, 0, 11) + ['country_source' => $countrySource ?? 'default'] + array_slice($result, 11));
        break;

    case '/api/v1/age/bulk':
        $results = array_map(fn ($n) => age((string) $n, $country, $body['gender'] ?? null), (array) ($body['names'] ?? []));
        $charged = count(array_filter($results, fn ($r) => $r['reason'] !== 'country_not_covered'));
        $results = array_map(fn ($r) => array_slice($r, 0, 11) + ['country_source' => $countrySource ?? 'default'] + array_slice($r, 11), $results);
        respond(200, ['credits_charged' => $charged, 'credits_remaining' => 99, 'request_id' => 'req_1', 'country_source' => $countrySource ?? 'default', 'results' => $results]);
        break;

    case '/api/v1/me':
        respond(200, [
            'email' => 'dev@example.com',
            'credits_remaining' => 99,
            'purchased_credits' => 50,
            'free_today' => 49,
            'free_daily_limit' => 50,
            'lifetime_requests' => 1200,
            'data_version' => '2026.09',
            'ai' => ['consented' => false, 'consented_at' => null, 'subprocessor' => ['provider' => null]],
        ]);
        break;

    // Error bodies that are not the API's JSON error envelope, as a proxy or
    // load balancer in front of the API might send.
    case '/html-error/gender':
        respond(502, '<html><body>Bad Gateway</body></html>', 'text/html');
        break;

    case '/scalar-error/gender':
        respond(500, 'Internal error');
        break;

    default:
        respond(402, ['error' => 'no_credits', 'message' => 'Out of credits.', 'request_id' => 'req_9']);
}
