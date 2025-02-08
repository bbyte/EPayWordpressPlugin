#!/bin/bash

# Configuration
PLUGIN_SLUG="epay-onetouch-payment"
VERSION=$(grep "Version:" epay-onetouch-payment.php | awk -F': ' '{print $2}' | tr -d ' \t\n\r')
RELEASE_DIR="release"
BUILD_DIR="$RELEASE_DIR/build"
PLUGIN_DIR="$BUILD_DIR/$PLUGIN_SLUG"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}Creating release for $PLUGIN_SLUG version $VERSION${NC}"

# Clean up any existing release directory
if [ -d "$RELEASE_DIR" ]; then
    echo "Cleaning up previous release directory..."
    rm -rf "$RELEASE_DIR"
fi

# Create necessary directories
echo "Creating release directories..."
mkdir -p "$PLUGIN_DIR"

# Copy required files and directories
echo "Copying plugin files..."
FILES_TO_COPY=(
    "epay-onetouch-payment.php"
    "README.md"
    "epay-onetouch-api.md"
    "assets"
    "examples"
    "includes"
    "languages"
)

for file in "${FILES_TO_COPY[@]}"; do
    if [ -e "$file" ]; then
        cp -R "$file" "$PLUGIN_DIR/"
        echo "✓ Copied $file"
    else
        echo -e "${RED}✗ Failed to copy $file - file not found${NC}"
        exit 1
    fi
done

# Remove any development or unnecessary files
echo "Removing development files..."
find "$PLUGIN_DIR" -name ".git*" -exec rm -rf {} +
find "$PLUGIN_DIR" -name "*.sh" -exec rm -f {} +
find "$PLUGIN_DIR" -name "*.log" -exec rm -f {} +
find "$PLUGIN_DIR" -name ".DS_Store" -exec rm -f {} +
find "$PLUGIN_DIR" -name "node_modules" -exec rm -rf {} +
find "$PLUGIN_DIR" -name ".idea" -exec rm -rf {} +
find "$PLUGIN_DIR" -name ".vscode" -exec rm -rf {} +
find "$PLUGIN_DIR" -name "*.map" -exec rm -f {} +
find "$PLUGIN_DIR" -name "*.ts" -exec rm -f {} +
find "$PLUGIN_DIR" -name "tsconfig.json" -exec rm -f {} +
find "$PLUGIN_DIR" -name "package.json" -exec rm -f {} +
find "$PLUGIN_DIR" -name "package-lock.json" -exec rm -f {} +
find "$PLUGIN_DIR" -name "composer.json" -exec rm -f {} +
find "$PLUGIN_DIR" -name "composer.lock" -exec rm -f {} +

# Remove development examples
rm -rf "$PLUGIN_DIR/examples"

# Create zip file
echo "Creating zip file..."
cd "$BUILD_DIR"
zip -r "../$PLUGIN_SLUG-$VERSION.zip" "$PLUGIN_SLUG"
cd - > /dev/null

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Successfully created $PLUGIN_SLUG-$VERSION.zip in the release directory${NC}"
    echo -e "${GREEN}✓ Plugin is ready to be uploaded to WordPress${NC}"
else
    echo -e "${RED}✗ Failed to create zip file${NC}"
    exit 1
fi

# Output final instructions
echo ""
echo "Next steps:"
echo "1. Test the plugin by installing the zip file in a fresh WordPress installation"
echo "2. Upload the zip file to WordPress.org plugin repository"
echo "3. Update the plugin's assets (screenshots, banner, icon) on WordPress.org"
echo ""
echo "Zip file location: $RELEASE_DIR/$PLUGIN_SLUG-$VERSION.zip"
