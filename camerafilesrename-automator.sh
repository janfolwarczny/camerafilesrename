#!/bin/sh

# Update this path if the PHP script is stored elsewhere.
PHP_SCRIPT="/Users/janfolwarczny/Work/camerafilesrename/camerafilesrename.php"

PHP_BINARY="${CAMERAFILESRENAME_PHP:-}"

if [ -z "$PHP_BINARY" ]; then
    PHP_BINARY="$(command -v php 2>/dev/null || true)"
fi

if [ -z "$PHP_BINARY" ] || [ ! -x "$PHP_BINARY" ]; then
    for candidate in \
        /opt/homebrew/bin/php \
        /usr/local/bin/php \
        /usr/bin/php
    do
        if [ -x "$candidate" ]; then
            PHP_BINARY="$candidate"
            break
        fi
    done
fi

if [ -z "$PHP_BINARY" ] || [ ! -x "$PHP_BINARY" ]; then
    echo "Error: PHP was not found. Set CAMERAFILESRENAME_PHP to its full path." >&2
    exit 1
fi

if [ ! -f "$PHP_SCRIPT" ]; then
    echo "Error: PHP script not found: $PHP_SCRIPT" >&2
    exit 1
fi

# In Automator, configure the action to pass input as arguments.
RESULT=$("$PHP_BINARY" "$PHP_SCRIPT" "$@" 2>&1)
STATUS=$?

# Pass the result as an osascript argument instead of interpolating it into
# AppleScript source; filenames containing quotes or newlines remain safe.
osascript \
    -e 'on run argv' \
    -e 'display dialog (item 1 of argv) with title "Camera Files Rename" buttons {"OK"} default button "OK"' \
    -e 'end run' \
    -- "$RESULT"

exit "$STATUS"
