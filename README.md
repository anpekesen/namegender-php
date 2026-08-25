# GenderScope PHP

```sh
composer require genderscope/genderscope
```

```php
$client = new GenderScope\Client($_ENV['GENDERSCOPE_API_KEY']);
$result = $client->name('Ayşe', country: 'TR');
echo $result['gender'];
```
