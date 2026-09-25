# File Uploads and Fragments

## File uploads

```html
<input type="file" ylc:model="attachment">
```

The browser uploads the file to `/ylc/upload` and updates the bound model with the returned temporary upload descriptor.

Temporary files can be previewed through `/ylc/temp-upload/{token}`.

## Fragments

Fragments allow an action to request a smaller DOM update:

```php
$this->replace('results');
$this->append('messages');
$this->prepend('messages');
```

The client recognizes fragment targets through `id`, `data-ylc-fragment`, `ylc:fragment`, or `fragment`.

Items can similarly use `id`, `data-ylc-item`, `ylc:item`, or `item`. Existing keyed items are morphed rather than blindly duplicated.

Yuga Hax named fragments can also be used:

```html
<fragment name="results">
    ...
</fragment>
```
