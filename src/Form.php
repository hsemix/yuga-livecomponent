<?php

namespace Yuga\Live;

abstract class Form
{
    protected Component $__component;

    protected string $__name;

    protected array $__errors = [];

    public function __construct(Component $component, string $name)
    {
        $this->__component = $component;
        $this->__name = $name;
    }

    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    public function all(): array
    {
        $data = [];

        foreach (get_object_vars($this) as $key => $value) {
            if (str_starts_with($key, '__')) {
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    public function reset(): static
    {
        foreach ($this->all() as $key => $value) {
            if (is_array($value)) {
                $this->{$key} = [];
            } elseif (is_bool($value)) {
                $this->{$key} = false;
            } elseif (is_int($value)) {
                $this->{$key} = 0;
            } elseif (is_float($value)) {
                $this->{$key} = 0.0;
            } else {
                $this->{$key} = '';
            }
        }

        return $this;
    }

    public function validate(): bool
    {
        $this->__errors = [];

        foreach ($this->rules() as $field => $rules) {
            $value = $this->{$field} ?? null;

            $rules = is_array($rules) ? $rules : explode('|', $rules);

            foreach ($rules as $rule) {
                $this->validateRule($field, $value, $rule);
            }
        }

        return empty($this->__errors);
    }

    protected function validateRule(string $field, mixed $value, string $rule): void
    {
        $fullField = "{$this->__name}.{$field}";

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

        $this->__component->addError("{$this->__name}.{$field}", $message);
    }

    public function errors(): array
    {
        return $this->__errors;
    }

    abstract public function rules(): array;
}