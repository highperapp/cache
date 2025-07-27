#!/bin/bash

# Build script for HighPerApp Cache Library
# This script builds the Rust FFI library and sets up the PHP environment

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

echo -e "${BLUE}HighPerApp Cache Library Build Script${NC}"
echo "======================================"

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if Rust is installed
check_rust() {
    print_status "Checking Rust installation..."
    if ! command -v rustc &> /dev/null; then
        print_error "Rust is not installed. Please install Rust from https://rustup.rs/"
        exit 1
    fi
    
    if ! command -v cargo &> /dev/null; then
        print_error "Cargo is not installed. Please install Rust from https://rustup.rs/"
        exit 1
    fi
    
    local rust_version=$(rustc --version)
    print_status "Found: $rust_version"
}

# Check if PHP is installed with FFI extension
check_php() {
    print_status "Checking PHP installation..."
    if ! command -v php &> /dev/null; then
        print_error "PHP is not installed. Please install PHP 8.1 or higher."
        exit 1
    fi
    
    local php_version=$(php -v | head -n 1)
    print_status "Found: $php_version"
    
    # Check PHP version (must be 8.1+)
    local php_version_number=$(php -r "echo PHP_VERSION_ID;")
    if [ "$php_version_number" -lt 80100 ]; then
        print_error "PHP 8.1 or higher is required. Current version: $(php -v | head -n 1)"
        exit 1
    fi
    
    # Check FFI extension
    if ! php -m | grep -q "FFI"; then
        print_warning "PHP FFI extension is not installed. Rust FFI engine will not be available."
        print_warning "To install FFI: apt-get install php-ffi (Ubuntu/Debian) or yum install php-ffi (CentOS/RHEL)"
    else
        print_status "PHP FFI extension is available"
    fi
}

# Check if Composer is installed
check_composer() {
    print_status "Checking Composer installation..."
    if ! command -v composer &> /dev/null; then
        print_warning "Composer is not installed. Installing dependencies manually..."
        return 1
    fi
    
    local composer_version=$(composer --version)
    print_status "Found: $composer_version"
    return 0
}

# Build Rust FFI library
build_rust() {
    print_status "Building Rust FFI library..."
    
    cd "$PROJECT_ROOT/rust"
    
    # Clean previous builds
    if [ -d "target" ]; then
        print_status "Cleaning previous Rust builds..."
        cargo clean
    fi
    
    # Build in release mode
    print_status "Compiling Rust library (this may take a while)..."
    cargo build --release
    
    # Check if build was successful
    local lib_path=""
    if [ -f "target/release/libhighper_cache.so" ]; then
        lib_path="target/release/libhighper_cache.so"
    elif [ -f "target/release/libhighper_cache.dylib" ]; then
        lib_path="target/release/libhighper_cache.dylib"
    elif [ -f "target/release/highper_cache.dll" ]; then
        lib_path="target/release/highper_cache.dll"
    else
        print_error "Rust library build failed. No output library found."
        exit 1
    fi
    
    print_status "Rust library built successfully: $lib_path"
    
    # Copy library to lib directory
    mkdir -p "$PROJECT_ROOT/lib"
    cp "$lib_path" "$PROJECT_ROOT/lib/"
    
    print_status "Rust library copied to lib/ directory"
    
    cd "$PROJECT_ROOT"
}

# Run Rust tests
test_rust() {
    print_status "Running Rust tests..."
    
    cd "$PROJECT_ROOT/rust"
    
    cargo test --release
    
    if [ $? -eq 0 ]; then
        print_status "All Rust tests passed"
    else
        print_warning "Some Rust tests failed"
    fi
    
    cd "$PROJECT_ROOT"
}

# Install PHP dependencies
install_php_deps() {
    print_status "Installing PHP dependencies..."
    
    if check_composer; then
        composer install --no-dev --optimize-autoloader
        print_status "PHP dependencies installed via Composer"
    else
        print_warning "Skipping Composer dependencies (Composer not available)"
    fi
}

