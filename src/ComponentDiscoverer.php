<?php

namespace Yuga\Live;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Yuga\Live\Attributes\Live;

class ComponentDiscoverer
{
    public function discover(
        string $path,
        string $namespace,
        ?string $prefix = null
    ): array {
        $components = [];

        if (!is_dir($path)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            if ($file->getExtension() !== 'php') continue;

            $relative = str_replace(
                $path . DIRECTORY_SEPARATOR,
                '',
                $file->getPathname()
            );

            $relativeClass = str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relative
            );

            $class = $namespace . '\\' . $relativeClass;

            if (!class_exists($class)) continue;
            if (!is_subclass_of($class, Component::class)) continue;

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()) continue;

            $config = $this->liveConfig($reflection);

            if ($config && !$config->discover) continue;

            $name = $config?->name ?: $this->nameFromClass($relativeClass);

            if ($prefix && !$config?->name) {
                $name = trim($prefix . '.' . $name, '.');
            }

            $components[$name] = [
                'class' => $class,
                'config' => $config,
            ];
        }

        return $components;
    }

    protected function liveConfig(\ReflectionClass $reflection): ?\Yuga\Live\Attributes\Live
    {
        $attrs = $reflection->getAttributes(
            \Yuga\Live\Attributes\Live::class
        );

        return $attrs
            ? $attrs[0]->newInstance()
            : null;
    }

    protected function nameFromClass(string $relativeClass): string
    {
        $parts = explode('\\', $relativeClass);

        $parts = array_map(function ($part) {
            return strtolower(
                preg_replace('/(?<!^)[A-Z]/', '-$0', $part)
            );
        }, $parts);

        return implode('.', $parts);
    }

    protected function nameFromClasss(string $class): string
    {
        $relative = str_replace('App\\Live\\', '', $class);

        $parts = explode('\\', $relative);

        $parts = array_map(function ($part) {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $part));
        }, $parts);

        return implode('.', $parts);
    }

    protected function configFor(string $class): Live
    {
        $reflection = new \ReflectionClass($class);

        $attributes = $reflection->getAttributes(\Yuga\Live\Attributes\Live::class);

        if (empty($attributes)) {
            return new Live(
                name: $this->nameFromClass($class)
            );
        }

        $config = $attributes[0]->newInstance();

        if (!$config->name) {
            $config->name = $this->nameFromClass($class);
        }

        return $config;
    }
}
