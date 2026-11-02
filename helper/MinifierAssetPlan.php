<?php

/**
 * Combine planning: decides which tags of one asset pass are combined into
 * which bundle, in what file order, and where each bundle tag is placed.
 */
final class MinifierAssetPlan
{
    private readonly MinifierAssetLocator $locator;

    /** @var \Closure(string $level, string $message): void */
    private readonly \Closure $log;

    /**
     * @param list<string> $excludePaths
     */
    private function __construct(
        string $rootPath,
        array $excludePaths,
        private readonly MinifierAssetType $type,
        private readonly bool $combineAll,
        ?\Closure $log,
        string $baseUrl
    ) {
        $this->log = $log ?? static function (string $level, string $message): void {
        };
        $this->locator = new MinifierAssetLocator($rootPath, $excludePaths, $this->log, $baseUrl);
    }

    /**
     * @param list<string> $excludePaths URLs containing any of these substrings are never combined
     * @param bool $combineAll Whether pre-minified files are combined too
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'debug' and 'warning' messages
     * @param string $baseUrl The site's base URL path ('' at the domain root, '/sub' in a subdirectory install)
     */
    public static function scripts(
        string $rootPath,
        array $excludePaths,
        bool $combineAll,
        ?\Closure $log = null,
        string $baseUrl = ''
    ): self {
        return new self($rootPath, $excludePaths, MinifierAssetType::Js, $combineAll, $log, $baseUrl);
    }

    /**
     * @param list<string> $excludePaths URLs containing any of these substrings are never combined
     * @param bool $combineAll Whether pre-minified files are combined too
     * @param (\Closure(string $level, string $message): void)|null $log Receives 'debug' and 'warning' messages
     * @param string $baseUrl The site's base URL path ('' at the domain root, '/sub' in a subdirectory install)
     */
    public static function stylesheets(
        string $rootPath,
        array $excludePaths,
        bool $combineAll,
        ?\Closure $log = null,
        string $baseUrl = ''
    ): self {
        return new self($rootPath, $excludePaths, MinifierAssetType::Css, $combineAll, $log, $baseUrl);
    }

    /**
     * @return list<MinifierCombineBlock> Blocks in page order
     */
    public function blocks(MinifierHtmlReplacements $tags): array
    {
        $blocks = [];
        $indices = [];
        $files = [];

        $closeBlock = function () use (&$blocks, &$indices, &$files, $tags): void {
            if ($files !== []) {
                $blocks[] = new MinifierCombineBlock(
                    $indices,
                    $this->inBundleOrder($files),
                    $this->anchorIndex($tags, $indices, $files)
                );
            }

            $indices = [];
            $files = [];
        };

        for ($index = 0; $index < $tags->count(); $index++) {
            if ($index > 0 && !$this->isContiguous($tags, $index - 1, $index)) {
                $closeBlock();
            }

            $path = $this->combinablePath($tags, $index);

            if ($path === null) {
                $closeBlock();

                continue;
            }

            // A file already in this bundle: drop the repeated tag, bundle the file once
            if (!in_array($path, array_column($files, 'path'), true)) {
                $files[] = new MinifierBundleFile($path, self::webPath($tags, $index));
                ($this->log)('debug', 'Adding file to combination: ' . $path);
            }

            $indices[] = $index;
        }

        $closeBlock();

        return $blocks;
    }

    /**
     * Where the bundle tag goes. A stylesheet bundle replaces the block's first tag; a
     * script bundle by default replaces the block's last tag.
     *
     * A script bundle that calls jQuery without containing it jumps to the first local
     * jQuery core later in the page, whatever lies in between, since it cannot run before
     * jQuery; from there it moves past the scripts combined right after that core.
     * Otherwise it moves past trailing jQuery files the block skipped. Both walks stop
     * at the first tag that breaks contiguity, such as an inline <script> in between.
     *
     * @param list<int> $indices The block's tags, in page order
     * @param list<MinifierBundleFile> $files
     */
    private function anchorIndex(MinifierHtmlReplacements $tags, array $indices, array $files): int
    {
        if ($this->type === MinifierAssetType::Css) {
            return $indices[0];
        }

        $lastIndex = $indices[count($indices) - 1];

        if (self::requiresExternalJquery($files)) {
            $jqueryIndex = $this->laterJqueryCoreIndex($tags, $lastIndex);

            if ($jqueryIndex !== null) {
                return $this->extendWhile(
                    $tags,
                    $jqueryIndex,
                    fn(int $index): bool => $this->combinablePath($tags, $index) !== null
                );
            }
        }

        return $this->extendWhile(
            $tags,
            $lastIndex,
            fn(int $index): bool => self::isJqueryDependency(self::webPath($tags, $index))
                && $this->locator->locate($tags->url($index)) !== null
        );
    }

    /**
     * Walks forward from a tag while the next tag is contiguous and accepted
     *
     * @param \Closure(int): bool $accepts
     * @return int The last accepted tag index
     */
    private function extendWhile(MinifierHtmlReplacements $tags, int $fromIndex, \Closure $accepts): int
    {
        $index = $fromIndex;

        while ($index + 1 < $tags->count()
            && $this->isContiguous($tags, $index, $index + 1)
            && $accepts($index + 1)) {
            $index++;
        }

        return $index;
    }

