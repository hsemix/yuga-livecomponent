<?php

namespace Yuga\Live\Controllers;

use Yuga\Http\Request;

class LiveStreamController
{
    public function stream(Request $request)
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $id = $request->get('id');
        $name = $request->get('name');
        $started = time();

        echo ": connected\n\n";
        echo str_repeat(' ', 4096) . "\n\n";
        flush();

        $this->registerStream($id, $name);

        // Bounded on purpose: this loop holds a worker/process for its
        // entire lifetime (worse still, the whole process under PHP's
        // built-in dev server, which is single-threaded), so every other
        // request - completely unrelated ones included - stalls behind it.
        // ylc-close tells the client (connectStream() in
        // ylc-live-plugin.js) to close this connection and open a fresh one
        // 1.5s later, keeping each hold brief instead of parking one
        // connection per open tab for as long as the tab stays open.
        while (!connection_aborted()) {
            $currentVersion = (int) app()->get('cache')->get("ylc-version:{$id}", 0);

            echo "event: ylc-refresh\n";
            echo "data: " . json_encode([
                'id' => $id,
                'version' => $currentVersion,
                'time' => time(),
            ]) . "\n\n";

            flush();

            if (time() - $started >= 15) {
                echo "event: ylc-close\n";
                echo "data: {}\n\n";
                flush();
                break;
            }

            sleep(1);
        }

        $this->unregisterStream($id);

        exit;
    }

    protected function registerStream(string $id, string $name): void
    {
        $cache = app()->get('cache');

        $streams = $cache->get('ylc-streams', []);

        $streams[$id] = [
            'component' => $name,
            'updated_at' => time(),
        ];

        $cache->set('ylc-streams', $streams);
    }

    protected function unregisterStream(string $id): void
    {
        $cache = app()->get('cache');

        $streams = $cache->get('ylc-streams', []);

        unset($streams[$id]);

        $cache->set('ylc-streams', $streams);
    }
}
