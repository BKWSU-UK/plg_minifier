# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.15] - 2025-06-28

### Fixed
- JavaScript combination now splits into separate blocks when an inline `<script>` tag appears between external script files, preserving configuration scripts (such as GDPR `gdprConfigurationOptions`) that must execute before dependent bundled files

### Added
- `MinifierAsset::gapContainsInlineScript()` to detect inline scripts between external JavaScript tags

## [1.0.14] - 2025-06-28

### Fixed
- **Critical**: JavaScript combination now inserts semicolon boundaries between concatenated files, preventing files ending with `})` from being parsed as a function call on the next file (fixes `$(...) is not a function` when template scripts precede Joomla core scripts)
- Combined JavaScript output now strips `sourceMappingURL` comments from bundled files to avoid spurious 404 errors in browser developer tools

### Added
- `MinifierAsset::prepareJsCombineSegment()` for safe JavaScript file concatenation

## [1.0.13] - 2025-06-28

### Fixed
- **Critical**: JavaScript combination now removes complete `<script src="..."></script>` elements instead of only the opening tag, preventing orphaned `</script>` tags and corrupted page markup
- **Critical**: jQuery and `jquery-noconflict` are now ordered before other scripts inside combined JavaScript bundles, preventing "jQuery is not defined" errors when template scripts load before jQuery in the page HTML
- Combined JavaScript files are now inserted after any immediately following jQuery dependency scripts when pre-minified files are excluded from combination

### Added
- `MinifierAsset::sortJsCombineEntries()` and jQuery dependency detection helpers
- `MinifierHtmlReplacements::externalScriptElementLength()` for full external script tag replacement

## [1.0.12] - 2025-06-28

### Added
- Joomla automatic update support via GitHub-hosted update server manifest and GitHub release packages
- Build script now generates `updates/plg_system_minifier.xml` with package checksums for update verification
- GitHub Actions release workflow that builds, publishes release assets, and appends a new manifest entry on `main` when a `v*` tag is pushed

## [1.0.11] - 2025-06-28

### Fixed
- CSS `url()` replacement now applies offset-based updates per match instead of global `str_replace`
- Duplicate local CSS and JS `<link>` / `<script>` tags are now removed when the same file appears more than once in a combine block
- External asset detection is now case-insensitive for `http://` and `https://` URLs
- Path traversal checks now normalise `..` segments when `realpath()` is unavailable
- JavaScript combination now preserves load order using contiguous blocks, matching CSS behaviour
- Pre-minified file detection now uses file extension checks instead of substring matching

### Added
- `MinifierAsset` helper for external URL detection, path normalisation, and root path validation
- `MinifierHtmlReplacements` helper for testable offset-based HTML updates
- `MinifierAsset::resolveWebPath()` for testable web path resolution with security checks
- Expanded PHPUnit coverage for path resolution, traversal rejection, and pre-minified file detection

### Changed
- Renamed `helper/CssAssetPaths.php` to `helper/MinifierCssAssetPaths.php` to match the class name
- `resolvePath()` now delegates to `MinifierAsset::resolveWebPath()`
- Build script now runs tests before packaging, installs production dependencies in an isolated staging directory, and excludes `vendor/bin` from release archives

## [1.0.10] - 2025-06-28

### Fixed
- **Critical**: Multiple CSS combine blocks now apply all HTML replacements in a single pass using original offsets, preventing corrupted markup when contiguous blocks are separated by external or excluded stylesheets
- CSS combination now uses `execute()` instead of `minify()` for path conversion, avoiding spurious writes to `combined.css`
- `resolvePath()` now validates paths with a directory-boundary check to prevent prefix-based traversal outside `JPATH_ROOT`

## [1.0.9] - 2025-06-28

### Fixed
- HTML tag replacement now uses offset-based updates to avoid incorrect replacements when duplicate tags exist
- Removed misleading namespace declaration from the plugin manifest

### Added
- `MinifierCssAssetPaths` helper class for testable CSS path handling
- PHPUnit test suite for CSS asset path conversion
- Offset-based `applyHtmlReplacements()` helper for safe HTML manipulation

### Changed
- CSS and JS combination now batch multiple minifiable files through a single minifier instance where possible
- JavaScript combination now uses `addFile()` consistently with CSS, skipping re-minification of `.min.js` files
- CSS asset path logic extracted to `helper/CssAssetPaths.php`

## [1.0.8] - 2025-06-28

### Fixed
- Pre-minified `.min.css` files are no longer re-minified when included in CSS combination; asset paths are relocated instead
- All `catch (Exception $e)` blocks now use `catch (\Exception $e)` for namespace-safe exception handling
- Debug-level file logging is now gated behind the debug setting

### Added
- `getCssContentForCombine()` method to handle minified and pre-minified CSS files appropriately
- `relocateCssAssetPaths()` and `replaceCssAssetPaths()` methods using the path-converter library for `url()` and `@import` handling

## [1.0.7] - 2025-06-28

### Fixed
- **Critical**: Replaced custom `convertCssUrls()` with the minify library's path converter via `minify($targetPath)`, preserving root-relative URLs and correctly resolving `../` paths
- **Critical**: Root-relative asset URLs in combined CSS are now prefixed with the Joomla base path for subdirectory installs
- **Critical**: CSS files separated by excluded, external, or pre-minified stylesheets are now combined into separate contiguous blocks, preserving cascade order

