<?php

use PHPUnit\Framework\TestCase;

class MinifierHtmlReplacementsTest extends TestCase
{
    public function testInsertAfterTagDirectlyBeforeARemovedTagKeepsBoth(): void
    {
        $edits = MinifierHtmlReplacements::scripts('<script src="/a.js"></script><script src="/b.js"></script>');

        $edits->insertAfterTag(0, '<script src="/combined.js"></script>');
        $edits->removeTag(1);

        $this->assertSame('<script src="/a.js"></script><script src="/combined.js"></script>', $edits->apply());
    }

    public function testReplaceTagReplacesTheWholeScriptElementIncludingItsClosingTag(): void
    {
        $edits = MinifierHtmlReplacements::scripts('<script src="/a.js"></script><script src="/b.js"></script>');

        $edits->replaceTag(0, '<script src="/combined.js"></script>');
        $edits->removeTag(1);

        $this->assertSame('<script src="/combined.js"></script>', $edits->apply());
    }

    public function testReplaceUrlRewritesOnlyThatTagsUrlEvenWhenTagsAreDuplicated(): void
    {
        $edits = MinifierHtmlReplacements::stylesheets(
            '<link href="/media/a.css?v=1" rel="stylesheet"><link href="/media/a.css?v=1" rel="stylesheet">'
        );

        $edits->replaceUrl(1, '/media/a.min.css?v=1');

        $this->assertSame(
            '<link href="/media/a.css?v=1" rel="stylesheet"><link href="/media/a.min.css?v=1" rel="stylesheet">',
            $edits->apply()
        );
    }

    public function testReplaceUrlKeepsTheScriptElementIntact(): void
    {
        $edits = MinifierHtmlReplacements::scripts('<script src="/a.js" defer></script>');

        $edits->replaceUrl(0, '/a.min.js');

        $this->assertSame('<script src="/a.min.js" defer></script>', $edits->apply());
    }

    public function testOverlappingEditsLeaveTheBodyUntouchedAndAreReported(): void
    {
        $body = '<script src="/a.js"></script><script src="/b.js"></script>';
        $edits = MinifierHtmlReplacements::scripts($body);

        $edits->removeTag(0);
        $edits->replaceUrl(0, '/a.min.js');
        $edits->removeTag(1);

        $this->assertSame($body, $edits->apply());
        $this->assertCount(1, $edits->conflicts());
    }

    public function testEditsThatOnlyTouchAtTheirEdgesDoNotConflict(): void
    {
        $edits = MinifierHtmlReplacements::scripts('<script src="/a.js"></script><script src="/b.js"></script>');

        $edits->removeTag(0);
        $edits->insertAfterTag(0, '<script src="/combined.js"></script>');
        $edits->removeTag(1);

        $this->assertSame('<script src="/combined.js"></script>', $edits->apply());
        $this->assertSame([], $edits->conflicts());
    }

    public function testTextBetweenStartsAfterTheClosingScriptTag(): void
    {
        $edits = MinifierHtmlReplacements::scripts('<script src="/a.js?v=2"></script><script>init();</script><script src="/b.js"></script>');

        $this->assertSame('/a.js?v=2', $edits->url(0));
        $this->assertSame('<script>init();</script>', $edits->textBetween(0, 1));
    }

    public function testTextBetweenStartsDirectlyAfterASelfClosingScriptTag(): void
    {
        $edits = MinifierHtmlReplacements::scripts('<script src="/a.js" />  <script src="/b.js"></script>');

        $this->assertSame('  ', $edits->textBetween(0, 1));
    }
}
