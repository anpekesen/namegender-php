<?php

namespace NameGender;

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

    public function name(string $name, ?string $country = null, array $options = []): array
    {
        return $this->post('/gender', array_filter(['name' => $name, 'country' => $country] + $options, fn ($v) => $v !== null));
    }

    public function email(string $email, ?string $country = null, array $options = []): array
    {
        return $this->post('/gender/email', array_filter(['email' => $email, 'country' => $country] + $options, fn ($v) => $v !== null));
    }

    public function username(string $username, ?string $country = null, array $options = []): array
    {
        return $this->post('/gender/username', array_filter(['username' => $username, 'country' => $country] + $options, fn ($v) => $v !== null));
    }

    public function bulk(array $names, ?string $country = null, string $type = 'name'): array
    {
        return $this->post('/gender/bulk', array_filter(['names' => array_values($names), 'country' => $country, 'type' => $type], fn ($v) => $v !== null));
    }

    /**
     * Country distribution of a name. Not a country-of-origin or ethnicity inference.
     *
     * `registrations` is counted volume, comparable only among countries that publish
     * counted birth statistics; `attested_in` is presence with no weight attached.
     *
     * @return array{
     *     status: bool, used_credits: int, remaining_credits: int, expires: null,
     *     data_version: ?string, request_id: ?string, duration: string, name: string,
     *     basis: array{counted_sources: list<string>, counted_countries: int, attested_countries: int, note: string},
     *     registrations: list<array{country: string, count: int, share: float|int, gender: ?string, probability: int, source: string}>,
     *     attested_in: list<string>
     * }
     */
    public function countries(string $name, ?int $limit = null): array
    {
        return $this->post('/gender/countries', array_filter(['name' => $name, 'limit' => $limit], fn ($v) => $v !== null));
    }

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
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            throw new NameGenderException($decoded['message'] ?? 'NameGender request failed', $status, $decoded);
        }

        return $decoded;
    }
}
