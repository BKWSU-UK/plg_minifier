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

    private readonly MinifierAssetLocator $locator;

    /**
     * @param string $rootPath Site root the asset URLs resolve against
     * @param list<string> $excludePaths URLs containing any of these substrings are left alone
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'debug', 'warning' and 'error' messages
     * @param string $baseUrl The site's base URL path ('' at the domain root, '/sub' in a subdirectory install)
     */
    public function __construct(string $rootPath, array $excludePaths = [], ?\Closure $log = null, string $baseUrl = '')
    {
        $this->log = $log ?? static function (string $level, string $message): void {
        };
        $this->locator = new MinifierAssetLocator($rootPath, $excludePaths, $this->log, $baseUrl);
    }

    /**
     * @param string $url Asset URL as written in the page, optionally with a query string
     * @return string|null The sibling .min URL (query string preserved), or null to leave the tag alone
     */
    public function minify(string $url, MinifierAssetType $type): ?string
    {
        [$path, $query] = MinifierAssetLocator::splitQuery($url);

        if ($type->isPreMinified($path)) {
            return null;
        }

        $sourcePath = $this->locator->locate($path);

        if ($sourcePath === null) {
            return null;
        }

        $siblingPath = self::siblingOf($sourcePath, $type);

        if (!is_writable(dirname($siblingPath))) {
            ($this->log)('error', 'Directory not writable: ' . dirname($siblingPath));

            return null;
        }

        if (!is_file($siblingPath) || filemtime($sourcePath) > filemtime($siblingPath)) {
            try {
                $minified = $type->minifier($sourcePath)->execute($siblingPath);

                if (@file_put_contents($siblingPath, $minified) === false) {
                    throw new \RuntimeException('Failed to write ' . $siblingPath);
                }
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

    /** `dir/name.ext` → `dir/name.min.<type>` */
    private static function siblingOf(string $path, MinifierAssetType $type): string
    {
        $name = preg_replace('/\.[^.]*$/', '', basename($path));

        return dirname($path) . '/' . $name . '.min.' . $type->value;
    }
}
