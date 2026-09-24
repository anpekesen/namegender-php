<?php

/**
 * Stand-in for the NameGender file job endpoints, served by `php -S` during
 * BatchesTest.
 *
 * Every request is appended to $NAMEGENDER_TEST_LOG as one JSON line (method,
 * URI, headers the test cares about, raw body). $NAMEGENDER_TEST_STATE counts
 * requests per route, so a route can fail once and then succeed, or move a
 * job through its statuses one poll at a time.
 */

$raw = file_get_contents('php://input');
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

file_put_contents(getenv('NAMEGENDER_TEST_LOG'), json_encode([
    'method' => $method,
    'uri' => $uri,
    'authorization' => $headers['authorization'] ?? null,
    'content_type' => $headers['content-type'] ?? null,
    'idempotency_key' => $headers['idempotency-key'] ?? null,
    'raw_body' => base64_encode($raw),
], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

/** How many times this route has been hit, this one included. */
function hit(string $route): int
{
    $file = getenv('NAMEGENDER_TEST_STATE');
    $state = json_decode((string) @file_get_contents($file), true) ?: [];
    $state[$route] = ($state[$route] ?? 0) + 1;
    file_put_contents($file, json_encode($state));

    return $state[$route];
}

function respond(int $status, mixed $payload = null, string $contentType = 'application/json'): void
{
    http_response_code($status);
    if ($payload === null) {
        return;
    }
    header('Content-Type: '.$contentType);
    echo is_string($payload) && $contentType !== 'application/json' ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
}

function job(string $id, string $status, array $extra = []): array
{
    return $extra + [
        'id' => $id,
        'status' => $status,
        'source' => 'api',
        'file' => ['name' => 'customers.csv', 'format' => 'csv'],
        'columns' => ['name' => 'first_name', 'country' => null],
        'rows' => ['total' => 3, 'processed' => $status === 'completed' ? 3 : 0, 'identified' => $status === 'completed' ? 3 : null],
        'progress' => $status === 'completed' ? 100 : 0,
        'credits' => ['reserved' => 3, 'charged' => $status === 'completed' ? 3 : null],
        'summary' => $status === 'completed' ? ['male' => 1, 'female' => 2, 'unknown' => 0, 'from_llm' => 0] : null,
        'error' => null,
        'inspection' => null,
    ];
}

switch (true) {
    case $method === 'POST' && $path === '/api/v1/batches':
        respond(201, job('B-NEW0000001', 'queued'));
        break;

    // 503 on the first attempt, as a load balancer might during a deploy.
    case $method === 'POST' && $path === '/flaky/batches':
        hit('flaky') === 1 ? respond(503, '<html>Service Unavailable</html>', 'text/html') : respond(201, job('B-NEW0000001', 'queued'));
        break;

    case $method === 'POST' && $path === '/down/batches':
        respond(503, '<html>Service Unavailable</html>', 'text/html');
        break;

    case $method === 'POST' && $path === '/api/v1/batches/B%2F1/start':
        respond(202, job('B/1', 'queued'));
        break;

    // queued, then processing, then completed, each with its own poll interval.
    case $method === 'GET' && $path === '/api/v1/batches/B-SLOW000001':
        $n = hit('slow');
        respond(200, match ($n) {
            1 => job('B-SLOW000001', 'queued', ['poll_after_seconds' => 2]),
            2 => job('B-SLOW000001', 'processing', ['poll_after_seconds' => 7, 'progress' => 50]),
            default => job('B-SLOW000001', 'completed'),
        });
        break;

    case $method === 'GET' && $path === '/api/v1/batches/B-FAIL000001':
        respond(200, job('B-FAIL000001', 'failed', ['error' => ['code' => 'name_column_missing', 'message' => 'No such column.']]));
        break;

    case $method === 'GET' && $path === '/api/v1/batches/B-STUCK00001':
        respond(200, job('B-STUCK00001', 'processing', ['poll_after_seconds' => 5]));
        break;

    case $method === 'GET' && $path === '/api/v1/batches':
        respond(200, ['data' => [job('B-NEW0000001', 'queued')], 'page' => (int) ($_GET['page'] ?? 1), 'per_page' => (int) ($_GET['limit'] ?? 20), 'total' => 1, 'has_more' => false]);
        break;

    case $method === 'DELETE' && $path === '/api/v1/batches/B-NEW0000001':
        respond(204);
        break;

    case $method === 'GET' && $path === '/api/v1/batches/B-NEW0000001/result':
        respond(200, "\u{FEFF}id,first_name,gender\n1,Ayşe,female\n", 'text/csv');
        break;

    default:
        respond(402, ['error' => 'no_credits', 'message' => 'Out of credits.', 'request_id' => 'req_9']);
}
