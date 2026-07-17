#!/usr/bin/env bash
# Package the plugin into patu.zip with only the distributable files.
set -euo pipefail
cd "$(dirname "$0")"
rm -rf build patu.zip
mkdir -p build/patu-optimizer
cp patu.php uninstall.php readme.txt README.md LICENSE build/patu-optimizer/
cp -r includes admin build/patu-optimizer/
python3 -c "import shutil; shutil.make_archive('patu','zip','build')"
rm -rf build
echo "built patu.zip contents:"
python3 -c "import zipfile;[print('  '+n) for n in zipfile.ZipFile('patu.zip').namelist()]"
