<?php

use MatthiasMullie\Minify;

/**
 * The two asset types the plugin rewrites
 */
enum MinifierAssetType: string
{
    case Css = 'css';
    case Js = 'js';

    public function isPreMinified(string $path): bool
    {
        return match ($this) {
            self::Css => MinifierAsset::isPreMinifiedCss($path),
            self::Js => MinifierAsset::isPreMinifiedJs($path),
        };
    }

    public function minifier(string $sourcePath): Minify\Minify
    {
        return match ($this) {
            self::Css => new Minify\CSS($sourcePath),
            self::Js => new Minify\JS($sourcePath),
        };
    }
}
