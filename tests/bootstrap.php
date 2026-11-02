<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/helper/MinifierAsset.php';
require dirname(__DIR__) . '/helper/MinifierCssAssetPaths.php';
require dirname(__DIR__) . '/helper/MinifierHtmlReplacements.php';
require dirname(__DIR__) . '/helper/MinifierAssetType.php';
require dirname(__DIR__) . '/helper/MinifierAssetLocator.php';
require dirname(__DIR__) . '/helper/MinifierSiblingMinifier.php';
require dirname(__DIR__) . '/helper/MinifierAssetPlan.php';
require dirname(__DIR__) . '/helper/MinifierBundleCache.php';

if (!defined('JPATH_ROOT')) {
    define('JPATH_ROOT', dirname(__DIR__) . '/tests/fixtures/joomla');
}
