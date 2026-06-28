<?php

use MatthiasMullie\PathConverter\Converter;

/**
 * CSS asset path utilities for combination and subdirectory installs
 */
class MinifierCssAssetPaths
{
    /**
     * Replaces relative url() and @import paths using the path converter
     */
    public static function replaceRelativePaths(string $cssContent, Converter $converter): string
    {
        $relativeRegexes = [
            '/url\s*\(\s*(?P<quotes>["\']?)(?P<path>.+?)(?P=quotes)\s*\)/ix',
            '/@import\s+(?P<quotes>["\'])(?P<path>.+?)(?P=quotes)/ix',
        ];

        $replacements = [];

        foreach ($relativeRegexes as $regex) {
            if (!preg_match_all($regex, $cssContent, $regexMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($regexMatches as $match) {
                $fullMatch = $match[0][0];
                $offset = $match[0][1];
                $type = str_starts_with($fullMatch, '@import') ? 'import' : 'url';
                $url = $match['path'][0];

                if (preg_match('/^(data:|https?:|\/)/i', $url) === 1) {
                    continue;
                }

                $params = strrchr($url, '?');
                $path = $params ? substr($url, 0, -strlen($params)) : $url;
                $url = $converter->convert($path) . ($params ?: '');
                $url = trim($url);

                if (preg_match('/[\s\)\'"#\x{7f}-\x{9f}]/u', $url)) {
                    $url = $match['quotes'][0] . $url . $match['quotes'][0];
                }

                $replacements[] = [
                    'offset' => $offset,
                    'length' => strlen($fullMatch),
                    'replacement' => $type === 'url'
                        ? 'url(' . $url . ')'
                        : '@import ' . $match['quotes'][0] . $url . $match['quotes'][0],
                ];
            }
        }

        return MinifierHtmlReplacements::apply($cssContent, $replacements);
    }

    /**
     * Prefixes root-relative CSS asset URLs with the Joomla base path
     */
    public static function prefixSubdirectoryPaths(string $cssContent, string $base): string
    {
        if ($base === '' || $base === '/') {
            return $cssContent;
        }

        $cssContent = preg_replace_callback(
            '/url\s*\(\s*([\'"]?)(\/[^\'"\)]+)\1\s*\)/i',
            function (array $matches) use ($base) {
                $quote = $matches[1];
                $url = $matches[2];

                if (str_starts_with($url, $base . '/') || $url === $base) {
                    return $matches[0];
                }

                return 'url(' . $quote . $base . $url . $quote . ')';
            },
            $cssContent
        );

        return preg_replace_callback(
            '/@import\s+([\'"])(\/[^\'"]+)\1/i',
            function (array $matches) use ($base) {
                $quote = $matches[1];
                $url = $matches[2];

                if (str_starts_with($url, $base . '/') || $url === $base) {
                    return $matches[0];
                }

                return '@import ' . $quote . $base . $url . $quote;
            },
            $cssContent
        );
    }

    /**
     * Relocates relative CSS asset paths for a combined output file
     */
    public static function relocatePaths(
        string $cssContent,
        string $sourcePath,
        string $targetPath,
        string $rootPath,
        string $base
    ): string {
        $converter = new Converter($sourcePath, $targetPath, $rootPath);

        return self::prefixSubdirectoryPaths(
            self::replaceRelativePaths($cssContent, $converter),
            $base
        );
    }
}
