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

        $this->assertSame(['https://fonts.example.com/css?family=A'], $bundle->externalImports);
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

    public function testPruningKeepsTheFiveMostRecentlyWrittenBundlesOfThatType(): void
    {
        $cache = $this->cache();
        $written = [];

        for ($n = 1; $n <= 7; $n++) {
            $this->write("/js/f$n.js", "var f$n = $n ;");
            $written[] = basename($cache->write($this->files("/js/f$n.js"), MinifierAssetType::Js)->url);
            touch($this->root . '/media/cache/js/' . end($written), time() - 100 + $n);
        }

        $this->write('/css/a.css', 'a { color: red; }');
        $css = basename($cache->write($this->files('/css/a.css'), MinifierAssetType::Css)->url);

        $cache->prune(MinifierAssetType::Js);

        $kept = array_map('basename', glob($this->root . '/media/cache/js/combined-*.js'));
        sort($kept);
        $expected = array_slice($written, 2);
        sort($expected);
        $this->assertSame($expected, $kept);
        $this->assertFileExists($this->root . '/media/cache/css/' . $css);
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
        $this->assertSame(['https://fonts.example.com/css?family=A'], $bundle->externalImports);
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
}
