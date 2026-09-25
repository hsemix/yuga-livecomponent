<?php

namespace Yuga\Live\Compilers;

use InvalidArgumentException;
use Yuga\Views\Compilers\Compiler;

class YlcCompiler
{
    public function compile(
        string $value,
        Compiler $compiler
    ): string {
        return preg_replace_callback(
            '/<ylc:mount\s*(?<attributes>[^>]*)\/>/',
            function ($matches) use ($compiler) {
                return $this->compileMount(
                    $matches['attributes'] ?? '',
                    $compiler
                );
            },
            $value
        );
    }

    protected function compileMount(
        string $attributes,
        Compiler $compiler
    ): string {
        $attributes = $compiler->parseAttributes($attributes);

        $component = null;
        $props = [];
        $options = [];

        foreach ($attributes as $attribute) {
            $name = $attribute['name'];

            if ($name === 'component') {
                $component = $attribute;
                continue;
            }

            if (str_starts_with($name, 'ylc:')) {
                $attribute['name'] = substr($name, 4);

                $options[] = $attribute;

                continue;
            }

            $props[] = $attribute;
        }

        if ($component === null) {
            throw new InvalidArgumentException(
                '<ylc:mount> requires a component attribute.'
            );
        }

        $componentExpression = $this->compileValue($component);

        $propsExpression = $compiler->compileAttributes($props);

        $optionsExpression = $compiler->compileAttributes($options);

        return sprintf(
            '<?php echo ylc(%s, %s, %s); ?>',
            $componentExpression,
            $propsExpression,
            $optionsExpression
        );
    }

    protected function compileValue(array $attribute): string
    {
        if ($attribute['bound']) {
            return (string) $attribute['value'];
        }

        return var_export(
            $attribute['value'],
            true
        );
    }
}