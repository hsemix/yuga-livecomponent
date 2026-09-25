# JavaScript API, Effects, Lifecycle, and Portals

## Component lookup

```js
const counter = YLC.first('counter')

YLC.find(id)
YLC.first()
YLC.first('counter')
YLC.all()
```

Component handles provide `el()`, `snapshot()`, `get()`, `set()`, `call()`, and `refresh()`.

## JavaScript actions

```js
YLC.js('notify', ({ payload }) => {
    console.log(payload.message)
})
```

From PHP:

```php
$this->js('notify', ['message' => 'Saved']);
```

## Effects

Components can queue browser effects with `dispatch()`, `emit()`, `toast()`, `redirect()`, and `js()`.

## Lifecycle events

Core browser events include:

```text
ylc:init
ylc:before-request
ylc:after-request
ylc:before-morph
ylc:after-morph
ylc:hydrated
```

The runtime also emits streaming, polling, and upload lifecycle events.

## Portals and teleports

Teleported markup is associated with its component using `data-ylc-portal-for`. Document-level delegation forwards `ylc:click` and `ylc:model` interactions from portals to the owning component.
