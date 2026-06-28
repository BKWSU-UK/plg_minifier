#!/bin/bash

##
# Build script for Joomla Minifier Plugin
# This script creates a distributable ZIP file of the plugin
##

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

# Configuration
PLUGIN_NAME="plg_system_minifier"
BUILD_DIR="build"
DIST_DIR="dist"
STAGING="$BUILD_DIR/$PLUGIN_NAME"
GITHUB_REPO="BKWSU-UK/plg_minifier"
GITHUB_BRANCH="main"
UPDATE_XML="updates/${PLUGIN_NAME}.xml"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Building ${PLUGIN_NAME}...${NC}"

for file in minifier.php minifier.xml composer.json composer.lock; do
    if [[ ! -f "$file" ]]; then
        echo -e "${RED}Required file missing: ${file}${NC}"
        exit 1
    fi
done

for dir in language helper; do
    if [[ ! -d "$dir" ]]; then
        echo -e "${RED}Required directory missing: ${dir}/${NC}"
        exit 1
    fi
done

VERSION=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' minifier.xml | head -1)
if [[ -z "$VERSION" ]]; then
    echo -e "${RED}Could not read version from minifier.xml${NC}"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo -e "${RED}Composer is not installed. Please install composer first.${NC}"
    exit 1
fi

if ! command -v zip &> /dev/null; then
    echo -e "${RED}zip is not installed. Please install zip first.${NC}"
    exit 1
fi

echo -e "${YELLOW}Running tests...${NC}"
if [[ ! -x vendor/bin/phpunit ]]; then
    composer install
fi
composer test

if [ -d "$BUILD_DIR" ]; then
    echo -e "${YELLOW}Cleaning previous build directory...${NC}"
    rm -rf "$BUILD_DIR"
fi

if [ -d "$DIST_DIR" ]; then
    echo -e "${YELLOW}Cleaning previous dist directory...${NC}"
    rm -rf "$DIST_DIR"
fi

mkdir -p "$STAGING"
mkdir -p "$DIST_DIR"

echo -e "${YELLOW}Installing production dependencies into staging directory...${NC}"
cp composer.json composer.lock "$STAGING/"
composer install --no-dev --optimize-autoloader --working-dir="$STAGING"

echo -e "${YELLOW}Copying plugin files...${NC}"
cp minifier.php minifier.xml "$STAGING/"
cp -r language helper "$STAGING/"

echo -e "${YELLOW}Creating ZIP archive...${NC}"
cd "$BUILD_DIR"
ZIP_FILE="${PLUGIN_NAME}_v${VERSION}.zip"
zip -r "$ZIP_FILE" "$PLUGIN_NAME" \
    -x "*.git*" "*.DS_Store" "*/vendor/bin/*" "*/vendor/bin"

mv "$ZIP_FILE" "../$DIST_DIR/"
cd "$ROOT"

cp "$DIST_DIR/$ZIP_FILE" "$PLUGIN_NAME.zip"

rm -rf "$BUILD_DIR"

PACKAGE_PATH="$DIST_DIR/$ZIP_FILE"
SHA256=$(sha256sum "$PACKAGE_PATH" | awk '{print $1}')
SHA384=$(sha384sum "$PACKAGE_PATH" | awk '{print $1}')
SHA512=$(sha512sum "$PACKAGE_PATH" | awk '{print $1}')
DOWNLOAD_URL="https://github.com/${GITHUB_REPO}/releases/download/v${VERSION}/${ZIP_FILE}"
INFO_URL="https://github.com/${GITHUB_REPO}/releases/tag/v${VERSION}"

mkdir -p "$(dirname "$UPDATE_XML")"
cat > "$UPDATE_XML" <<EOF
<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>${PLUGIN_NAME}</name>
        <description>Joomla CSS and JavaScript minifier system plugin</description>
        <element>minifier</element>
        <type>plugin</type>
        <folder>system</folder>
        <client>site</client>
        <version>${VERSION}</version>
        <infourl title="Minifier Plugin Release Notes">${INFO_URL}</infourl>
        <downloads>
            <downloadurl type="full" format="zip">${DOWNLOAD_URL}</downloadurl>
        </downloads>
        <tags>
            <tag>stable</tag>
        </tags>
        <targetplatform name="joomla" version="5\.[0-9]+" />
        <php_minimum>8.3</php_minimum>
        <maintainer>Allan Schweitz</maintainer>
        <maintainerurl>https://www.brahmakumaris.org</maintainerurl>
        <sha256>${SHA256}</sha256>
        <sha384>${SHA384}</sha384>
        <sha512>${SHA512}</sha512>
    </update>
</updates>
EOF

echo -e "${GREEN}Build complete!${NC}"
echo -e "${GREEN}Package created: ${DIST_DIR}/${ZIP_FILE}${NC}"
echo -e "${GREEN}Installation file: ${PLUGIN_NAME}.zip${NC}"
echo -e "${GREEN}Update server manifest: ${UPDATE_XML}${NC}"
echo -e "${YELLOW}Version: ${VERSION}${NC}"
echo -e "${YELLOW}Publish release: gh release create v${VERSION} ${PACKAGE_PATH} --title v${VERSION}${NC}"
