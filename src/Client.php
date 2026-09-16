<?php

namespace NameGender;

/**
 * NameGender API client.
 *
 * Success is the HTTP status: any non-2xx response throws NameGenderException,
 * whose body is {error, message, request_id, docs}. Branch on `error`, not on
 * `message`.
 *
 * `$options` on the lookup methods is sent as-is: `ai_fallback` (bool) falls
 * back to a language model for names not in the database and needs AI consent
 * on the account; `best_guess` (bool) returns the most likely gender even below
 * the probability threshold.
 *
 * @phpstan-type GenderResult array{
 *     query: string, name: ?string, gender: ?string, country: ?string,
 *     sample_size: int, probability: int, took_ms: int,
 *     source: string, confidence: string, matched_as: ?string
 * }
 */
class Client
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://namegender.com/api/v1',
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
    }

    /**
     * @param  array{ai_fallback?: bool, best_guess?: bool}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string,
     *     query: string, name: ?string, gender: ?string, country: ?string,
     *     sample_size: int, probability: int, took_ms: int,
     *     source: string, confidence: string, matched_as: ?string
     * }
     */
    public function name(string $name, ?string $country = null, array $options = []): array
    {
        return $this->post('/gender', array_filter(['name' => $name, 'country' => $country] + $options, fn ($v) => $v !== null));
    }

    /**
     * @param  array{ai_fallback?: bool, best_guess?: bool}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string,
     *     query: string, name: ?string, gender: ?string, country: ?string,
     *     sample_size: int, probability: int, took_ms: int,
     *     source: string, confidence: string, matched_as: ?string
     * }
     */
    public function email(string $email, ?string $country = null, array $options = []): array
    {
        return $this->post('/gender/email', array_filter(['email' => $email, 'country' => $country] + $options, fn ($v) => $v !== null));
    }

    /**
     * @param  array{ai_fallback?: bool, best_guess?: bool}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string,
     *     query: string, name: ?string, gender: ?string, country: ?string,
     *     sample_size: int, probability: int, took_ms: int,
     *     source: string, confidence: string, matched_as: ?string
     * }
     */
    public function username(string $username, ?string $country = null, array $options = []): array
    {
        return $this->post('/gender/username', array_filter(['username' => $username, 'country' => $country] + $options, fn ($v) => $v !== null));
    }

    /**
     * @param  list<string>  $names
     * @param  array{ai_fallback?: bool, best_guess?: bool}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, took_ms: int,
     *     summary: array{total: int, identified: int, unknown: int, match_rate: float|int},
     *     results: list<GenderResult>
     * }
     */
    public function bulk(array $names, ?string $country = null, string $type = 'name', array $options = []): array
    {
        return $this->post('/gender/bulk', array_filter(['names' => array_values($names), 'country' => $country, 'type' => $type] + $options, fn ($v) => $v !== null));
    }

    /**
     * Country distribution of a name. Not a country-of-origin or ethnicity inference.
     *
     * `registrations` is counted volume, comparable only among countries that publish
     * counted birth statistics; `attested_in` is presence with no weight attached.
     *
     * @return array{
     *     credits_charged: int, credits_remaining: int,
     *     data_version: ?string, request_id: ?string, took_ms: int, name: string,
     *     basis: array{counted_sources: list<string>, counted_countries: int, attested_countries: int, note: string},
     *     registrations: list<array{country: string, count: int, share: float|int, gender: ?string, probability: int, source: string}>,
     *     attested_in: list<string>
     * }
     */
    public function countries(string $name, ?int $limit = null): array
    {
        return $this->post('/gender/countries', array_filter(['name' => $name, 'limit' => $limit], fn ($v) => $v !== null));
    }

    /**
     * @return array{
     *     email: string, credits_remaining: int, purchased_credits: int,
     *     free_today: int, free_daily_limit: int, lifetime_requests: int, data_version: ?string,
     *     ai: array{consented: bool, consented_at: ?string, subprocessor: array<string, ?string>}
     * }
     */
    public function account(): array
    {
        return $this->request('GET', '/me');
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer '.$this->apiKey,
        ];
        $context = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 30]];
        if ($body !== null) {
            $context['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $raw = @file_get_contents(rtrim($this->baseUrl, '/').$path, false, stream_context_create($context));
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $decoded = is_array($decoded) ? $decoded : null;
        if ($status < 200 || $status >= 300 || $decoded === null) {
            throw new NameGenderException($decoded['message'] ?? 'NameGender request failed', $status, $decoded);
        }

        return $decoded;
    }
}
