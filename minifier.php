<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.minifier
 *
 * @copyright   (C) 2024 Brahma Kumaris. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

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
        preg_match_all('/<link[^>]+href=([\'"])(.*?\.css(?:\?[^"\']*)?)\1[^>]*>/i', $body, $matches);

        if (empty($matches[2])) {
            return $body;
        }

        $rootPath = str_replace('\\', '/', JPATH_ROOT);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

    // If combine_css is enabled, collect all CSS content
    if ($this->params->get('combine_css', 0)) {
        $combinedContent = '';
        $firstMatchIndex = null;
        $processedFiles = [];
        $tagsToRemove = [];
        $combineAll = $this->params->get('combine_all_css', 0);

        // Process files in the exact order they appear
        foreach ($matches[2] as $index => $cssFile) {
            // Extract query parameters
            if (strpos($cssFile, '?') !== false) {
                list($cleanCssFile, $queryString) = explode('?', $cssFile, 2);
            } else {
                $cleanCssFile = $cssFile;
            }

            // Skip if file matches excluded paths
            if ($this->isExcluded($cleanCssFile, $excludeArray)) {
                continue;
            }

            // Skip external files
            if (strpos($cleanCssFile, '//') === 0 || strpos($cleanCssFile, 'http') === 0) {
                continue;
            }

            $cssPath = $this->resolvePath($cleanCssFile, $rootPath);
            if ($cssPath === false) {
                continue;
            }
            if (file_exists($cssPath) && !in_array($cssPath, $processedFiles)) {
                // For minified files, only add if combine_all_css is enabled
                if (strpos($cleanCssFile, '.min.css') !== false && !$combineAll) {
                    continue;
                }

                if ($this->params->get('debug', 0)) {
                    $this->app->enqueueMessage(sprintf('Adding file to combination: %s', $cssPath), 'debug');
                }

                // Track the first file we're combining for insertion point
                if ($firstMatchIndex === null) {
                    $firstMatchIndex = $index;
                }

                // Read the file content directly to maintain order
                $fileContent = file_get_contents($cssPath);
                
                // Minify only if it's not already minified
                if (strpos($cleanCssFile, '.min.css') === false) {
                    $minifier = new Minify\CSS($fileContent);
                    $fileContent = $minifier->minify();
                }

                $combinedContent .= "/* File: {$cleanCssFile} */\n" . $fileContent . "\n";
                $processedFiles[] = $cssPath;

                // Mark this tag for removal
                $tagsToRemove[] = $index;
            }
        }

        if ($combinedContent && $firstMatchIndex !== null) {
            try {
                // Generate hash for the combined content
                $hash = substr(md5($combinedContent), 0, 8);
                
                // Create the combined file
                $combinedFilename = "combined-{$hash}.css";
                $combinedPath = JPATH_ROOT . self::CSS_CACHE_DIR . $combinedFilename;
                
                // Ensure directory exists
                $cacheDir = dirname($combinedPath);
                if (!is_dir($cacheDir)) {
                    if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                        throw new \RuntimeException('Failed to create cache directory: ' . $cacheDir);
                    }
                }

                // Save the combined file
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

                // Replace the first CSS file with the combined one
                $combinedUrl = Uri::root(true) . self::CSS_CACHE_DIR . $combinedFilename;
                $combinedTag = '<link href="' . $combinedUrl . '" rel="stylesheet">';
                $body = str_replace($matches[0][$firstMatchIndex], $combinedTag, $body);
                
                // Remove all other CSS tags that were combined (skip the first one as we already replaced it)
                foreach ($tagsToRemove as $index) {
                    if ($index !== $firstMatchIndex) {
                        $body = str_replace($matches[0][$index], '', $body);
                    }
                }
                
                // Clean up old combined files
                $this->cleanupCombinedFiles($cacheDir, 'combined-', '.css', self::CACHE_FILES_TO_KEEP);
            } catch (\Exception $e) {
                Log::add(
                    'Failed to create combined CSS file: ' . $e->getMessage(),
                    Log::ERROR,
                    $this->logCategory
                );
            }
        }
    }
        // Add this else block to handle individual CSS minification when combine is disabled
        else {
            // Process individual files
            foreach ($matches[2] as $index => $cssFile) {
                // Extract query parameters
                $queryString = '';
                if (strpos($cssFile, '?') !== false) {
                    list($cleanCssFile, $queryString) = explode('?', $cssFile, 2);
                    $queryString = '?' . $queryString;
                } else {
                    $cleanCssFile = $cssFile;
                }
                
                Log::add('CSS File: ' . $cssFile, Log::DEBUG, $this->logCategory);
                
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
                        $body = str_replace($matches[0][$index], 
                            str_replace($cssFile, $minifiedUrl . $queryString, $matches[0][$index]), 
                            $body);
                    }
                } catch (Exception $e) {
                    Log::add('CSS Minification failed: ' . $e->getMessage() . ' for file: ' . $cssFile, Log::ERROR, $this->logCategory);
                    continue;
                }
            }
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
        preg_match_all('/<script[^>]+src=([\'"])(.*?\.js(?:\?[^"\']*)?)\1[^>]*>/i', $body, $matches);

        if (empty($matches[2])) {
            return $body;
        }

        $rootPath = str_replace('\\', '/', JPATH_ROOT);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

        // If combine_js is enabled, collect all JS content
        if ($this->params->get('combine_js', 0)) {
            $combinedContent = '';
            $insertAfterIndex = -1; // Track where to insert the combined file
            $processedFiles = [];
            $skippedTags = []; // Track tags we skip but don't want to remove
            $combineAll = $this->params->get('combine_all_js', 0);
            $tagsToRemove = [];

            // Process files in the exact order they appear
            foreach ($matches[2] as $index => $jsFile) {
                // Extract query parameters
                if (strpos($jsFile, '?') !== false) {
                    list($cleanJsFile, $queryString) = explode('?', $jsFile, 2);
                } else {
                    $cleanJsFile = $jsFile;
                }

                // Skip if file matches excluded paths
                if ($this->isExcluded($cleanJsFile, $excludeArray)) {
                    // Track that we're keeping this tag
                    $skippedTags[] = $index;
                    // Insert combined file after the last skipped tag
                    if ($insertAfterIndex < $index) {
                        $insertAfterIndex = $index;
                    }
                    continue;
                }

                // Skip external files
                if (strpos($cleanJsFile, '//') === 0 || strpos($cleanJsFile, 'http') === 0) {
                    $skippedTags[] = $index;
                    if ($insertAfterIndex < $index) {
                        $insertAfterIndex = $index;
                    }
                    continue;
                }

                $jsPath = $this->resolvePath($cleanJsFile, $rootPath);
                if ($jsPath === false) {
                    $skippedTags[] = $index;
                    if ($insertAfterIndex < $index) {
                        $insertAfterIndex = $index;
                    }
                    continue;
                }
                
                if (file_exists($jsPath) && !in_array($jsPath, $processedFiles)) {
                    // For minified files, only add if combine_all_js is enabled
                    if (strpos($cleanJsFile, '.min.js') !== false && !$combineAll) {
                        // Keep pre-minified files in their original position
                        $skippedTags[] = $index;
                        // Insert combined file after this pre-minified file
                        if ($insertAfterIndex < $index) {
                            $insertAfterIndex = $index;
                        }
                        if ($this->params->get('debug', 0)) {
                            Log::add(
                                sprintf('Skipping pre-minified file (not combining): %s', $cleanJsFile),
                                Log::DEBUG,
                                $this->logCategory
                            );
                        }
                        continue;
                    }

                    if ($this->params->get('debug', 0)) {
                        Log::add(
                            sprintf('Adding JS file to combination: %s', $jsPath),
                            Log::DEBUG,
                            $this->logCategory
                        );
                    }

                    // Read the file content directly to maintain order
                    $fileContent = file_get_contents($jsPath);
                    
                    // Minify only if it's not already minified
                    if (strpos($cleanJsFile, '.min.js') === false) {
                        $minifier = new Minify\JS($fileContent);
                        $fileContent = $minifier->minify();
                    }

                    $combinedContent .= "/* File: {$cleanJsFile} */\n" . $fileContent . "\n";
                    $processedFiles[] = $jsPath;
                    
                    // Mark this tag for removal
                    $tagsToRemove[] = $index;
                }
            }

            if ($combinedContent) {
                try {
                    // Generate hash for the combined content
                    $hash = substr(md5($combinedContent), 0, 8);
                    
                    // Create the combined file
                    $combinedFilename = "combined-{$hash}.js";
                    $combinedPath = JPATH_ROOT . self::JS_CACHE_DIR . $combinedFilename;
                    
                    // Ensure directory exists
                    $cacheDir = dirname($combinedPath);
                    if (!is_dir($cacheDir)) {
                        if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                            throw new \RuntimeException('Failed to create cache directory: ' . $cacheDir);
                        }
                    }

                    // Save the combined file
                    if (file_put_contents($combinedPath, $combinedContent) === false) {
                        throw new \RuntimeException('Failed to write combined JS file: ' . $combinedPath);
                    }

                    if ($this->params->get('debug', 0)) {
                        Log::add(
                            sprintf('Created combined JS file: %s (will insert after index %d)', $combinedFilename, $insertAfterIndex),
                            Log::INFO,
                            $this->logCategory
                        );
                    }

                    $combinedUrl = Uri::root(true) . self::JS_CACHE_DIR . $combinedFilename;
                    $combinedTag = '<script src="' . $combinedUrl . '"></script>';
                    
                    // If we have a position to insert after, insert there
                    // Otherwise, insert at the position of the first combined file
                    if ($insertAfterIndex >= 0 && isset($matches[0][$insertAfterIndex])) {
                        // Insert after the last skipped tag
                        $insertAfterPattern = preg_quote($matches[0][$insertAfterIndex], '/') . '(?:\s*<\/script>)?';
                        $body = preg_replace('/' . $insertAfterPattern . '/', '$0' . "\n" . $combinedTag, $body, 1);
                    } elseif (!empty($tagsToRemove)) {
                        // Insert at the position of the first combined file
                        $firstCombinedIndex = $tagsToRemove[0];
                        $firstMatchPattern = preg_quote($matches[0][$firstCombinedIndex], '/') . '(?:\s*<\/script>)?';
                        $body = preg_replace('/' . $firstMatchPattern . '/', $combinedTag, $body, 1);
                        // Remove the first index from tagsToRemove since we already handled it
                        array_shift($tagsToRemove);
                    }
                    
                    // Remove all other tags that were combined
                    foreach ($tagsToRemove as $index) {
                        $scriptPattern = preg_quote($matches[0][$index], '/') . '(?:\s*<\/script>)?';
                        $body = preg_replace('/' . $scriptPattern . '/', '', $body);
                    }
                    
                    // Clean up old combined files
                    $this->cleanupCombinedFiles($cacheDir, 'combined-', '.js', self::CACHE_FILES_TO_KEEP);
                } catch (\Exception $e) {
                    Log::add(
                        'Failed to create combined JS file: ' . $e->getMessage(),
                        Log::ERROR,
                        $this->logCategory
                    );
                }
            }

            return $body;
        }

        // Only process individual files if js_enabled is true
        elseif ($this->params->get('js_enabled', 1)) {
            // Process individual files
            foreach ($matches[2] as $index => $jsFile) {
                // Extract query parameters
                $queryString = '';
                if (strpos($jsFile, '?') !== false) {
                    list($cleanJsFile, $queryString) = explode('?', $jsFile, 2);
                    $queryString = '?' . $queryString;
                } else {
                    $cleanJsFile = $jsFile;
                }
                
                Log::add('JS File: ' . $jsFile, Log::DEBUG, $this->logCategory);
                
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
                        $body = str_replace($matches[0][$index], 
                            str_replace($jsFile, $minifiedUrl . $queryString, $matches[0][$index]), 
                            $body);
                    }
                } catch (Exception $e) {
                    Log::add('JS Minification failed: ' . $e->getMessage() . ' for file: ' . $jsFile, Log::ERROR, $this->logCategory);
                    continue;
                }
            }
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
            return (strpos($cssFile, '//') === 0 || strpos($cssFile, 'http') === 0);
        }
        
        // Original skip logic for non-combine-all mode
        return (strpos($cssFile, '//') === 0 || 
                strpos($cssFile, 'http') === 0 || 
                strpos($cssFile, '.min.css') !== false);
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
        // Handle absolute paths starting with /
        if (strpos($file, '/') === 0) {
            $resolved = $rootPath . $file;
        }
        // Check if file is from a module (contains /modules/)
        elseif (strpos($file, '/modules/') !== false) {
            // Extract the path after /modules/
            $modulePath = substr($file, strpos($file, '/modules/'));
            $resolved = $rootPath . $modulePath;
        }
        // Check if file is from media folder
        elseif (strpos($file, '/media/') !== false) {
            // Extract the path after /media/
            $mediaPath = substr($file, strpos($file, '/media/'));
            $resolved = $rootPath . $mediaPath;
        }
        // Default case for relative paths
        else {
            $resolved = $rootPath . '/' . $file;
        }
        
        // Security: Verify the resolved path is within JPATH_ROOT
        $realPath = realpath($resolved);
        $realRoot = realpath($rootPath);
        
        // If realpath fails, the file doesn't exist yet, so check the directory
        if ($realPath === false) {
            $dirPath = dirname($resolved);
            $realPath = realpath($dirPath);
            if ($realPath !== false) {
                $realPath = $realPath . '/' . basename($resolved);
            }
        }
        
        // Ensure the path is within the root directory
        if ($realPath !== false && $realRoot !== false) {
            if (strpos($realPath, $realRoot) !== 0) {
                Log::add(
                    sprintf('Security: Path traversal attempt detected: %s resolves outside root', $file),
                    Log::WARNING,
                    $this->logCategory
                );
                return false;
            }
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
        if (strpos($jsFile, '//') === 0 || strpos($jsFile, 'http') === 0) {
            return true;
        }

        // Skip already minified files
        if (strpos($jsFile, '.min.js') !== false) {
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
            } catch (Exception $e) {
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
            } catch (Exception $e) {
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
            } catch (Exception $e) {
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
            } catch (Exception $e) {
                Log::add('Failed to delete old combined file: ' . $file, Log::WARNING, $this->logCategory);
            }
        }
    }
} 