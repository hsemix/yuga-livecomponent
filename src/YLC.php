<?php

namespace Yuga\Live;

class YLC
{
    protected static ?ComponentRegistry $registry = null;

    protected static array $components = [];

    protected static array $componentOptions = [];

    public static function registry(): ComponentRegistry
    {
        if (!static::$registry) {
            static::$registry = new ComponentRegistry();
        }

        return static::$registry;
    }


    public static function component(string $name, string $class): void
    {
        static::$components[$name] = $class;

        static::registry()->register($name, $class);
    }

    public static function options(string $name, array $options = []): void
    {
        static::$componentOptions[$name] = $options;
    }

    public static function getOptions(string $name): array
    {
        return static::$componentOptions[$name] ?? [];
    }

    // public static function component(string $name, string $class): void
    // {
    //     static::registry()->register($name, $class);
    // }

    public static function render(string $name, array $params = [], array $options = []): string
    {
        $renderer = new ComponentRenderer(static::registry());

        return $renderer->mount($name, $params, $options);
    }

    public static function refresh(string $componentId): void
    {
        $cache = app()->get('cache');

        $key = "ylc-version:{$componentId}";

        $version = (int) $cache->get($key, 0);

        $cache->set($key, $version + 1);
    }

    public static function refreshByComponent(string $component): void
    {
        $cache = app()->get('cache');

        $streams = $cache->get('ylc-streams', []);

        foreach ($streams as $id => $meta) {
            if (($meta['component'] ?? null) === $component) {
                static::refresh($id);
            }
        }
    }
}