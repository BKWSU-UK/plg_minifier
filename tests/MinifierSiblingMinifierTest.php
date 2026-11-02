<?php

use PHPUnit\Framework\TestCase;

class MinifierSiblingMinifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/minifier-sibling-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/css', 0755, true);
        mkdir($this->root . '/js', 0755, true);
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
        file_put_contents($this->root . $webPath, $content);
    }

    public function testPreMinifiedCssIsSkippedAndNeverMinifiedAgain(): void
    {
        $this->write('/css/template.min.css', 'p{margin:0}');

        $result = (new MinifierSiblingMinifier($this->root))->minify('/css/template.min.css', MinifierAssetType::Css);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->root . '/css/template.min.min.css');
    }

    public static function assets(): array
    {
        return [
            'css' => [MinifierAssetType::Css, '/css/site.css', 'body { color : red ; }', '/css/site.min.css', 'body{color:red}'],
            'js' => [MinifierAssetType::Js, '/js/app.js', 'var answer = 42 ;', '/js/app.min.js', 'var answer=42'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assets')]
    public function testSourceWithoutASiblingIsMinifiedAndTheQueryStringKept(
        MinifierAssetType $type,
        string $source,
        string $content,
        string $expectedUrl,
        string $expectedContent
    ): void {
        $this->write($source, $content);

        $result = (new MinifierSiblingMinifier($this->root))->minify($source . '?v=3', $type);

        $this->assertSame($expectedUrl . '?v=3', $result);
        $this->assertSame($expectedContent, file_get_contents($this->root . $expectedUrl));
    }

    public function testARelativeUrlIsResolvedFromTheSiteRootAndMinified(): void
    {
        $this->write('/css/site.css', 'body { color : red ; }');

        $this->assertSame(
            'css/site.min.css',
            (new MinifierSiblingMinifier($this->root))->minify('css/site.css', MinifierAssetType::Css)
        );
        $this->assertFileExists($this->root . '/css/site.min.css');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assets')]
    public function testFreshSiblingIsReusedAndStaleSiblingIsMinifiedAgain(
        MinifierAssetType $type,
        string $source,
        string $content,
        string $expectedUrl,
        string $expectedContent
    ): void {
        $this->write($source, $content);
        $this->write($expectedUrl, 'CACHED');
        touch($this->root . $source, time() - 100);
        touch($this->root . $expectedUrl, time() - 50);
        $minifier = new MinifierSiblingMinifier($this->root);

        $this->assertSame($expectedUrl, $minifier->minify($source, $type));
        $this->assertSame('CACHED', file_get_contents($this->root . $expectedUrl));

        touch($this->root . $source, time() - 10);
        clearstatcache();

        $this->assertSame($expectedUrl, $minifier->minify($source, $type));
        $this->assertSame($expectedContent, file_get_contents($this->root . $expectedUrl));
    }

    public static function skippedUrls(): array
    {
        $cases = [];

        foreach (MinifierAssetType::cases() as $type) {
            $ext = $type->value;
            $cases += [
                "$ext external" => [$type, "https://cdn.example.com/$ext/app.$ext"],
                "$ext protocol-relative" => [$type, "//cdn.example.com/$ext/app.$ext"],
                "$ext upper-case scheme" => [$type, "HTTPS://cdn.example.com/$ext/app.$ext"],
                "$ext mixed-case scheme" => [$type, "HttP://cdn.example.com/$ext/app.$ext"],
                "$ext excluded" => [$type, "/$ext/vendor/app.$ext?v=1"],
                "$ext missing" => [$type, "/$ext/missing.$ext"],
                "$ext pre-minified" => [$type, "/$ext/app.min.$ext"],
                "$ext pre-minified with query" => [$type, "/$ext/app.min.$ext?ver=1"],
            ];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('skippedUrls')]
    public function testSkippedUrlsAreLeftAlone(MinifierAssetType $type, string $url): void
    {
        $ext = $type->value;
        mkdir($this->root . "/$ext/vendor");
        $this->write("/$ext/vendor/app.$ext", 'a { }');
        $this->write("/$ext/app.min.$ext", 'a{}');

        $minifier = new MinifierSiblingMinifier($this->root, excludePaths: ["/$ext/vendor/"]);

        $this->assertNull($minifier->minify($url, $type));
        $this->assertFileDoesNotExist($this->root . "/$ext/vendor/app.min.$ext");
        $this->assertFileDoesNotExist($this->root . "/$ext/app.min.min.$ext");
    }

    public function testPathTraversalOutsideTheRootIsSkippedAndReported(): void
    {
        $outside = dirname($this->root) . '/' . basename($this->root) . '-outside.css';
        file_put_contents($outside, 'body { color : red ; }');
        $messages = [];
        $minifier = new MinifierSiblingMinifier(
            $this->root,
            log: function (string $level, string $message) use (&$messages): void {
                $messages[] = [$level, $message];
            }
        );

        try {
            $result = $minifier->minify('/css/../../' . basename($outside), MinifierAssetType::Css);
        } finally {
            unlink($outside);
        }

        $this->assertNull($result);
        $this->assertContains('warning', array_column($messages, 0));
    }

    public static function externalUrls(): array
    {
        return [
            'https' => ['https://cdn.example.com/js/app.js'],
            'upper-case scheme' => ['HTTPS://cdn.example.com/js/app.js'],
            'mixed-case scheme' => ['HttP://cdn.example.com/js/app.js'],
            'protocol-relative' => ['//cdn.example.com/js/app.js'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('externalUrls')]
    public function testAnExternalUrlIsSkippedWithoutLookingForALocalFile(string $url): void
    {
        $messages = [];
        $minifier = new MinifierSiblingMinifier(
            $this->root,
            log: function (string $level, string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        $this->assertNull($minifier->minify($url, MinifierAssetType::Js));
        $this->assertSame([], $messages);
    }

    public function testAnUpperCaseMinExtensionCountsAsPreMinified(): void
    {
        $this->write('/js/LIB.MIN.JS', 'var l=1');

        $this->assertNull((new MinifierSiblingMinifier($this->root))->minify('/js/LIB.MIN.JS', MinifierAssetType::Js));
        $this->assertSame([], glob($this->root . '/js/*.min.js'));
    }

    public function testAMinifiedStylesheetKeepsItsCharsetFirstAndCompleteWhenItHasAnImport(): void
    {
        $this->write('/css/fonts.css', "@charset \"utf-8\";\n@import url(\"https://fonts.example.com/css?family=A\");\nbody { font-family: A; }\n");

        (new MinifierSiblingMinifier($this->root))->minify('/css/fonts.css', MinifierAssetType::Css);

        $this->assertSame(
            '@charset "utf-8";@import url(https://fonts.example.com/css?family=A);body{font-family:A}',
            file_get_contents($this->root . '/css/fonts.min.css')
        );
    }

    public function testInASubdirectoryInstallAUrlUnderTheBasePathIsMinified(): void
    {
        $this->write('/css/site.css', 'body { color : red ; }');

        $minifier = new MinifierSiblingMinifier($this->root, baseUrl: '/sub');

        $this->assertSame('/sub/css/site.min.css?v=3', $minifier->minify('/sub/css/site.css?v=3', MinifierAssetType::Css));
        $this->assertSame('body{color:red}', file_get_contents($this->root . '/css/site.min.css'));
    }

    public function testInASubdirectoryInstallAUrlWithoutTheBasePathStillResolvesFromTheSiteRoot(): void
    {
        $this->write('/css/site.css', 'body { color : red ; }');

        $minifier = new MinifierSiblingMinifier($this->root, baseUrl: '/sub');

        $this->assertSame('/css/site.min.css', $minifier->minify('/css/site.css', MinifierAssetType::Css));
    }

    public function testOnlyAWholeBasePathSegmentIsTreatedAsTheBasePath(): void
    {
        mkdir($this->root . '/way/css', 0755, true);
        $this->write('/way/css/site.css', 'body { color : red ; }');

        $minifier = new MinifierSiblingMinifier($this->root, baseUrl: '/sub');

        $this->assertNull($minifier->minify('/subway/css/site.css', MinifierAssetType::Css));
        $this->assertFileDoesNotExist($this->root . '/way/css/site.min.css');
    }

    public function testInASubdirectoryInstallTraversalOutsideTheRootIsStillRefusedAndReported(): void
    {
        $outside = dirname($this->root) . '/' . basename($this->root) . '-outside.css';
        file_put_contents($outside, 'body { color : red ; }');
        $messages = [];
        $minifier = new MinifierSiblingMinifier(
            $this->root,
            log: function (string $level, string $message) use (&$messages): void {
                $messages[] = [$level, $message];
            },
            baseUrl: '/sub'
        );

        try {
            // Only once the base path is stripped does this land outside the root (without
            // stripping it would be <root>/<name>-outside.css, inside the root but missing)
            $result = $minifier->minify('/sub/../' . basename($outside), MinifierAssetType::Css);
        } finally {
            unlink($outside);
        }

        $this->assertNull($result);
        $this->assertContains('warning', array_column($messages, 0));
    }

    public function testInASubdirectoryInstallARelativeUrlStillResolvesFromTheSiteRoot(): void
    {
        $this->write('/css/site.css', 'body { color : red ; }');

        $minifier = new MinifierSiblingMinifier($this->root, baseUrl: '/sub');

        $this->assertSame('css/site.min.css', $minifier->minify('css/site.css', MinifierAssetType::Css));
    }

    public function testABaseUrlWithATrailingSlashIsTheSameBasePath(): void
    {
        $this->write('/css/site.css', 'body { color : red ; }');

        $minifier = new MinifierSiblingMinifier($this->root, baseUrl: '/sub/');

        $this->assertSame('/sub/css/site.min.css', $minifier->minify('/sub/css/site.css', MinifierAssetType::Css));
    }

    public static function qualifiedImports(): array
    {
        return [
            'layer statement then layered import' => [
                "@layer base, theme;\n@import url(\"https://cdn.example.com/theme.css\") layer(theme);\n@layer base { body { color: red; } }\n",
                '@layer base,theme;@import url(https://cdn.example.com/theme.css) layer(theme);@layer base{body{color:red}}',
            ],
            'media query' => [
                "@import url(\"https://cdn.example.com/print.css\") print;\nbody { color: red; }\n",
                '@import url(https://cdn.example.com/print.css) print;body{color:red}',
            ],
            'supports()' => [
                "@import url(\"https://cdn.example.com/grid.css\") supports(display: grid);\np { margin: 0; }\n",
                '@import url(https://cdn.example.com/grid.css) supports(display:grid);p{margin:0}',
            ],
            'media query with a feature' => [
                "@import url(\"https://cdn.example.com/wide.css\") screen and (min-width: 900px);\np { margin: 0; }\n",
                '@import url(https://cdn.example.com/wide.css) screen and (min-width:900px);p{margin:0}',
            ],
            'layer statements before and after an import keep their order' => [
                "@layer a;\n@import url(\"https://cdn.example.com/b.css\") layer(b);\n@layer c;\nbody { color: red; }\n",
                '@layer a;@import url(https://cdn.example.com/b.css) layer(b);@layer c;body{color:red}',
            ],
            'two imports, the second qualified' => [
                "@import url(\"https://cdn.example.com/a.css\");\n@import url(\"https://cdn.example.com/b.css\") print;\np { margin: 0; }\n",
                '@import url(https://cdn.example.com/a.css);@import url(https://cdn.example.com/b.css) print;p{margin:0}',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('qualifiedImports')]
    public function testAMinifiedStylesheetKeepsItsImportsWholeAndTheRulesAroundThem(string $css, string $expected): void
    {
        $this->write('/css/q.css', $css);

        (new MinifierSiblingMinifier($this->root))->minify('/css/q.css', MinifierAssetType::Css);

        $this->assertSame($expected, file_get_contents($this->root . '/css/q.min.css'));
    }

    public static function localImports(): array
    {
        return [
            'of a file that has its own import' => [
                ['/css/_inc.css' => "@layer z;\n@import url(\"https://cdn.example.com/deep.css\") print;\nk { color: blue; }\n"],
                "@import url(\"_inc.css\");\nbody { color: red; }\n",
                '@import url(_inc.css);body{color:red}',
            ],
            'with layer()' => [
                ['/css/_l.css' => 'p { margin: 0; }'],
                "@import url(\"_l.css\") layer(theme);\nbody { color: red; }\n",
                '@import url(_l.css) layer(theme);body{color:red}',
            ],
            'with supports()' => [
                ['/css/_s.css' => 'p { margin: 0; }'],
                "@import url(\"_s.css\") supports(display: grid);\nbody { color: red; }\n",
                '@import url(_s.css) supports(display:grid);body{color:red}',
            ],
            'with a media query, still inlined' => [
                ['/css/_m.css' => 'p { margin: 0; }'],
                "@import url(\"_m.css\") print;\nbody { color: red; }\n",
                '@media print{p{margin:0}}body{color:red}',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localImports')]
    public function testALocalImportIsOnlyInlinedWhenItsContentCanBeInlinedCorrectly(array $files, string $css, string $expected): void
    {
        foreach ($files as $webPath => $content) {
            $this->write($webPath, $content);
        }
        $this->write('/css/a.css', $css);

        (new MinifierSiblingMinifier($this->root))->minify('/css/a.css', MinifierAssetType::Css);

        $this->assertSame($expected, file_get_contents($this->root . '/css/a.min.css'));
    }
}
