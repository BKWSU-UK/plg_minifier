#!/bin/bash

##
# Build script for Joomla Minifier Plugin
# This script creates a distributable ZIP file of the plugin
##

# Exit on any error
set -e

# Configuration
PLUGIN_NAME="plg_system_minifier"
BUILD_DIR="build"
DIST_DIR="dist"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Building ${PLUGIN_NAME}...${NC}"

# Clean previous build
if [ -d "$BUILD_DIR" ]; then
    echo -e "${YELLOW}Cleaning previous build directory...${NC}"
    rm -rf "$BUILD_DIR"
fi

if [ -d "$DIST_DIR" ]; then
    echo -e "${YELLOW}Cleaning previous dist directory...${NC}"
    rm -rf "$DIST_DIR"
fi

# Create build directory
mkdir -p "$BUILD_DIR/$PLUGIN_NAME"
mkdir -p "$DIST_DIR"

# Check if composer is installed
if ! command -v composer &> /dev/null; then
    echo -e "${RED}Composer is not installed. Please install composer first.${NC}"
    exit 1
fi

# Install/update composer dependencies
echo -e "${YELLOW}Installing composer dependencies...${NC}"
composer install --no-dev --optimize-autoloader

# Copy files to build directory
echo -e "${YELLOW}Copying plugin files...${NC}"
cp minifier.php "$BUILD_DIR/$PLUGIN_NAME/"
cp minifier.xml "$BUILD_DIR/$PLUGIN_NAME/"
cp composer.json "$BUILD_DIR/$PLUGIN_NAME/"
cp composer.lock "$BUILD_DIR/$PLUGIN_NAME/"
cp -r vendor "$BUILD_DIR/$PLUGIN_NAME/"
cp -r language "$BUILD_DIR/$PLUGIN_NAME/"

# Get version from XML
VERSION=$(grep -oP '(?<=<version>)[^<]+' minifier.xml)

# Create ZIP file
echo -e "${YELLOW}Creating ZIP archive...${NC}"
cd "$BUILD_DIR"
ZIP_FILE="${PLUGIN_NAME}_v${VERSION}.zip"
zip -r "$ZIP_FILE" "$PLUGIN_NAME" -x "*.git*" "*.DS_Store"

# Move to dist directory
mv "$ZIP_FILE" "../$DIST_DIR/"
cd ..

# Also create a copy with standard name for easy installation
cp "$DIST_DIR/$ZIP_FILE" "$PLUGIN_NAME.zip"

# Clean up build directory
rm -rf "$BUILD_DIR"

echo -e "${GREEN}Build complete!${NC}"
echo -e "${GREEN}Package created: ${DIST_DIR}/${ZIP_FILE}${NC}"
echo -e "${GREEN}Installation file: ${PLUGIN_NAME}.zip${NC}"
echo -e "${YELLOW}Version: ${VERSION}${NC}"


