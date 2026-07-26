#!/bin/sh
# Package the extension as an installable .xpi.
#
# An .xpi is just a zip with manifest.json at the archive root - not inside a
# subdirectory, which is the usual way to get an "invalid add-on" error.
#
# Usage: ./build.sh   ->  ../quickjot-<version>.xpi
set -eu

cd "$(dirname "$0")"

# fail early on a broken manifest rather than shipping one
version=$(python3 -c 'import json; print(json.load(open("manifest.json"))["version"])')

out="../quickjot-$version.xpi"
rm -f "$out"

# everything in here is shipped except this script and any stray build output
if command -v zip >/dev/null 2>&1; then
    zip -q -r -FS "$out" . -x 'build.sh' -x '*.xpi' -x '*/.*' -x '.*'
else
    python3 - "$out" <<'PY'
import os, sys, zipfile

out = sys.argv[1]
skip = {'build.sh'}
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if not d.startswith('.')]
        for name in sorted(files):
            if name in skip or name.startswith('.') or name.endswith('.xpi'):
                continue
            path = os.path.join(root, name)
            z.write(path, os.path.relpath(path, '.'))
PY
fi

echo "built $(cd .. && pwd)/quickjot-$version.xpi"
