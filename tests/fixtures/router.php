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

$country = is_array($body) ? ($body['country'] ?? null) : null;

switch ($path) {
    case '/api/v1/gender':
        respond(200, credits(1) + result((string) ($body['name'] ?? ''), $country));
        break;

    case '/api/v1/gender/email':
        respond(200, credits(1) + result((string) ($body['email'] ?? ''), $country));
        break;

    case '/api/v1/gender/username':
        respond(200, credits(1) + result((string) ($body['username'] ?? ''), $country));
        break;

    case '/api/v1/gender/bulk':
        $names = (array) ($body['names'] ?? []);
        respond(200, credits(count($names)) + [
            'took_ms' => 5,
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
