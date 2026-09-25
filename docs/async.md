# Lazy Loading, Polling, and Streaming

## Lazy loading

```php
#[Live(name: 'report', lazy: true)]
```

or:

```html
<ylc:mount component="report" ylc:lazy />
```

The browser uses `IntersectionObserver` to load lazy components when they enter the viewport.

## Polling

```php
#[Live(name: 'status', poll: true, pollInterval: 5000)]
```

The browser periodically requests a refresh.

## Streaming

```php
#[Live(
    name: 'orders',
    stream: true,
    streamInterval: 1000,
    streamAlways: false,
)]
```

Streaming uses `EventSource` against `/ylc/stream`. The stream signals that state changed and the browser performs the normal YLC refresh request.

When `streamAlways` is disabled, version information avoids unnecessary refreshes. Repeated stream failures can fall back to polling.
