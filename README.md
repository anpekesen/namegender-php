# NameGender PHP

```sh
composer require namegender/namegender
```

```php
$client = new NameGender\Client($_ENV['NAMEGENDER_API_KEY']);
$result = $client->name('Ayşe', country: 'TR');
echo $result['gender'];
```
