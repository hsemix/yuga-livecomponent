# Forms and Advanced State

## Form objects

A typed public property whose class extends `Yuga\Live\Form` is initialized automatically.

```php
class ProfileForm extends Form
{
    public string $name = '';
    public string $email = '';
}

public ProfileForm $form;
```

Nested fields use dot notation:

```html
<input ylc:model="form.name">
```

## Computed properties

```php
#[Computed]
public function getTotalProperty(): int
{
    return $this->calculateTotal();
}
```

Access it as `$this->total`. Computed values are cached for the component cycle by default.

## Locked properties

```php
#[Locked]
public int $accountId;
```

Browser-submitted updates cannot modify locked properties.

## URL state

```php
#[Url(as: 'q')]
public string $search = '';

#[Url(history: false)]
public int $page = 1;
```

YLC synchronizes these properties with query parameters and browser navigation. Array URL properties are JSON encoded/decoded by the client.
