#!/bin/bash
#
# Credentium Claim Moodle Plugin Deployment Script
# Copyright © 2025 CloudTeam Sp. z o.o.
#
# Packages the plugin (this repository root) into a Moodle-installable ZIP.
# The archive contains a single top-level directory `credentiumclaim/`, as
# required by Moodle for the `local_credentiumclaim` component.
#
# Works both locally (macOS/Linux) and in CI. Version is read from version.php,
# which is the single source of truth for the release number.
#

set -euo pipefail

# Colors (disabled when not a TTY, e.g. in CI logs)
if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Credentium Claim Moodle Plugin Deployment${NC}"
echo -e "${BLUE}Copyright © 2025 CloudTeam Sp. z o.o.${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# --- Read version from version.php (single source of truth) ---
if [ ! -f "version.php" ]; then
    echo -e "${RED}Error: version.php not found!${NC}"
    exit 1
fi

VERSION=$(grep '^\$plugin->version' version.php | sed -E 's/.*= ([0-9]+);.*/\1/')
RELEASE=$(grep '^\$plugin->release' version.php | sed -E "s/.*'(.*)'.*/\1/")

if [ -z "$VERSION" ] || [ -z "$RELEASE" ]; then
    echo -e "${RED}Error: Could not extract version information from version.php${NC}"
    exit 1
fi

echo -e "${GREEN}Plugin Version:${NC} $VERSION"
echo -e "${GREEN}Release Version:${NC} $RELEASE"
echo ""

# --- Prepare build directories ---
DIST_DIR="$SCRIPT_DIR/dist"
BUILD_DIR="$DIST_DIR/build"
PLUGIN_DIR="$BUILD_DIR/credentiumclaim"

echo -e "${YELLOW}Creating build directories...${NC}"
rm -rf "$DIST_DIR"
mkdir -p "$PLUGIN_DIR"

# --- Copy plugin files, excluding VCS / build tooling / dev-only assets ---
# Everything NOT excluded here ships inside the credentiumclaim/ directory.
echo -e "${YELLOW}Copying plugin files...${NC}"
rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='.github' \
    --exclude='.claude' \
    --exclude='.DS_Store' \
    --exclude='._*' \
    --exclude='.idea' \
    --exclude='.vscode' \
    --exclude='deploy.sh' \
    --exclude='.buildignore' \
    --exclude='DEPLOYMENT.md' \
    --exclude='CLAUDE.md' \
    --exclude='docs' \
    --exclude='dist' \
    --exclude='*.zip' \
    --exclude='.phpunit.result.cache' \
    ./ "$PLUGIN_DIR/"

echo -e "${GREEN}Copied plugin files to build directory${NC}"

# --- Verify critical files are present in the package ---
echo -e "${YELLOW}Verifying critical files...${NC}"
critical_files=(
    "version.php"
    "lib.php"
    "settings.php"
    "db/install.xml"
    "db/access.php"
    "lang/en/local_credentiumclaim.php"
)
missing_files=()
for file in "${critical_files[@]}"; do
    [ -f "$PLUGIN_DIR/$file" ] || missing_files+=("$file")
done
if [ ${#missing_files[@]} -gt 0 ]; then
    echo -e "${RED}Error: Critical files missing from build:${NC}"
    printf '  %s\n' "${missing_files[@]}"
    exit 1
fi
echo -e "${GREEN}All critical files present${NC}"

# --- Create the ZIP ---
ZIP_NAME="local_credentiumclaim-${RELEASE}.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

echo -e "${YELLOW}Creating ZIP archive...${NC}"
( cd "$BUILD_DIR" && zip -r -q "$ZIP_PATH" credentiumclaim/ )
ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
echo -e "${GREEN}✓ Successfully created: $ZIP_NAME ($ZIP_SIZE)${NC}"

# --- Checksums (portable: macOS uses shasum/md5, Linux uses sha256sum/md5sum) ---
echo -e "${YELLOW}Calculating checksums...${NC}"
cd "$DIST_DIR"
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$ZIP_NAME" > "${ZIP_NAME}.sha256"
else
    shasum -a 256 "$ZIP_NAME" > "${ZIP_NAME}.sha256"
fi
if command -v md5sum >/dev/null 2>&1; then
    md5sum "$ZIP_NAME" > "${ZIP_NAME}.md5"
else
    # macOS `md5 -r` prints "<hash> <file>", matching md5sum's format.
    md5 -r "$ZIP_NAME" > "${ZIP_NAME}.md5"
fi
echo -e "${GREEN}✓ Checksums created${NC}"

# --- Clean up the intermediate build tree, keep only the artifacts ---
rm -rf "$BUILD_DIR"

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}Deployment Package Created Successfully!${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "${GREEN}Package Details:${NC}"
echo -e "  Name:     $ZIP_NAME"
echo -e "  Size:     $ZIP_SIZE"
echo -e "  Location: $ZIP_PATH"
echo ""
echo -e "${GREEN}Checksums:${NC}"
echo -e "  SHA256:   $(cut -d' ' -f1 < "${ZIP_NAME}.sha256")"
echo -e "  MD5:      $(cut -d' ' -f1 < "${ZIP_NAME}.md5")"
echo ""
echo -e "${YELLOW}Installation:${NC}"
echo -e "  1. Site Administration > Plugins > Install plugins > upload the ZIP"
echo -e "  2. Or extract to {moodle_root}/local/credentiumclaim/"
echo -e "  3. Visit Site Administration > Notifications to finish installation"
echo ""
echo -e "${BLUE}Credentium® is a registered trademark — © 2025 CloudTeam Sp. z o.o.${NC}"
