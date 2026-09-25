<?php

namespace Yuga\Live;

use Exception;
use ReflectionObject;
use ReflectionProperty;
use Yuga\Live\Attributes\Computed;

abstract class Component
{
    public string $__id;
    public string $__name;

    /** The component handling the current request (set by ComponentRegistry::resolve()). */
    protected static ?Component $active = null;

    protected array $__effects = [
        'dispatches' => [],
        'redirect' => null,
        'emits' => [],
        'toasts' => [],
        'js' => [],
    ];

    protected array $__errors = [];

    protected array $rules = [];

    protected array $listeners = [];

    public int $__version = 0;

    protected array $__fragments = [];

    protected array $__computedCache = [];

    protected array $__js = [];

    public function mount(...$params): void {}
    public function boot(): void {}
    public function hydrate(): void {}
    public function dehydrate(): void {}

    public function updating(string $property, mixed $value): void {}
    public function updated(string $property, mixed $value): void {}

    public function call(string $method, array $params = [])
    {
        if (!method_exists($this, $method)) {
            throw new Exception("Method {$method} does not exist.");
        }

        if (str_starts_with($method, '__')) {
            throw new Exception("Cannot call internal YLC method.");
        }

        $before = serialize($this->getPublicState());

        $result = $this->{$method}(...$params);

        $this->__computedCache = [];

        $after = serialize($this->getPublicState());

        if ($before !== $after) {
            $this->touch();
        }

        return $result;
    }

    public function getListeners(): array
    {
        return $this->listeners;
    }

    public function getPublicState(): array
    {
        $state = [];

        $reflection = new \ReflectionObject($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (str_starts_with($name, '__')) {
                continue;
            }

            $value = $this->{$name};

            if ($value instanceof Form) {
                $state[$name] = $value->all();
                continue;
            }

            $state[$name] = $value;
        }

        return $state;
    }

    public function setPublicState(array $state): void
    {
        foreach ($state as $property => $value) {
            if (str_contains($property, '.')) {
                $this->setNestedProperty($property, $value);
                continue;
            }

            if (!property_exists($this, $property)) {
                continue;
            }

            if ($this->isLockedProperty($property)) {
                continue;
            }

            if ($this->{$property} instanceof Form && is_array($value)) {
                $this->{$property}->fill($value);
                continue;
            }

            $current = $this->{$property} ?? null;

            if ($current !== $value) {
                $this->touch();
            }

            $this->updating($property, $value);

            $this->{$property} = $value;

            $this->updated($property, $value);
        }
    }

    abstract public function render();

    /**
     * A render() that returns a Yuga\Views\View (e.g. `return view(...)`)
     * doesn't actually return its HTML - View::__toString() -> the Hax
     * compiler's renderHaxTemplate() include()s the compiled template and
     * never returns a value, so the real markup gets echoed straight to
     * the output buffer as a side effect while __toString() hands back "".
     * A plain string render() (most components) has no such side effect.
     * Capturing both and preferring whichever side actually produced
     * output covers either case without needing to know which one a given
     * component uses.
     */
    public function renderToHtml(): string
    {
        ob_start();
        $returned = (string) $this->render();
        $echoed = ob_get_clean();

        return $echoed !== '' ? $echoed : $returned;
    }

    public function dispatch(string $event, array $payload = []): static
    {
        $this->__effects['dispatches'][] = [
            'event' => $event,
            'payload' => $payload,
        ];

        return $this;
    }

    public function emit(string $event, array $payload = []): static
    {
        $this->__effects['emits'][] = [
            'event' => $event,
            'payload' => $payload,
        ];

        return $this;
    }

    public function redirect(string $url): static
    {
        $this->__effects['redirect'] = $url;

        return $this;
    }

    /**
     * The component currently handling a mount/message request, so code
     * without a $this (e.g. a fluent Notification) can queue effects on it.
     */
    public static function active(): ?Component
    {
        return static::$active;
    }

    public static function setActive(?Component $component): void
    {
        static::$active = $component;
    }

    /**
     * @param array{title?: string, duration?: int, persistent?: bool} $options
     */
    public function toast(string $message, string $type = 'success', array $options = []): static
    {
        $this->__effects['toasts'][] = array_merge($options, [
            'message' => $message,
            'type' => $type,
        ]);

        return $this;
    }

    public function getEffects(): array
    {
        return $this->__effects;
        // return array_merge($this->__effects, [
        //     'js' => $this->__js,
        // ]);
    }

    public function validate(array $rules = []): bool
    {
        $rules = $rules ?: $this->rules;
        $this->__errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $this->{$field} ?? null;
            $fieldRules = is_array($ruleString)
                ? $ruleString
                : explode('|', $ruleString);

            foreach ($fieldRules as $rule) {
                $this->validateRule($field, $value, $rule);
            }
        }

