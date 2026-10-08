#!/bin/bash

set -e

# ============================================================
# Immofirst Production Assets Upload
# ============================================================

# ------------------------------------------------------------
# Configuration
# ------------------------------------------------------------

THEME_DIR="web/themes/custom/suchauftrag_theme"
ASSET_DIR="production-assets/v1"

SSH_USER="u999580389"
SSH_HOST="195.35.62.48"
SSH_PORT="65002"

REMOTE_BASE="/home/u999580389/domains/mistyrose-loris-414319.hostingersite.com/public_html/production-assets/v1"

# ------------------------------------------------------------
# Check current branch
# ------------------------------------------------------------

CURRENT_BRANCH=$(git branch --show-current)



echo "✓ Branch: $CURRENT_BRANCH"

# ------------------------------------------------------------
# Check source directories
# ------------------------------------------------------------

if [ ! -d "$THEME_DIR/css" ]; then
    echo "ERROR: CSS directory not found:"
    echo "$THEME_DIR/css"
    exit 1
fi

if [ ! -d "$THEME_DIR/js" ]; then
    echo "ERROR: JS directory not found:"
    echo "$THEME_DIR/js"
    exit 1
fi

# ------------------------------------------------------------
# Create local production asset directories
# ------------------------------------------------------------

mkdir -p "$ASSET_DIR/css"
mkdir -p "$ASSET_DIR/js"

# ------------------------------------------------------------
# Clean old generated assets
# Keep .gitkeep files
# ------------------------------------------------------------

echo "→ Cleaning local production assets..."

find "$ASSET_DIR/css" -type f ! -name '.gitkeep' -delete
find "$ASSET_DIR/js" -type f ! -name '.gitkeep' -delete

# ------------------------------------------------------------
# Copy CSS
# ------------------------------------------------------------

echo "→ Copying CSS files..."

find "$THEME_DIR/css" -maxdepth 1 -type f -name '*.css' -exec cp {} "$ASSET_DIR/css/" \;

# ------------------------------------------------------------
# Copy JS
# ------------------------------------------------------------

echo "→ Copying JS files..."

find "$THEME_DIR/js" -maxdepth 1 -type f -name '*.js' -exec cp {} "$ASSET_DIR/js/" \;

# ------------------------------------------------------------
# Verify files were copied
# ------------------------------------------------------------

CSS_COUNT=$(find "$ASSET_DIR/css" -maxdepth 1 -type f -name '*.css' | wc -l | tr -d ' ')
JS_COUNT=$(find "$ASSET_DIR/js" -maxdepth 1 -type f -name '*.js' | wc -l | tr -d ' ')

if [ "$CSS_COUNT" -eq 0 ]; then
    echo "ERROR: No CSS files were copied."
    exit 1
fi

if [ "$JS_COUNT" -eq 0 ]; then
    echo "ERROR: No JS files were copied."
    exit 1
fi

# ------------------------------------------------------------
# Display files
# ------------------------------------------------------------

echo ""
echo "Production CSS:"
find "$ASSET_DIR/css" -maxdepth 1 -type f -name '*.css' | sort

echo ""
echo "Production JS:"
find "$ASSET_DIR/js" -maxdepth 1 -type f -name '*.js' | sort

echo ""
echo "CSS files: $CSS_COUNT"
echo "JS files:  $JS_COUNT"

# ------------------------------------------------------------
# Test SSH connection
# ------------------------------------------------------------

echo ""
echo "→ Testing SSH connection..."

ssh -p "$SSH_PORT" \
    "$SSH_USER@$SSH_HOST" \
    "echo '✓ SSH connection successful'"

# ------------------------------------------------------------
# Create remote directories
# ------------------------------------------------------------

echo "→ Creating remote directories..."

ssh -p "$SSH_PORT" \
    "$SSH_USER@$SSH_HOST" \
    "mkdir -p '$REMOTE_BASE/css' '$REMOTE_BASE/js'"

# ------------------------------------------------------------
# Upload CSS
# ------------------------------------------------------------

echo "→ Uploading CSS..."

scp -P "$SSH_PORT" \
    "$ASSET_DIR"/css/*.css \
    "$SSH_USER@$SSH_HOST:$REMOTE_BASE/css/"

# ------------------------------------------------------------
# Upload JS
# ------------------------------------------------------------

echo "→ Uploading JS..."

scp -P "$SSH_PORT" \
    "$ASSET_DIR"/js/*.js \
    "$SSH_USER@$SSH_HOST:$REMOTE_BASE/js/"

# ------------------------------------------------------------
# Verify remote files
# ------------------------------------------------------------

echo ""
echo "→ Verifying remote CSS files..."

ssh -p "$SSH_PORT" \
    "$SSH_USER@$SSH_HOST" \
    "find '$REMOTE_BASE/css' -maxdepth 1 -type f -name '*.css' | sort"

echo ""
echo "→ Verifying remote JS files..."

ssh -p "$SSH_PORT" \
    "$SSH_USER@$SSH_HOST" \
    "find '$REMOTE_BASE/js' -maxdepth 1 -type f -name '*.js' | sort"

# ------------------------------------------------------------
# Finished
# ------------------------------------------------------------

echo ""
echo "============================================"
echo "✓ Production assets uploaded successfully"
echo "============================================"
echo ""
echo "Remote base:"
echo "$REMOTE_BASE"
echo ""
echo "Public base URL:"
echo "https://mistyrose-loris-414319.hostingersite.com/production-assets/v1/"