<?php

use PHPUnit\Framework\TestCase;

class MinifierAssetTest extends TestCase
{
    public function testIsExternalUrlDetectsProtocolRelativeUrls(): void
    {
        $this->assertTrue(MinifierAsset::isExternalUrl('//cdn.example.com/app.js'));
    }

    public function testIsExternalUrlDetectsHttpAndHttpsCaseInsensitively(): void
    {
        $this->assertTrue(MinifierAsset::isExternalUrl('http://cdn.example.com/app.js'));
        $this->assertTrue(MinifierAsset::isExternalUrl('HTTPS://cdn.example.com/app.js'));
        $this->assertTrue(MinifierAsset::isExternalUrl('HttpS://cdn.example.com/app.js'));
    }

    public function testIsExternalUrlRejectsLocalPaths(): void
    {
        $this->assertFalse(MinifierAsset::isExternalUrl('/media/system/css/system.css'));
        $this->assertFalse(MinifierAsset::isExternalUrl('media/system/css/system.css'));
    }

    public function testIsPathWithinRootRejectsPrefixAttack(): void
    {
        $this->assertFalse(MinifierAsset::isPathWithinRoot('/var/www_evil/file.css', '/var/www'));
    }

    public function testIsPathWithinRootAcceptsNestedPaths(): void
    {
        $this->assertTrue(MinifierAsset::isPathWithinRoot('/var/www/media/template.css', '/var/www'));
        $this->assertTrue(MinifierAsset::isPathWithinRoot('/var/www', '/var/www'));
    }

    public function testNormalisePathCollapsesDotSegments(): void
    {
        $this->assertSame(
            '/var/www/media/template.css',
            MinifierAsset::normalisePath('/var/www/media/./template.css')
        );
    }

    public function testIsPathWithinRootRejectsTraversalSegments(): void
    {
        $this->assertFalse(
            MinifierAsset::isPathWithinRoot(
                '/var/www/html/templates/css/../../../../etc/passwd',
                '/var/www/html'
            )
        );
    }

    public function testIsPreMinifiedCssUsesExtensionOnly(): void
    {
        $this->assertTrue(MinifierAsset::isPreMinifiedCss('/media/template.min.css'));
        $this->assertTrue(MinifierAsset::isPreMinifiedCss('/media/template.min.css?ver=1'));
        $this->assertFalse(MinifierAsset::isPreMinifiedCss('/media/backup.min.css.bak'));
        $this->assertFalse(MinifierAsset::isPreMinifiedCss('/media/template.css'));
    }

    public function testIsPreMinifiedJsUsesExtensionOnly(): void
    {
        $this->assertTrue(MinifierAsset::isPreMinifiedJs('/media/jquery.min.js'));
        $this->assertFalse(MinifierAsset::isPreMinifiedJs('/media/backup.min.js.bak'));
        $this->assertFalse(MinifierAsset::isPreMinifiedJs('/media/app.js'));
    }

    public function testIsJqueryDependencyDetectsCoreAndNoConflictFiles(): void
    {
        $this->assertTrue(MinifierAsset::isJqueryCore('/media/vendor/jquery/js/jquery.min.js'));
        $this->assertTrue(MinifierAsset::isJqueryNoConflict('/media/legacy/js/jquery-noconflict.min.js'));
        $this->assertTrue(MinifierAsset::isJqueryDependency('/media/vendor/jquery/js/jquery.min.js'));
        $this->assertFalse(MinifierAsset::isJqueryDependency('/templates/ism/js/template.min.js'));
    }

    public function testPrepareJsCombineSegmentPrefixesSemicolonAndStripsSourceMap(): void
    {
        $segment = MinifierAsset::prepareJsCombineSegment("$(function(){})//# sourceMappingURL=bootstrap.min.js.map\n");

        $this->assertSame(";$(function(){})", trim($segment));
        $this->assertStringNotContainsString('sourceMappingURL', $segment);
    }

    public function testGapContainsInlineScriptDetectsInlineScriptTags(): void
    {
        $gap = '</script><script>var gdprConfigurationOptions = {};</script><script src="/media/a.js">';

        $this->assertTrue(MinifierAsset::gapContainsInlineScript($gap));
        $this->assertFalse(MinifierAsset::gapContainsInlineScript('</script><script src="/media/a.js"></script>'));
        $this->assertFalse(MinifierAsset::gapContainsInlineScript(''));
    }

    public function testSortJsCombineEntriesPlacesJqueryBeforeDependents(): void
    {
        $entries = [
            ['path' => '/var/www/templates/ism/js/template.min.js', 'cleanJsFile' => '/templates/ism/js/template.min.js'],
            ['path' => '/var/www/media/vendor/jquery/js/jquery.min.js', 'cleanJsFile' => '/media/vendor/jquery/js/jquery.min.js'],
            ['path' => '/var/www/templates/ism/js/bootstrap.bundle.min.js', 'cleanJsFile' => '/templates/ism/js/bootstrap.bundle.min.js'],
            ['path' => '/var/www/media/legacy/js/jquery-noconflict.min.js', 'cleanJsFile' => '/media/legacy/js/jquery-noconflict.min.js'],
        ];

        $sorted = MinifierAsset::sortJsCombineEntries($entries);
        $order = array_column($sorted, 'cleanJsFile');

        $this->assertSame('/media/vendor/jquery/js/jquery.min.js', $order[0]);
        $this->assertSame('/media/legacy/js/jquery-noconflict.min.js', $order[1]);
        $this->assertGreaterThan(
            array_search('/media/legacy/js/jquery-noconflict.min.js', $order, true),
            array_search('/templates/ism/js/template.min.js', $order, true)
        );
    }
}
