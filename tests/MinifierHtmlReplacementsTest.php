<?php

use PHPUnit\Framework\TestCase;

class MinifierHtmlReplacementsTest extends TestCase
{
    public function testApplyReplacesDuplicateTagsWithoutAmbiguity(): void
    {
        $body = '<link href="/media/a.css" rel="stylesheet"><link href="/media/a.css" rel="stylesheet">';
        $firstLength = strlen('<link href="/media/a.css" rel="stylesheet">');

        $result = MinifierHtmlReplacements::apply($body, [
            [
                'offset' => $firstLength,
                'length' => $firstLength,
                'replacement' => '<link href="/media/a.min.css" rel="stylesheet">',
            ],
            [
                'offset' => 0,
                'length' => $firstLength,
                'replacement' => '<link href="/media/a.min.css" rel="stylesheet">',
            ],
        ]);

        $this->assertSame(
            '<link href="/media/a.min.css" rel="stylesheet"><link href="/media/a.min.css" rel="stylesheet">',
            $result
        );
    }

    public function testApplySupportsInsertionsAndRemovals(): void
    {
        $body = '<script src="/media/a.js"></script><script src="/media/b.js"></script>';

        $result = MinifierHtmlReplacements::apply($body, [
            [
                'offset' => strlen('<script src="/media/a.js"></script>'),
                'length' => strlen('<script src="/media/b.js"></script>'),
                'replacement' => '',
            ],
            [
                'offset' => 0,
                'length' => strlen('<script src="/media/a.js"></script>'),
                'replacement' => '<script src="/media/combined.js"></script>',
            ],
        ]);

        $this->assertSame('<script src="/media/combined.js"></script>', $result);
    }
}
