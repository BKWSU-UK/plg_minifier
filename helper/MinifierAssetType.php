<?php

use MatthiasMullie\Minify;

/**
 * The two asset types the plugin rewrites
 */
enum MinifierAssetType: string
{
    case Css = 'css';
    case Js = 'js';

    /** Whether a web path (without its query string) already ends in `.min.css` / `.min.js`, any letter case */
    public function isPreMinified(string $webPath): bool
    {
        return str_ends_with(strtolower($webPath), '.min.' . $this->value);
    }

    public function minifier(string $sourcePath): Minify\Minify
    {
        return match ($this) {
            self::Css => new MinifierCss($sourcePath),
            self::Js => new Minify\JS($sourcePath),
        };
    }
}
