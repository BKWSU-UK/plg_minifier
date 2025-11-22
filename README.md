# Joomla CSS/JS Minifier Plugin

A Joomla system plugin that automatically minifies CSS and JavaScript files to improve website performance.


## Requirements

- Joomla 5.0 or higher
- PHP 8.3 or higher
- Composer for dependencies (development only)

## Installation

### From Release Package

1. Download the latest release ZIP file (`plg_system_minifier_vX.X.X.zip`)
2. Install through Joomla's Extension Manager
3. Enable the plugin through Joomla's Plugin Manager

### From Source

1. Clone this repository or download the source code
2. Run the build script:
   ```bash
   ./build.sh
   ```
3. Install the generated `plg_system_minifier.zip` file through Joomla's Extension Manager
4. Enable the plugin through Joomla's Plugin Manager

### Manual Build

If you prefer to build manually:

1. Run Composer to install dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
2. Create a ZIP file containing:
   - minifier.php
   - minifier.xml
   - vendor/ directory
   - language/ directory
   - composer.json
   - composer.lock
3. Install the ZIP file through Joomla's Extension Manager
4. Enable the plugin through Joomla's Plugin Manager

## Configuration

The plugin provides several configuration options in the Joomla backend:

- **Enable CSS Minification**: Enable/disable CSS minification
- **Combine CSS Files**: Combine non-minified CSS files into a single file
- **Include Pre-Minified CSS**: Also include *.min.css files in combination (requires CSS combination enabled)
- **Enable JavaScript Minification**: Enable/disable JavaScript minification
- **Combine JavaScript Files**: Combine non-minified JavaScript files into a single file
- **Include Pre-Minified JS**: Also include *.min.js files in combination (requires JS combination enabled)
- **Exclude Paths**: List of paths to exclude from minification (one per line)
- **Debug Mode**: Enable detailed logging for troubleshooting

## Features

- **Automatic Minification**: Minifies CSS and JavaScript files on-the-fly
- **File Combination**: Combines multiple files into single files to reduce HTTP requests
- **Smart Caching**: Only re-minifies files when source files change
- **Automatic Cleanup**: Keeps only the 5 most recent combined files
- **Path Exclusions**: Exclude specific paths from minification
- **Security**: Built-in path traversal protection
- **Debug Logging**: Detailed logging for troubleshooting
- **Preserves Query Parameters**: Maintains version strings and other URL parameters
- **Type Safe**: Full PHP 8.3+ type declarations

## How It Works

1. The plugin intercepts page rendering
2. Scans for CSS and JavaScript files in the HTML
3. Creates minified versions of local files (if they don't exist or are outdated)
4. Combines multiple files of the same type into single files
5. Updates HTML to reference minified and combined versions
6. Skips external files and already minified files

## File Naming Convention

- Original: `style.css` → Minified: `style.min.css`
- Original: `script.js` → Minified: `script.min.js`
- Combined CSS: `combined-{hash}.css` (stored in `/media/cache/css/`)
- Combined JS: `combined-{hash}.js` (stored in `/media/cache/js/`)

## Troubleshooting

### Common Issues

#### "jQuery is not defined" Error
**Problem**: Scripts that depend on jQuery are being combined, but jQuery itself is loaded separately by Joomla.

**Solution (v1.0.5+)**: This should be automatically fixed! The plugin now places the combined JavaScript file **after** all skipped files (including jQuery). If you still see this error:

1. **Update to version 1.0.5+** which fixes the load order
2. **Clear all caches**:
   - Delete `/media/cache/js/combined-*.js`
   - Clear Joomla cache (System → Clear Cache)
   - Clear browser cache

**Alternative solutions** if updating doesn't work:
1. **Enable "Include Pre-Minified JS"** option in plugin settings (includes jQuery in combined file)
2. **OR** Add template JavaScript to exclusion list:
   ```
   /templates/yourtemplate/js/
   ```
3. **OR** Disable "Combine JavaScript Files" and use individual minification only

**Technical details**: Version 1.0.5+ intelligently tracks which scripts are skipped (jQuery, external scripts, excluded paths, pre-minified files when not combining all) and places the combined file **after** them to ensure proper dependency order.

#### Files Not Being Minified
1. **Enable debug mode** in plugin settings to see detailed logging
2. **Check Joomla's system log** for entries with category 'plg_system_minifier'
3. **Ensure write permissions** on directories where minified files are created:
   - `/media/cache/css/`
   - `/media/cache/js/`
   - Source file directories (for individual minification)
4. **Verify excluded paths** if certain files should be skipped

#### Cache Issues
1. **Clear cache** by deleting files in `/media/cache/css/` and `/media/cache/js/`
2. **Clear Joomla cache** through System → Clear Cache
3. **Clear browser cache** or use incognito mode for testing

#### Version Issues
1. **Check PHP version**: Requires PHP 8.3 or higher
2. **Check Joomla version**: Requires Joomla 5.0 or higher

## Known Limitations

- Only processes local files (external files are skipped)
- File combination maintains source order but may affect load order-dependent scripts
- Large combined files may impact initial page load (consider excluding large third-party libraries)
- Minified files are created in the same directory as source files (ensure write permissions)

## License

GNU General Public License version 2 or later

## Credits

- Uses [matthiasmullie/minify](https://github.com/matthiasmullie/minify) for minification
- Created by Allan Schweitz