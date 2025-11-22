# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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


