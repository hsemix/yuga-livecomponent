<?php

namespace Yuga\Live;

class FragmentRenderer
{
    public static function extract(string $html, array $fragments): array
    {
        $result = [];

        if (empty($fragments)) {
            return $result;
        }

        $dom = new \DOMDocument();

        libxml_use_internal_errors(true);

        $dom->loadHTML(
            '<!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();

        foreach ($fragments as $fragment) {
            $name = $fragment['name'];

            $node = static::findFragmentNode($dom, $name);

            if (!$node) {
                continue;
            }

            $result[$name] = $dom->saveHTML($node);
        }

        return $result;
    }

    protected static function findFragmentNode(\DOMDocument $dom, string $name): ?\DOMNode
    {
        foreach ($dom->getElementsByTagName('*') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            // 1. id has first priority
            if ($node->getAttribute('id') === $name) {
                return $node;
            }

            // 2. data attribute fallback
            if ($node->getAttribute('data-ylc-fragment') === $name) {
                return $node;
            }

            // 3. ylc:fragment fallback
            if ($node->getAttribute('ylc:fragment') === $name) {
                return $node;
            }

            // 4. DOMDocument may rewrite ylc:fragment as fragment
            if ($node->getAttribute('fragment') === $name) {
                return $node;
            }
        }

        return null;
    }
}