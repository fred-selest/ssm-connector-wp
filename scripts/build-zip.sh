#!/bin/sh
# Construit les ZIP installables dans le dossier donné (par défaut : dist/) :
#   ssm-connector-wp-vX.Y.Z.zip   archive versionnée
#   ssm-connector-wp.zip          même archive sous un nom stable (le guide d'installation de SSM pointe dessus)
# Le ZIP contient un seul dossier « ssm-connector/ » : c'est lui que WordPress crée dans wp-content/plugins/.
set -eu

cd "$(dirname "$0")/.."
out="${1:-dist}"
version=$(sed -n 's/^ \* Version: *//p' ssm-connector.php | head -1 | tr -d '\r')
[ -n "$version" ] || { echo "Version introuvable dans ssm-connector.php" >&2; exit 1; }

rm -rf "$out/build"
mkdir -p "$out/build/ssm-connector"
cp ssm-connector.php uninstall.php readme.txt "$out/build/ssm-connector/"
rm -f "$out/ssm-connector-wp-v$version.zip" "$out/ssm-connector-wp.zip"
(cd "$out/build" && zip -q -r -X "../ssm-connector-wp-v$version.zip" ssm-connector)
cp "$out/ssm-connector-wp-v$version.zip" "$out/ssm-connector-wp.zip"
rm -rf "$out/build"
echo "$out/ssm-connector-wp-v$version.zip"
