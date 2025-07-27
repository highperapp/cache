#!/bin/bash

set -e

echo "🚀 Building HighPer Cache with Rust FFI support..."

# Detect target architecture
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    TARGET="x86_64-unknown-linux-gnu"
    EXT="so"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    if [[ $(uname -m) == "arm64" ]]; then
        TARGET="aarch64-apple-darwin"
    else
        TARGET="x86_64-apple-darwin"
    fi
    EXT="dylib"
elif [[ "$OSTYPE" == "msys" || "$OSTYPE" == "win32" ]]; then
    TARGET="x86_64-pc-windows-msvc"
    EXT="dll"
else
    echo "❌ Unsupported OS: $OSTYPE"
    exit 1
fi

echo "🏗️  Target architecture: $TARGET"

# Check if Rust is installed
if ! command -v cargo &> /dev/null; then
    echo "❌ Cargo not found. Please install Rust: https://rustup.rs/"
    exit 1
fi

# Build optimized release
echo "🔨 Building optimized release..."
cargo build --release --target $TARGET

# Create FFI directory if it doesn't exist
mkdir -p ../src/FFI

# Copy library to PHP FFI directory
LIB_NAME="libhighper_cache"
SRC_PATH="target/$TARGET/release/${LIB_NAME}.${EXT}"
DEST_PATH="../src/FFI/${LIB_NAME}.${EXT}"

if [ -f "$SRC_PATH" ]; then
    cp "$SRC_PATH" "$DEST_PATH"
    echo "✅ Rust FFI library built successfully"
    echo "📁 Library available at: $DEST_PATH"
    
    # Check library size
    SIZE=$(du -h "$DEST_PATH" | cut -f1)
    echo "📊 Library size: $SIZE"
    
    # Test library loading (Linux/macOS only)
    if [[ "$OSTYPE" != "msys" && "$OSTYPE" != "win32" ]]; then
        if command -v ldd &> /dev/null; then
            echo "🔍 Library dependencies:"
            ldd "$DEST_PATH" || true
        elif command -v otool &> /dev/null; then
            echo "🔍 Library dependencies:"
            otool -L "$DEST_PATH" || true
        fi
    fi
    
    echo "🎉 Build completed successfully!"
else
    echo "❌ Build failed - library not found at: $SRC_PATH"
    exit 1
fi