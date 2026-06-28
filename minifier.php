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

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
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
        preg_match_all('/<link[^>]+href=([\'"])(.*?\.css(?:\?[^"\']*)?)\1[^>]*>/i', $body, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[2])) {
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

            foreach ($matches[2] as $index => $cssFileMatch) {
                $cssFile = $this->getMatchValue($cssFileMatch);
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

            $cssReplacements = [];
            $cacheDirs = [];

            foreach ($blocks as $block) {
                $blockResult = $this->prepareCombinedCssBlock($matches, $block, $targetPath);

                if ($blockResult !== null) {
                    array_push($cssReplacements, ...$blockResult['replacements']);
                    $cacheDirs[$blockResult['cacheDir']] = true;
                }
            }

            if ($cssReplacements !== []) {
                $body = $this->applyHtmlReplacements($body, $cssReplacements);

                foreach (array_keys($cacheDirs) as $cacheDir) {
                    $this->cleanupCombinedFiles($cacheDir, 'combined-', '.css', self::CACHE_FILES_TO_KEEP);
                }
            }
        } else {
            // Process individual files
            $cssReplacements = [];

            foreach ($matches[2] as $index => $cssFileMatch) {
                $cssFile = $this->getMatchValue($cssFileMatch);
                // Extract query parameters
                $queryString = '';
                if (strpos($cssFile, '?') !== false) {
                    list($cleanCssFile, $queryString) = explode('?', $cssFile, 2);
                    $queryString = '?' . $queryString;
                } else {
                    $cleanCssFile = $cssFile;
                }
                
                if ($this->params->get('debug', 0)) {
                    Log::add('CSS File: ' . $cssFile, Log::DEBUG, $this->logCategory);
                }
                
                // Skip if file matches excluded paths
                if ($this->isExcluded($cleanCssFile, $excludeArray)) {
                    continue;
                }

                // Skip external CSS files and already minified files
                if ($this->shouldSkipCssFile($cleanCssFile)) {
                    continue;
                }

                try {
                    $minifiedUrl = $this->minifyCssFile($cleanCssFile, $rootPath);
                    if ($minifiedUrl) {
                        $tag = $this->getTagString($matches, $index);
                        $cssReplacements[] = [
                            'offset' => $this->getTagOffset($matches, $index),
                            'length' => strlen($tag),
                            'replacement' => str_replace($cssFile, $minifiedUrl . $queryString, $tag),
                        ];
                    }
                } catch (\Exception $e) {
                    Log::add('CSS Minification failed: ' . $e->getMessage() . ' for file: ' . $cssFile, Log::ERROR, $this->logCategory);
                    continue;
                }
            }

            $body = $this->applyHtmlReplacements($body, $cssReplacements);
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
        preg_match_all('/<script[^>]+src=([\'"])(.*?\.js(?:\?[^"\']*)?)\1[^>]*>/i', $body, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[2])) {
            return $body;
        }

        $rootPath = str_replace('\\', '/', JPATH_ROOT);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

        // If combine_js is enabled, collect contiguous blocks of JS content
        if ($this->params->get('combine_js', 0)) {
            $combineAll = $this->params->get('combine_all_js', 0);
            $blocks = [];
            $currentBlock = null;
            $previousMatchIndex = null;

            foreach ($matches[2] as $index => $jsFileMatch) {
                if ($previousMatchIndex !== null
                    && $this->gapContainsInlineScriptBetweenMatches($body, $matches, $previousMatchIndex, $index)) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                }

                $previousMatchIndex = $index;

                $jsFile = $this->getMatchValue($jsFileMatch);
                if (strpos($jsFile, '?') !== false) {
                    list($cleanJsFile,) = explode('?', $jsFile, 2);
                } else {
                    $cleanJsFile = $jsFile;
                }

                $breaksContiguity = $this->isExcluded($cleanJsFile, $excludeArray)
                    || MinifierAsset::isExternalUrl($cleanJsFile);

                if ($breaksContiguity) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                    continue;
                }

                $jsPath = $this->resolvePath($cleanJsFile, $rootPath);
                if ($jsPath === false || !file_exists($jsPath)) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                    continue;
                }

                if (MinifierAsset::isPreMinifiedJs($cleanJsFile) && !$combineAll) {
                    if ($currentBlock !== null && $currentBlock['files'] !== []) {
                        $blocks[] = $currentBlock;
                        $currentBlock = null;
                    }
                    continue;
                }

                if ($currentBlock !== null && in_array($jsPath, $currentBlock['processedFiles'], true)) {
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
                    Log::add(
                        sprintf('Adding JS file to combination: %s', $jsPath),
                        Log::DEBUG,
                        $this->logCategory
                    );
                }

                $currentBlock['files'][] = [
                    'path' => $jsPath,
                    'cleanJsFile' => $cleanJsFile,
                ];
                $currentBlock['indices'][] = $index;
                $currentBlock['processedFiles'][] = $jsPath;
            }

            if ($currentBlock !== null && $currentBlock['files'] !== []) {
                $blocks[] = $currentBlock;
            }

            $jsReplacements = [];
            $cacheDirs = [];

            foreach ($blocks as $block) {
                $blockResult = $this->prepareCombinedJsBlock($body, $matches, $block, $excludeArray, $rootPath, $combineAll);

                if ($blockResult !== null) {
                    array_push($jsReplacements, ...$blockResult['replacements']);
                    $cacheDirs[$blockResult['cacheDir']] = true;
                }
            }

            if ($jsReplacements !== []) {
                $body = $this->applyHtmlReplacements($body, $jsReplacements);

                foreach (array_keys($cacheDirs) as $cacheDir) {
                    $this->cleanupCombinedFiles($cacheDir, 'combined-', '.js', self::CACHE_FILES_TO_KEEP);
                }
            }

            return $body;
        }

        // Only process individual files if js_enabled is true
        elseif ($this->params->get('js_enabled', 1)) {
            $jsReplacements = [];

            // Process individual files
            foreach ($matches[2] as $index => $jsFileMatch) {
                $jsFile = $this->getMatchValue($jsFileMatch);
                // Extract query parameters
                $queryString = '';
                if (strpos($jsFile, '?') !== false) {
                    list($cleanJsFile, $queryString) = explode('?', $jsFile, 2);
                    $queryString = '?' . $queryString;
                } else {
                    $cleanJsFile = $jsFile;
                }
                
                if ($this->params->get('debug', 0)) {
                    Log::add('JS File: ' . $jsFile, Log::DEBUG, $this->logCategory);
                }
                
                // Skip if file matches excluded paths
                if ($this->isExcluded($cleanJsFile, $excludeArray)) {
                    continue;
                }

                // Skip external JS files and already minified files
                if ($this->shouldSkipJsFile($cleanJsFile)) {
                    continue;
                }

                try {
                    $minifiedUrl = $this->minifyJsFile($cleanJsFile, $rootPath);
                    if ($minifiedUrl) {
                        $offset = $this->getTagOffset($matches, $index);
                        $openingTag = $this->getTagString($matches, $index);
                        $length = MinifierHtmlReplacements::externalScriptElementLength($body, $offset, $openingTag);
                        $replacementTag = str_replace($jsFile, $minifiedUrl . $queryString, $openingTag);

                        if ($length > strlen($openingTag)) {
                            $replacementTag .= '</script>';
                        }

                        $jsReplacements[] = [
                            'offset' => $offset,
                            'length' => $length,
                            'replacement' => $replacementTag,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::add('JS Minification failed: ' . $e->getMessage() . ' for file: ' . $jsFile, Log::ERROR, $this->logCategory);
                    continue;
                }
            }

            $body = $this->applyHtmlReplacements($body, $jsReplacements);
        }

        return $body;
    }

    /**
     * Determines if a CSS file should be skipped (external or already minified)
     * 
     * @param string $cssFile The CSS file path
     * @return bool True if file should be skipped, false otherwise
     */
    protected function shouldSkipCssFile(string $cssFile): bool
    {
        // If combine_all_css is enabled, don't skip any local files
        if ($this->params->get('combine_all_css', 0)) {
            return MinifierAsset::isExternalUrl($cssFile);
        }
        
        // Original skip logic for non-combine-all mode
        return MinifierAsset::isExternalUrl($cssFile)
            || MinifierAsset::isPreMinifiedCss($cssFile);
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
     * Determines if a JavaScript file should be skipped (external or already minified)
     * 
     * @param string $jsFile The JavaScript file path
     * @return bool True if file should be skipped, false otherwise
     */
    protected function shouldSkipJsFile(string $jsFile): bool
    {
        // Skip external files
        if (MinifierAsset::isExternalUrl($jsFile)) {
            return true;
        }

        // Skip already minified files
        if (MinifierAsset::isPreMinifiedJs($jsFile)) {
            return true;
        }

        return false;
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
     * Minifies a CSS file
     * 
     * @param string $cssFile Original CSS file path
     * @param string $rootPath Joomla root path
     * @return string|false Minified file URL or false on failure
     */
    protected function minifyCssFile(string $cssFile, string $rootPath)
    {
        // Handle relative and absolute paths
        $cssPath = $this->resolvePath($cssFile, $rootPath);
        
        // Security check
        if ($cssPath === false) {
            return false;
        }
        
        // Debug logging
        if ($this->params->get('debug', 0)) {
            Log::add(
                sprintf('CSS Minifier Debug - Original: %s, Resolved: %s', $cssFile, $cssPath),
                Log::DEBUG,
                $this->logCategory
            );
        }
        
        if (!file_exists($cssPath)) {
            if ($this->params->get('debug', 0)) {
                Log::add('CSS file not found: ' . $cssPath, Log::WARNING, $this->logCategory);
            }
            return false;
        }

        // Create minified filename
        $minifiedPath = dirname($cssPath) . '/' . File::stripExt(basename($cssPath)) . '.min.css';
        
        // Ensure target directory exists and is writable
        if (!$this->ensureWritableDirectory(dirname($minifiedPath))) {
            return false;
        }

        // Check if minified file needs to be updated
        $shouldMinify = !file_exists($minifiedPath);
        if (!$shouldMinify) {
            $originalTime = filemtime($cssPath);
            $minifiedTime = filemtime($minifiedPath);
            $shouldMinify = ($originalTime > $minifiedTime);
            
            if ($this->params->get('debug', 0)) {
                Log::add(
                    sprintf('CSS timestamps - Original: %s, Minified: %s, Should minify: %s',
                        date('Y-m-d H:i:s', $originalTime),
                        date('Y-m-d H:i:s', $minifiedTime),
                        $shouldMinify ? 'yes' : 'no'
                    ),
                    Log::DEBUG,
                    $this->logCategory
                );
            }
        }

        if ($shouldMinify) {
            try {
                $minifier = new Minify\CSS($cssPath);
                $minifier->minify($minifiedPath);
                
                // Ensure the minified file timestamp is set after the original
                touch($minifiedPath, time());
                
                if ($this->params->get('debug', 0)) {
                    Log::add('CSS file minified: ' . $minifiedPath, Log::INFO, $this->logCategory);
                }
            } catch (\Exception $e) {
                Log::add('CSS minification failed: ' . $e->getMessage(), Log::ERROR, $this->logCategory);
                return false;
            }
        }

        return dirname($cssFile) . '/' . File::stripExt(basename($cssFile)) . '.min.css';
    }

    /**
     * Minifies a JavaScript file
     * 
     * @param string $jsFile Original JavaScript file path
     * @param string $rootPath Joomla root path
     * @return string|false Minified file URL or false on failure
     */
    protected function minifyJsFile(string $jsFile, string $rootPath)
    {
        // Handle relative and absolute paths
        $jsPath = $this->resolvePath($jsFile, $rootPath);
        
        // Security check
        if ($jsPath === false) {
            return false;
        }
        
        // Debug logging
        if ($this->params->get('debug', 0)) {
            Log::add(
                sprintf('JS Minifier Debug - Original: %s, Resolved: %s', $jsFile, $jsPath),
                Log::DEBUG,
                $this->logCategory
            );
        }
        
        if (!file_exists($jsPath)) {
            if ($this->params->get('debug', 0)) {
                Log::add('JS file not found: ' . $jsPath, Log::WARNING, $this->logCategory);
            }
            return false;
        }

        // Create minified filename
        $minifiedPath = dirname($jsPath) . '/' . File::stripExt(basename($jsPath)) . '.min.js';
        
        // Ensure target directory exists and is writable
        if (!$this->ensureWritableDirectory(dirname($minifiedPath))) {
            return false;
        }

        // Check if minified file needs to be updated
        $shouldMinify = !file_exists($minifiedPath);
        if (!$shouldMinify) {
            $originalTime = filemtime($jsPath);
            $minifiedTime = filemtime($minifiedPath);
            $shouldMinify = ($originalTime > $minifiedTime);
            
            if ($this->params->get('debug', 0)) {
                Log::add(
                    sprintf('JS timestamps - Original: %s, Minified: %s, Should minify: %s',
                        date('Y-m-d H:i:s', $originalTime),
                        date('Y-m-d H:i:s', $minifiedTime),
                        $shouldMinify ? 'yes' : 'no'
                    ),
                    Log::DEBUG,
                    $this->logCategory
                );
            }
        }

        if ($shouldMinify) {
            try {
                // Regular minification
                $minifier = new Minify\JS($jsPath);
                $minifier->minify($minifiedPath);
                
                // Ensure the minified file timestamp is set after the original
                touch($minifiedPath, time());
                
                if ($this->params->get('debug', 0)) {
                    Log::add('JS file minified: ' . $minifiedPath, Log::INFO, $this->logCategory);
                }
            } catch (\Exception $e) {
                Log::add('JS minification failed: ' . $e->getMessage(), Log::ERROR, $this->logCategory);
                return false;
            }
        }

        return dirname($jsFile) . '/' . File::stripExt(basename($jsFile)) . '.min.js';
    }

    /**
     * Ensures a directory exists and is writable
     * 
     * @param string $directory Directory path to check/create
     * @return bool True if directory is writable, false otherwise
     */
    protected function ensureWritableDirectory(string $directory): bool
    {
        if (!Folder::exists($directory)) {
            try {
                Folder::create($directory);
            } catch (\Exception $e) {
                Log::add('Failed to create directory: ' . $directory, Log::ERROR, $this->logCategory);
                return false;
            }
        }

        if (!is_writable($directory)) {
            Log::add('Directory not writable: ' . $directory, Log::ERROR, $this->logCategory);
            return false;
        }

        return true;
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

    protected function getMatchValue(string|array $match): string
    {
        return is_array($match) ? $match[0] : $match;
    }

    protected function getTagString(array $matches, int $index): string
    {
        return $this->getMatchValue($matches[0][$index]);
    }

    protected function getTagOffset(array $matches, int $index): int
    {
        return $matches[0][$index][1];
    }

    protected function getScriptElementLength(string $body, array $matches, int $index): int
    {
        return MinifierHtmlReplacements::externalScriptElementLength(
            $body,
            $this->getTagOffset($matches, $index),
            $this->getTagString($matches, $index)
        );
    }

    protected function gapContainsInlineScriptBetweenMatches(
        string $body,
        array $matches,
        int $previousIndex,
        int $nextIndex
    ): bool {
        $gapStart = $this->getTagOffset($matches, $previousIndex)
            + $this->getScriptElementLength($body, $matches, $previousIndex);
        $gapEnd = $this->getTagOffset($matches, $nextIndex);

        if ($gapEnd <= $gapStart) {
            return false;
        }

        return MinifierAsset::gapContainsInlineScript(substr($body, $gapStart, $gapEnd - $gapStart));
    }

    /**
     * @param array $scriptMatches preg_match_all results for script tags
     */
    protected function resolveJsCombineAnchorIndex(
        array $scriptMatches,
        array $blockIndices,
        array $excludeArray,
        string $rootPath,
        bool $combineAll
    ): int {
        $anchorIndex = $blockIndices[count($blockIndices) - 1];
        $matchCount = count($scriptMatches[0]);

        if ($combineAll) {
            return $anchorIndex;
        }

        for ($index = $anchorIndex + 1; $index < $matchCount; $index++) {
            $jsFile = $this->getMatchValue($scriptMatches[2][$index]);
            $cleanJsFile = explode('?', $jsFile, 2)[0];

            if ($this->isExcluded($cleanJsFile, $excludeArray)
                || MinifierAsset::isExternalUrl($cleanJsFile)
                || !MinifierAsset::isJqueryDependency($cleanJsFile)) {
                break;
            }

            $jsPath = $this->resolvePath($cleanJsFile, $rootPath);
            if ($jsPath === false || !file_exists($jsPath)) {
                break;
            }

            $anchorIndex = $index;
        }

        return $anchorIndex;
    }

    /**
     * @param array<int, array{offset: int, length: int, replacement: string}> $replacements
     */
    protected function applyHtmlReplacements(string $body, array $replacements): string
    {
        return MinifierHtmlReplacements::apply($body, $replacements);
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
     * @param array<int, array{path: string, cleanJsFile: string}> $entries
     */
    protected function buildJsCombineContent(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $entries = MinifierAsset::sortJsCombineEntries($entries);

        $allMinifiable = !array_filter(
            $entries,
            static fn(array $entry): bool => MinifierAsset::isPreMinifiedJs($entry['cleanJsFile'])
        );

        if ($allMinifiable && count($entries) > 1) {
            $minifier = new Minify\JS();

            foreach ($entries as $entry) {
                $minifier->addFile($entry['path']);
            }

            $comment = '/* Files: ' . implode(', ', array_column($entries, 'cleanJsFile')) . " */\n";

            return $comment . $minifier->minify() . "\n";
        }

        $content = '';

        foreach ($entries as $entry) {
            $fileContent = $this->getJsContentForCombine($entry['path'], $entry['cleanJsFile']);
            $content .= "/* File: {$entry['cleanJsFile']} */\n"
                . MinifierAsset::prepareJsCombineSegment($fileContent);
        }

        return $content;
    }

    protected function getJsContentForCombine(string $jsPath, string $cleanJsFile): string
    {
        if (MinifierAsset::isPreMinifiedJs($cleanJsFile)) {
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
                $fileContent,
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
     * Builds a combined CSS cache file and returns HTML replacements for one block
     *
     * @param array $linkMatches preg_match_all results for link tags
     * @param array $block Combined block data with content and indices
     * @return array{replacements: array<int, array{offset: int, length: int, replacement: string}>, cacheDir: string}|null
     */
    protected function prepareCombinedCssBlock(array $linkMatches, array $block, string $targetPath): ?array
    {
        $combinedContent = $this->buildCssBlockContent($block['files'], $targetPath);
        $tagsToRemove = $block['indices'];
        $firstMatchIndex = $tagsToRemove[0];

        if ($combinedContent === '') {
            return null;
        }

        try {
            $hash = substr(md5($combinedContent), 0, 8);
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
            $combinedTag = '<link href="' . $combinedUrl . '" rel="stylesheet">';
            $replacements = [];

            foreach ($tagsToRemove as $index) {
                $replacements[] = [
                    'offset' => $this->getTagOffset($linkMatches, $index),
                    'length' => strlen($this->getTagString($linkMatches, $index)),
                    'replacement' => $index === $firstMatchIndex ? $combinedTag : '',
                ];
            }

            return [
                'replacements' => $replacements,
                'cacheDir' => $cacheDir,
            ];
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
     * Builds a combined JS cache file and returns HTML replacements for one block
     *
     * @param string $body Page HTML content
     * @param array $scriptMatches preg_match_all results for script tags
     * @param array $block Combined block data with files and indices
     * @param array<int, string> $excludeArray Paths to exclude from combination
     * @param string $rootPath Joomla root path
     * @param bool $combineAll Whether pre-minified files are included in the block
     * @return array{replacements: array<int, array{offset: int, length: int, replacement: string}>, cacheDir: string}|null
     */
    protected function prepareCombinedJsBlock(
        string $body,
        array $scriptMatches,
        array $block,
        array $excludeArray,
        string $rootPath,
        bool $combineAll
    ): ?array {
        $combinedContent = $this->buildJsCombineContent($block['files']);
        $tagsToRemove = $block['indices'];
        $anchorIndex = $this->resolveJsCombineAnchorIndex(
            $scriptMatches,
            $tagsToRemove,
            $excludeArray,
            $rootPath,
            $combineAll
        );
        $anchorInBlock = in_array($anchorIndex, $tagsToRemove, true);

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
            $combinedTag = '<script src="' . $combinedUrl . '"></script>';
            $replacements = [];

            foreach ($tagsToRemove as $index) {
                if ($anchorInBlock && $index === $anchorIndex) {
                    continue;
                }

                $replacements[] = [
                    'offset' => $this->getTagOffset($scriptMatches, $index),
                    'length' => $this->getScriptElementLength($body, $scriptMatches, $index),
                    'replacement' => '',
                ];
            }

            if ($anchorInBlock) {
                $replacements[] = [
                    'offset' => $this->getTagOffset($scriptMatches, $anchorIndex),
                    'length' => $this->getScriptElementLength($body, $scriptMatches, $anchorIndex),
                    'replacement' => $combinedTag,
                ];
            } else {
                $anchorOffset = $this->getTagOffset($scriptMatches, $anchorIndex);
                $anchorLength = $this->getScriptElementLength($body, $scriptMatches, $anchorIndex);

                $replacements[] = [
                    'offset' => $anchorOffset + $anchorLength,
                    'length' => 0,
                    'replacement' => $combinedTag,
                ];
            }

            return [
                'replacements' => $replacements,
                'cacheDir' => $cacheDir,
            ];
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

