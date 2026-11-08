<?php

namespace App\Support;

class PublicMediaUrl
{
    public static function make(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $path = self::storagePath($value);
        if (!$path) {
            return $value;
        }

        return route('media.show', ['path' => $path]);
    }

    public static function storagePath(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $urlPath = parse_url($value, PHP_URL_PATH) ?: $value;
        if (preg_match('~(?:^|/)storage/(.+)$~', $urlPath, $matches)) {
            $path = $matches[1];
        } elseif (preg_match('~(?:^|/)api/media/(.+)$~', $urlPath, $matches)) {
            $path = $matches[1];
        } elseif (preg_match('~^(?:products|users|logos)/[A-Za-z0-9._/-]+$~', $value)) {
            $path = $value;
        } else {
            return null;
        }

        return preg_match('~^(?:products|users|logos)/[A-Za-z0-9._/-]+$~', $path)
            && !str_contains($path, '..')
                ? $path
                : null;
    }
}
