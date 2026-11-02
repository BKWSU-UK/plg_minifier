<?php

use MatthiasMullie\Minify;

/**
 * Combined-bundle cache: turns the ordered files of one combine block into a
 * bundle file under `<site root>/media/cache/<css|js>/combined-<hash>.<ext>`.
 *
 * Owns choosing minify vs. pass-through per file, relocating CSS `url()` and
 * `@import` paths for the cache location, hoisting `@charset`/`@import`,
 * extracting external `@import`s (which become separate `<link>` tags),
 * content hashing, writing, and pruning bundles no page has used for a while.
 */
final class MinifierBundleCache
{
    /** Default retention window in days: a bundle unused for longer than this is deleted by prune() */
    public const DEFAULT_RETENTION_DAYS = 7;

    public const DEFAULT_RETENTION_SECONDS = self::DEFAULT_RETENTION_DAYS * 86400;

    /** @var array<string, array<string, true>> Bundle file names written by this instance, keyed by asset type value */
    private array $writtenNames = [];

    /** @var \Closure(string $level, string $message): void */
    private readonly \Closure $log;

    /**
     * @param string $siteRoot Filesystem path of the site root; bundles are written below it
     * @param string $baseUrl The site's base URL path ('' at the domain root, '/sub' in a subdirectory install)
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'info', 'warning' and 'error' messages
     * @param int $retentionSeconds How long an unused bundle is kept; it must outlast every page cache
     *                              (Joomla, proxy, CDN, open browser tabs) that can still link it
     */
    public function __construct(
        private readonly string $siteRoot,
        private readonly string $baseUrl,
        ?\Closure $log = null,
        private readonly int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS
    ) {
        $this->log = $log ?? static function (string $level, string $message): void {
        };
    }

    /**
     * The retention window for a lifetime setting in days. A missing, zero, negative or
     * non-numeric setting uses the default, never a shorter window.
     */
    public static function retentionSecondsFromDays(mixed $days): int
    {
        $days = is_numeric($days) ? (int) $days : 0;

        return ($days >= 1 ? $days : self::DEFAULT_RETENTION_DAYS) * 86400;
    }

    /**
     * @param list<MinifierBundleFile> $files Bundle files in load order
     * @return MinifierBundle|null The written bundle, or null when there was nothing to write or writing failed
     */
    public function write(array $files, MinifierAssetType $type): ?MinifierBundle
    {
        if ($files === []) {
            return null;
        }

        $label = strtoupper($type->value);
        $cacheDir = $this->cacheDir($type);

        try {
            ['css' => $content, 'externalImports' => $externalImports] = $type === MinifierAssetType::Css
                ? $this->cssBundle($files, $cacheDir . '/combined.css')
                : ['css' => $this->jsBundle($files), 'externalImports' => []];

            if ($content === '') {
                return null;
            }

            $filename = 'combined-' . substr(md5($content . self::importsKey($externalImports)), 0, 8) . '.' . $type->value;

            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                throw new \RuntimeException('Failed to create cache directory: ' . $cacheDir);
            }

            if (file_put_contents($cacheDir . '/' . $filename, $content) === false) {
                throw new \RuntimeException(sprintf('Failed to write combined %s file: %s', $label, $cacheDir . '/' . $filename));
            }
        } catch (\Exception $e) {
            ($this->log)('error', sprintf('Failed to create combined %s file: %s', $label, $e->getMessage()));

            return null;
        }

        $this->writtenNames[$type->value][$filename] = true;
        ($this->log)('info', sprintf('Created combined %s file: %s', $label, $filename));

