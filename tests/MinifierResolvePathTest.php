<?php

use PHPUnit\Framework\TestCase;

class MinifierResolvePathTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = JPATH_ROOT;
        $this->ensureFixture('/media/system/test.css', 'body {}');
        $this->ensureFixture('/templates/cassiopeia/css/template.css', '.template {}');
        $this->ensureFixture('/modules/mod_example/media/mod_example.js', 'window.mod = true;');
    }

    public function testResolveWebPathAcceptsAbsoluteMediaPath(): void
    {
        $resolved = MinifierAsset::resolveWebPath('/media/system/test.css', $this->fixtureRoot);

        $this->assertSame($this->fixtureRoot . '/media/system/test.css', $resolved);
    }

    public function testResolveWebPathAcceptsRelativePath(): void
    {
        $resolved = MinifierAsset::resolveWebPath('templates/cassiopeia/css/template.css', $this->fixtureRoot);

        $this->assertSame($this->fixtureRoot . '/templates/cassiopeia/css/template.css', $resolved);
    }

    public function testResolveWebPathExtractsModulePath(): void
    {
        $resolved = MinifierAsset::resolveWebPath(
            '/modules/mod_example/media/mod_example.js',
            $this->fixtureRoot
        );

        $this->assertSame($this->fixtureRoot . '/modules/mod_example/media/mod_example.js', $resolved);
    }

    public function testResolveWebPathRejectsTraversalOutsideRoot(): void
    {
        $this->assertFalse(
            MinifierAsset::resolveWebPath('/templates/css/../../../../etc/passwd', $this->fixtureRoot)
        );
    }

    public function testResolveWebPathRejectsTraversalWhenTargetDoesNotExist(): void
    {
        $this->assertFalse(
            MinifierAsset::resolveWebPath('/media/../../outside.css', $this->fixtureRoot)
        );
    }

    public function testNormalisePathCollapsesParentSegments(): void
    {
        $normalised = MinifierAsset::normalisePath('/var/www/html/templates/css/../../../../etc/passwd');

        $this->assertFalse(MinifierAsset::isPathWithinRoot($normalised, '/var/www/html'));
    }

    private function ensureFixture(string $relativePath, string $contents): void
    {
        $path = $this->fixtureRoot . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!is_file($path)) {
            file_put_contents($path, $contents);
        }
    }
}