        return empty($this->__errors);
    }

    protected function validateRule(string $field, mixed $value, string $rule): void
    {
        if ($rule === 'required') {
            if ($value === null || $value === '') {
                $this->addError($field, "The {$field} field is required.");
            }
        }

        if ($rule === 'email') {
            if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->addError($field, "The {$field} field must be a valid email address.");
            }
        }

        if (str_starts_with($rule, 'min:')) {
            $min = (int) substr($rule, 4);

            if (strlen((string) $value) < $min) {
                $this->addError($field, "The {$field} field must be at least {$min} characters.");
            }
        }

        if (str_starts_with($rule, 'max:')) {
            $max = (int) substr($rule, 4);

            if (strlen((string) $value) > $max) {
                $this->addError($field, "The {$field} field may not be greater than {$max} characters.");
            }
        }
    }

    public function addError(string $field, string $message): void
    {
        $this->__errors[$field][] = $message;
    }

    public function getErrors(): array
    {
        return $this->__errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->__errors);
    }

    public function error(string $field): ?string
    {
        return $this->__errors[$field][0] ?? null;
    }

    public function validateOnly(string $field): bool
    {
        if (!isset($this->rules[$field])) {
            return true;
        }

        unset($this->__errors[$field]);

        $ruleString = $this->rules[$field];

        $fieldRules = is_array($ruleString)
            ? $ruleString
            : explode('|', $ruleString);

        $value = $this->{$field} ?? null;

        foreach ($fieldRules as $rule) {
            $this->validateRule($field, $value, $rule);
        }

        return empty($this->__errors[$field]);
    }

    public function __get(string $name): mixed
    {
        $method = 'get' . ucfirst($name) . 'Property';

        if (!method_exists($this, $method)) {
            trigger_error('Undefined property: ' . static::class . '::$' . $name, E_USER_NOTICE);
            return null;
        }

        $reflection = new \ReflectionMethod($this, $method);
        $attributes = $reflection->getAttributes(Computed::class);

        if (empty($attributes)) {
            return $this->{$method}();
        }

        $computed = $attributes[0]->newInstance();

        if (!$computed->cache) {
            return $this->{$method}();
        }

        if (!array_key_exists($name, $this->__computedCache)) {
            $this->__computedCache[$name] = $this->{$method}();
        }

        return $this->__computedCache[$name];
    }

    protected function isLockedProperty(string $property): bool
    {
        if (!property_exists($this, $property)) {
            return false;
        }

        $reflection = new ReflectionProperty($this, $property);

        return !empty($reflection->getAttributes(
            \Yuga\Live\Attributes\Locked::class
        ));
    }

    public function touch(): void
    {
        $this->__version++;
    }

    public function replace(string $fragment): static
    {
        $this->__fragments[] = [
            'type' => 'replace',
            'name' => $fragment,
        ];

        return $this;
    }

    public function getFragments(): array
    {
        return $this->__fragments;
    }

    public function append(string $fragment, ?string $item = null): static
    {
        $this->__fragments[] = [
            'type' => 'append',
            'name' => $fragment,
            'item' => $item,
        ];

        return $this;
    }

    public function prepend(string $fragment, ?string $item = null): static
    {
        $this->__fragments[] = [
            'type' => 'prepend',
            'name' => $fragment,
            'item' => $item,
        ];

        return $this;
    }

    public function getUrlProperties(): array
    {
        $reflection = new \ReflectionObject($this);

        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(
                \Yuga\Live\Attributes\Url::class
            );

            if (empty($attributes)) continue;

            $config = $attributes[0]->newInstance();
            $type = $property->getType();

            $properties[$property->getName()] = [
                'name' => $config->as ?: $property->getName(),
                'history' => $config->history,
                'default' => $property->getDefaultValue(),
                // The client JSON-encodes/decodes the query string value for
                // an array property (a plain URLSearchParams.set() on an
                // array would just stringify it into garbage) - needs to
                // know which properties are array-typed to do that, and so
                // does hydrateUrlProperties() below for the initial
                // request->get() read, which is always a plain string.
                'array' => $type instanceof \ReflectionNamedType && $type->getName() === 'array',
            ];
        }

        return $properties;
    }

    protected function setNestedProperty(string $path, mixed $value): void
    {
        $segments = explode('.', $path);

        $target = $this;

        while (count($segments) > 1) {
            $segment = array_shift($segments);

            if (!isset($target->{$segment})) {
                return;
            }

            $target = $target->{$segment};
        }

        $property = array_shift($segments);

        if (!property_exists($target, $property)) {
            return;
        }

        $current = $target->{$property} ?? null;

        if ($current !== $value) {
            $this->__version++;
        }

        $target->{$property} = $value;

        $this->updated($path, $value);
    }

    public function bootForms(): void
    {
        $reflection = new \ReflectionObject($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();

            if (!$type || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (!is_subclass_of($class, Form::class)) {
                continue;
            }

            $name = $property->getName();

            if (!isset($this->{$name})) {
                $this->{$name} = new $class($this, $name);
            }
        }
    }

    public function js(string $action, array $payload = []): static
    {
        $this->__effects['js'][] = [
            'action' => $action,
            'payload' => $payload,
        ];

        return $this;
    }

    public function getJsEffects(): array
    {
        return $this->__js;
    }
}
