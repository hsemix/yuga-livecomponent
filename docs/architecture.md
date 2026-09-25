# Architecture

YLC is layered on top of Yuga rather than built into the framework's view compiler.

```text
Yuga Hax view
    |
    | compiler extension
    v
YlcCompiler
    |
    | <ylc:mount ... /> -> ylc(...)
    v
YLC PHP runtime
    |
    | snapshot + checksum + HTML
    v
YLC browser plugin
    |
    | requests / morphing / effects
    v
YS + browser DOM
```

## Compiler integration

`YlcProvider::load()` registers `YlcCompiler::compile` through `view()->compiler()->extend(...)`. This keeps YLC syntax owned by the package.

## Discovery

Configured discovery sources are scanned during provider boot. Components are registered with `YLC::component()`, and `#[Live]` supplies component defaults.

## Message cycle

The browser sends snapshot/checksum data plus updates and/or an action to `/ylc/message`. The server restores state, applies updates, invokes the action, renders, and returns the next snapshot/checksum, HTML or fragments, and effects.

## Browser update

The plugin updates its snapshot, preserves the active model where necessary, applies fragments or morphs the component, reinitializes behavior, handles effects, and emits lifecycle events.

## Security boundaries

Snapshots are checksum-protected. Browser updates cannot change `#[Locked]` properties, and methods beginning with `__` cannot be invoked as actions. Applications should still authorize actions and validate domain rules server-side.
