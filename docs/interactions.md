# Bindings, Actions, Loading, and Validation

## Model binding

```html
<input type="text" ylc:model="name">
```

The runtime handles text-like inputs, textarea, select, checkbox, radio, numeric/range inputs, date/time inputs, color inputs, and files. IME composition events are respected.

## Actions

```html
<button ylc:click="save">Save</button>
<button ylc:click="remove(42, 'archive')">Archive</button>
```

Methods beginning with `__` cannot be invoked as component actions.

## Loading

```html
<button ylc:click="save" ylc:loading.attr="disabled" ylc:target="save">
    Save
</button>

<span ylc:loading>Saving...</span>
<span ylc:error>Something went wrong.</span>
```

## Validation

```php
protected array $rules = [
    'name' => 'required|min:2',
    'email' => 'required|email',
];
```

Call `validate()` for all rules or `validateOnly($field)` for one field. Current built-in rules include `required`, `email`, `min:n`, and `max:n`.
