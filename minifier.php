<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.minifier
 *
 * @copyright   (C) 2024 Brahma Kumaris. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

require_once __DIR__ . '/helper/MinifierAsset.php';
require_once __DIR__ . '/helper/MinifierCssAssetPaths.php';
require_once __DIR__ . '/helper/MinifierHtmlReplacements.php';
require_once __DIR__ . '/helper/MinifierAssetType.php';
require_once __DIR__ . '/helper/MinifierAssetLocator.php';
require_once __DIR__ . '/helper/MinifierSiblingMinifier.php';
require_once __DIR__ . '/helper/MinifierAssetPlan.php';

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Filesystem\File;
use MatthiasMullie\Minify;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Application\CMSApplicationInterface;

class PlgSystemMinifier extends CMSPlugin
{
    /** @var CMSApplicationInterface Application object */
    protected $app;
    
    /** @var boolean Auto load language files */
    protected $autoloadLanguage = true;
    
    /** @var string Category for logging */
    protected $logCategory = 'plg_system_minifier';
    
    /** @var string CSS cache directory path */
    private const CSS_CACHE_DIR = '/media/cache/css/';
    
    /** @var string JS cache directory path */
    private const JS_CACHE_DIR = '/media/cache/js/';
    
    /** @var int Number of old combined files to keep */
    private const CACHE_FILES_TO_KEEP = 5;
    