    private function laterJqueryCoreIndex(MinifierHtmlReplacements $tags, int $afterIndex): ?int
    {
        for ($index = $afterIndex + 1; $index < $tags->count(); $index++) {
            if ($this->locator->isLocal($tags->url($index)) && self::isJqueryCore(self::webPath($tags, $index))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A bundle without jQuery core whose code calls jQuery
     *
     * @param list<MinifierBundleFile> $files
     */
    private static function requiresExternalJquery(array $files): bool
    {
        foreach ($files as $file) {
            if (self::isJqueryCore($file->webPath)) {
                return false;
            }
        }

        foreach ($files as $file) {
            $content = file_get_contents($file->path);

            if ($content !== false && self::callsJquery($content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scripts load jQuery core first, then jQuery noConflict, then the rest in page order
     *
     * @param list<MinifierBundleFile> $files
     * @return list<MinifierBundleFile>
     */
    private function inBundleOrder(array $files): array
    {
        if ($this->type !== MinifierAssetType::Js) {
            return $files;
        }

        $priority = static fn(MinifierBundleFile $file): int => match (true) {
            self::isJqueryCore($file->webPath) => 0,
            self::isJqueryNoConflict($file->webPath) => 1,
            default => 2,
        };

        usort($files, static fn(MinifierBundleFile $a, MinifierBundleFile $b): int => $priority($a) <=> $priority($b));

        return $files;
    }

    /**
     * Whether nothing between two neighbouring tags stops them sharing a bundle: for
     * scripts, an inline <script> in between must keep running between them; for
     * stylesheets, styles the combiner does not track (an inline <style>, or a
     * stylesheet <link> it cannot combine) must keep their place in the cascade.
     */
    private function isContiguous(MinifierHtmlReplacements $tags, int $previousIndex, int $index): bool
    {
        $between = $tags->textBetween($previousIndex, $index);

        return match ($this->type) {
            MinifierAssetType::Js => !self::containsInlineScript($between),
            MinifierAssetType::Css => !self::containsUntrackedStyles($between),
        };
    }

    /**
     * The contiguity rule: a tag can join a bundle when it points at an existing local,
     * non-excluded file that is not pre-minified (unless pre-minified files are combined too).
     *
     * @return string|null The file's absolute path, or null when the tag breaks contiguity
     */
    private function combinablePath(MinifierHtmlReplacements $tags, int $index): ?string
    {
        if (!$this->combineAll && $this->type->isPreMinified(self::webPath($tags, $index))) {
            return null;
        }

        return $this->locator->locate($tags->url($index));
    }

    private static function isJqueryCore(string $webPath): bool
    {
        return preg_match('#/jquery(?:\.min)?\.js$#', strtolower($webPath)) === 1;
    }

    private static function isJqueryNoConflict(string $webPath): bool
    {
        return str_contains(strtolower($webPath), 'jquery-noconflict');
    }

    private static function isJqueryDependency(string $webPath): bool
    {
        return self::isJqueryCore($webPath) || self::isJqueryNoConflict($webPath);
    }

    /** Script code that calls `jQuery(…)`, or `$(…)` at the start of a statement */
    private static function callsJquery(string $code): bool
    {
        return preg_match('/\bjQuery\s*\(|(?:^|[{;}])\s*\$\s*\(/m', $code) === 1;
    }

    /**
     * Markup containing an inline `<style>` element or a `<link>` whose `rel` attribute has
     * the `stylesheet` token (quoted among other tokens, or unquoted on its own)
     */
    private static function containsUntrackedStyles(string $html): bool
    {
        return preg_match(
            '/<style(?=[\s>\/])'
            . '|<link\b[^>]*(?<![\w-])rel\s*=\s*'
            . '(?:"(?:[^"]*\s)?stylesheet(?:\s[^"]*)?"|\'(?:[^\']*\s)?stylesheet(?:\s[^\']*)?\'|stylesheet(?=[\s>\/]))/i',
            $html
        ) === 1;
    }

    /** Markup containing a `<script>` without a `src` attribute */
    private static function containsInlineScript(string $html): bool
    {
        return preg_match('/<script(?![^>]*\bsrc\s*=)[^>]*>/i', $html) === 1;
    }

    private static function webPath(MinifierHtmlReplacements $tags, int $index): string
    {
        return MinifierAssetLocator::splitQuery($tags->url($index))[0];
    }
}

/**
 * One file of a bundle: where it is on disk and the web path it was linked as.
 */
final class MinifierBundleFile
{
    /**
     * @param string $path Absolute filesystem path
     * @param string $webPath The page's URL for the file, without its query string
     */
    public function __construct(
        public readonly string $path,
        public readonly string $webPath
    ) {
    }
}

/**
 * One planned bundle: the tags it replaces, its files in bundle order, and its anchor tag.
 */
final class MinifierCombineBlock
{
    /**
     * @param list<int> $tagIndices Every tag the bundle replaces, in page order
     * @param list<MinifierBundleFile> $files Bundle files in load order
     * @param int $anchorIndex The bundle tag replaces this tag when it is a member, otherwise goes right after it
     */
    public function __construct(
        public readonly array $tagIndices,
        public readonly array $files,
        public readonly int $anchorIndex
    ) {
    }

    /** Registers the edits that swap the block's tags for one bundle tag */
    public function placeBundle(MinifierHtmlReplacements $tags, string $bundleTag): void
    {
        $anchorIsMember = in_array($this->anchorIndex, $this->tagIndices, true);

        foreach ($this->tagIndices as $index) {
            if ($anchorIsMember && $index === $this->anchorIndex) {
                $tags->replaceTag($index, $bundleTag);
            } else {
                $tags->removeTag($index);
            }
        }

        if (!$anchorIsMember) {
            $tags->insertAfterTag($this->anchorIndex, $bundleTag);
        }
    }
}
