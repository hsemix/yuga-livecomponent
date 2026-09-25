<?php

namespace Yuga\Live;

class ComponentRenderer
{
    public function __construct(
        protected ComponentRegistry $registry
    ) {}

    public function mount(string $name, array $params = [], array $options = []): string
    {
        $streamAttr = !empty($options['stream']) ? 'ylc:stream' : '';
        $streamIntervalAttr = !empty($options['streamInterval'])
            ? 'ylc:stream-interval="' . (int) $options['streamInterval'] . '"'
            : '';

        $streamAlwaysAttr = !empty($options['streamAlways']) ? 'ylc:stream-always' : '';

        $pollAttr = !empty($options['poll']) ? 'ylc:poll' : '';

        $pollIntervalAttr = !empty($options['pollInterval'])
            ? 'ylc:poll-interval="' . (int) $options['pollInterval'] . '"'
            : '';

        $component = $this->registry->resolve($name);

        $component->__id = bin2hex(random_bytes(10));
        $component->__name = $name;

        $component->bootForms();

        $component->mount(...$params);

        $this->hydrateUrlProperties($component);

        $html = $component->renderToHtml();

        $snapshot = [
            'id' => $component->__id,
            'name' => $component->__name,
            'version' => $component->__version,
            'state' => $component->getPublicState(),
            'listeners' => $component->getListeners(),
            'url' => $component->getUrlProperties(),
        ];

        $checksum = Checksum::generate($snapshot);

        $snapshotJson = htmlspecialchars(json_encode($snapshot), ENT_QUOTES, 'UTF-8');

        $options = array_merge(
            YLC::getOptions($name),
            $options
        );

        if (!empty($options['lazy'])) {
            return $this->lazyPlaceholder($name, $params, $options);
        }

        return <<<HTML
            <div 
                class="ylc-component"
                ylc:id="{$component->__id}" 
                ylc:component="{$component->__name}"
                {$streamAttr}
                {$streamIntervalAttr}
                {$streamAlwaysAttr}
                {$pollAttr}
                {$pollIntervalAttr}
                ylc:snapshot="{$snapshotJson}"
                ylc:checksum="{$checksum}"
            >
                {$html}
            </div>
        HTML;
    }

    protected function hydrateUrlProperties(Component $component): void
    {
        if (!function_exists('request')) {
            return;
        }

        $updates = [];

        foreach ($component->getUrlProperties() as $property => $config) {
            $value = request()->get($config['name'], null);

            if ($value === null) {
                continue;
            }

            if (!empty($config['array'])) {
                // Yuga\Http\Input\Input::parseInputs() runs every $_GET value
                // through filter_var(..., FILTER_SANITIZE_FULL_SPECIAL_CHARS)
                // (XSS protection) before this is ever reached - that HTML-
                // entity-encodes the JSON's own quotes ('"' -> '&quot;'),
                // so json_decode() would silently fail on the raw value.
                $decoded = json_decode(htmlspecialchars_decode((string) $value, ENT_QUOTES), true);

                if (!is_array($decoded)) {
                    continue;
                }

                $value = $decoded;
            }

            $updates[$property] = $value;
        }

        if (!empty($updates)) {
            $component->setPublicState($updates);
        }
    }

    protected function lazyPlaceholder(string $name, array $params = [], array $options = []): string
    {
        $paramsJson = htmlspecialchars(json_encode($params), ENT_QUOTES, 'UTF-8');
        $optionsJson = htmlspecialchars(json_encode(array_merge($options, [
            'lazy' => false,
        ])), ENT_QUOTES, 'UTF-8');

        $placeholder = $options['placeholder'] ?? null;
        $placeholderView = $options['placeholderView'] ?? null;

        $content = 'Loading...';

        if ($placeholderView) {
            $content = (string) view($placeholderView, [
                'name' => $name,
                'params' => $params,
                'options' => $options,
            ]);
        } elseif ($placeholder) {
            $content = $placeholder;
        }

        return <<<HTML
            <div
                class="ylc-lazy"
                ylc:lazy
                ylc:lazy-name="{$name}"
                ylc:lazy-params="{$paramsJson}"
                ylc:lazy-options="{$optionsJson}"
            >
                {$content}
            </div>
        HTML;
    }
}
