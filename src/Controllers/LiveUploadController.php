<?php

namespace Yuga\Live\Controllers;

use Yuga\Http\Request;

class LiveUploadController
{
    public function upload(Request $request)
    {
        $file = $_FILES['file'] ?? null;

        $config = config('ylc.uploads');

        if ($file['size'] > $config['maxSize']) {
            return response()->json(['message' => 'File is too large.'], 422);
        }

        if (!in_array($file['type'], $config['types'], true)) {
            return response()->json(['message' => 'File type is not allowed.'], 422);
        }

        if (!$file) {
            return response()->json([
                'message' => 'No file uploaded.',
            ], 422);
        }

        $token = bin2hex(random_bytes(20));

        $dir = storage('framework/ylc/uploads');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $original = basename($file['name']);
        $path = $dir . '/' . $token . '_' . $original;

        move_uploaded_file($file['tmp_name'], $path);

        app()->get('cache')->set("ylc-upload:{$token}", [
            'token' => $token,
            'name' => $original,
            'type' => $file['type'],
            'size' => $file['size'],
            'path' => $path,
            'uploaded_at' => time(),
        ]);

        return response()->json([
            'token' => $token,
            'name' => $original,
            'type' => $file['type'],
            'size' => $file['size'],
            'preview' => host('/ylc/temp-upload/' . $token),
        ]);
    }

    public function preview($token)
    {
        $meta = app()->get('cache')->get("ylc-upload:{$token}");

        if (!$meta || !file_exists($meta['path'])) { // abort
            http_response_code(404);
            exit('File not found');
        }

        header('Content-Type: ' . $meta['type']);

        readfile($meta['path']);
        exit;
    }
}
