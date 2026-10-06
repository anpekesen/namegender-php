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
 * the probability threshold; `locale` (string, a language tag such as `it-IT`
 * or `pt_BR`) supplies the country from its region when `country` is not
 * given; `ip` (string, the end user's IP address) supplies the country from
 * where it is when neither `country` nor a regional `locale` is given, and is
 * not stored. Priority is country > locale > ip; `country_source` in the
 * response ("country", "locale", "ip" or null) says which one applied.
 *
 * @phpstan-type GenderResult array{
 *     query: string, name: ?string, gender: ?string, country: ?string,
 *     sample_size: int, probability: int, took_ms: int,
 *     source: string, confidence: string, matched_as: ?string
 * }
 * @phpstan-type SalutationResult array{
 *     query: string, language: string, form: string, reason: ?string,
 *     salutation: array{formal: string, informal: string, neutral: string},
 *     parts: array{opening: ?string, courtesy: ?string, academic: ?string, name: ?string},
 *     gender: ?string, gender_source: ?string, probability: ?int, confidence: ?string,
 *     first_name: ?string, last_name: ?string, name_type: string, country: ?string
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
     * @param  array{ai_fallback?: bool, best_guess?: bool, locale?: string, ip?: string}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, country_source: ?string,
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
     * @param  array{ai_fallback?: bool, best_guess?: bool, locale?: string, ip?: string}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, country_source: ?string,
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
     * @param  array{ai_fallback?: bool, best_guess?: bool, locale?: string, ip?: string}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, country_source: ?string,
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
     * @param  array{ai_fallback?: bool, best_guess?: bool, locale?: string, ip?: string}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, took_ms: int,
     *     country_source: ?string,
     *     summary: array{total: int, identified: int, unknown: int, match_rate: float|int},
     *     results: list<GenderResult>
     * }
     */
    public function bulk(array $names, ?string $country = null, string $type = 'name', array $options = []): array
    {
        return $this->post('/gender/bulk', array_filter(['names' => array_values($names), 'country' => $country, 'type' => $type] + $options, fn ($v) => $v !== null));
    }

    /**
     * A ready-to-use letter salutation for a name ("Sehr geehrte Frau Dr. Müller,").
     *
     * Pass `$name` as the full name, titles included, or pass null and give
     * `first_name` and `last_name` in `$options` when they are stored
     * separately. Other options: `language` (en, en-US, en-GB, de, de-AT,
     * de-CH, fr, es, it, pt, pt-PT, pt-BR, nl, tr, pl, ja), `country`,
     * `locale`, `ip`, `gender` ("male", "female" or "neutral"),
     * `min_probability` (50–100, default 90) and `title` ("Dr."). When the
     * gender is not certain the neutral form comes back; `form` and `reason`
     * say why. `best_guess` and `ai_fallback` do not apply here. One credit.
     *
     * @param  array{first_name?: string, last_name?: string, language?: string, country?: string, locale?: string, ip?: string, gender?: string, min_probability?: int, title?: string}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, country_source: ?string,
     *     query: string, language: string, form: string, reason: ?string,
     *     salutation: array{formal: string, informal: string, neutral: string},
     *     parts: array{opening: ?string, courtesy: ?string, academic: ?string, name: ?string},
     *     gender: ?string, gender_source: ?string, probability: ?int, confidence: ?string,
     *     first_name: ?string, last_name: ?string, name_type: string, country: ?string
     * }
     */
    public function salutation(?string $name, array $options = []): array
    {
        return $this->post('/salutation', array_filter(['name' => $name] + $options, fn ($v) => $v !== null));
    }

    /**
     * Salutations for up to 100 names, in input order. `$options` (as for
     * `salutation`, without `first_name`/`last_name`) applies to every name.
     * One credit per name.
     *
     * @param  list<string>  $names
     * @param  array{language?: string, country?: string, locale?: string, ip?: string, gender?: string, min_probability?: int, title?: string}  $options
     * @return array{
     *     credits_charged: int, credits_remaining: int, data_version: ?string, request_id: ?string, took_ms: int,
     *     country_source: ?string, language: string,
     *     summary: array{total: int, gendered: int, neutral: int, organization: int},
     *     results: list<SalutationResult>
     * }
     */
    public function salutationBulk(array $names, array $options = []): array
    {
        return $this->post('/salutation/bulk', array_filter(['names' => array_values($names)] + $options, fn ($v) => $v !== null));
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

    /**
     * File jobs: upload a CSV or XLSX file, get it back with gender columns added.
     */
    public function batches(): Batches
    {
        return new Batches($this);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        [$status, $raw] = $this->send($method, $path, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new NameGenderException('NameGender request failed', $status, null);
        }

        return $decoded;
    }

    /**
     * One HTTP request. Returns the status and the raw body of a 2xx response
     * and throws for anything else; status 0 means the request never got an
     * answer (connection refused, DNS, timeout).
     *
     * `$timeout` is in seconds. PHP's http stream applies it to connecting
     * and to each wait for the socket, not to the whole request: a transfer
     * that keeps moving is never cut off, one that stalls for longer is.
     *
     * @param  list<string>  $headers
     * @return array{int, string}
     */
    private function send(string $method, string $path, ?string $content = null, string $contentType = 'application/json', array $headers = [], float $timeout = 30): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: '.$contentType,
            'Authorization: Bearer '.$this->apiKey,
            ...$headers,
        ];
        $context = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => $timeout]];
        if ($content !== null) {
            $context['http']['content'] = $content;
        }

        $raw = @file_get_contents(rtrim($this->baseUrl, '/').$path, false, stream_context_create($context));
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        $raw = is_string($raw) ? $raw : '';
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($raw, true);
            $decoded = is_array($decoded) ? $decoded : null;
            throw new NameGenderException($decoded['message'] ?? 'NameGender request failed', $status, $decoded);
        }

        return [$status, $raw];
    }
}
