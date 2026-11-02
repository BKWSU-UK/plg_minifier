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
        $this->write('/css/a.css', 'a { color: red; }');
        $this->write('/css/b.css', 'b { color: blue; }');
        $this->write('/css/pre.min.css', 'p{margin:0}');
        $this->write('/css/vendor/lib.css', '.lib { top: 0; }');
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
     * Plans the page's scripts (or stylesheets), places a numbered placeholder bundle
     * for each block, and returns the rewritten page plus each block's bundle file paths.
     *
     * @param list<string> $excludePaths
     * @return array{0: string, 1: list<list<string>>}
     */
    private function combine(
        string $html,
        bool $combineAll = false,
        array $excludePaths = [],
        MinifierAssetType $type = MinifierAssetType::Js,
        string $baseUrl = ''
    ): array {
        [$tags, $plan, $bundle] = $type === MinifierAssetType::Js
            ? [MinifierHtmlReplacements::scripts($html), MinifierAssetPlan::scripts(...), self::bundle(...)]
            : [MinifierHtmlReplacements::stylesheets($html), MinifierAssetPlan::stylesheets(...), self::stylesheetBundle(...)];
        $bundleFiles = [];

        foreach ($plan($this->root, $excludePaths, $combineAll, baseUrl: $baseUrl)->blocks($tags) as $number => $block) {
            $block->placeBundle($tags, $bundle($number));
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

    public static function jqueryUsage(): array
    {
        return [
            'jQuery() call' => ['jQuery(function () { window.ready = true; });', true],
            '$() call after a statement' => ['window.x = 1; });$(function () {});', true],
            'own $ function' => ['function $(t, e) { return t + e; }', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jqueryUsage')]
    public function testOnlyABundleWhoseCodeCallsJqueryMovesAfterALaterJqueryCore(string $code, bool $moves): void
    {
        $this->write('/js/uses.js', $code);

        [$html] = $this->combine(self::script('uses.js') . self::INLINE . self::JQUERY);

        $this->assertSame(
            $moves ? self::INLINE . self::JQUERY . self::bundle(0) : self::bundle(0) . self::INLINE . self::JQUERY,
            $html
        );
    }

    public function testANonMinifiedJqueryCoreLaterInThePageCountsAsJqueryToo(): void
    {
        $this->write('/media/vendor/jquery/js/jquery.js', 'window.jQuery = function () {};');
        $jquery = '<script src="/media/vendor/jquery/js/jquery.js"></script>';

        [$html] = $this->combine(self::script('template.js') . self::INLINE . $jquery . self::script('pre.min.js'));

        // jquery.js is combinable, so it becomes bundle 1; the template bundle still loads after it
        $this->assertSame(self::INLINE . self::bundle(1) . self::bundle(0) . self::script('pre.min.js'), $html);
    }

    public function testAnExternalScriptTheCombinerDoesNotTrackDoesNotSplitTheBlock(): void
    {
        $untracked = '<script src="/index.php?option=com_ajax&format=raw"></script>';

        [$html, $files] = $this->combine(self::script('a.js') . $untracked . self::script('b.js'));

        $this->assertSame($untracked . self::bundle(0), $html);
        $this->assertSame([['/js/a.js', '/js/b.js']], $files);
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

    private static function stylesheet(string $name): string
    {
        return '<link href="/css/' . $name . '" rel="stylesheet">';
    }

    private static function stylesheetBundle(int $number): string
    {
        return '<link href="/bundle-' . $number . '.css" rel="stylesheet">';
    }

    public function testContiguousStylesheetsBecomeOneBundleInPlaceOfTheFirstTag(): void
    {
        [$html, $files] = $this->combine(
            self::stylesheet('a.css') . "\n" . self::stylesheet('b.css?v=2'),
            type: MinifierAssetType::Css
        );

        $this->assertSame(self::stylesheetBundle(0) . "\n", $html);
        $this->assertSame([['/css/a.css', '/css/b.css']], $files);
    }

    public static function stylesheetBlockBreakers(): array
    {
        return [
            'excluded' => [self::stylesheet('vendor/lib.css')],
            'external' => ['<link href="https://cdn.example.com/x.css" rel="stylesheet">'],
            'missing' => [self::stylesheet('missing.css')],
            'outside the site root' => ['<link href="/css/../../outside.css" rel="stylesheet">'],
            'pre-minified' => [self::stylesheet('pre.min.css')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stylesheetBlockBreakers')]
    public function testAStylesheetThatCannotBeCombinedSplitsTheBlockAndKeepsItsPlaceInTheCascade(string $breaker): void
    {
        [$html, $files] = $this->combine(
            self::stylesheet('a.css') . $breaker . self::stylesheet('b.css'),
            excludePaths: ['/css/vendor/'],
            type: MinifierAssetType::Css
        );

        $this->assertSame(self::stylesheetBundle(0) . $breaker . self::stylesheetBundle(1), $html);
        $this->assertSame([['/css/a.css'], ['/css/b.css']], $files);
    }

    public function testCombineAllKeepsPreMinifiedStylesheetsInTheBlockInPageOrder(): void
    {
        [$html, $files] = $this->combine(
            self::stylesheet('a.css') . self::stylesheet('pre.min.css') . self::stylesheet('b.css'),
            combineAll: true,
            type: MinifierAssetType::Css
        );

        $this->assertSame(self::stylesheetBundle(0), $html);
        $this->assertSame([['/css/a.css', '/css/pre.min.css', '/css/b.css']], $files);
    }

    public function testADuplicateStylesheetInTheBlockIsRemovedButItsFileBundledOnce(): void
    {
        [$html, $files] = $this->combine(
            self::stylesheet('a.css') . self::stylesheet('b.css') . self::stylesheet('a.css?v=2'),
            type: MinifierAssetType::Css
        );

        $this->assertSame(self::stylesheetBundle(0), $html);
        $this->assertSame([['/css/a.css', '/css/b.css']], $files);
    }

    public function testEachFileAddedToABundleIsReportedAtDebugLevel(): void
    {
        $messages = [];
        $log = function (string $level, string $message) use (&$messages): void {
            $messages[] = [$level, $message];
        };

        MinifierAssetPlan::stylesheets($this->root, [], false, $log)
            ->blocks(MinifierHtmlReplacements::stylesheets(self::stylesheet('a.css') . self::stylesheet('b.css')));

        $this->assertSame(
            [
                ['debug', 'Adding file to combination: ' . $this->root . '/css/a.css'],
                ['debug', 'Adding file to combination: ' . $this->root . '/css/b.css'],
            ],
            $messages
        );
    }

    public function testInASubdirectoryInstallScriptsUnderTheBasePathAreCombined(): void
    {
        [$html, $files] = $this->combine(
            '<script src="/sub/js/a.js"></script><script src="/sub/js/b.js?v=2"></script>',
            baseUrl: '/sub'
        );

        $this->assertSame(self::bundle(0), $html);
        $this->assertSame([['/sub/js/a.js', '/sub/js/b.js']], $files);
    }

    public function testInASubdirectoryInstallStylesheetsUnderTheBasePathAreCombined(): void
    {
        [$html, $files] = $this->combine(
            '<link href="/sub/css/a.css" rel="stylesheet"><link href="/sub/css/b.css" rel="stylesheet">',
            type: MinifierAssetType::Css,
            baseUrl: '/sub'
        );

        $this->assertSame(self::stylesheetBundle(0), $html);
        $this->assertSame([['/sub/css/a.css', '/sub/css/b.css']], $files);
    }

    public static function styleMarkupBetweenStylesheets(): array
    {
        return [
            'inline style' => ['<style>p { color: blue; }</style>'],
            'inline style with attributes, upper case' => ['<STYLE media="screen">p { color: blue; }</STYLE>'],
            'untracked stylesheet link' => ['<link href="https://fonts.googleapis.com/css2?family=Fraunces&display=swap" rel="stylesheet">'],
            'untracked stylesheet link, single-quoted rel' => ["<link rel='stylesheet' href='/index.php?option=com_custom&format=css'>"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('styleMarkupBetweenStylesheets')]
    public function testStylesTheCombinerDoesNotTrackSplitTheBlockAndKeepTheirPlaceInTheCascade(string $styles): void
    {
        [$html, $files] = $this->combine(
            self::stylesheet('a.css') . $styles . self::stylesheet('b.css'),
            type: MinifierAssetType::Css
        );

        $this->assertSame(self::stylesheetBundle(0) . $styles . self::stylesheetBundle(1), $html);
        $this->assertSame([['/css/a.css'], ['/css/b.css']], $files);
    }

    public function testMarkupThatAddsNoStylesDoesNotSplitAStylesheetBlock(): void
    {
        $between = "\n  <!-- template styles -->\n"
            . '<meta name="viewport" content="width=device-width">'
            . '<link href="/media/images/favicon.svg" rel="icon">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com">'
            . '<link rel="preload" href="/fonts/a.woff2" as="font">'
            . '<link data-rel="stylesheet" href="/x">'
            . '<link rel=icon href=/img/stylesheet.png>'
            . '<link rel="x-stylesheet" href="/y">'
            . '<style-guide></style-guide>';

        [$html, $files] = $this->combine(
            self::stylesheet('a.css') . $between . self::stylesheet('b.css'),
            type: MinifierAssetType::Css
        );

        $this->assertSame(self::stylesheetBundle(0) . $between, $html);
        $this->assertSame([['/css/a.css', '/css/b.css']], $files);
    }

    public function testAnInlineStyleDoesNotSplitAScriptBlock(): void
    {
        [$html] = $this->combine(self::script('a.js') . '<style>p { color: blue; }</style>' . self::script('b.js'));

        $this->assertSame('<style>p { color: blue; }</style>' . self::bundle(0), $html);
    }
}
