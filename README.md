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
`ai_fallback`, `best_guess`, `locale` and `ip`:

```php
$result = $client->name('Andrea', country: 'IT', options: ['best_guess' => true]);
```

When you don't know the country, pass what you do know. `locale` is a language
tag such as `it-IT` or `pt_BR`; its region is used as the country (a tag
without a region, such as `en`, sets none). `ip` is the end user's IP address;
the country it is in is used, and the API does not store it. `country` wins
over `locale`, and `locale` over `ip`:

```php
$result = $client->name('Andrea', options: [
    'locale' => 'it-IT',                // e.g. the user's app or browser language
    'ip' => $_SERVER['REMOTE_ADDR'],    // used only if locale has no region
]);
echo $result['country'], ' ', $result['country_source']; // IT locale
```

A result carries `query`, `name`, `first_name`, `middle_name`, `last_name`, `name_type`, `gender`, `country`, `probability`,
`sample_size`, `took_ms`, `source`, `confidence` and `matched_as`, alongside
`credits_charged`, `credits_remaining`, `data_version`, `request_id` and
`country_source`. `country_source` is `country`, `locale`, `ip` or `null`, and
says where `country` came from; `bulk` returns it once, next to `summary`.
Success is the HTTP status: any non-2xx response throws
`NameGender\NameGenderException` with `status` and `body`
(`['error', 'message', 'request_id', 'docs']`). Branch on `body['error']`, not
on the message.

## Salutation

Returns a ready-to-use letter salutation for a name, with titles parsed out.

```php
$result = $client->salutation('Dr. Anna Müller', ['language' => 'de']);
echo $result['salutation']['formal'];   // Sehr geehrte Frau Dr. Müller,

$result = $client->salutation('Ahmet Yılmaz', ['language' => 'tr']);
echo $result['salutation']['formal'];   // Sayın Ahmet Bey,

// First and last name stored separately: pass null as the name.
$result = $client->salutation(null, ['first_name' => 'Anna', 'last_name' => 'Müller', 'title' => 'Dr.', 'language' => 'de']);

$result = $client->salutationBulk(['Dr. Anna Müller', 'ACME GmbH'], ['language' => 'de']);
print_r($result['summary']);   // ['total' => 2, 'gendered' => 1, 'neutral' => 0, 'organization' => 1]
```

Options: `language` (en, en-US, en-GB, de, de-AT, de-CH, fr, es, it, pt,
pt-PT, pt-BR, nl, tr, pl, ja; an unsupported one throws with status 422),
`country`, `locale`, `ip`, `gender` (`male`, `female` or `neutral`),
`min_probability` (50–100, default 90) and `title`. One credit per name;
`salutationBulk` takes up to 100 names and returns `results` in input order.

`salutation` carries `formal`, `informal` and `neutral`, and `parts` the
pieces (`opening`, `courtesy`, `academic`, `name`, each possibly `null`).
When the gender is not certain you get the neutral form: `form` is
`gendered`, `neutral` or `organization`, and `reason` says why it is not
gendered (`gender_unknown`, `below_min_probability`, ...). `best_guess` does
not apply to salutations.

## Name check

Says whether a name typed into a form looks like a real person's name, with
the reasons.

```php
$result = $client->nameCheck('asdf qwerty');
echo $result['assessment'];   // implausible
echo $result['signals'][0]['code'];   // keyboard_pattern

$result = $client->nameCheck('Jennifer Null');
echo $result['assessment'];   // plausible

// First and last name stored separately: pass null as the name.
$result = $client->nameCheck(null, ['first_name' => 'Jennifer', 'last_name' => 'Null', 'country' => 'US']);

$result = $client->nameCheckBulk(['asdf qwerty', 'Jennifer Null']);
print_r($result['summary']);   // ['total' => 2, 'plausible' => 1, 'suspicious' => 0, 'implausible' => 1]
```

Options: `country`, `locale` and `ip`. One credit per name; `nameCheckBulk`
takes up to 100 names and returns `results` in input order. `assessment` is
`plausible`, `suspicious` or `implausible`, `score` is 0–100, and each signal
has `code`, `severity`, `part` and `value` (the last two possibly `null`).

It never calls a name fake: use it to flag records for a second look, not to
reject people automatically. Surnames are judged by their shape only.

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

## File jobs

Upload a CSV or XLSX file (up to 100 MB and 1,000,000 rows) and get it back
with gender columns added. One credit per row, charged only if the job
completes.

```php
$batches = $client->batches();

$job = $batches->create('customers.csv', [   // a path, the file's contents (with 'filename') or a stream
    'name_column' => 'first_name',            // required to start
    'country_column' => 'country',            // optional: a country code per row
]);

$done = $batches->wait($job['id'], onProgress: fn (array $j) => print($j['progress']."\n"));
if ($done['status'] === 'failed') {
    throw new RuntimeException($done['error']['code']);
}

$batches->download($done['id'], 'customers-gender.csv');
```

`name_column` is required to start: a guessed column that turns out to be
wrong would spend credits on the wrong data. To see the columns and the cost
first, upload with `'start' => false`, read `$job['inspection']`, then call
`$batches->start($job['id'], ['name_column' => ...])`.

`create` sends an `Idempotency-Key` and retries network errors and 502/503/504
with the same key, so a retry never opens a second job. Pass your own
`idempotency_key` to keep that guarantee across your own retries.
`create` and `download` give up only after a transfer stalls for 300 seconds
(other calls: 30); pass `'timeout' => ...` to `create`, or `timeout:` to
`download`, to change that.

`wait` returns a failed job rather than throwing; branch on
`$job['error']['code']`. `cancel` returns the credit of a job that has not
started, and deletes a finished one. `list(limit:, page:)` includes jobs
started from the dashboard. Up to three jobs can be queued or running at once;
a fourth is refused with `429 too_many_batches`.

The result appends `gender`, `probability`, `sample_size`, `country`, `source`,
`matched_as`, `first_name`, `middle_name`, `last_name` and `name_type` to every
row. A CSV result starts with a UTF-8 byte order mark so that Excel reads it
correctly; strip the first three bytes before handing it to `fgetcsv`.

## Webhooks

Add an endpoint under Webhooks in the dashboard, and NameGender sends a signed
`POST` to it when a file job completes or fails, and when credits are about to
run out (`credits.low`) or have run out (`credits.depleted`, checked hourly).
`Webhooks::verify` checks the signature and the timestamp, and returns the
event.

```php
use NameGender\Webhooks;
use NameGender\WebhookVerificationException;

try {
    $event = Webhooks::verify(
        file_get_contents('php://input'),   // the raw body, not a decoded $_POST
        $_SERVER['HTTP_NAMEGENDER_SIGNATURE'] ?? null,
        $_ENV['NAMEGENDER_WEBHOOK_SECRET'],
    );
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}

http_response_code(204);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();   // PHP-FPM: send the answer now, then do the work
}

if ($event['type'] === 'batch.completed') {
    $job = $event['data']['object'];   // the job, as batches()->get() returns it
}
```

In Laravel or Symfony, pass `$request->getContent()` and
`$request->header('NameGender-Signature')` (Symfony:
`$request->headers->get(...)`).

Use `$event['id']` (also the `NameGender-Event-Id` header) to ignore a delivery
you have already handled. A retry carries the same id, and order is not
guaranteed. Anything other than a 2xx within 10 seconds is retried, up to 8
attempts over about 45 hours.
