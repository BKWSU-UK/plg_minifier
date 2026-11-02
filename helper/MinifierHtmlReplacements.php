<?php

/**
 * Tag-level edits to one asset pass over the rendered page.
 *
 * Built from the page body and the PREG_OFFSET_CAPTURE matches of one asset
 * type. Callers describe edits in terms of matched tags; this module owns the
 * byte offsets, element spans (including a directly following </script>), the
 * order in which edits sharing an offset are applied, and overlap detection.
 */
class MinifierHtmlReplacements
{
    private const SCRIPT_CLOSE = '</script>';

    /** Capture group holding the asset URL in the plugin's tag patterns */
    private const URL_GROUP = 2;

    // The URL may not contain a quote, so it cannot run on into a later tag's attribute
    private const SCRIPT_PATTERN = '/<script[^>]+src=([\'"])([^"\']*?\.js(?:\?[^"\']*)?)\1[^>]*>/i';

    private const STYLESHEET_PATTERN = '/<link[^>]+href=([\'"])([^"\']*?\.css(?:\?[^"\']*)?)\1[^>]*>/i';

    /** @var array<int, array{offset: int, length: int, replacement: string, sequence: int}> */
    private array $edits = [];

    /** Every external `<script src="….js">` tag in the page */
    public static function scripts(string $body): self
    {
        preg_match_all(self::SCRIPT_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE);

        return new self($body, $matches);
    }

    /** Every `<link href="….css">` tag in the page */
    public static function stylesheets(string $body): self
    {
        preg_match_all(self::STYLESHEET_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE);

        return new self($body, $matches);
    }

    /**
     * @param array $matches preg_match_all(..., PREG_OFFSET_CAPTURE) result;
     *                       group 0 is the tag, group 2 the asset URL
     */
    public function __construct(
        private readonly string $body,
        private readonly array $matches
    ) {
    }

    public function count(): int
    {
        return count($this->matches[0]);
    }

    /** The asset URL of one tag, exactly as written (including any query string) */
    public function url(int $index): string
    {
        return $this->matches[self::URL_GROUP][$index][0];
    }

    /**
     * The markup between the end of one element and the start of a later one;
     * empty when the elements are adjacent.
     */
    public function textBetween(int $earlierIndex, int $laterIndex): string
    {
        $start = $this->tagOffset($earlierIndex) + $this->elementLength($earlierIndex);
        $end = $this->tagOffset($laterIndex);

        return $end > $start ? substr($this->body, $start, $end - $start) : '';
    }

    /**
     * Rewrites the URL inside one tag, leaving the rest of the element intact.
     */
    public function replaceUrl(int $index, string $url): void
    {
        [$oldUrl, $urlOffset] = $this->matches[self::URL_GROUP][$index];

        $this->addEdit($urlOffset, strlen($oldUrl), $url);
    }

    public function replaceTag(int $index, string $html): void
    {
        $this->addEdit($this->tagOffset($index), $this->elementLength($index), $html);
    }

    public function removeTag(int $index): void
    {
        $this->addEdit($this->tagOffset($index), $this->elementLength($index), '');
    }

    public function insertAfterTag(int $index, string $html): void
    {
        $this->addEdit($this->tagOffset($index) + $this->elementLength($index), 0, $html);
    }

    /**
     * Returns the edited body, or the original body unchanged when any edits
     * overlap (see conflicts()): a partial or corrupted rewrite is worse than
     * serving the page's original assets.
     */
    public function apply(): string
    {
        if ($this->conflicts() !== []) {
            return $this->body;
        }

        $edits = $this->edits;

        // Apply from the end of the body backwards so earlier offsets stay valid.
        // At a shared offset, apply insertions last so a removal or replacement
        // starting there cannot consume the inserted markup.
        usort($edits, static function (array $a, array $b): int {
            return [$b['offset'], $b['length'] > 0, $b['sequence']]
                <=> [$a['offset'], $a['length'] > 0, $a['sequence']];
        });

        $body = $this->body;

        foreach ($edits as $edit) {
            $body = substr_replace($body, $edit['replacement'], $edit['offset'], $edit['length']);
        }

        return $body;
    }

    /**
     * Describes every pair of overlapping edits. Edits that only touch at their
     * edges (e.g. an insertion at the start of a removed element) do not overlap.
     *
     * @return list<string>
     */
    public function conflicts(): array
    {
        $conflicts = [];
        $count = count($this->edits);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $this->edits[$i];
                $b = $this->edits[$j];

                if ($a['offset'] < $b['offset'] + $b['length'] && $b['offset'] < $a['offset'] + $a['length']) {
                    $conflicts[] = sprintf(
                        'Edit at offset %d (length %d) overlaps edit at offset %d (length %d)',
                        $a['offset'],
                        $a['length'],
                        $b['offset'],
                        $b['length']
                    );
                }
            }
        }

        return $conflicts;
    }

    private function addEdit(int $offset, int $length, string $replacement): void
    {
        $this->edits[] = [
            'offset' => $offset,
            'length' => $length,
            'replacement' => $replacement,
            'sequence' => count($this->edits),
        ];
    }

    private function tagOffset(int $index): int
    {
        return $this->matches[0][$index][1];
    }

    private function tagString(int $index): string
    {
        return $this->matches[0][$index][0];
    }

    private function elementLength(int $index): int
    {
        $tag = $this->tagString($index);
        $length = strlen($tag);

        if (stripos($tag, '<script') === 0
            && substr($this->body, $this->tagOffset($index) + $length, strlen(self::SCRIPT_CLOSE)) === self::SCRIPT_CLOSE) {
            $length += strlen(self::SCRIPT_CLOSE);
        }

        return $length;
    }
}