### Removed
- Removed `convertCssUrls()` method in favour of library path conversion and `prefixSubdirectoryCssUrls()`

### Added
- `writeCombinedCssBlock()` method for writing each contiguous CSS combination block
- `prefixSubdirectoryCssUrls()` method for subdirectory base path handling

## [1.0.6] - 2024-11-22

### Fixed
- **Critical**: Fixed CSS cascade order issue when "Combine CSS Files" is enabled
- **Critical**: Fixed Bootstrap icons and other assets not displaying when CSS files are combined
- Combined CSS file now replaces the first combined CSS file instead of being inserted at the end of `</head>`
- This preserves the original CSS cascade order and prevents styles from being overridden incorrectly
- Relative URLs in CSS (fonts, icons, images) are now converted to absolute paths when combining files

### Added
- New `convertCssUrls()` method to properly convert relative URLs in CSS to absolute paths
- Proper handling of CSS url() references including fonts, icons, and background images

### Changed
- CSS combination logic now tracks the first combined file position and uses it as the insertion point
- Improved handling of CSS file removal to prevent disrupting the document structure
- CSS files are now processed using `addFile()` method for proper minification with path context

### Technical Details
When combining CSS files, the plugin now:
1. Maintains the original CSS cascade order by inserting the combined file at the position of the first CSS file that was combined
2. Converts all relative URLs (fonts, icons, images) to absolute paths so they continue to work from the new combined file location
3. Uses the matthiasmullie/minify library's `addFile()` method to ensure proper path handling during minification

This ensures that CSS specificity and cascade rules work as expected, and that all CSS assets (Bootstrap icons, fonts, etc.) load correctly.

## [1.0.5] - 2024-11-22

### Fixed
- **Critical**: Fixed JavaScript load order issue where combined file was placed before dependencies (jQuery)
- Combined JavaScript file now loads AFTER all skipped/excluded files (jQuery, external scripts, pre-minified when not combining all)
- This prevents "jQuery is not defined" errors when jQuery is loaded separately from Joomla's asset manager

### Changed
- Improved script insertion logic to track the last skipped file and insert combined file after it
- Better handling of dependency order for scripts that rely on libraries loaded outside the combination

### Technical Details
The plugin now tracks which script tags are skipped (excluded paths, external URLs, pre-minified files when `combine_all_js` is disabled) and inserts the combined file **after** the last skipped tag. This ensures that dependencies like jQuery, which may be loaded by Joomla's WebAssetManager or excluded from combination, are available before the combined scripts execute.

## [1.0.4] - 2024-11-22

### Fixed
- Improved handling of pre-minified JavaScript files to prevent "jQuery is not defined" errors
- Better tracking of skipped files (excluded, external, and pre-minified) to maintain proper script order
- Standardised logging to use `Log::add()` instead of mixed `enqueueMessage()` in JS combination

### Changed
- Updated language strings to clarify that pre-minified files are excluded by default
- Added warning about potential jQuery dependency issues when "Include Pre-Minified JS" is disabled
- Improved debug logging to show when pre-minified files are skipped

### Added
- Comprehensive troubleshooting section in README for "jQuery is not defined" error
- Better documentation of the "Include Pre-Minified JS" setting and its impact

## [1.0.3] - 2024-11-22

### Fixed
- Fixed incorrect type hint from `JApplicationCms` to `CMSApplicationInterface` for Joomla 5+ compatibility
- Fixed `shouldSkipJsFile()` logic that incorrectly skipped all minified files regardless of settings
- Added path traversal protection to `resolvePath()` method to prevent security vulnerabilities
- Added proper error handling for file operations with try-catch blocks and graceful degradation
- Fixed duplicate language strings for CSS and JS combination options

### Removed
- Removed broken JavaScript obfuscation feature that would break most JavaScript code
- Removed `obfuscate_js` configuration option from plugin settings
- Removed related obfuscation language strings

### Added
- Added type declarations (type hints and return types) to all methods for PHP 8.3+ compatibility
- Added constants for cache directory paths (CSS_CACHE_DIR, JS_CACHE_DIR, CACHE_FILES_TO_KEEP)
- Added security validation in file path resolution
- Added automatic cleanup of old combined files (keeps 5 most recent)
- Added `.gitignore` file to exclude vendor directory and build files
- Added build script for automated ZIP creation
- Added minimum PHP (8.3.0) and Joomla (5.0.0) version requirements to manifest
- Added namespace declaration to manifest for future compatibility

### Changed
- Updated composer.json to pin matthiasmullie/minify to specific version (^1.3.73)
- Updated composer.json with proper package metadata
- Improved language strings to be more distinct and descriptive
- Standardised logging to use `Log::add()` instead of mixed `enqueueMessage()`
- Removed misleading `disabled` attribute from form fields
- Updated version to 1.0.3
- Updated documentation to reflect Joomla 5+ and PHP 8.3+ requirements

### Security
- Path traversal protection prevents accessing files outside JPATH_ROOT
- Enhanced validation of file paths before processing

## [1.0.2] - 2024-11

### Added
- Initial release with CSS and JavaScript minification
- File combination support
- Exclusion paths configuration
- Debug logging

## [1.0.1] - 2024-11

### Added
- Initial beta release

## [1.0.0] - 2024-11

### Added
- Initial development version


