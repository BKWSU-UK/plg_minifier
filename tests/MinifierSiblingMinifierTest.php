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
                "$ext excluded" => [$type, "/$ext/vendor/app.$ext?v=1"],
                "$ext missing" => [$type, "/$ext/missing.$ext"],
                "$ext pre-minified" => [$type, "/$ext/app.min.$ext"],
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
}
