# Installation and Configuration

## Requirements

- PHP 8.2, 8.3, or 8.4
- Yuga Framework 5.x
- Yuga JavaScript runtime

## Install

```bash
composer require yuga/livecomponent
```

`YlcProvider` is registered through Composer package discovery. It registers the compiler extension, routes, publishable assets, and component discovery.

Publish the package configuration and browser plugin using Yuga's publishing mechanism with the `ylc-assets` tag.

## Routes

YLC registers:

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/ylc/message` | State updates and actions |
| POST | `/ylc/lazy` | Lazy component loading |
| GET | `/ylc/stream` | Server-sent refresh notifications |
| POST | `/ylc/upload` | Temporary uploads |
| GET | `/ylc/temp-upload/{token}` | Temporary upload preview |

## Discovery

The published `config/ylc.php` contains discovery sources. Each source defines a filesystem path, PHP namespace, and optional component-name prefix.

```php
'discovery' => [
    [
        'path' => path('app/Live'),
        'namespace' => 'App\\Live',
        'prefix' => null,
    ],
],
```

A class such as `App\Live\Shopping\Cart` is discovered as `shopping.cart` unless `#[Live(name: '...')]` overrides the name.

Use `#[Live(discover: false)]` to exclude a component from discovery.
