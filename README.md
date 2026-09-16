# NameGender PHP

PHP client for the [NameGender API](https://namegender.com/docs). Requires
PHP 8.1 or later. Get an API key from the namegender.com dashboard.

```sh
composer require namegender/namegender
```

```php
$client = new NameGender\Client($_ENV['NAMEGENDER_API_KEY']);
$result = $client->name('Ayşe', country: 'TR');
echo $result['gender'], ' ', $result['probability'], ' ', $result['sample_size'];
```

## Options and response

`name`, `email`, `username` and `bulk` take an `$options` array with
`ai_fallback` and `best_guess`:

```php
$result = $client->name('Andrea', country: 'IT', options: ['best_guess' => true]);
```

A result carries `query`, `name`, `gender`, `country`, `probability`,
`sample_size`, `took_ms`, `source`, `confidence` and `matched_as`, alongside
`credits_charged`, `credits_remaining`, `data_version` and `request_id`.
Success is the HTTP status: any non-2xx response throws
`NameGender\NameGenderException` with `status` and `body`
(`['error', 'message', 'request_id', 'docs']`). Branch on `body['error']`, not
on the message.

## Country distribution

Returns the countries a name is recorded in. This is not a country-of-origin or
ethnicity inference, and must not be used as one.

```php
$result = $client->countries('Mehmet', limit: 10);
print_r($result['registrations']); // [['country' => 'FR', 'count' => 3775, 'share' => 58.97, 'gender' => 'male', 'probability' => 99, 'source' => 'insee'], ...]
print_r($result['attested_in']);   // ['AL', 'AU', 'BE', ..., 'TR', 'US']
echo $result['basis']['note'];
```

The two lists are deliberately kept apart. `registrations` is measured volume and
is comparable only among the seven countries that publish counted birth
statistics (US, UK, France, Canada, Spain, Ireland, Norway); `share` is a
percentage across those counts alone. `attested_in` is presence with no weight
attached, which is where countries that publish no counts, such as Turkey, Japan
and India, appear. Show `basis.note` next to any percentage you display.

`limit` (1–100, default 25) caps how many counted countries come back in
`registrations`. One credit per request.
