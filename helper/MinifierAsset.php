<?php

/**
 * Asset path utilities shared by the minifier plugin
 */
class MinifierAsset
{
    public static function isExternalUrl(string $path): bool
    {
        if (str_starts_with($path, '//')) {
            return true;
        }

        return preg_match('/^https?:\/\//i', $path) === 1;
    }

    public static function normalisePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/');
        $segments = explode('/', $absolute ? ltrim($path, '/') : $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($resolved !== [] && end($resolved) !== '..') {
                    array_pop($resolved);
                } elseif (!$absolute) {
                    $resolved[] = '..';
                }

                continue;
            }

            $resolved[] = $segment;
        }

        $normalised = implode('/', $resolved);

        return $absolute ? '/' . $normalised : $normalised;
    }

    public static function isPathWithinRoot(string $path, string $root): bool
    {
        $normalisedPath = self::normalisePath($path);
        $normalisedRoot = rtrim(self::normalisePath($root), '/');

        if ($normalisedPath === $normalisedRoot) {
            return true;
        }

        return str_starts_with($normalisedPath, $normalisedRoot . '/');
    }

    public static function resolveWebPath(string $file, string $rootPath): string|false
    {
        $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');

        if (str_starts_with($file, '/')) {
            $resolved = $rootPath . $file;
        } elseif (str_contains($file, '/modules/')) {
            $resolved = $rootPath . substr($file, strpos($file, '/modules/'));
        } elseif (str_contains($file, '/media/')) {
            $resolved = $rootPath . substr($file, strpos($file, '/media/'));
        } else {
            $resolved = $rootPath . '/' . $file;
        }

        $realPath = realpath($resolved);
        $realRoot = realpath($rootPath);

        if ($realPath === false) {
            $realDir = realpath(dirname($resolved));

            if ($realDir !== false) {
                $realPath = $realDir . '/' . basename($resolved);
            }
        }

        $pathToCheck = $realPath !== false
            ? str_replace('\\', '/', $realPath)
            : self::normalisePath($resolved);
        $rootToCheck = $realRoot !== false
            ? str_replace('\\', '/', $realRoot)
            : $rootPath;

        if (!self::isPathWithinRoot($pathToCheck, $rootToCheck)) {
            return false;
        }

        return $resolved;
    }

    public static function isPreMinifiedCss(string $path): bool
    {
        return self::hasExtension($path, '.min.css');
    }

    public static function isPreMinifiedJs(string $path): bool
    {
        return self::hasExtension($path, '.min.js');
    }

    private static function hasExtension(string $path, string $extension): bool
    {
        $path = explode('?', $path, 2)[0];

        return str_ends_with(strtolower($path), strtolower($extension));
    }
}
