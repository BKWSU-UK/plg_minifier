<?php

use MatthiasMullie\PathConverter\Converter;

/**
 * CSS asset path utilities for combination and subdirectory installs
 */
class MinifierCssAssetPaths
{
    public static function stripUtf8Bom(string $cssContent): string
    {
        return str_starts_with($cssContent, "\xEF\xBB\xBF")
            ? substr($cssContent, 3)
            : $cssContent;
    }

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
     * Prepares combined CSS: strips duplicate @charset rules, hoists local @import,
     * and extracts external @import URLs for HTML link tags.
     *
     * @return array{css: string, externalImports: array<int, string>}
     */
    public static function prepareCombinedCss(string $cssContent): array
    {
        $charset = '';
        $externalImports = [];
        $localImports = [];
        $body = $cssContent;

        while (preg_match('/@charset\s+[^;]+;/i', $body, $match, PREG_OFFSET_CAPTURE) === 1) {
            if ($charset === '') {
                $charset = trim($match[0][0]);
            }

            $body = substr_replace($body, '', $match[0][1], strlen($match[0][0]));
        }

        while (preg_match(
            '/@import\s*(?:url\s*\(\s*)?(["\'])(.*?)\1(?:\s+[^;]*)?;/is',
            $body,
            $match,
            PREG_OFFSET_CAPTURE
        ) === 1) {
            $rule = trim($match[0][0]);
            $importUrl = $match[2][0];
            $body = substr_replace($body, '', $match[0][1], strlen($match[0][0]));

            if (self::isExternalImportUrl($importUrl)) {
                if (!in_array($importUrl, $externalImports, true)) {
                    $externalImports[] = $importUrl;
                }

                continue;
            }

            if (!in_array($rule, $localImports, true)) {
                $localImports[] = $rule;
            }
        }

        while (preg_match(
            '/@import\s+url\(\s*([^"\')\s][^)]*)\s*\)(?:\s+[^;]*)?;/is',
            $body,
            $match,
            PREG_OFFSET_CAPTURE
        ) === 1) {
            $rule = trim($match[0][0]);
            $importUrl = trim($match[1][0]);
            $body = substr_replace($body, '', $match[0][1], strlen($match[0][0]));

            if (self::isExternalImportUrl($importUrl)) {
                if (!in_array($importUrl, $externalImports, true)) {
                    $externalImports[] = $importUrl;
                }

                continue;
            }

            if (!in_array($rule, $localImports, true)) {
                $localImports[] = $rule;
            }
        }

        $body = ltrim($body);
        $body = str_replace("\xEF\xBB\xBF", '', $body);

        if ($charset === '' && $localImports === [] && $externalImports === []) {
            return [
                'css' => $cssContent,
                'externalImports' => [],
            ];
        }

        $prefix = $charset !== '' ? $charset . "\n" : '';

        if ($localImports !== []) {
            $prefix .= implode("\n", $localImports) . "\n";
        }

        return [
            'css' => $prefix . $body,
            'externalImports' => $externalImports,
        ];
    }

    /**
     * Moves @charset and local @import rules to the top of combined CSS output
     */
    public static function hoistAtRules(string $cssContent): string
    {
        return self::prepareCombinedCss($cssContent)['css'];
    }

    private static function isExternalImportUrl(string $url): bool
    {
        return str_starts_with($url, '//')
            || preg_match('/^https?:\/\//i', $url) === 1;
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
