<?php

namespace NameGender;

/**
 * File jobs: upload a CSV or XLSX file, get it back with gender columns added.
 * Reached as `$client->batches()`.
 *
 * @phpstan-type BatchJob array{
 *     id: string, status: 'uploaded'|'queued'|'processing'|'completed'|'failed'|'cancelled',
 *     source: string, file: array{name: string, format: string},
 *     columns: array{name: ?string, country: ?string},
 *     rows: array{total: int, processed: int, identified: ?int}, progress: int,
 *     credits: array{reserved: ?int, charged: ?int},
 *     summary: ?array{male: int, female: int, unknown: int, from_llm: int},
 *     error: ?array{code: string, message: string},
 *     inspection: ?array{columns: list<string>, preview: list<list<string>>, guessed_name_column: ?string, guessed_country_column: ?string, credits_needed: int, credits_available: int},
 *     poll_after_seconds?: int
 * }
 */
class Batches
{
    private const FINISHED = ['completed', 'failed', 'cancelled'];

    // Statuses worth retrying an upload for: the request may never have reached
    // the application. Everything else (402, 422, 429 too_many_batches) would
    // fail the same way again.
    private const RETRYABLE = [502, 503, 504];

    private readonly \Closure $send;

    private readonly \Closure $sleep;

    /**
     * @param  (callable(int|float): void)|null  $sleep  Pauses between polls and retries, in seconds.
     */
    public function __construct(Client $client, ?callable $sleep = null)
    {
        // Client::send is private; bind to it rather than making it public API.
        $this->send = \Closure::bind(fn (...$args) => $this->send(...$args), $client, Client::class);
        $this->sleep = $sleep !== null ? \Closure::fromCallable($sleep) : static fn ($seconds) => usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Upload a file and, unless `start` is false, start it.
     *
     * `$file` is a path, the file's contents as a string, or a readable stream.
     * The extension of the file name (`.csv`, `.xlsx`) tells the API the
     * format; pass `filename` when `$file` is not a path. `name_column` is
     * required to start: a guessed column that is wrong would spend credits
     * on the wrong data. With `start => false` the job carries `inspection`
     * (columns, preview, cost) and is started with start().
     *
     * One Idempotency-Key is used for every attempt, so a retry after a
     * dropped connection returns the first job instead of opening a second
     * one and reserving credit twice.
     *
     * @param  string|resource  $file
     * @param  array{
     *     filename?: string, start?: bool, idempotency_key?: string, retries?: int,
     *     name_column?: string, country_column?: string, country?: string,
     *     ai_fallback?: bool, best_guess?: bool, delete_after_download?: bool
     * }  $options
     * @return BatchJob
     */
    public function create($file, array $options = []): array
    {
        $filename = $options['filename'] ?? null;
        $key = $options['idempotency_key'] ?? self::uuid();
        $retries = $options['retries'] ?? 2;
        unset($options['filename'], $options['idempotency_key'], $options['retries']);

        [$content, $name] = self::readFile($file, $filename);
        $fields = [];
        foreach ($options + ['start' => true] as $field => $value) {
            if ($value !== null) {
                $fields[$field] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        [$body, $contentType] = self::multipart($fields, 'file', $name, $content);

        for ($attempt = 0; ; $attempt++) {
            try {
                return self::decode(($this->send)('POST', '/batches', $body, $contentType, ['Idempotency-Key: '.$key]));
            } catch (NameGenderException $e) {
                // Status 0 is a network error: the upload may or may not have landed.
                $retryable = $e->status === 0 || in_array($e->status, self::RETRYABLE, true);
                if (! $retryable || $attempt >= $retries) {
                    throw $e;
                }
            }
            ($this->sleep)(2 ** $attempt);
        }
    }

    /**
     * Start a job uploaded with `start => false`.
     *
     * @param  array{name_column: string, country_column?: string, country?: string, ai_fallback?: bool, best_guess?: bool, delete_after_download?: bool}  $settings
     * @return BatchJob
     */
    public function start(string $id, array $settings): array
    {
        if (($settings['name_column'] ?? '') === '') {
            throw new \InvalidArgumentException('name_column is required');
        }
        $body = json_encode(array_filter($settings, fn ($v) => $v !== null), JSON_THROW_ON_ERROR);

        return self::decode(($this->send)('POST', '/batches/'.rawurlencode($id).'/start', $body));
    }

    /**
     * @return BatchJob
     */
    public function get(string $id): array
    {
        return self::decode(($this->send)('GET', '/batches/'.rawurlencode($id)));
    }

    /**
     * Newest first. Includes jobs started from the dashboard.
     *
     * @return array{data: list<BatchJob>, page: int, per_page: int, total: int, has_more: bool}
     */
    public function list(?int $limit = null, ?int $page = null): array
    {
        $query = http_build_query(array_filter(['limit' => $limit, 'page' => $page], fn ($v) => $v !== null));

        return self::decode(($this->send)('GET', '/batches'.($query !== '' ? '?'.$query : '')));
    }

    /**
     * Cancel a job that has not started (credit is returned), or delete a finished one.
     */
    public function cancel(string $id): void
    {
        // 204 with no body.
        ($this->send)('DELETE', '/batches/'.rawurlencode($id));
    }

    /**
     * Poll until the job is completed, failed or cancelled, and return it.
     *
     * A failed job is returned, not thrown: check `status` and `error['code']`.
     *
     * @param  (callable(array): void)|null  $onProgress  Called with the job after every poll.
     * @return BatchJob
     */
    public function wait(string $id, int $timeout = 3600, ?callable $onProgress = null): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $job = $this->get($id);
            if ($onProgress !== null) {
                $onProgress($job);
            }
            if (in_array($job['status'] ?? null, self::FINISHED, true) || ($job['status'] ?? null) === 'uploaded') {
                return $job;
            }
            $pause = $job['poll_after_seconds'] ?? 5;
            if (microtime(true) + $pause > $deadline) {
                throw new NameGenderException("Timed out waiting for $id", 0, $job);
            }
            ($this->sleep)($pause);
        }
    }

    /**
     * The result file as a string, or written to `$path` (which is then returned).
     */
    public function download(string $id, ?string $path = null): string
    {
        [, $content] = ($this->send)('GET', '/batches/'.rawurlencode($id).'/result');
        if ($path === null) {
            return $content;
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Could not write $path");
        }

        return $path;
    }

    /**
     * @param  array{int, string}  $response
     */
    private static function decode(array $response): array
    {
        $decoded = json_decode($response[1], true);
        if (! is_array($decoded)) {
            throw new NameGenderException('NameGender request failed', $response[0], null);
        }

        return $decoded;
    }

    /**
     * @param  string|resource  $file
     * @return array{string, string}
     */
    private static function readFile($file, ?string $filename): array
    {
        if (is_resource($file)) {
            $content = stream_get_contents($file);
            $uri = stream_get_meta_data($file)['uri'] ?? '';
            $name = $filename ?? (is_string($uri) && is_file($uri) ? basename($uri) : null);
            if ($content === false) {
                throw new \InvalidArgumentException('Could not read the stream');
            }
            if ($name === null || $name === '') {
                throw new \InvalidArgumentException('filename is required when the stream has no file name');
            }

            return [$content, $name];
        }
        if (! is_string($file)) {
            throw new \InvalidArgumentException('file must be a path, a string or a stream');
        }
        // A short string without NUL bytes that names an existing file is a
        // path; anything else is the file's contents.
        if (strlen($file) < 4096 && ! str_contains($file, "\0") && is_file($file)) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new \InvalidArgumentException("Could not read $file");
            }

            return [$content, $filename ?? basename($file)];
        }
        if ($filename === null || $filename === '') {
            throw new \InvalidArgumentException('filename is required when file is not a path to an existing file');
        }

        return [$file, $filename];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{string, string}
     */
    private static function multipart(array $fields, string $fileField, string $filename, string $content): array
    {
        $boundary = bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        }
        // A quote or line break in a file name would end the header early.
        $quoted = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '%22', '', ''], $filename);
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$fileField\"; filename=\"$quoted\"\r\n"
            ."Content-Type: application/octet-stream\r\n\r\n"
            .$content."\r\n--$boundary--\r\n";

        return [$body, "multipart/form-data; boundary=$boundary"];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
