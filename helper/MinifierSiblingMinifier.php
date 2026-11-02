<?php

/**
 * Individual-mode minification: rewrites one asset URL to a sibling
 * `<name>.min.<ext>` file next to its source.
 *
 * Owns the skip rules (external, excluded, pre-minified, unresolvable or
 * outside the site root, missing), the freshness check (re-minify only when
 * the source is newer than its sibling) and query-string preservation, for
 * both asset types alike.
 */
final class MinifierSiblingMinifier
{
    /** @var \Closure(string $level, string $message): void */
    private readonly \Closure $log;

    /**
     * @param string $rootPath Site root the asset URLs resolve against
     * @param list<string> $excludePaths URLs containing any of these substrings are left alone
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'debug', 'warning' and 'error' messages
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly array $excludePaths = [],
        ?\Closure $log = null
    ) {
        $this->log = $log ?? static function (string $level, string $message): void {
        };
    }

    /**
     * @param string $url Asset URL as written in the page, optionally with a query string
     * @return string|null The sibling .min URL (query string preserved), or null to leave the tag alone
     */
    public function minify(string $url, MinifierAssetType $type): ?string
    {
        [$path, $query] = self::splitQuery($url);

        if ($this->isExcluded($path) || MinifierAsset::isExternalUrl($path) || $type->isPreMinified($path)) {
            return null;
        }

        $sourcePath = MinifierAsset::resolveWebPath($path, $this->rootPath);

        if ($sourcePath === false) {
            ($this->log)('warning', sprintf('Security: Path traversal attempt detected: %s resolves outside root', $path));

            return null;
        }

        if (!is_file($sourcePath)) {
            ($this->log)('debug', sprintf('%s file not found: %s', strtoupper($type->value), $sourcePath));

            return null;
        }

        $siblingPath = self::siblingOf($sourcePath, $type);

        if (!is_writable(dirname($siblingPath))) {
            ($this->log)('error', 'Directory not writable: ' . dirname($siblingPath));

            return null;
        }

        if (!is_file($siblingPath) || filemtime($sourcePath) > filemtime($siblingPath)) {
            try {
                $type->minifier($sourcePath)->minify($siblingPath);
            } catch (\Exception $e) {
                ($this->log)('error', sprintf('%s minification failed: %s for file: %s', strtoupper($type->value), $e->getMessage(), $url));

                return null;
            }

            // Stamp the sibling now so it is never older than the source it came from
            touch($siblingPath, time());
            ($this->log)('debug', sprintf('%s file minified: %s', strtoupper($type->value), $siblingPath));
        }

        return self::siblingOf($path, $type) . $query;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $excludePath) {
            if ($excludePath !== '' && str_contains($path, $excludePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string} The path and the query string including its leading "?" (or "")
     */
    private static function splitQuery(string $url): array
    {
        $position = strpos($url, '?');

        return $position === false
            ? [$url, '']
            : [substr($url, 0, $position), substr($url, $position)];
    }

    /** `dir/name.ext` → `dir/name.min.<type>` */
    private static function siblingOf(string $path, MinifierAssetType $type): string
    {
        $name = preg_replace('/\.[^.]*$/', '', basename($path));

        return dirname($path) . '/' . $name . '.min.' . $type->value;
    }
}
