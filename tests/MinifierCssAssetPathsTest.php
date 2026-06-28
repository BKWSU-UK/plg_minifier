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
}
