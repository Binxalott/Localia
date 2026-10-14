#!/usr/bin/env sh
# Start the Localia agent page on http://localhost:8787
cd "$(dirname "$0")" || exit 1
if command -v python3 >/dev/null 2>&1; then exec python3 serve.py "$@"
elif command -v node >/dev/null 2>&1; then exec node serve.js "$@"
else echo "Please install Python 3 or Node.js (or open index.html directly - see README)."; exit 1
fi
