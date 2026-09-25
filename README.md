# Yuga Live Components

Yuga Live Components (YLC) adds server-rendered, reactive components to the Yuga Framework. Component state stays on the server and is synchronized in the browser through the Yuga JavaScript runtime.

## Requirements

- PHP 8.2, 8.3, or 8.4
- Yuga Framework 5.x
- The Yuga JavaScript runtime

## Installation

Install the package with Composer:

```bash
composer require yuga/livecomponent
```

`YlcProvider` is registered through Composer package discovery. It adds the Live Component endpoints and discovers components configured by the application.

Publish or copy the browser plugin into the application's public assets using the Yuga publishing mechanism and the `ylc-assets` tag. The package asset is `public/plugins/ylc-live-plugin.js`.

Register `YLCPlugin` with the Yuga JavaScript runtime after loading the runtime and the published plugin. Use the normal Yuga plugin registration API used by the application:

```html
<script src="/assets/ys.js"></script>
<script src="/plugins/ylc-live-plugin.js"></script>
<script>
    // Register YLCPlugin with the Yuga JS runtime here.
    // The exact registration call depends on the Yuga runtime bootstrap.
</script>
```

The plugin communicates with these routes, which are registered automatically by `YlcProvider`:

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/ylc/message` | Component updates and actions |
| POST | `/ylc/lazy` | Lazy component loading |
| GET | `/ylc/stream` | Stream refresh notifications |
| POST | `/ylc/upload` | Temporary file uploads |
| GET | `/ylc/temp-upload/{token}` | Temporary upload preview |

## Configuration

Add a `ylc` configuration entry to the Yuga application's configuration. Each discovery source needs a filesystem path and the namespace used by the PHP classes:

```php
return [
    'discovery' => [
        [
            'path' => path('app/Live'),
            'namespace' => 'App\\Live',
            'prefix' => null,
        ],
    ],

    'uploads' => [
        'maxSize' => 10 * 1024 * 1024,
        'types' => ['image/jpeg', 'image/png', 'application/pdf'],
    ],
];
```

Components are named from their class path. For example, `App\Live\Shopping\Cart` becomes `shopping.cart`. Set `#[Live(name: 'cart')]` to choose an explicit name.

## Creating a component

Create a class that extends `Yuga\Live\Component`:

```php
<?php

namespace App\\Live;

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
        return <<<HTML
            <section>
                <strong>{$this->count}</strong>
                <button type="button" ylc:click="increment">+</button>
            </section>
        HTML;
    }
}
```

Public properties are included in the component snapshot and can be bound from the browser. Public methods can be invoked with `ylc:click`.

To disable discovery for a class, use `#[Live(discover: false)]`. Discovery options can also be set on the attribute:

```php
#[Live(
    name: 'orders',
    lazy: true,
    stream: true,
    streamInterval: 1000,
    poll: false,
)]
```

## Mounting a component

Mount a component from a Yuga view with the `ylc` helper:

```php
<?= ylc('counter') ?>
```

Pass parameters to `mount()` and per-instance options as the second and third arguments:

```php
<?= ylc('orders', [$customerId], [
    'stream' => true,
    'streamInterval' => 2000,
    'placeholder' => '<p>Loading orders...</p>',
]) ?>
```

The component receives mount parameters in `mount()`:

```php
public function mount(int $customerId): void
{
    $this->customerId = $customerId;
}
```

## Bindings and actions

Use `ylc:model` for state bindings and `ylc:click` for actions:

```html
<div>
    <input type="text" ylc:model="name">
    <button type="button" ylc:click="save(name)">Save</button>

    <span ylc:loading>Saving...</span>
    <span ylc:error>Something went wrong.</span>
</div>
```

Supported model values include text inputs, checkboxes, radios, multiple selects, numeric inputs, ranges, and file inputs. Model updates are sent to the server automatically.

Loading state can also be reflected in attributes:

```html
<button
    type="button"
    ylc:click="save"
    ylc:loading.attr="disabled"
    ylc:target="save"
>
    Save
</button>
```

Action parameters use JavaScript expressions inside the attribute, for example `remove(42, 'archive')`.

## Forms and validation

Typed public properties extending `Form` are initialized automatically. Define the form fields and validation rules in a form class:

```php
use Yuga\Live\Form;

class ProfileForm extends Form
{
    public string $name = '';
    public string $email = '';

    public function rules(): array
    {
        return [
            'name' => 'required|min:2',
            'email' => 'required|email',
        ];
    }
}
```

Use it on a component and bind nested fields with dot notation:

```php
public ProfileForm $form;

public function save(): void
{
    if (!$this->form->validate()) {
        return;
    }

    // Persist $this->form->all().
    $this->form->reset();
}
```

```html
<input type="text" ylc:model="form.name">
<input type="email" ylc:model="form.email">
<button type="button" ylc:click="save">Save</button>
```

## URL state

Mark a public property with `#[Url]` to synchronize it with the query string:

```php
use Yuga\Live\Attributes\Url;

#[Url(as: 'q')]
public string $search = '';

#[Url(history: false)]
public int $page = 1;
```

Array properties are JSON-encoded in the query string. URL-backed state is restored on the initial request and when the browser history changes.

## Computed and locked properties

Computed properties are exposed through a `get<Name>Property()` method:

```php
use Yuga\Live\Attributes\Computed;

#[Computed]
public function getTotalProperty(): int
{
    return $this->items->sum('price');
}
```

Use `#[Computed(cache: false)]` when the value must be recalculated each time it is read. Use `#[Locked]` on a public property when browser updates must not change it.

## JavaScript API

The plugin exposes components through `window.YLC`:

```js
const component = window.YLC.first('counter')

await component.set('count', 10)
await component.call('increment')
console.log(component.get('count'))
```

Other helpers include `YLC.find(id)`, `YLC.all()`, and `component.refresh()`.

Register named JavaScript actions from application code:

```js
window.YLC.js('notify', ({ payload }) => {
    console.log(payload.message)
})
```

Then queue one from a component with `$this->js('notify', ['message' => 'Saved']);`.

## Component lifecycle

Components may define these hooks:

```php
public function boot(): void {}
public function mount(...$params): void {}
public function hydrate(): void {}
public function dehydrate(): void {}
public function updating(string $property, mixed $value): void {}
public function updated(string $property, mixed $value): void {}
```

Use `stream: true` for server-sent refresh notifications or `poll: true` for periodic refreshes. The client falls back to polling after repeated stream failures when fallback is enabled.

## License

Yuga Live Components is open-sourced under the MIT license.