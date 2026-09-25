<?php

namespace Yuga\Live\Controllers;

use Yuga\Http\Request;
use Yuga\Live\Checksum;
use Yuga\Live\FragmentRenderer;
use Yuga\Live\YLC;

class LiveController
{
    public function message(Request $request)
    {
        $payload = $request->all();

        $snapshot = $payload['snapshot'];
        $updates = $payload['updates'] ?? [];
        $action = $payload['action'] ?? null;
        $params = $payload['params'] ?? [];

        $checksum = $payload['checksum'] ?? '';

        if (!Checksum::verify($snapshot, $checksum)) {
            return response()->json([
                'message' => 'Invalid YLC snapshot checksum.',
            ], 419);
        }

        $component = YLC::registry()->resolve($snapshot['name']);

        $component->__id = $snapshot['id'];
        $component->__name = $snapshot['name'];

        $component->bootForms();

        $component->boot();

        $component->setPublicState($snapshot['state'] ?? []);

        $component->hydrate();

        if (!empty($updates)) {
            $component->setPublicState($updates);
        }

        if ($action) {
            $component->call($action, $params);
        }

        $component->dehydrate();

        $html = $component->renderToHtml();

        $fragments = $component->getFragments();

        $hasOnlyReplace = !empty($fragments);

        foreach ($fragments as $fragment) {
            if (($fragment['type'] ?? null) !== 'replace') {
                $hasOnlyReplace = false;
                break;
            }
        }

        $fragments = $component->getFragments();

        $fragmentHtml = FragmentRenderer::extract($html, $fragments);

        $newSnapshot = [
            'id' => $component->__id,
            'name' => $component->__name,
            'version' => $component->__version,
            'state' => $component->getPublicState(),
            'listeners' => $component->getListeners(),
            'url' => $component->getUrlProperties(),
        ];

        app()->get('cache')->set(
            "ylc-version:{$component->__id}",
            $component->__version
        );

        $newChecksum = Checksum::generate($newSnapshot);

        return response()->json([
            'html' => $hasOnlyReplace ? null : $html,
            'fragmentHtml' => $hasOnlyReplace ? FragmentRenderer::extract($html, $fragments) : [],
            'fragments' => $fragments,
            'snapshot' => $newSnapshot,
            'checksum' => $newChecksum,
            'effects' => $component->getEffects(),
            'errors' => $component->getErrors(),
            'listeners' => $component->getListeners(),
        ]);
    }

    public function lazy(Request $request)
    {
        // sleep(5);
        $payload = $request->all();

        $name = $payload['name'] ?? null;
        $params = $payload['params'] ?? [];
        $options = $payload['options'] ?? [];

        if (!$name) {
            return response()->json([
                'message' => 'Missing lazy component name.',
            ], 422);
        }

        $html = YLC::render($name, $params, array_merge($options, [
            'lazy' => false,
        ]));

        return response()->json([
            'html' => $html,
        ]);
    }
}
