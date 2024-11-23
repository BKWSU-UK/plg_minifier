<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use MatthiasMullie\Minify;
use Joomla\CMS\Log\Log;

class PlgSystemMinifier extends CMSPlugin
{
    protected $app;
    protected $autoloadLanguage = true;
    protected $logCategory = 'plg_system_minifier';
    
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
            if ($this->shouldSkipFile($cleanCssFile)) {
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

        return $body;
    }

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

    protected function shouldSkipFile($cssFile)
    {
        return (strpos($cssFile, '//') === 0 || 
                strpos($cssFile, 'http') === 0 || 
                strpos($cssFile, '.min.css') !== false);
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
} 