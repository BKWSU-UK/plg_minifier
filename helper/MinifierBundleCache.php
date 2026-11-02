<?php

use MatthiasMullie\Minify;

/**
 * Combined-bundle cache: turns the ordered files of one combine block into a
 * bundle file under `<site root>/media/cache/<css|js>/combined-<hash>.<ext>`.
 *
 * Owns choosing minify vs. pass-through per file, relocating CSS `url()` and
 * `@import` paths for the cache location, hoisting `@charset`/`@import`,
 * extracting external `@import`s (which become separate `<link>` tags),
 * content hashing, writing, and pruning old bundles.
 */
final class MinifierBundleCache
{
    /** prune() keeps this many most recent bundles per asset type; bundles written by this instance are never deleted */
    private const KEEP = 5;

    /** @var array<string, array<string, true>> Bundle file names written by this instance, keyed by asset type value */
    private array $writtenNames = [];

    /** @var \Closure(string $level, string $message): void */
    private readonly \Closure $log;

    /**
     * @param string $siteRoot Filesystem path of the site root; bundles are written below it
     * @param string $baseUrl The site's base URL path ('' at the domain root, '/sub' in a subdirectory install)
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'info', 'warning' and 'error' messages
     */
    public function __construct(
        private readonly string $siteRoot,
        private readonly string $baseUrl,
        ?\Closure $log = null
    ) {
        $this->log = $log ?? static function (string $level, string $message): void {
        };
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

            $filename = 'combined-' . substr(md5($content . implode('', $externalImports)), 0, 8) . '.' . $type->value;

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
     * Deletes old bundles of one asset type: keeps the most recently written ones, and
     * never deletes a bundle this instance wrote, since the page being rendered links it
     */
    public function prune(MinifierAssetType $type): void
    {
        $bundles = glob($this->cacheDir($type) . '/combined-*.' . $type->value) ?: [];

        if (count($bundles) <= self::KEEP) {
            return;
        }

        usort($bundles, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $current = $this->writtenNames[$type->value] ?? [];

        foreach (array_slice($bundles, self::KEEP) as $bundle) {
            if (isset($current[basename($bundle)])) {
                continue;
            }

            if (!@unlink($bundle)) {
                ($this->log)('warning', 'Failed to delete old combined file: ' . $bundle);
            }
        }
    }

    private function cacheDir(MinifierAssetType $type): string
    {
        return rtrim($this->siteRoot, '/\\') . '/media/cache/' . $type->value;
    }

    /**
     * @param list<MinifierBundleFile> $files
     * @return array{css: string, externalImports: list<string>} Bundle CSS and the external @import URLs taken out of it
     */
    private function cssBundle(array $files, string $targetPath): array
    {
        $allMinifiable = !array_filter(
            $files,
            static fn(MinifierBundleFile $file): bool => MinifierAssetType::Css->isPreMinified($file->webPath)
        );

        if ($allMinifiable && count($files) > 1) {
            $minifier = new Minify\CSS();

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

        $minifier = new Minify\CSS();
        $minifier->addFile($file->path);

        return $this->minified($minifier, $targetPath);
    }

    /** Minified CSS with its charset repaired and root-relative URLs prefixed with the base URL */
    private function minified(Minify\CSS $minifier, string $targetPath): string
    {
        return MinifierCssAssetPaths::prefixSubdirectoryPaths(
            MinifierCssAssetPaths::repairMinifiedCharset($minifier->execute($targetPath)),
            $this->baseUrl
        );
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
     * @param list<string> $externalImports External stylesheet URLs taken out of a CSS bundle's @import rules
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

        foreach ($this->externalImports as $importUrl) {
            $html .= '<link href="' . htmlspecialchars($importUrl, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">';
        }

        return $html . '<link href="' . $this->url . '" rel="stylesheet">';
    }
}
