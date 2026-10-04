#!/bin/sh
# Contrôle que la version est la même partout : en-tête du plugin, constante, « Stable tag » de readme.txt
# et première section numérotée du CHANGELOG. Avec un argument, elle doit aussi être égale à celui-ci.
set -eu

cd "$(dirname "$0")/.."
header=$(sed -n 's/^ \* Version: *//p' ssm-connector.php | head -1 | tr -d '\r')
const=$(sed -n "s/^define('SSM_CONNECTOR_VERSION', '\(.*\)');.*/\1/p" ssm-connector.php | head -1)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt | head -1 | tr -d '\r')
changelog=$(sed -n 's/^## \[\([0-9][0-9.]*\)\].*/\1/p' CHANGELOG.md | head -1)

echo "en-tête du plugin : $header"
echo "constante         : $const"
echo "readme.txt        : $stable"
echo "CHANGELOG.md      : $changelog"

fail=0
[ -n "$header" ] || fail=1
[ "$header" = "$const" ] || fail=1
[ "$header" = "$stable" ] || fail=1
[ "$header" = "$changelog" ] || fail=1
if [ $# -ge 1 ]; then
    echo "demandée          : $1"
    [ "$header" = "$1" ] || fail=1
fi
if [ "$fail" -ne 0 ]; then
    echo "Les versions ne concordent pas." >&2
    exit 1
fi
echo "Versions cohérentes."
