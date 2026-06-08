#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

if [ ! -f package.json ]; then
    exit 0
fi

install_node_deps() {
    if [ -f package-lock.json ]; then
        npm ci
        return
    fi

    npm install
}

if [ ! -d node_modules ]; then
    echo "Node dependencies are missing. Installing..."
    install_node_deps
    exit 0
fi

if [ -f package-lock.json ] && [ ! -f node_modules/.package-lock.json ]; then
    echo "Node dependency metadata is missing. Reinstalling..."
    install_node_deps
    exit 0
fi

if [ -f package-lock.json ] && [ package-lock.json -nt node_modules/.package-lock.json ]; then
    echo "Node lockfile is newer than installed dependencies. Reinstalling..."
    install_node_deps
    exit 0
fi

if ! npm ls --depth=0 >/dev/null 2>&1; then
    echo "Node dependency tree is incomplete. Reinstalling..."
    install_node_deps
    exit 0
fi

echo "Node dependencies are up to date."
