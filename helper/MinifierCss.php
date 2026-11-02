<?php

use MatthiasMullie\Minify;

/**
 * The minify library's CSS minifier with correct `@import` handling.
 *
 * The library moves every `@import` to the very top of the output, but its
 * pattern also takes the `;` just before each import (ending the `@charset` or
 * `@layer` statement before it, so that statement swallows the next rule) and
 * only moves `@import url(…)`, leaving any media query, `supports(…)` or
 * `layer(…)` behind as stray text. Here each import moves whole, the rule before
 * it is left intact, `@charset` stays first, and `@layer` order statements that
 * come before an import move up with it in their original order.
 *
 * The library also inlines local imports, wrapping the content in
 * `@media <qualifier>` (wrong for `supports(…)` / `layer(…)`) and minifying the
 * imported file with its own class (so imports inside it get mangled). Those
 * imports are kept as `@import` instead of being inlined.
 *
 * Must be loaded after the vendor autoloader, since it extends a library class. If a
 * library upgrade stops calling these methods, the qualified-import tests of the
 * sibling minifier and the bundle cache fail.
 */
final class MinifierCss extends Minify\CSS
{
    /** An `@import` rule at a rule boundary: its URL and any qualifiers, up to its terminating `;` */
    private const IMPORT_PATTERN = '/(?:^|(?<=[;{}])|(?<=\*\/))\s*@import\s*'
        . '(?:url\(\s*(?:"[^"]*"|\'[^\']*\'|[^)]*)\s*\)|"[^"]*"|\'[^\']*\')'
        . '(?:[^;"\'{}]|"[^"]*"|\'[^\']*\')*(?:;|$)/i';

    /** The URL and qualifier of one matched `@import` rule */
    private const IMPORT_PARTS_PATTERN = '/^\s*@import\s*(?:url\(\s*(["\']?)(.*?)\1\s*\)|(["\'])(.*?)\3)(.*?);?\s*$/is';

    /** A layer order statement such as `@layer base, theme;` (not a layer block) at a rule boundary */
    private const LAYER_STATEMENT_PATTERN = '/(?:^|(?<=[;{}])|(?<=\*\/))\s*@layer\s+[^;{}]+;/i';

    /** A `@charset` rule at the very start */
    private const LEADING_CHARSET_PATTERN = '/^\s*@charset\s*"[^"]*"\s*;/i';

    /**
     * @param string $content Minified CSS of every added source, strings restored
     */
    protected function moveImportsToTop($content): string
    {
        $charset = '';

        if (preg_match(self::LEADING_CHARSET_PATTERN, $content, $match) === 1) {
            $charset = trim($match[0]);
            $content = substr($content, strlen($match[0]));
        }

        if (preg_match_all(self::IMPORT_PATTERN, $content, $imports, PREG_OFFSET_CAPTURE) === 0) {
            return $charset . $content;
        }

        $hoisted = [];

        foreach ($imports[0] as [$rule, $offset]) {
            $hoisted[$offset] = $rule;
        }

        $lastImportOffset = max(array_keys($hoisted));
        preg_match_all(self::LAYER_STATEMENT_PATTERN, $content, $layers, PREG_OFFSET_CAPTURE);

        foreach ($layers[0] as [$statement, $offset]) {
            if ($offset < $lastImportOffset) {
                $hoisted[$offset] = $statement;
            }
        }

        krsort($hoisted);

        foreach ($hoisted as $offset => $rule) {
            $content = substr_replace($content, '', $offset, strlen($rule));
        }

        ksort($hoisted);
        $top = '';

        foreach ($hoisted as $rule) {
            $top .= rtrim(trim($rule), ';') . ';';
        }

        return $charset . $top . $content;
    }

    /**
     * Inlines local imports as the library does, except those it would get wrong
     *
     * @param string $source Path of the file being minified ('' for raw CSS)
     * @param string $content Its minified CSS
     * @param array $parents Files already being imported in this chain
     */
    protected function combineImports($source, $content, $parents): string
    {
        $kept = [];
        $content = preg_replace_callback(
            self::IMPORT_PATTERN,
            function (array $match) use ($source, &$kept): string {
                if (!$this->mustStayAnImport((string) $source, $match[0])) {
                    return $match[0];
                }

                $placeholder = "\x1Aminifier-kept-import-" . count($kept) . "\x1A";
                $kept[$placeholder] = $match[0];

                return $placeholder;
            },
            $content
        );

        return strtr(parent::combineImports($source, $content, $parents), $kept);
    }

    /**
     * An import with `supports(…)` or `layer(…)` (which inlining would turn into an
     * invalid `@media` block), or a local import of a file that has imports of its own
     */
    private function mustStayAnImport(string $source, string $rule): bool
    {
        if (preg_match(self::IMPORT_PARTS_PATTERN, $rule, $parts) !== 1) {
            return false;
        }

        $url = $parts[2] !== '' ? $parts[2] : ($parts[4] ?? '');
        $qualifier = $parts[5] ?? '';

        if (preg_match('/\b(?:supports|layer)\b/i', $qualifier) === 1) {
            return true;
        }

        $path = dirname($source) . '/' . $url;

        return $url !== ''
            && $this->canImportByPath($url)
            && $this->canImportFile($path)
            && stripos((string) file_get_contents($path), '@import') !== false;
    }
}
