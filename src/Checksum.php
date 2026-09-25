<?php

namespace Yuga\Live;

class Checksum
{
    public static function generate(array $snapshot): string
    {
        $secret = env('APP_KEY', 'yuga-secret-key');

        return hash_hmac(
            'sha256',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $secret
        );
    }

    public static function verify(array $snapshot, string $checksum): bool
    {
        return hash_equals(static::generate($snapshot), $checksum);
    }
}