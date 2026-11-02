<?php

use PHPUnit\Framework\TestCase;

class MinifierAssetTest extends TestCase
{
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
}
