<?php

use MatthiasMullie\PathConverter\Converter;
use PHPUnit\Framework\TestCase;

class MinifierCssAssetPathsTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = JPATH_ROOT;
    }

    public function testReplaceRelativePathsPreservesRootRelativeUrls(): void
    {
        $converter = new Converter(
            $this->rootPath . '/templates/cassiopeia/css/template.css',
            $this->rootPath . '/media/cache/css/combined.css',
            $this->rootPath
        );

        $css = '.icon { background: url(/media/vendor/bootstrap/fonts/icons.woff2); }';
        $result = MinifierCssAssetPaths::replaceRelativePaths($css, $converter);

        $this->assertSame($css, $result);
    }

    public function testReplaceRelativePathsConvertsParentDirectoryUrls(): void
    {
        $converter = new Converter(
            $this->rootPath . '/templates/cassiopeia/css/template.css',
            $this->rootPath . '/media/cache/css/combined.css',
            $this->rootPath
        );

        $css = '@font-face { src: url(../fonts/icon.woff2); }';
        $result = MinifierCssAssetPaths::replaceRelativePaths($css, $converter);

        $this->assertSame(
            '@font-face { src: url(../../../templates/cassiopeia/fonts/icon.woff2); }',
            $result
        );
    }

    public function testReplaceRelativePathsReplacesEachMatchIndividually(): void
    {
        $converter = new Converter(
            $this->rootPath . '/templates/cassiopeia/css/a.css',
            $this->rootPath . '/media/cache/css/combined.css',
            $this->rootPath
        );

        $css = '.a { background: url(../images/a.png); } .b { background: url(../images/a.png); }';
        $result = MinifierCssAssetPaths::replaceRelativePaths($css, $converter);

        $this->assertSame(
            2,
            substr_count($result, 'url(../../../templates/cassiopeia/images/a.png)')
        );
        $this->assertStringNotContainsString('url(../images/a.png)', $result);
    }

    public function testPrefixSubdirectoryPathsAddsBasePath(): void
    {
        $css = '.icon { background: url(/media/vendor/icon.woff2); }';
        $result = MinifierCssAssetPaths::prefixSubdirectoryPaths($css, '/joomla');

        $this->assertSame('.icon { background: url(/joomla/media/vendor/icon.woff2); }', $result);
    }

    public function testPrefixSubdirectoryPathsSkipsWhenBaseIsEmpty(): void
    {
        $css = '.icon { background: url(/media/vendor/icon.woff2); }';
        $result = MinifierCssAssetPaths::prefixSubdirectoryPaths($css, '');

        $this->assertSame($css, $result);
    }

    public function testPrefixSubdirectoryPathsHandlesImportRules(): void
    {
        $css = '@import "/media/system/css/system.css";';
        $result = MinifierCssAssetPaths::prefixSubdirectoryPaths($css, '/joomla');

        $this->assertSame('@import "/joomla/media/system/css/system.css";', $result);
    }

    public function testRelocatePathsConvertsRelativeUrlsForCombinedOutput(): void
    {
        $css = '.icon { background: url(../fonts/icon.woff2); }';
        $result = MinifierCssAssetPaths::relocatePaths(
            $css,
            $this->rootPath . '/templates/cassiopeia/css/template.css',
            $this->rootPath . '/media/cache/css/combined.css',
            $this->rootPath,
            '/joomla'
        );

        $this->assertSame(
            '.icon { background: url(../../../templates/cassiopeia/fonts/icon.woff2); }',
            $result
        );
    }

    public function testRelocatePathsPrefixesRootRelativeUrlsForSubdirectoryInstalls(): void
    {
        $css = '.icon { background: url(/media/vendor/icon.woff2); }';
        $result = MinifierCssAssetPaths::relocatePaths(
            $css,
            $this->rootPath . '/templates/cassiopeia/css/template.css',
            $this->rootPath . '/media/cache/css/combined.css',
            $this->rootPath,
            '/joomla'
        );

        $this->assertSame(
            '.icon { background: url(/joomla/media/vendor/icon.woff2); }',
            $result
        );
    }

    public function testHoistAtRulesMovesImportRulesToTop(): void
    {
        $css = "body { color: red; }\n"
            . '@import "/media/fonts/local.css";'
            . ':root { --font: Fraunces; }';

        $result = MinifierCssAssetPaths::hoistAtRules($css);

        $this->assertStringStartsWith('@import "/media/fonts/local.css";', $result);
        $this->assertStringContainsString("body { color: red; }", $result);
        $this->assertStringContainsString(':root { --font: Fraunces; }', $result);
    }

    public function testPrepareCombinedCssExtractsExternalImports(): void
    {
        $css = 'body { color: red; } '
            . '@import"https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&display=swap";'
            . ':root { --font: Fraunces; }';

        $result = MinifierCssAssetPaths::prepareCombinedCss($css);

        $this->assertSame(
            [
                'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,400..500&display=swap',
            ],
            $result['externalImports']
        );
        $this->assertStringNotContainsString('@import', $result['css']);
        $this->assertStringContainsString('body { color: red; }', $result['css']);
        $this->assertStringContainsString(':root { --font: Fraunces; }', $result['css']);
    }

    public function testPrepareCombinedCssStripsDuplicateCharsetRules(): void
    {
        $css = '@charset "UTF-8"; body { color: red; } @charset "UTF-8"; .alert { color: blue; }';

        $result = MinifierCssAssetPaths::prepareCombinedCss($css);

        $this->assertStringStartsWith('@charset "UTF-8";' . "\n", $result['css']);
        $this->assertSame(1, substr_count($result['css'], '@charset'));
    }

    public function testPrepareCombinedCssRemovesMidFileBomAfterImportExtraction(): void
    {
        $css = "/* bootstrap */\n:root{}\n/* template */\n\xEF\xBB\xBF"
            . '@import"https://fonts.googleapis.com/css2?family=Test&display=swap";'
            . ':root{--x:1}';

        $result = MinifierCssAssetPaths::prepareCombinedCss($css);

        $this->assertSame(['https://fonts.googleapis.com/css2?family=Test&display=swap'], $result['externalImports']);
        $this->assertSame(0, substr_count($result['css'], "\xEF\xBB\xBF"));
        $this->assertStringContainsString(':root{--x:1}', $result['css']);
    }

    public function testStripUtf8BomRemovesLeadingByteOrderMark(): void
    {
        $this->assertSame('@import"x";', MinifierCssAssetPaths::stripUtf8Bom("\xEF\xBB\xBF@import\"x\";"));
    }

    public function testHoistAtRulesPlacesCharsetBeforeImports(): void
    {
        $css = '.rule { color: blue; } @charset "utf-8"; @import "/fonts.css";';

        $result = MinifierCssAssetPaths::hoistAtRules($css);

        $this->assertStringStartsWith('@charset "utf-8";' . "\n" . '@import "/fonts.css";' . "\n", $result);
        $this->assertStringContainsString('.rule { color: blue; }', $result);
        $this->assertStringNotContainsString('@charset "utf-8"; @import', $result);
    }

    public function testHoistAtRulesDeduplicatesIdenticalImports(): void
    {
        $css = '@import "/fonts.css"; body { color: red; } @import "/fonts.css";';

        $result = MinifierCssAssetPaths::hoistAtRules($css);

        $this->assertSame(1, substr_count($result, '@import "/fonts.css";'));
    }

    public function testHoistAtRulesHandlesSemicolonsInsideQuotedImportUrls(): void
    {
        $css = 'body { color: red; } '
            . '@import "/media/fonts/local.css";'
            . ':root { --font: Fraunces; }';

        $result = MinifierCssAssetPaths::hoistAtRules($css);

        $this->assertStringStartsWith('@import "/media/fonts/local.css";', $result);
        $this->assertStringContainsString(':root { --font: Fraunces; }', $result);
    }
}
