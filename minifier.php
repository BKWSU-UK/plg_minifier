<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use MatthiasMullie\Minify;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;

class PlgSystemMinifier extends CMSPlugin
{
    /** @var JApplicationCms Application object */
    protected $app;
    
    /** @var boolean Auto load language files */
    protected $autoloadLanguage = true;
    
    /** @var string Category for logging */
    protected $logCategory = 'plg_system_minifier';
    
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
     */
    public function onAfterRender()
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
     * @param string $body Page HTML content
     * @return string Modified HTML content
     */
    protected function processCssFiles($body)
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
            $firstMatch = null;
            $processedFiles = [];
            $combineAll = $this->params->get('combine_all_css', 0);

            // Process files in the exact order they appear
            foreach ($matches[2] as $index => $cssFile) {
                // Save first match for replacement
                if ($firstMatch === null) {
                    $firstMatch = $matches[0][$index];
                }

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
                if (file_exists($cssPath) && !in_array($cssPath, $processedFiles)) {
                    // For minified files, only add if combine_all_css is enabled
                    if (strpos($cleanCssFile, '.min.css') !== false && !$combineAll) {
                        continue;
                    }

                    if ($this->params->get('debug', 0)) {
                        $this->app->enqueueMessage(sprintf('Adding file to combination: %s', $cssPath), 'debug');
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

                    // Remove this CSS link from body except for the first one
                    if ($firstMatch !== $matches[0][$index]) {
                        $body = str_replace($matches[0][$index], '', $body);
                    }
                }
            }

            if ($combinedContent && $firstMatch) {
                // Generate hash for the combined content
                $hash = substr(md5($combinedContent), 0, 8);
                
                // Create the combined file
                $combinedFilename = "combined-{$hash}.css";
                $combinedPath = JPATH_ROOT . '/media/cache/css/' . $combinedFilename;
                
                // Ensure directory exists
                $cacheDir = dirname($combinedPath);
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }

                // Save the combined file
                file_put_contents($combinedPath, $combinedContent);

                if ($this->params->get('debug', 0)) {
                    $this->app->enqueueMessage(
                        sprintf('Created combined CSS file: %s', $combinedFilename),
                        'debug'
                    );
                }

                // Replace the first CSS link with the combined file
                $combinedUrl = Uri::root(true) . '/media/cache/css/' . $combinedFilename;
                $replacement = '<link href="' . $combinedUrl . '" rel="stylesheet">';
                $body = str_replace($firstMatch, $replacement, $body);
            }
        }

        return $body;
    }

    /**
     * Processes and minifies JavaScript files found in the page
     * @param string $body Page HTML content
     * @return string Modified HTML content
     */
    protected function processJsFiles($body)
    {
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
            $firstMatch = null;
            $processedFiles = [];
            $combineAll = $this->params->get('combine_all_js', 0);

            // Process files in the exact order they appear
            foreach ($matches[2] as $index => $jsFile) {
                // Save first match for replacement
                if ($firstMatch === null) {
                    $firstMatch = $matches[0][$index];
                }

                // Extract query parameters
                if (strpos($jsFile, '?') !== false) {
                    list($cleanJsFile, $queryString) = explode('?', $jsFile, 2);
                } else {
                    $cleanJsFile = $jsFile;
                }

                // Skip if file matches excluded paths
                if ($this->isExcluded($cleanJsFile, $excludeArray)) {
                    continue;
                }

                // Skip external files
                if (strpos($cleanJsFile, '//') === 0 || strpos($cleanJsFile, 'http') === 0) {
                    continue;
                }

                $jsPath = $this->resolvePath($cleanJsFile, $rootPath);
                if (file_exists($jsPath) && !in_array($jsPath, $processedFiles)) {
                    // For minified files, only add if combine_all_js is enabled
                    if (strpos($cleanJsFile, '.min.js') !== false && !$combineAll) {
                        continue;
                    }

                    if ($this->params->get('debug', 0)) {
                        $this->app->enqueueMessage(sprintf('Adding JS file to combination: %s', $jsPath), 'debug');
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

                    // Remove this JS script tag from body except for the first one
                    if ($firstMatch !== $matches[0][$index]) {
                        // Replace entire script tag instead of just the matched pattern
                        $scriptPattern = preg_quote($matches[0][$index], '/') . '(?:\s*<\/script>)?';
                        $body = preg_replace('/' . $scriptPattern . '/', '', $body);
                    }
                }
            }

            if ($combinedContent && $firstMatch) {
                // Generate hash for the combined content
                $hash = substr(md5($combinedContent), 0, 8);
                
                // Create the combined file
                $combinedFilename = "combined-{$hash}.js";
                $combinedPath = JPATH_ROOT . '/media/cache/js/' . $combinedFilename;
                
                // Ensure directory exists
                $cacheDir = dirname($combinedPath);
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }

                // Save the combined file
                file_put_contents($combinedPath, $combinedContent);

                if ($this->params->get('debug', 0)) {
                    $this->app->enqueueMessage(
                        sprintf('Created combined JS file: %s', $combinedFilename),
                        'debug'
                    );
                }

                // Replace the first JS script tag with the combined file
                $combinedUrl = Uri::root(true) . '/media/cache/js/' . $combinedFilename;
                $replacement = '<script src="' . $combinedUrl . '"></script>';
                
                // Create pattern to match the entire script tag including its closing tag
                $firstMatchPattern = preg_quote($firstMatch, '/') . '(?:\s*<\/script>)?';
                $body = preg_replace('/' . $firstMatchPattern . '/', $replacement, $body, 1);
            }

            return $body;
        }

        // If not combining, proceed with individual file minification
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

        return $body;
    }

    /**
     * Determines if a CSS file should be skipped (external or already minified)
     */
    protected function shouldSkipFile($cssFile)
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
     * @param string $file Original file path
     * @param string $rootPath Joomla root path
     * @return string Resolved absolute file path
     */
    protected function resolvePath($file, $rootPath)
    {
        // Handle absolute paths starting with /
        if (strpos($file, '/') === 0) {
            return $rootPath . $file;
        }
        
        // Check if file is from a module (contains /modules/)
        if (strpos($file, '/modules/') !== false) {
            // Extract the path after /modules/
            $modulePath = substr($file, strpos($file, '/modules/'));
            return $rootPath . $modulePath;
        }
        
        // Check if file is from media folder
        if (strpos($file, '/media/') !== false) {
            // Extract the path after /media/
            $mediaPath = substr($file, strpos($file, '/media/'));
            return $rootPath . $mediaPath;
        }
        
        // Default case for relative paths
        return $rootPath . '/' . $file;
    }

    protected function shouldSkipJsFile($jsFile)
    {
        return (strpos($jsFile, '//') === 0 || 
                strpos($jsFile, 'http') === 0 || 
                strpos($jsFile, '.min.js') !== false);
    }

    protected function isExcluded($cssFile, $excludeArray)
    {
        foreach ($excludeArray as $excludePath) {
            if (!empty($excludePath) && strpos($cssFile, $excludePath) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function minifyCssFile($cssFile, $rootPath)
    {
        // Handle relative and absolute paths
        $cssPath = $this->resolvePath($cssFile, $rootPath);
        
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
        if (!file_exists($minifiedPath) || filemtime($cssPath) > filemtime($minifiedPath)) {
            $minifier = new Minify\CSS($cssPath);
            $minifier->minify($minifiedPath);
        }

        return dirname($cssFile) . '/' . File::stripExt(basename($cssFile)) . '.min.css';
    }

    protected function minifyJsFile($jsFile, $rootPath)
    {
        // Handle relative and absolute paths
        $jsPath = $this->resolvePath($jsFile, $rootPath);
        
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
        if (!file_exists($minifiedPath) || filemtime($jsPath) > filemtime($minifiedPath)) {
            $minifier = new Minify\JS($jsPath);
            $minifier->minify($minifiedPath);
        }

        return dirname($jsFile) . '/' . File::stripExt(basename($jsFile)) . '.min.js';
    }

    protected function ensureWritableDirectory($directory)
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
     * @param string $directory Directory to clean
     * @param string $prefix File prefix to match
     * @param string $suffix File suffix to match
     * @param int $keep Number of files to keep
     */
    protected function cleanupCombinedFiles($directory, $prefix, $suffix, $keep = 5)
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