<?php

use PHPUnit\Framework\TestCase;

class MinifierAssetPlanTest extends TestCase
{
    private const JQUERY = '<script src="/media/vendor/jquery/js/jquery.min.js"></script>';
    private const NOCONFLICT = '<script src="/media/legacy/js/jquery-noconflict.min.js"></script>';
    private const INLINE = '<script>init();</script>';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/minifier-plan-' . bin2hex(random_bytes(4));

        $this->write('/js/a.js', 'var a = 1;');
        $this->write('/js/b.js', 'var b = 2;');
        $this->write('/js/c.js', 'var c = 3;');
        $this->write('/js/template.js', 'jQuery(function () { window.ready = true; });');
        $this->write('/js/pre.min.js', 'var p=1;');
        $this->write('/js/vendor/lib.js', 'var lib = 1;');
        $this->write('/media/vendor/jquery/js/jquery.min.js', 'window.jQuery=function(){};');
        $this->write('/media/legacy/js/jquery-noconflict.min.js', 'jQuery.noConflict();');
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

    private static function script(string $name): string
    {
        return '<script src="/js/' . $name . '"></script>';
    }

    private static function bundle(int $number): string
    {
        return '<script src="/bundle-' . $number . '.js"></script>';
    }

    /**
     * Plans the page, places a numbered placeholder bundle for each block,
     * and returns the rewritten page plus each block's bundle file URLs.
     *
     * @return array{0: string, 1: list<list<string>>}
     */
    private function combine(string $html, bool $combineAll = false, array $excludePaths = []): array
    {
        $tags = MinifierHtmlReplacements::scripts($html);
        $blocks = MinifierAssetPlan::scripts($this->root, $excludePaths, $combineAll)->blocks($tags);
        $bundleFiles = [];

        foreach ($blocks as $number => $block) {
            $block->placeBundle($tags, self::bundle($number));
            $bundleFiles[] = array_column($block->files, 'webPath');
        }

        return [$tags->apply(), $bundleFiles];
    }

    public function testContiguousScriptsBecomeOneBundleInPlaceOfTheLastTag(): void
    {
        [$html, $files] = $this->combine(self::script('a.js') . "\n" . self::script('b.js?v=2'));

        $this->assertSame("\n" . self::bundle(0), $html);
        $this->assertSame([['/js/a.js', '/js/b.js']], $files);
    }

    public static function blockBreakers(): array
    {
        return [
            'excluded' => [self::script('vendor/lib.js')],
            'external' => ['<script src="https://cdn.example.com/x.js"></script>'],
            'protocol-relative' => ['<script src="//cdn.example.com/x.js"></script>'],
            'missing' => [self::script('missing.js')],
            'outside the site root' => ['<script src="/js/../../outside.js"></script>'],
            'pre-minified' => [self::script('pre.min.js')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockBreakers')]
    public function testATagThatCannotBeCombinedSplitsTheBlockAndStaysInPlace(string $breaker): void
    {
        [$html, $files] = $this->combine(
            self::script('a.js') . $breaker . self::script('b.js'),
            excludePaths: ['/js/vendor/']
        );

        $this->assertSame(self::bundle(0) . $breaker . self::bundle(1), $html);
        $this->assertSame([['/js/a.js'], ['/js/b.js']], $files);
    }

    public function testCombineAllKeepsPreMinifiedScriptsInTheBlock(): void
    {
        [$html, $files] = $this->combine(
            self::script('a.js') . self::script('pre.min.js') . self::script('b.js'),
            combineAll: true
        );

        $this->assertSame(self::bundle(0), $html);
        $this->assertSame([['/js/a.js', '/js/pre.min.js', '/js/b.js']], $files);
    }

    public function testAnInlineScriptBetweenTagsSplitsTheBlock(): void
    {
        [$html, $files] = $this->combine(self::script('a.js') . self::INLINE . self::script('b.js'));

        $this->assertSame(self::bundle(0) . self::INLINE . self::bundle(1), $html);
        $this->assertSame([['/js/a.js'], ['/js/b.js']], $files);
    }

    public function testADuplicateTagInTheBlockIsRemovedButItsFileBundledOnce(): void
    {
        [$html, $files] = $this->combine(self::script('a.js') . self::script('a.js?v=2') . self::script('b.js'));

        $this->assertSame(self::bundle(0), $html);
        $this->assertSame([['/js/a.js', '/js/b.js']], $files);
    }

    public function testABundleAfterSeparatelyLoadedJqueryStaysInPlace(): void
    {
        [$html, $files] = $this->combine(self::JQUERY . self::NOCONFLICT . self::script('template.js'));

        $this->assertSame(self::JQUERY . self::NOCONFLICT . self::bundle(0), $html);
        $this->assertSame([['/js/template.js']], $files);
    }

    public function testABundleThatUsesJqueryMovesAfterAJqueryCoreLoadedLaterInThePage(): void
    {
        [$html, $files] = $this->combine(self::script('template.js') . self::script('a.js') . self::JQUERY . self::NOCONFLICT);

        $this->assertSame(self::JQUERY . self::bundle(0) . self::NOCONFLICT, $html);
        $this->assertSame([['/js/template.js', '/js/a.js']], $files);
    }

    public function testABundleThatUsesJqueryMovesPastTheScriptsCombinedRightAfterTheLaterJqueryCore(): void
    {
        [$html, $files] = $this->combine(
            self::script('template.js') . self::JQUERY . self::script('b.js') . self::script('c.js') . self::NOCONFLICT
        );

        $this->assertSame(self::JQUERY . self::bundle(0) . self::NOCONFLICT . self::bundle(1), $html);
        $this->assertSame([['/js/template.js'], ['/js/b.js', '/js/c.js']], $files);
    }

    public function testABundleMovesPastTrailingSkippedJqueryFilesEvenWhenTheNextBundleIsAdjacent(): void
    {
        [$html, $files] = $this->combine(
            self::script('a.js') . self::script('b.js') . self::JQUERY . self::NOCONFLICT . self::script('c.js')
        );

        $this->assertSame(self::JQUERY . self::NOCONFLICT . self::bundle(0) . self::bundle(1), $html);
        $this->assertSame([['/js/a.js', '/js/b.js'], ['/js/c.js']], $files);
    }

    public function testABundleThatUsesJqueryJumpsPastAnInlineScriptToALaterJqueryCore(): void
    {
        [$html] = $this->combine(self::script('template.js') . self::INLINE . self::JQUERY . self::NOCONFLICT);

        $this->assertSame(self::INLINE . self::JQUERY . self::bundle(0) . self::NOCONFLICT, $html);
    }

    public function testTheTrailingJqueryWalkStopsAtAnInlineScript(): void
    {
        [$html] = $this->combine(
            self::script('a.js') . self::script('b.js') . self::INLINE . self::JQUERY . self::NOCONFLICT
        );

        $this->assertSame(self::bundle(0) . self::INLINE . self::JQUERY . self::NOCONFLICT, $html);
    }

    public function testCombineAllBundlesJqueryCoreThenNoConflictThenTheRest(): void
    {
        [$html, $files] = $this->combine(
            self::script('template.js') . self::script('a.js') . self::JQUERY . self::NOCONFLICT,
            combineAll: true
        );

        $this->assertSame(self::bundle(0), $html);
        $this->assertSame([[
            '/media/vendor/jquery/js/jquery.min.js',
            '/media/legacy/js/jquery-noconflict.min.js',
            '/js/template.js',
            '/js/a.js',
        ]], $files);
    }
}
