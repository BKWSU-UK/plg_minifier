<?php

use PHPUnit\Framework\TestCase;

class MinifierBundleCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/minifier-bundle-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
    }

    private function write(string $webPath, string $content): void
    {
        if (!is_dir(dirname($this->root . $webPath))) {
            mkdir(dirname($this->root . $webPath), 0755, true);
        }

        file_put_contents($this->root . $webPath, $content);
    }

    /**
     * Bundle input for files already written under the site root
     *
     * @return list<MinifierBundleFile>
     */
    private function files(string ...$webPaths): array
    {
        return array_map(fn(string $webPath): MinifierBundleFile => new MinifierBundleFile($this->root . $webPath, $webPath), $webPaths);
    }

    private function cache(string $baseUrl = ''): MinifierBundleCache
    {
        return new MinifierBundleCache($this->root, $baseUrl);
    }

    /** The bundle file a bundle URL points at, given the site's base URL */
    private function contentAt(string $url, string $baseUrl = ''): string
    {
        return file_get_contents($this->root . substr($url, strlen($baseUrl)));
    }

    public function testMinifiableStylesheetsAreMinifiedTogetherInOrderIntoTheCssCache(): void
    {
        $this->write('/css/a.css', 'a { color : red ; }');
        $this->write('/css/b.css', 'b { color : blue ; }');

        $bundle = $this->cache('/sub')->write($this->files('/css/a.css', '/css/b.css'), MinifierAssetType::Css);

        $this->assertMatchesRegularExpression('#^/sub/media/cache/css/combined-[0-9a-f]{8}\.css$#', $bundle->url);
        $this->assertSame(
            "/* Files: /css/a.css, /css/b.css */\na{color:red}b{color:blue}\n",
            $this->contentAt($bundle->url, '/sub')
        );
    }

    public function testMinifiableScriptsAreMinifiedTogetherInOrderIntoTheJsCache(): void
    {
        $this->write('/js/a.js', 'var a = 1 ;');
        $this->write('/js/b.js', 'var b = 2 ;');

        $bundle = $this->cache()->write($this->files('/js/a.js', '/js/b.js'), MinifierAssetType::Js);

        $this->assertMatchesRegularExpression('#^/media/cache/js/combined-[0-9a-f]{8}\.js$#', $bundle->url);
        $this->assertSame("/* Files: /js/a.js, /js/b.js */\nvar a=1;var b=2\n", $this->contentAt($bundle->url));
        $this->assertSame('<script src="' . $bundle->url . '"></script>', $bundle->html());
    }

    public function testPreMinifiedScriptsAreBundledAsIsInSeparatedSegmentsWithoutSourceMaps(): void
    {
        $this->write('/js/a.js', 'var a = 1 ;');
        $this->write('/js/lib.min.js', "var l=1\n//# sourceMappingURL=lib.min.js.map\n");

        $bundle = $this->cache()->write($this->files('/js/a.js', '/js/lib.min.js'), MinifierAssetType::Js);

        $this->assertSame(
            "/* File: /js/a.js */\n;var a=1\n/* File: /js/lib.min.js */\n;var l=1\n",
            $this->contentAt($bundle->url)
        );
    }

    public function testAUtf8BomIsStrippedFromPreMinifiedScripts(): void
    {
        $this->write('/js/a.js', 'var a = 1 ;');
        $this->write('/js/lib.min.js', "\xEF\xBB\xBFvar l=1;");

        $bundle = $this->cache()->write($this->files('/js/a.js', '/js/lib.min.js'), MinifierAssetType::Js);

        $this->assertStringNotContainsString("\xEF\xBB\xBF", $this->contentAt($bundle->url));
    }

    public function testRelocatedUrlsInAPreMinifiedStylesheetStillResolveFromTheCacheLocation(): void
    {
        $this->write('/templates/site/img/bg.png', 'png');
        $this->write('/templates/site/css/template.min.css', "\xEF\xBB\xBF.hero{background:url(../img/bg.png)}.logo{background:url(/images/logo.png)}");

        $bundle = $this->cache('/sub')->write($this->files('/templates/site/css/template.min.css'), MinifierAssetType::Css);
        $content = $this->contentAt($bundle->url, '/sub');

        $this->assertStringNotContainsString("\xEF\xBB\xBF", $content);
        $this->assertSame(1, preg_match('/\.hero\{background:url\(([^)]+)\)\}/', $content, $match));
        $this->assertFileExists($this->root . '/media/cache/css/' . $match[1]);
        $this->assertStringContainsString('.logo{background:url(/sub/images/logo.png)}', $content);
    }

    public function testRelocatedUrlsInAMinifiedStylesheetStillResolveFromTheCacheLocation(): void
    {
        // Larger than the minifier's inline-as-data-URI limit, so the url() stays a path
        $this->write('/templates/site/img/bg.png', str_repeat('x', 100000));
        $this->write('/templates/site/css/template.css', '.hero { background: url(../img/bg.png); } .logo { background: url(/images/logo.png); }');

        $bundle = $this->cache('/sub')->write($this->files('/templates/site/css/template.css'), MinifierAssetType::Css);
        $content = $this->contentAt($bundle->url, '/sub');

        $this->assertSame(1, preg_match('/\.hero\{background:url\(([^)]+)\)\}/', $content, $match));
        $this->assertFileExists($this->root . '/media/cache/css/' . $match[1]);
        $this->assertStringContainsString('.logo{background:url(/sub/images/logo.png)}', $content);
    }

    public function testUtf8BomsAreStrippedFromMinifiableStylesheets(): void
    {
        $this->write('/css/a.css', "\xEF\xBB\xBFa { color: red; }");
        $this->write('/css/b.css', "\xEF\xBB\xBFb { color: blue; }");
        $cache = $this->cache();

        foreach ([$this->files('/css/a.css'), $this->files('/css/a.css', '/css/b.css')] as $files) {
            $this->assertStringNotContainsString(
                "\xEF\xBB\xBF",
                $this->contentAt($cache->write($files, MinifierAssetType::Css)->url)
            );
        }
    }

    public function testExternalImportsBecomeLinkTagsAheadOfTheBundle(): void
    {
        $this->write('/css/fonts.css', "@import url(\"https://fonts.example.com/css?family=A\");\nbody{font-family:A}");

        $bundle = $this->cache()->write($this->files('/css/fonts.css'), MinifierAssetType::Css);

        $this->assertSame([['url' => 'https://fonts.example.com/css?family=A', 'media' => '']], $bundle->externalImports);
        $this->assertStringNotContainsString('fonts.example.com', $this->contentAt($bundle->url));
        $this->assertSame(
            '<link href="https://fonts.example.com/css?family=A" rel="stylesheet"><link href="' . $bundle->url . '" rel="stylesheet">',
            $bundle->html()
        );
    }

    public function testTheBundleNameIsStableForTheSameInputAndChangesWithTheContentOrExternalImports(): void
    {
        $this->write('/css/a.css', 'a { color: red; }');
        $this->write('/css/fonts-a.css', '@import url("https://fonts.example.com/css?family=A");a{color:red}');
        $this->write('/css/fonts-b.css', '@import url("https://fonts.example.com/css?family=B");a{color:red}');
        $cache = $this->cache();
        $url = fn(string $webPath): string => $cache->write($this->files($webPath), MinifierAssetType::Css)->url;

        $first = $url('/css/a.css');
        $this->assertSame($first, $url('/css/a.css'));

        $this->write('/css/a.css', 'a { color: blue; }');
        $this->assertNotSame($first, $url('/css/a.css'));

        $this->assertNotSame($url('/css/fonts-a.css'), $url('/css/fonts-b.css'));
    }

    public function testNothingToBundleWritesNothing(): void
    {
        $this->assertNull($this->cache()->write([], MinifierAssetType::Js));
        $this->assertDirectoryDoesNotExist($this->root . '/media/cache');
    }

    /**
     * Writes one JS bundle per name in its own cache instance (a separate pass) and
     * stamps each with the given age in seconds
     *
     * @param array<string, int> $agesByName Script name => age of its bundle in seconds
     * @return list<string> Bundle file names, in the order given
     */
    private function writeBundles(MinifierBundleCache $cache, array $agesByName): array
    {
        $written = [];

        foreach ($agesByName as $name => $age) {
            $this->write("/js/$name.js", "var $name = 1 ;");
            $written[] = basename($cache->write($this->files("/js/$name.js"), MinifierAssetType::Js)->url);
            touch($this->root . '/media/cache/js/' . end($written), time() - $age);
        }

        return $written;
    }

    /** @return list<string> */
    private function keptJsBundles(): array
    {
        $kept = array_map('basename', glob($this->root . '/media/cache/js/combined-*.js'));
        sort($kept);

        return $kept;
    }

    private const DAY = 86400;

    public function testBundlesUsedWithinTheRetentionWindowSurvivePruningHoweverManyThereAre(): void
    {
        $recent = $this->writeBundles($this->cache(), [
            'r1' => 9 * 3600, 'r2' => 8 * 3600, 'r3' => 7 * 3600, 'r4' => 6 * 3600, 'r5' => 5 * 3600,
            'r6' => 4 * 3600, 'r7' => 3 * 3600, 'r8' => 2 * 3600, 'r9' => 3600,
        ]);

        $pass = $this->cache();
        $current = $this->writeBundles($pass, ['now' => 0]);
        $pass->prune(MinifierAssetType::Js);

        $expected = [...$recent, ...$current];
        sort($expected);
        $this->assertSame($expected, $this->keptJsBundles());
    }

    public function testBundlesUnusedForLongerThanTheRetentionWindowAreDeleted(): void
    {
        $this->writeBundles($this->cache(), ['stale1' => 8 * self::DAY, 'stale2' => 30 * self::DAY]);
        $fresh = $this->writeBundles($this->cache(), ['fresh' => 6 * self::DAY]);
        $this->write('/css/a.css', 'a { color: red; }');
        $css = basename($this->cache()->write($this->files('/css/a.css'), MinifierAssetType::Css)->url);
        touch($this->root . '/media/cache/css/' . $css, time() - 30 * self::DAY);

        $pass = $this->cache();
        $current = $this->writeBundles($pass, ['now' => 0]);
        $pass->prune(MinifierAssetType::Js);

        $expected = [...$fresh, ...$current];
        sort($expected);
        $this->assertSame($expected, $this->keptJsBundles());
        $this->assertFileExists($this->root . '/media/cache/css/' . $css);
    }

    public function testABundleReusedByALaterPageCountsAsRecentlyUsed(): void
    {
        [$original] = $this->writeBundles($this->cache(), ['shared' => 30 * self::DAY]);

        // Another page renders with the same bundle, then a third page's pass prunes
        $reused = basename($this->cache()->write($this->files('/js/shared.js'), MinifierAssetType::Js)->url);
        $this->assertSame($original, $reused);
        $pass = $this->cache();
        $this->writeBundles($pass, ['other' => 0]);
        $pass->prune(MinifierAssetType::Js);

        $this->assertContains($reused, $this->keptJsBundles());
    }

    public function testABundleWrittenInThisPassIsNeverDeleted(): void
    {
        $pass = $this->cache();
        $current = $this->writeBundles($pass, ['p1' => 30 * self::DAY, 'p2' => 0]);
        $pass->prune(MinifierAssetType::Js);

        sort($current);
        $this->assertSame($current, $this->keptJsBundles());
    }

    public static function lifetimeSettings(): array
    {
        return [
            'one day' => [1, self::DAY],
            'thirty days, as a string' => ['30', 30 * self::DAY],
            'not set' => [null, 7 * self::DAY],
            'zero' => [0, 7 * self::DAY],
            'negative' => [-3, 7 * self::DAY],
            'not a number' => ['soon', 7 * self::DAY],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lifetimeSettings')]
    public function testTheLifetimeSettingInDaysBecomesTheRetentionWindowAndInvalidValuesUseTheDefault(
        mixed $days,
        int $seconds
    ): void {
        $this->assertSame($seconds, MinifierBundleCache::retentionSecondsFromDays($days));
    }

    public function testTheRetentionWindowIsConfigurable(): void
    {
        $this->writeBundles($this->cache(), ['old' => 2 * self::DAY]);

        $pass = new MinifierBundleCache($this->root, '', retentionSeconds: self::DAY);
        $current = $this->writeBundles($pass, ['now' => 0]);
        $pass->prune(MinifierAssetType::Js);

        $this->assertSame($current, $this->keptJsBundles());
    }

    private const FONTS_CSS = "@charset \"utf-8\";\n@import url(\"https://fonts.example.com/css?family=A\");\nbody { font-family: A; }\n";

    public function testACharsetStaysFirstAndCompleteWhenAnExternalImportIsExtracted(): void
    {
        $this->write('/css/fonts.css', self::FONTS_CSS);

        $bundle = $this->cache()->write($this->files('/css/fonts.css'), MinifierAssetType::Css);
        $content = $this->contentAt($bundle->url);

        $this->assertStringStartsWith('@charset "utf-8";', $content);
        $this->assertStringContainsString('body{font-family:A}', $content);
        $this->assertStringNotContainsString('@import', $content);
        $this->assertSame([['url' => 'https://fonts.example.com/css?family=A', 'media' => '']], $bundle->externalImports);
    }

    public function testACharsetWithAnImportDoesNotSwallowRulesFromTheNextStylesheet(): void
    {
        $this->write('/css/fonts.css', self::FONTS_CSS);
        $this->write('/css/b.css', 'p { margin: 0; color: red; }');

        $bundle = $this->cache()->write($this->files('/css/fonts.css', '/css/b.css'), MinifierAssetType::Css);
        $content = $this->contentAt($bundle->url);

        $this->assertStringStartsWith('@charset "utf-8";', $content);
        $this->assertStringContainsString('body{font-family:A}', $content);
        $this->assertStringContainsString('p{margin:0;color:red}', $content);
        $this->assertSame(1, substr_count($content, '@charset'));
    }

    public function testACharsetStaysFirstAndCompleteWithALocalImport(): void
    {
        $this->write('/css/local.css', "@charset \"utf-8\";\n@import url(\"/css/base.css\");\nbody { color: red; }\n");

        $content = $this->contentAt($this->cache()->write($this->files('/css/local.css'), MinifierAssetType::Css)->url);

        $this->assertStringStartsWith('@charset "utf-8";' . "\n" . '@import', $content);
        $this->assertStringContainsString('body{color:red}', $content);
    }

    public static function charsetTextInStrings(): array
    {
        return [
            'in a content string' => ['p:before { content: "@charset \'x\'"; }', 'content:"@charset \'x\'"'],
            'in a data URL' => [
                "@charset \"utf-8\";\ndiv { background: url(\"data:text/css,@charset 'x';\"); }",
                "url(\"data:text/css,@charset 'x';\")",
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('charsetTextInStrings')]
    public function testCharsetTextInsideAStringIsLeftAlone(string $css, string $kept): void
    {
        $this->write('/css/s.css', $css);

        $content = $this->contentAt($this->cache()->write($this->files('/css/s.css'), MinifierAssetType::Css)->url);

        $this->assertStringContainsString($kept, $content);
        $this->assertLessThanOrEqual(1, substr_count($content, '@charset "'));
    }

    public function testOnlyALowerCaseDoubleQuotedCharsetIsTreatedAsACharset(): void
    {
        $this->write('/css/u.css', "@CHARSET 'utf-8';\nbody { color: red; }");

        $content = $this->contentAt($this->cache()->write($this->files('/css/u.css'), MinifierAssetType::Css)->url);

        $this->assertStringNotContainsString('@charset "', $content);
        $this->assertStringContainsString("@CHARSET 'utf-8'", $content);
    }

    public function testACharsetWithoutAnImportIsBundledAsBefore(): void
    {
        $this->write('/css/c.css', "@charset \"utf-8\";\nbody { color: red; }\n");
        $this->write('/css/d.css', "p { margin: 0; }\n");
        $cache = $this->cache();

        // Expected content and names recorded from the bundle cache before the charset repair existed
        $single = $cache->write($this->files('/css/c.css'), MinifierAssetType::Css);
        $this->assertSame('/media/cache/css/combined-b5c27197.css', $single->url);
        $this->assertSame("@charset \"utf-8\";\n/* File: /css/c.css */\nbody{color:red}\n", $this->contentAt($single->url));

        $multi = $cache->write($this->files('/css/c.css', '/css/d.css'), MinifierAssetType::Css);
        $this->assertSame('/media/cache/css/combined-7dfa2313.css', $multi->url);
    }

    public function testAnExternalImportWithAMediaQueryBecomesALinkForThatMedia(): void
    {
        $this->write('/css/media.css', "@import url(\"https://cdn.example.com/print.css\") print;\nbody { color: red; }\n");

        $bundle = $this->cache()->write($this->files('/css/media.css'), MinifierAssetType::Css);
        $content = $this->contentAt($bundle->url);

        $this->assertSame([['url' => 'https://cdn.example.com/print.css', 'media' => 'print']], $bundle->externalImports);
        $this->assertSame(
            '<link href="https://cdn.example.com/print.css" rel="stylesheet" media="print"><link href="' . $bundle->url . '" rel="stylesheet">',
            $bundle->html()
        );
        $this->assertStringNotContainsString('@import', $content);
        $this->assertStringNotContainsString('print;', $content);
        $this->assertStringContainsString('body{color:red}', $content);
    }

    public static function importsALinkCannotExpress(): array
    {
        return [
            'supports()' => [
                "@import url(\"https://cdn.example.com/grid.css\") supports(display: grid);\np { margin: 0; }\n",
                '@import url(https://cdn.example.com/grid.css) supports(display:grid);',
                'p{margin:0}',
            ],
            'layer()' => [
                "@import url(\"https://cdn.example.com/theme.css\") layer(theme);\np { margin: 0; }\n",
                '@import url(https://cdn.example.com/theme.css) layer(theme);',
                'p{margin:0}',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importsALinkCannotExpress')]
    public function testAnExternalImportALinkCannotExpressStaysAsAnImportAtTheTop(string $css, string $import, string $rule): void
    {
        $this->write('/css/q.css', $css);

        $bundle = $this->cache()->write($this->files('/css/q.css'), MinifierAssetType::Css);
        $content = $this->contentAt($bundle->url);

        $this->assertSame([], $bundle->externalImports);
        $this->assertStringStartsWith($import, $content);
        $this->assertStringContainsString($rule, $content);
    }

    public function testALayerStatementStaysAheadOfTheLayeredImportItOrders(): void
    {
        $this->write(
            '/css/layers.css',
            "@layer base, theme;\n@import url(\"https://cdn.example.com/theme.css\") layer(theme);\n@layer base { body { color: red; } }\n"
        );

        $content = $this->contentAt($this->cache()->write($this->files('/css/layers.css'), MinifierAssetType::Css)->url);

        $this->assertStringStartsWith("@layer base,theme;\n@import url(https://cdn.example.com/theme.css) layer(theme);", $content);
        $this->assertStringContainsString('@layer base{body{color:red}}', $content);
        $this->assertSame(1, substr_count($content, '@layer base,theme;'));
    }

    public function testALayerStatementBeforeAPlainImportKeepsItsSemicolon(): void
    {
        $this->write('/css/l.css', "@layer a, b;\n@import url(\"https://cdn.example.com/x.css\");\nbody { color: red; }\n");

        $content = $this->contentAt($this->cache()->write($this->files('/css/l.css'), MinifierAssetType::Css)->url);

        $this->assertStringContainsString('@layer a,b;', $content);
        $this->assertStringContainsString('body{color:red}', $content);
    }

    public function testALayerStatementInALaterStylesheetStaysAheadOfItsLayeredImport(): void
    {
        $this->write('/css/a.css', 'body { color: red; }');
        $this->write('/css/b.css', "@layer x;\n@import url(\"https://cdn.example.com/y.css\") layer(y);\np { margin: 0; }\n");

        $content = $this->contentAt($this->cache()->write($this->files('/css/a.css', '/css/b.css'), MinifierAssetType::Css)->url);

        $this->assertStringStartsWith("@layer x;\n@import url(https://cdn.example.com/y.css) layer(y);", $content);
        $this->assertStringContainsString('body{color:red}p{margin:0}', $content);
    }

    public function testImportTextInsideAStringIsLeftAlone(): void
    {
        $this->write('/css/s.css', 'p:before { content: "@import url(x.css) print;"; }');

        $bundle = $this->cache()->write($this->files('/css/s.css'), MinifierAssetType::Css);

        $content = $this->contentAt($bundle->url);

        // The text stays inside the string (the minifier still relocates the url() path in it)
        $this->assertMatchesRegularExpression('/content:"@import url\([^)]*x\.css\) print;"/', $content);
        $this->assertStringStartsWith('/* File: /css/s.css */', $content);
        $this->assertSame([], $bundle->externalImports);
    }

    public function testPlainImportsAreBundledAsBefore(): void
    {
        $this->write('/css/ext.css', "@import url(\"https://fonts.example.com/css?family=A\");\nbody { font-family: A; }\n");
        $this->write('/css/b.css', "p { margin: 0; }\n");
        $this->write('/css/local.css', "@import url(\"/css/base.css\");\nh1 { color: red; }\n");
        $this->write('/css/pre.min.css', "@import \"https://fonts.example.com/css?family=B\";.x{top:0}");
        $cache = $this->cache();
        $name = fn(string ...$webPaths): string => basename($cache->write($this->files(...$webPaths), MinifierAssetType::Css)->url);

        // Bundle names recorded from the bundle cache before the import-hoisting fix
        $this->assertSame('combined-671a2adf.css', $name('/css/ext.css'));
        $this->assertSame('combined-745dfca4.css', $name('/css/ext.css', '/css/b.css'));
        $this->assertSame('combined-ebb395a7.css', $name('/css/local.css'));
        $this->assertSame('combined-3b0930a5.css', $name('/css/pre.min.css', '/css/b.css'));
    }
}