    /**
     * Constructor: Sets up autoloader and logging
     */
    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);
        
        // Check if vendor directory exists
        if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
            Log::addLogger(
                ['text_file' => 'plg_system_minifier.log.php'],
                Log::ALL,
                [$this->logCategory]
            );
            return;
        }
        
        require_once __DIR__ . '/vendor/autoload.php';
        
        // Set up logger if debug is enabled
        if ($this->params->get('debug', 0)) {
            Log::addLogger(
                ['text_file' => 'plg_system_minifier.log.php'],
                Log::ALL,
                [$this->logCategory]
            );
        }
    }

    /**
     * Plugin event triggered after page rendering
     * Handles both CSS and JS minification if enabled
     * 
     * @return void
     */
    public function onAfterRender(): void
    {
        // Check if any minification is enabled
        if (!$this->params->get('enabled', 1) && !$this->params->get('js_enabled', 1)) {
            return;
        }

        // Only run in site context and if autoloader exists
        if (!$this->app->isClient('site') || !class_exists('MatthiasMullie\Minify\CSS')) {
            return;
        }

        // Get response body
        $body = $this->app->getBody();
        if (empty($body)) {
            return;
        }

        // Process CSS files if enabled
        if ($this->params->get('enabled', 1)) {
            $body = $this->processCssFiles($body);
        }

        // Process JS files if enabled
        if ($this->params->get('js_enabled', 1)) {
            $body = $this->processJsFiles($body);
        }

        // Set modified body
        $this->app->setBody($body);
    }

    /**
     * Processes and minifies CSS files found in the page
     * 
     * @param string $body Page HTML content
     * @return string Modified HTML content
     */
    protected function processCssFiles(string $body): string
    {
        // Find all CSS files, including those with query parameters
        $tags = MinifierHtmlReplacements::stylesheets($body);

        if ($tags->count() === 0) {
            return $body;
        }

        $rootPath = str_replace('\\', '/', JPATH_ROOT);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

        // If combine_css is enabled, collect contiguous blocks of CSS content
        if ($this->params->get('combine_css', 0)) {
            $combineAll = $this->params->get('combine_all_css', 0);
            $targetPath = JPATH_ROOT . self::CSS_CACHE_DIR . 'combined.css';
            $blocks = [];
            $currentBlock = null;

            for ($index = 0; $index < $tags->count(); $index++) {
                $cssFile = $tags->url($index);
                if (strpos($cssFile, '?') !== false) {
                    list($cleanCssFile,) = explode('?', $cssFile, 2);
                } else {
                    $cleanCssFile = $cssFile;
                }

                $breaksContiguity = $this->isExcluded($cleanCssFile, $excludeArray)
                    || MinifierAsset::isExternalUrl($cleanCssFile);

                if ($breaksContiguity) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                    continue;
                }

                $cssPath = $this->resolvePath($cleanCssFile, $rootPath);
                if ($cssPath === false || !file_exists($cssPath)) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                    continue;
                }

                if (MinifierAsset::isPreMinifiedCss($cleanCssFile) && !$combineAll) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                    continue;
                }

                if ($currentBlock !== null && in_array($cssPath, $currentBlock['processedFiles'], true)) {
                    $currentBlock['indices'][] = $index;
                    continue;
                }

                if ($currentBlock === null) {
                    $currentBlock = [
                        'files' => [],
                        'indices' => [],
                        'processedFiles' => [],
                    ];
                }

                if ($this->params->get('debug', 0)) {
                    $this->app->enqueueMessage(sprintf('Adding file to combination: %s', $cssPath), 'debug');
                }

                $currentBlock['files'][] = [
                    'path' => $cssPath,
                    'cleanCssFile' => $cleanCssFile,
                ];
                $currentBlock['indices'][] = $index;
                $currentBlock['processedFiles'][] = $cssPath;
            }

            if ($currentBlock !== null && $currentBlock['files'] !== []) {
                $blocks[] = $currentBlock;
            }

            $cacheDirs = [];

            foreach ($blocks as $block) {
                $cacheDir = $this->prepareCombinedCssBlock($tags, $block, $targetPath);

                if ($cacheDir !== null) {
                    $cacheDirs[$cacheDir] = true;
                }
            }

            if ($cacheDirs !== []) {
                $body = $this->applyEdits($tags, MinifierAssetType::Css);

                foreach (array_keys($cacheDirs) as $cacheDir) {
                    $this->cleanupCombinedFiles($cacheDir, 'combined-', '.css', self::CACHE_FILES_TO_KEEP);
                }
            }
        } else {
            // Process individual files
            $minifier = $this->siblingMinifier($rootPath, $excludeArray);

            for ($index = 0; $index < $tags->count(); $index++) {
                $minifiedUrl = $minifier->minify($tags->url($index), MinifierAssetType::Css);

                if ($minifiedUrl !== null) {
                    $tags->replaceUrl($index, $minifiedUrl);
                }
            }

            $body = $this->applyEdits($tags, MinifierAssetType::Css);
        }

        return $body;
    }

    /**
     * Processes and minifies JavaScript files found in the page
     * 
     * @param string $body Page HTML content
     * @return string Modified HTML content
     */
    protected function processJsFiles(string $body): string
    {
        // If JS minification is disabled, return body unchanged
        if (!$this->params->get('js_enabled', 1)) {
            return $body;
        }

        // Find all JS files, including those with query parameters
        $tags = MinifierHtmlReplacements::scripts($body);

        if ($tags->count() === 0) {
            return $body;
        }

        $rootPath = str_replace('\\', '/', JPATH_ROOT);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

        // If combine_js is enabled, replace each planned block of scripts with one bundle
        if ($this->params->get('combine_js', 0)) {
            $blocks = MinifierAssetPlan::scripts(
                $rootPath,
                array_values($excludeArray),
                (bool) $this->params->get('combine_all_js', 0),
                $this->logger()
            )->blocks($tags);

            $cacheDirs = [];

            foreach ($blocks as $block) {
                $cacheDir = $this->prepareCombinedJsBlock($tags, $block);

                if ($cacheDir !== null) {
                    $cacheDirs[$cacheDir] = true;
                }
            }

            if ($cacheDirs !== []) {
                $body = $this->applyEdits($tags, MinifierAssetType::Js);

                foreach (array_keys($cacheDirs) as $cacheDir) {
                    $this->cleanupCombinedFiles($cacheDir, 'combined-', '.js', self::CACHE_FILES_TO_KEEP);
                }
            }

            return $body;
        }

        // Process individual files
        $minifier = $this->siblingMinifier($rootPath, $excludeArray);

        for ($index = 0; $index < $tags->count(); $index++) {
            $minifiedUrl = $minifier->minify($tags->url($index), MinifierAssetType::Js);

            if ($minifiedUrl !== null) {
                $tags->replaceUrl($index, $minifiedUrl);
            }
        }

        return $this->applyEdits($tags, MinifierAssetType::Js);
    }

    /**
     * Individual-mode minifier for one asset pass, logging to the plugin's category
     *
     * @param array<int, string> $excludeArray Paths to exclude from minification
     */
    protected function siblingMinifier(string $rootPath, array $excludeArray): MinifierSiblingMinifier
    {
        return new MinifierSiblingMinifier($rootPath, array_values($excludeArray), $this->logger());
    }

    /**
     * Log sink for the helper modules: debug messages only when the debug param is on
     *
     * @return \Closure(string $level, string $message): void
     */
    protected function logger(): \Closure
    {
        $debug = (bool) $this->params->get('debug', 0);

        return function (string $level, string $message) use ($debug): void {
            if ($level === 'debug' && !$debug) {
                return;
            }

            $priority = match ($level) {
                'error' => Log::ERROR,
                'warning' => Log::WARNING,
                default => Log::DEBUG,
            };

            Log::add($message, $priority, $this->logCategory);
        };
    }

    /**
     * Resolves file paths considering various Joomla locations
     * 
     * @param string $file Original file path
     * @param string $rootPath Joomla root path
     * @return string|false Resolved absolute file path or false on security violation
     */
    protected function resolvePath(string $file, string $rootPath)
    {
        $resolved = MinifierAsset::resolveWebPath($file, $rootPath);

        if ($resolved === false) {
            Log::add(
                sprintf('Security: Path traversal attempt detected: %s resolves outside root', $file),
                Log::WARNING,
                $this->logCategory
            );
        }

        return $resolved;
    }

    /**
     * Check if a file path is in the exclusion list
     * 
     * @param string $cssFile File path to check
     * @param array $excludeArray Array of paths to exclude
     * @return bool True if excluded, false otherwise
     */
    protected function isExcluded(string $cssFile, array $excludeArray): bool
    {
        foreach ($excludeArray as $excludePath) {
            if (!empty($excludePath) && strpos($cssFile, $excludePath) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cleans up old combined files, keeping only the most recent ones
     * 
     * @param string $directory Directory to clean
     * @param string $prefix File prefix to match
     * @param string $suffix File suffix to match
     * @param int $keep Number of files to keep
     * @return void
     */
    protected function cleanupCombinedFiles(string $directory, string $prefix, string $suffix, int $keep = 5): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/' . $prefix . '*' . $suffix);
        if (count($files) <= $keep) {
            return;
        }

        // Sort files by modification time
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Remove old files
        foreach (array_slice($files, $keep) as $file) {
            try {
                File::delete($file);
            } catch (\Exception $e) {
                Log::add('Failed to delete old combined file: ' . $file, Log::WARNING, $this->logCategory);
            }
        }
    }

    /**
     * Applies one asset pass's edits; overlapping edits leave the page untouched
     */
    protected function applyEdits(MinifierHtmlReplacements $edits, MinifierAssetType $type): string
    {
        foreach ($edits->conflicts() as $conflict) {
            Log::add(
                sprintf('%s rewrite skipped, overlapping edits: %s', strtoupper($type->value), $conflict),
                Log::WARNING,
                $this->logCategory
            );
        }

        return $edits->apply();
    }

    /**
     * @param array<int, array{path: string, cleanCssFile: string}> $files
     */
    protected function buildCssBlockContent(array $files, string $targetPath): string
    {
        if ($files === []) {
            return '';
        }

        $allMinifiable = !array_filter(
            $files,
            static fn(array $file): bool => MinifierAsset::isPreMinifiedCss($file['cleanCssFile'])
        );

        if ($allMinifiable && count($files) > 1) {
            $minifier = new Minify\CSS();

            foreach ($files as $file) {
                $minifier->addFile($file['path']);
            }

            $content = $this->prefixSubdirectoryCssUrls($minifier->execute($targetPath));
            $comment = '/* Files: ' . implode(', ', array_column($files, 'cleanCssFile')) . " */\n";

            return $comment . $content . "\n";
        }

        $content = '';

        foreach ($files as $file) {
            $fileContent = $this->getCssContentForCombine($file['path'], $file['cleanCssFile'], $targetPath);
            $content .= "/* File: {$file['cleanCssFile']} */\n" . $fileContent . "\n";
        }

        return $content;
    }

    /**
     * @param array<int, array{path: string, webPath: string}> $entries
     */
    protected function buildJsCombineContent(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $allMinifiable = !array_filter(
            $entries,
            static fn(array $entry): bool => MinifierAsset::isPreMinifiedJs($entry['webPath'])
        );

        if ($allMinifiable && count($entries) > 1) {
            $minifier = new Minify\JS();

            foreach ($entries as $entry) {
                $minifier->addFile($entry['path']);
            }

            $comment = '/* Files: ' . implode(', ', array_column($entries, 'webPath')) . " */\n";

            return $comment . $minifier->minify() . "\n";
        }

        $content = '';

        foreach ($entries as $entry) {
            $fileContent = $this->getJsContentForCombine($entry['path'], $entry['webPath']);
            $content .= "/* File: {$entry['webPath']} */\n"
                . MinifierAsset::prepareJsCombineSegment($fileContent);
        }

        return $content;
    }

    protected function getJsContentForCombine(string $jsPath, string $webPath): string
    {
        if (MinifierAsset::isPreMinifiedJs($webPath)) {
            $fileContent = file_get_contents($jsPath);

            if ($fileContent === false) {
                throw new \RuntimeException('Failed to read JS file: ' . $jsPath);
            }

            return $fileContent;
        }

        $minifier = new Minify\JS();
        $minifier->addFile($jsPath);

        return $minifier->minify();
    }

    /**
     * Returns minified or raw CSS content with asset paths adjusted for combination
     *
     * @param string $cssPath Absolute path to the CSS file
     * @param string $cleanCssFile Web-relative CSS file path
     * @param string $targetPath Target combined CSS file path
     * @return string CSS content ready for combination
     */
    protected function getCssContentForCombine(string $cssPath, string $cleanCssFile, string $targetPath): string
    {
        if (MinifierAsset::isPreMinifiedCss($cleanCssFile)) {
            $fileContent = file_get_contents($cssPath);

            if ($fileContent === false) {
                throw new \RuntimeException('Failed to read CSS file: ' . $cssPath);
            }

            return MinifierCssAssetPaths::relocatePaths(
                MinifierCssAssetPaths::stripUtf8Bom($fileContent),
                $cssPath,
                $targetPath,
                JPATH_ROOT,
                Uri::root(true)
            );
        }

        $minifier = new Minify\CSS();
        $minifier->addFile($cssPath);

        return $this->prefixSubdirectoryCssUrls($minifier->execute($targetPath));
    }

    /**
     * Builds a combined CSS cache file and registers the tag edits for one block
     *
     * @param array $block Combined block data with files and indices
     * @return string|null The cache directory written to, or null when nothing was combined
     */
    protected function prepareCombinedCssBlock(MinifierHtmlReplacements $tags, array $block, string $targetPath): ?string
    {
        $prepared = MinifierCssAssetPaths::prepareCombinedCss(
            $this->buildCssBlockContent($block['files'], $targetPath)
        );
        $combinedContent = $prepared['css'];
        $tagsToRemove = $block['indices'];
        $firstMatchIndex = $tagsToRemove[0];

        if ($combinedContent === '') {
            return null;
        }

        try {
            $hash = substr(md5($combinedContent . implode('', $prepared['externalImports'])), 0, 8);
            $combinedFilename = "combined-{$hash}.css";
            $combinedPath = JPATH_ROOT . self::CSS_CACHE_DIR . $combinedFilename;
            $cacheDir = dirname($combinedPath);

            if (!is_dir($cacheDir)) {
                if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                    throw new \RuntimeException('Failed to create cache directory: ' . $cacheDir);
                }
            }

            if (file_put_contents($combinedPath, $combinedContent) === false) {
                throw new \RuntimeException('Failed to write combined CSS file: ' . $combinedPath);
            }

            if ($this->params->get('debug', 0)) {
                Log::add(
                    sprintf('Created combined CSS file: %s', $combinedFilename),
                    Log::INFO,
                    $this->logCategory
                );
            }

            $combinedUrl = Uri::root(true) . self::CSS_CACHE_DIR . $combinedFilename;
            $combinedTag = '';

            foreach ($prepared['externalImports'] as $importUrl) {
                $combinedTag .= '<link href="' . htmlspecialchars($importUrl, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">';
            }

            $combinedTag .= '<link href="' . $combinedUrl . '" rel="stylesheet">';

            foreach ($tagsToRemove as $index) {
                if ($index === $firstMatchIndex) {
                    $tags->replaceTag($index, $combinedTag);
                } else {
                    $tags->removeTag($index);
                }
            }

            return $cacheDir;
        } catch (\Exception $e) {
            Log::add(
                'Failed to create combined CSS file: ' . $e->getMessage(),
                Log::ERROR,
                $this->logCategory
            );
        }

        return null;
    }

    /**
     * Builds a combined JS cache file and registers the tag edits for one planned block
     *
     * @return string|null The cache directory written to, or null when nothing was combined
     */
    protected function prepareCombinedJsBlock(
        MinifierHtmlReplacements $tags,
        MinifierCombineBlock $block
    ): ?string {
        $combinedContent = $this->buildJsCombineContent($block->files);

        if ($combinedContent === '') {
            return null;
        }

        try {
            $hash = substr(md5($combinedContent), 0, 8);
            $combinedFilename = "combined-{$hash}.js";
            $combinedPath = JPATH_ROOT . self::JS_CACHE_DIR . $combinedFilename;
            $cacheDir = dirname($combinedPath);

            if (!is_dir($cacheDir)) {
                if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                    throw new \RuntimeException('Failed to create cache directory: ' . $cacheDir);
                }
            }

            if (file_put_contents($combinedPath, $combinedContent) === false) {
                throw new \RuntimeException('Failed to write combined JS file: ' . $combinedPath);
            }

            if ($this->params->get('debug', 0)) {
                Log::add(
                    sprintf('Created combined JS file: %s', $combinedFilename),
                    Log::INFO,
                    $this->logCategory
                );
            }

            $combinedUrl = Uri::root(true) . self::JS_CACHE_DIR . $combinedFilename;
            $block->placeBundle($tags, '<script src="' . $combinedUrl . '"></script>');

            return $cacheDir;
        } catch (\Exception $e) {
            Log::add(
                'Failed to create combined JS file: ' . $e->getMessage(),
                Log::ERROR,
                $this->logCategory
            );
        }

        return null;
    }

    /**
     * Prefixes root-relative CSS asset URLs with the Joomla base path for subdirectory installs
     *
     * @param string $cssContent CSS content
     * @return string CSS content with prefixed URLs where required
     */
    protected function prefixSubdirectoryCssUrls(string $cssContent): string
    {
        return MinifierCssAssetPaths::prefixSubdirectoryPaths($cssContent, Uri::root(true));
    }
}

