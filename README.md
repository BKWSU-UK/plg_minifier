# Joomla CSS/JS Minifier Plugin

A Joomla system plugin that automatically minifies CSS and JavaScript files to improve website performance.

## Features

- Automatic CSS minification
- Automatic JavaScript minification
- Exclude specific paths from minification
- Debug logging support
- Preserves query parameters in URLs
- Handles both relative and absolute paths
- Support for Joomla module and media files

## Requirements

- Joomla 4.x or higher
- PHP 8.0 or higher
- Composer for dependencies

## Installation

1. Clone this repository or download the source code
2. Run Composer to install dependencies:
   ```bash
   composer install
   ```
3. Create a ZIP file containing:
   - minifier.php
   - minifier.xml
   - vendor/ directory
   - language/ directory
   - composer.json
   - composer.lock

4. Install the plugin through Joomla's Extension Manager
5. Enable the plugin through Joomla's Plugin Manager

## Configuration

The plugin provides several configuration options in the Joomla backend:

- **CSS Minification**: Enable/disable CSS minification
- **JS Minification**: Enable/disable JavaScript minification
- **Debug Mode**: Enable detailed logging for troubleshooting
- **Exclude Paths**: List of paths to exclude from minification (one per line)

## How It Works

1. The plugin intercepts page rendering
2. Scans for CSS and JavaScript files in the HTML
3. Creates minified versions of local files (if they don't exist or are outdated)
4. Updates HTML to reference minified versions
5. Skips external files and already minified files

## File Naming Convention

- Original: `style.css` → Minified: `style.min.css`
- Original: `script.js` → Minified: `script.min.js`

## Troubleshooting

1. Enable debug mode in plugin settings
2. Check Joomla's system log for entries with category 'plg_system_minifier'
3. Ensure write permissions on directories where minified files are created
4. Verify excluded paths if certain files should be skipped

## License

GNU General Public License version 2 or later

## Credits

- Uses [matthiasmullie/minify](https://github.com/matthiasmullie/minify) for minification
- Created by Allan Schweitz