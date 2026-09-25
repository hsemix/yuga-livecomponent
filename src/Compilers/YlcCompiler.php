<?php

namespace Yuga\Live\Compilers;

use Yuga\Views\HaxCompiler;

class YlcCompiler
{
    public function compile(string $value, HaxCompiler $compiler): string
    {
        return $this->compileMounts($value, $compiler);
    }

    protected function compileMounts(
        string $value,
        HaxCompiler $compiler
    ): string {
        return preg_replace_callback(
            '/<ylc:mount\s+([^>]*?)\/>/s',
            function ($matches) use ($compiler) {
                return $this->compileMount(
                    $matches[1],
                    $compiler
                );
            },
            $value
        );
    }

    protected function compileMount(
        string $attributes,
        HaxCompiler $compiler
    ): string {
        $parsed = $this->parseAttributes($attributes);

        if (!isset($parsed['component'])) {
            throw new \InvalidArgumentException(
                '<ylc:mount> requires a "component" attribute.'
            );
        }

        $component = $parsed['component'];
        unset($parsed['component']);

        $optionNames = [
            'lazy',
            'stream',
            'poll',
            'stream-always',
        ];

        $props = [];
        $options = [];

        foreach ($parsed as $name => $value) {
            if (str_starts_with($name, 'ylc:')) {
                $option = substr($name, 4);

                $options[$option] = $value;

                continue;
            }

            $props[$name] = $value;
        }

        return sprintf(
            '<?php echo ylc(%s, %s, %s); ?>',
            $component,
            $this->compileArray($props),
            $this->compileArray($options)
        );
    }

    protected function parseAttributes(string $attributes): array
    {
        $result = [];

        $pattern = '/
        (?<bound>:)?                         # optional :
        (?<name>[\w:-]+)                    # attribute name
        (?:
            \s*=\s*
            (?:
                "(?<double>[^"]*)"           # "value"
                |
                \'(?<single>[^\']*)\'        # \'value\'
            )
        )?
    /x';

        preg_match_all(
            $pattern,
            $attributes,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = $match['name'];

            $hasValue =
                ($match['double'] ?? '') !== '' ||
                ($match['single'] ?? '') !== '' ||
                str_contains($match[0], '=');

            if (!$hasValue) {
                // <ylc:mount ... lazy />
                $result[$name] = 'true';
                continue;
            }

            $value = $match['double'] !== ''
                ? $match['double']
                : $match['single'];

            if (($match['bound'] ?? '') === ':') {
                // :user="$user"
                // :limit="20"
                // :enabled="true"
                $result[$name] = $value;
            } else {
                // component="profile"
                $result[$name] = var_export($value, true);
            }
        }

        return $result;
    }

    protected function compileArray(array $values): string
    {
        if (empty($values)) {
            return '[]';
        }

        $items = [];

        foreach ($values as $key => $value) {
            $items[] = var_export($key, true) . ' => ' . $value;
        }

        return '[' . implode(', ', $items) . ']';
    }
}
