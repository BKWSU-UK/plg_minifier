<?php

use MatthiasMullie\PathConverter\Converter;

/**
 * CSS asset path utilities for combination and subdirectory installs
 */
class MinifierCssAssetPaths
{
    /**
     * A real `@charset` rule: exactly `@charset "…"` (the only form CSS treats as an encoding
     * declaration) at a rule boundary, so text inside strings and url()s never matches.
     * Group 1 is the whitespace before the rule, which removal puts back; group 2 is the encoding.
     */
    private const CHARSET_PATTERN = '/(?:^|(?<=[;{}])|(?<=\*\/))(\s*)@charset "([^"]*)"(?:\s*;)?/';

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

        return self::spliceReplacements($cssContent, $replacements);
    }

    /**
     * Applies offset records from the end backwards so earlier offsets stay valid.
     * The url() and quoted @import patterns never match overlapping text.
     *
     * @param array<int, array{offset: int, length: int, replacement: string}> $replacements
     */
    private static function spliceReplacements(string $cssContent, array $replacements): string
    {
        usort($replacements, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);

        foreach ($replacements as $replacement) {
            $cssContent = substr_replace(
                $cssContent,
                $replacement['replacement'],
                $replacement['offset'],
                $replacement['length']
            );
        }

        return $cssContent;
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
     * `@import "…"` or `@import url("…")` with any qualifiers, at a rule boundary (so text
     * inside strings never matches); groups `lead` (whitespace before it), `url`, `qualifier`
     */
    private const IMPORT_QUOTED_PATTERN = '/(?:^|(?<=[;{}])|(?<=\*\/))(?<lead>(?:\s|\xEF\xBB\xBF)*)'
        . '@import\s*(?:url\s*\(\s*)?(["\'])(?<url>.*?)\2(?<qualifier>\s+[^;]*)?;/is';

    /** `@import url(…)` with an unquoted URL and any qualifiers, at a rule boundary */
    private const IMPORT_UNQUOTED_PATTERN = '/(?:^|(?<=[;{}])|(?<=\*\/))(?<lead>(?:\s|\xEF\xBB\xBF)*)'
        . '@import\s+url\(\s*(?<url>[^"\')\s][^)]*)\s*\)(?<qualifier>\s+[^;]*)?;/is';

    /** A layer order statement such as `@layer base, theme;` (not a layer block), at a rule boundary */
    private const LAYER_STATEMENT_PATTERN = '/(?:^|(?<=[;{}])|(?<=\*\/))(?<lead>(?:\s|\xEF\xBB\xBF)*)(?<statement>@layer\s+[^;{}]+;)/i';

    /**
     * Prepares combined CSS: keeps one `@charset` first, takes external `@import`s out
     * for HTML `<link>` tags where a link can express them (no qualifier, or a media
     * query), and hoists the remaining `@import`s (local ones, and external ones with
     * `supports()` or `layer()`) to the top whole. Layer order statements that come
     * before a hoisted import move up with it, in their original order.
     *
     * @return array{css: string, externalImports: list<array{url: string, media: string}>}
     */
    public static function prepareCombinedCss(string $cssContent): array
    {
        $charset = '';
        $body = $cssContent;

        if (preg_match(self::CHARSET_PATTERN, $body, $match) === 1) {
            $charset = '@charset "' . $match[2] . '";';
            $body = preg_replace(self::CHARSET_PATTERN, '$1', $body);
        }

        $externalImports = [];
        $hoisted = [];
        $removals = [];
        $lastHoistedOffset = -1;

        foreach (self::importsIn($body) as $offset => $import) {
            $removals[$offset] = $import['length'];

            if (self::isExternalImportUrl($import['url'])
                && preg_match('/\b(?:supports|layer)\b/i', $import['qualifier']) !== 1) {
                $link = ['url' => $import['url'], 'media' => $import['qualifier']];

                if (!in_array($link, $externalImports, true)) {
                    $externalImports[] = $link;
                }

                continue;
            }

            if (!in_array($import['rule'], $hoisted, true)) {
                $hoisted[$offset] = $import['rule'];
            }

            $lastHoistedOffset = $offset;
        }

        if ($lastHoistedOffset >= 0) {
            preg_match_all(self::LAYER_STATEMENT_PATTERN, $body, $layers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($layers as $layer) {
                [$statement, $offset] = $layer['statement'];

                if ($offset < $lastHoistedOffset && !isset($removals[$offset])) {
                    $hoisted[$offset] = $statement;
                    $removals[$offset] = strlen($statement);
                }
            }
        }

        krsort($removals);

        foreach ($removals as $offset => $length) {
            $body = substr_replace($body, '', $offset, $length);
        }

        $body = ltrim($body);
        $body = str_replace("\xEF\xBB\xBF", '', $body);

        if ($charset === '' && $hoisted === [] && $externalImports === []) {
            return [
                'css' => $cssContent,
                'externalImports' => [],
            ];
        }

        ksort($hoisted);
        $prefix = $charset !== '' ? $charset . "\n" : '';

        if ($hoisted !== []) {
            $prefix .= implode("\n", $hoisted) . "\n";
        }

        return [
            'css' => $prefix . $body,
            'externalImports' => $externalImports,
        ];
    }

    /**
     * Every `@import` rule, by offset in page order; overlapping matches are dropped
     *
     * @return array<int, array{length: int, rule: string, url: string, qualifier: string}>
     */
    private static function importsIn(string $css): array
    {
        $imports = [];

        foreach ([self::IMPORT_QUOTED_PATTERN, self::IMPORT_UNQUOTED_PATTERN] as $pattern) {
            preg_match_all($pattern, $css, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $lead = strlen($match['lead'][0]);
                $imports[$match[0][1] + $lead] = [
                    'length' => strlen($match[0][0]) - $lead,
                    'rule' => trim($match[0][0]),
                    'url' => trim($match['url'][0]),
                    'qualifier' => trim($match['qualifier'][0] ?? ''),
                ];
            }
        }

        ksort($imports);
        $end = -1;

        foreach ($imports as $offset => $import) {
            if ($offset < $end) {
                unset($imports[$offset]);

                continue;
            }

            $end = $offset + $import['length'];
        }

        return $imports;
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
