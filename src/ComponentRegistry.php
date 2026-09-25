<?php

namespace Yuga\Live;

class ComponentRegistry
{
    protected array $components = [];

    public function register(string $name, string $class): void
    {
        $this->components[$name] = $class;
    }

    public function resolve(string $name): Component
    {
        if (!isset($this->components[$name])) {
            throw new \Exception("YLC component [{$name}] not registered.");
        }

        $component = new $this->components[$name];

        Component::setActive($component);

        return $component;
    }
}