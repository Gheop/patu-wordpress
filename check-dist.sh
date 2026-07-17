#!/usr/bin/env sh
# Run Plugin Check against the DISTRIBUTED package, in a folder named like the
# real slug (patu-optimizer) — NOT the dev dir. The dev dir contains tests/,
# build.sh, .github, etc. that never ship, and its folder name (patu) doesn't
# match the text domain, so checking it reports noise. This checks what the
# review team actually sees.
set -e
cd "$(dirname "$0")"
./build.sh >/dev/null
docker compose exec -T cli sh -c '
  cd /var/www/html/wp-content/plugins
  rm -rf patu-optimizer /tmp/po
  php -r "\$z=new ZipArchive();\$z->open(\"patu/patu.zip\");\$z->extractTo(\"/tmp/po\");\$z->close();"
  cp -r /tmp/po/patu-optimizer .'
docker compose exec -T cli wp plugin check patu-optimizer || true
docker compose exec -T cli sh -c 'rm -rf /var/www/html/wp-content/plugins/patu-optimizer /tmp/po'
docker compose exec -T cli wp plugin delete patu-optimizer >/dev/null 2>&1 || true
