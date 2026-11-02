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
require_once __DIR__ . '/helper/MinifierBundleCache.php';

use Joomla\CMS\Plugin\CMSPlugin;
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
        $baseUrl = Uri::root(true);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

        // If combine_css is enabled, replace each planned block of stylesheets with one bundle
        if ($this->params->get('combine_css', 0)) {
            $plan = MinifierAssetPlan::stylesheets(
                $rootPath,
                array_values($excludeArray),
                (bool) $this->params->get('combine_all_css', 0),
                $this->logger(),
                $baseUrl
            );

            return $this->combineBlocks($tags, $plan, MinifierAssetType::Css, $baseUrl);
        }

        // Process individual files
        $minifier = new MinifierSiblingMinifier($rootPath, array_values($excludeArray), $this->logger(), $baseUrl);

        for ($index = 0; $index < $tags->count(); $index++) {
            $minifiedUrl = $minifier->minify($tags->url($index), MinifierAssetType::Css);

            if ($minifiedUrl !== null) {
                $tags->replaceUrl($index, $minifiedUrl);
            }
        }

        return $this->applyEdits($tags, MinifierAssetType::Css);
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
        $baseUrl = Uri::root(true);
        $excludePaths = $this->params->get('exclude_paths', '');
        $excludeArray = array_filter(array_map('trim', explode("\n", $excludePaths)));

        // If combine_js is enabled, replace each planned block of scripts with one bundle
        if ($this->params->get('combine_js', 0)) {
            $plan = MinifierAssetPlan::scripts(
                $rootPath,
                array_values($excludeArray),
                (bool) $this->params->get('combine_all_js', 0),
                $this->logger(),
                $baseUrl
            );

            return $this->combineBlocks($tags, $plan, MinifierAssetType::Js, $baseUrl);
        }

        // Process individual files
        $minifier = new MinifierSiblingMinifier($rootPath, array_values($excludeArray), $this->logger(), $baseUrl);

        for ($index = 0; $index < $tags->count(); $index++) {
            $minifiedUrl = $minifier->minify($tags->url($index), MinifierAssetType::Js);

            if ($minifiedUrl !== null) {
                $tags->replaceUrl($index, $minifiedUrl);
            }
        }

        return $this->applyEdits($tags, MinifierAssetType::Js);
    }

    /**
     * Log sink for the helper modules: debug and info messages only when the debug param is on
     *
     * @return \Closure(string $level, string $message): void
     */
    protected function logger(): \Closure
    {
        $debug = (bool) $this->params->get('debug', 0);

        return function (string $level, string $message) use ($debug): void {
            if (($level === 'debug' || $level === 'info') && !$debug) {
                return;
            }

            $priority = match ($level) {
                'error' => Log::ERROR,
                'warning' => Log::WARNING,
                'info' => Log::INFO,
                default => Log::DEBUG,
            };

            Log::add($message, $priority, $this->logCategory);
        };
    }

    /**
     * Replaces each planned block with one cached bundle and prunes old bundles
     */
    protected function combineBlocks(
        MinifierHtmlReplacements $tags,
        MinifierAssetPlan $plan,
        MinifierAssetType $type,
        string $baseUrl
    ): string {
        $cache = new MinifierBundleCache(JPATH_ROOT, $baseUrl, $this->logger());
        $written = false;

        foreach ($plan->blocks($tags) as $block) {
            $bundle = $cache->write($block->files, $type);

            if ($bundle !== null) {
                $block->placeBundle($tags, $bundle->html());
                $written = true;
            }
        }

        $body = $this->applyEdits($tags, $type);

        if ($written) {
            $cache->prune($type);
        }

        return $body;
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
}