        return new MinifierBundle(
            $this->baseUrl . '/media/cache/' . $type->value . '/' . $filename,
            $externalImports,
            $type
        );
    }

    /**
     * Deletes bundles of one asset type that no page has used within the retention window.
     * Every render that uses a bundle rewrites it, so a bundle's modification time is when
     * it was last used. A bundle this instance wrote is never deleted, since the page being
     * rendered links it.
     */
    public function prune(MinifierAssetType $type): void
    {
        $current = $this->writtenNames[$type->value] ?? [];
        $cutoff = time() - $this->retentionSeconds;

        foreach (glob($this->cacheDir($type) . '/combined-*.' . $type->value) ?: [] as $bundle) {
            $lastUsed = @filemtime($bundle);

            // Unreadable usually means another request just deleted it
            if (isset($current[basename($bundle)]) || $lastUsed === false || $lastUsed >= $cutoff) {
                continue;
            }

            if (!@unlink($bundle)) {
                ($this->log)('warning', 'Failed to delete old combined file: ' . $bundle);
            }
        }
    }

    /**
     * The external imports' part of the bundle hash; a plain import contributes just its URL
     *
     * @param list<array{url: string, media: string}> $externalImports
     */
    private static function importsKey(array $externalImports): string
    {
        return implode('', array_map(
            static fn(array $import): string => $import['url'] . ($import['media'] !== '' ? ' ' . $import['media'] : ''),
            $externalImports
        ));
    }

    private function cacheDir(MinifierAssetType $type): string
    {
        return rtrim($this->siteRoot, '/\\') . '/media/cache/' . $type->value;
    }

    /**
     * @param list<MinifierBundleFile> $files
     * @return array{css: string, externalImports: list<array{url: string, media: string}>} Bundle CSS and the external imports taken out of it
     */
    private function cssBundle(array $files, string $targetPath): array
    {
        $allMinifiable = !array_filter(
            $files,
            static fn(MinifierBundleFile $file): bool => MinifierAssetType::Css->isPreMinified($file->webPath)
        );

        if ($allMinifiable && count($files) > 1) {
            $minifier = new MinifierCss();

            foreach ($files as $file) {
                $minifier->addFile($file->path);
            }

            $content = '/* Files: ' . implode(', ', array_column($files, 'webPath')) . " */\n"
                . $this->minified($minifier, $targetPath) . "\n";
        } else {
            $content = '';

            foreach ($files as $file) {
                $content .= "/* File: {$file->webPath} */\n" . $this->cssFile($file, $targetPath) . "\n";
            }
        }

        $prepared = MinifierCssAssetPaths::prepareCombinedCss($content);

        return ['css' => $prepared['css'], 'externalImports' => array_values($prepared['externalImports'])];
    }

    /**
     * One stylesheet's CSS with its asset paths relocated for the bundle's location
     *
     */
    private function cssFile(MinifierBundleFile $file, string $targetPath): string
    {
        if (MinifierAssetType::Css->isPreMinified($file->webPath)) {
            return MinifierCssAssetPaths::relocatePaths(
                self::read($file->path),
                $file->path,
                $targetPath,
                $this->siteRoot,
                $this->baseUrl
            );
        }

        $minifier = new MinifierCss();
        $minifier->addFile($file->path);

        return $this->minified($minifier, $targetPath);
    }

    /** Minified CSS with root-relative URLs prefixed with the base URL */
    private function minified(MinifierCss $minifier, string $targetPath): string
    {
        return MinifierCssAssetPaths::prefixSubdirectoryPaths($minifier->execute($targetPath), $this->baseUrl);
    }

    /**
     * @param list<MinifierBundleFile> $files
     */
    private function jsBundle(array $files): string
    {
        $allMinifiable = !array_filter(
            $files,
            static fn(MinifierBundleFile $file): bool => MinifierAssetType::Js->isPreMinified($file->webPath)
        );

        if ($allMinifiable && count($files) > 1) {
            $minifier = new Minify\JS();

            foreach ($files as $file) {
                $minifier->addFile($file->path);
            }

            return '/* Files: ' . implode(', ', array_column($files, 'webPath')) . " */\n" . $minifier->minify() . "\n";
        }

        $content = '';

        foreach ($files as $file) {
            if (MinifierAssetType::Js->isPreMinified($file->webPath)) {
                $script = self::read($file->path);
            } else {
                $minifier = new Minify\JS();
                $minifier->addFile($file->path);
                $script = $minifier->minify();
            }

            $content .= "/* File: {$file->webPath} */\n" . self::jsSegment($script);
        }

        return $content;
    }

    /**
     * One script's bundle segment: source map comment dropped, and a leading `;` so a
     * previous file without a trailing semicolon cannot run into it
     */
    private static function jsSegment(string $script): string
    {
        $script = trim(preg_replace('/\/\/#\s*sourceMappingURL=\S+/', '', $script) ?? $script);

        return $script === '' ? "\n" : ';' . $script . "\n";
    }

    /**
     * A pass-through file's content, without a leading UTF-8 BOM that would otherwise end up mid-bundle
     */
    private static function read(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException('Failed to read file: ' . $path);
        }

        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }
}

/**
 * A written combined bundle
 */
final class MinifierBundle
{
    /**
     * @param string $url The bundle's URL path
     * @param list<array{url: string, media: string}> $externalImports External stylesheets taken out of a CSS bundle's
     *                                                               @import rules, with their media query ('' for all media)
     */
    public function __construct(
        public readonly string $url,
        public readonly array $externalImports,
        private readonly MinifierAssetType $type
    ) {
    }

    /** The markup that loads the bundle: external imports first, then the bundle itself */
    public function html(): string
    {
        if ($this->type === MinifierAssetType::Js) {
            return '<script src="' . $this->url . '"></script>';
        }

        $html = '';

        foreach ($this->externalImports as $import) {
            $html .= '<link href="' . htmlspecialchars($import['url'], ENT_QUOTES, 'UTF-8') . '" rel="stylesheet"'
                . ($import['media'] !== '' ? ' media="' . htmlspecialchars($import['media'], ENT_QUOTES, 'UTF-8') . '"' : '')
                . '>';
        }

        return $html . '<link href="' . $this->url . '" rel="stylesheet">';
    }
}