# Run PHP tests
test_php() {
    print_status "Running PHP tests..."
    
    if [ -f "vendor/bin/phpunit" ]; then
        vendor/bin/phpunit
        
        if [ $? -eq 0 ]; then
            print_status "All PHP tests passed"
        else
            print_warning "Some PHP tests failed"
        fi
    else
        print_warning "PHPUnit not available, skipping PHP tests"
    fi
}

# Benchmark the library
benchmark() {
    print_status "Running benchmarks..."
    
    if [ -f "benchmark.php" ]; then
        php benchmark.php
    else
        print_warning "Benchmark script not found, skipping benchmarks"
    fi
}

# Create example configuration
create_config() {
    print_status "Creating example configuration..."
    
    if [ ! -f ".env" ] && [ -f ".env.example" ]; then
        cp ".env.example" ".env"
        print_status "Created .env from .env.example"
    fi
}

# Validate installation
validate_installation() {
    print_status "Validating installation..."
    
    # Check if library file exists
    if [ -f "lib/libhighper_cache.so" ] || [ -f "lib/libhighper_cache.dylib" ] || [ -f "lib/highper_cache.dll" ]; then
        print_status "✓ Rust FFI library is available"
    else
        print_warning "✗ Rust FFI library not found"
    fi
    
    # Check if PHP can load the library
    if php -r "
        require_once 'vendor/autoload.php';
        use HighPerApp\\Cache\\FFI\\RustCacheFFI;
        try {
            \$ffi = new RustCacheFFI();
            if (\$ffi->isAvailable()) {
                echo 'PHP FFI integration working\\n';
                exit(0);
            } else {
                echo 'PHP FFI integration not working\\n';
                exit(1);
            }
        } catch (Exception \$e) {
            echo 'PHP FFI integration failed: ' . \$e->getMessage() . '\\n';
            exit(1);
        }
    " 2>/dev/null; then
        print_status "✓ PHP FFI integration is working"
    else
        print_warning "✗ PHP FFI integration not working"
    fi
}

# Main build process
main() {
    local build_mode="full"
    local run_tests=false
    local run_benchmarks=false
    
    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --rust-only)
                build_mode="rust"
                shift
                ;;
            --php-only)
                build_mode="php"
                shift
                ;;
            --test)
                run_tests=true
                shift
                ;;
            --benchmark)
                run_benchmarks=true
                shift
                ;;
            --help)
                echo "Usage: $0 [OPTIONS]"
                echo "Options:"
                echo "  --rust-only    Build only Rust components"
                echo "  --php-only     Install only PHP components"
                echo "  --test         Run tests after building"
                echo "  --benchmark    Run benchmarks after building"
                echo "  --help         Show this help message"
                exit 0
                ;;
            *)
                print_error "Unknown option: $1"
                exit 1
                ;;
        esac
    done
    
    print_status "Starting build process (mode: $build_mode)..."
    
    # Pre-flight checks
    if [[ "$build_mode" == "full" ]] || [[ "$build_mode" == "rust" ]]; then
        check_rust
    fi
    
    if [[ "$build_mode" == "full" ]] || [[ "$build_mode" == "php" ]]; then
        check_php
    fi
    
    # Build components
    if [[ "$build_mode" == "full" ]] || [[ "$build_mode" == "rust" ]]; then
        build_rust
        
        if [ "$run_tests" = true ]; then
            test_rust
        fi
    fi
    
    if [[ "$build_mode" == "full" ]] || [[ "$build_mode" == "php" ]]; then
        install_php_deps
        create_config
        
        if [ "$run_tests" = true ]; then
            test_php
        fi
    fi
    
    # Post-build tasks
    if [[ "$build_mode" == "full" ]]; then
        validate_installation
    fi
    
    if [ "$run_benchmarks" = true ]; then
        benchmark
    fi
    
    print_status "Build completed successfully!"
    echo ""
    echo -e "${GREEN}Next steps:${NC}"
    echo "1. Review and update .env configuration"
    echo "2. Test the installation: php -r \"require 'vendor/autoload.php'; echo 'Installation OK\n';\""
    echo "3. Run benchmarks: php benchmark.php (if available)"
    echo "4. Check the documentation for usage examples"
}

# Run main function with all arguments
main "$@"