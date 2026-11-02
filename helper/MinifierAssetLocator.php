<?php

/**
 * Finds the local file behind an asset URL written in the page.
 *
 * Shared by individual minification and combine planning so both apply the
 * same rules: excluded and external URLs are not local, paths resolving
 * outside the site root are refused (and reported), missing files are skipped.
 */
final class MinifierAssetLocator
{
    /** @var \Closure(string $level, string $message): void */
    private readonly \Closure $log;

    /**
     * @param string $rootPath Site root the asset URLs resolve against
     * @param list<string> $excludePaths URLs containing any of these substrings are treated as not local
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'debug' and 'warning' messages
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly array $excludePaths = [],
        ?\Closure $log = null
    ) {
        $this->log = $log ?? static function (string $level, string $message): void {
        };
    }

    /** Neither excluded nor external (query string ignored) */
    public function isLocal(string $url): bool
    {
        [$path] = self::splitQuery($url);

        foreach ($this->excludePaths as $excludePath) {
            if ($excludePath !== '' && str_contains($path, $excludePath)) {
                return false;
            }
        }

        return !self::isExternalUrl($path);
    }

    /**
     * @return string|null Absolute path of the existing local file, or null when the URL
     *                     is not local, resolves outside the site root, or the file is missing
     */
    public function locate(string $url): ?string
    {
        if (!$this->isLocal($url)) {
            return null;
        }

        [$path] = self::splitQuery($url);
        $resolved = MinifierAsset::resolveWebPath($path, $this->rootPath);

        if ($resolved === false) {
            ($this->log)('warning', sprintf('Security: Path traversal attempt detected: %s resolves outside root', $path));

            return null;
        }

        if (!is_file($resolved)) {
            ($this->log)('debug', 'Asset file not found: ' . $resolved);

            return null;
        }

        return $resolved;
    }

    /** Protocol-relative or http(s) URLs, any letter case */
    private static function isExternalUrl(string $path): bool
    {
        return str_starts_with($path, '//') || preg_match('/^https?:\/\//i', $path) === 1;
    }

    /**
     * @return array{0: string, 1: string} The path and the query string including its leading "?" (or "")
     */
    public static function splitQuery(string $url): array
    {
        $position = strpos($url, '?');

        return $position === false
            ? [$url, '']
            : [substr($url, 0, $position), substr($url, $position)];
    }
}
