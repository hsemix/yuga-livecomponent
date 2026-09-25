# Components and Rendering

A live component extends `Yuga\Live\Component`.

```php
use Yuga\Live\Attributes\Live;
use Yuga\Live\Component;

#[Live(name: 'counter')]
class Counter extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<button ylc:click="increment">' . $this->count . '</button>';
    }
}
```

Public properties form the serializable component state.

## Lifecycle

Components can implement `boot()`, `mount()`, `hydrate()`, `dehydrate()`, `updating()`, and `updated()`.

## Mounting

```php
<?= ylc('counter') ?>
```

YLC also extends Hax with:

```html
<ylc:mount component="counter" />
```

Props use normal Hax attribute conventions:

```html
<ylc:mount
    component="report"
    title="Annual Report"
    :year="$year"
/>
```

Mount-specific options use the `ylc:` namespace:

```html
<ylc:mount component="report" :year="$year" ylc:lazy ylc:stream />
```

A dynamic component name is supported:

```html
<ylc:mount :component="$componentName" />
```

The tag compiles to the existing `ylc(...)` helper.

## Class-level options

```php
#[Live(
    name: 'orders',
    lazy: true,
    stream: true,
    streamInterval: 1000,
    streamAlways: false,
    poll: false,
    pollInterval: null,
)]
```
