<?php

/**
 * Offset-based HTML replacement utilities
 */
class MinifierHtmlReplacements
{
    /**
     * @param array<int, array{offset: int, length: int, replacement: string}> $replacements
     */
    public static function apply(string $body, array $replacements): string
    {
        if ($replacements === []) {
            return $body;
        }

        usort($replacements, static fn(array $a, array $b): int => $b['offset'] <=> $a['offset']);

        foreach ($replacements as $replacement) {
            $body = substr_replace(
                $body,
                $replacement['replacement'],
                $replacement['offset'],
                $replacement['length']
            );
        }

        return $body;
    }
}
