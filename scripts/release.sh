#!/bin/sh
# Builds the App Store archive build/<app>-<version>.tar.gz and prints the signature the App Store
# asks for on upload. The app is phone_system (default) or bizzkickr: scripts/release.sh bizzkickr
# Key and certificate stay outside the repository:
#   ~/.nextcloud/certificates/<app>.key (private, never committed)
#   ~/.nextcloud/certificates/<app>.crt (issued by Nextcloud for the app id)
# With the certificate present, the app is also code-signed (appinfo/signature.json) with a
# disposable Nextcloud container, so servers can check its integrity.
set -eu
cd "$(dirname "$0")/.."
APP="${1:-phone_system}"
case "$APP" in phone_system|bizzkickr) ;; *) echo "Unknown app $APP" >&2; exit 1 ;; esac
CERTS="${CERTS:-$HOME/.nextcloud/certificates}"
KEY="$CERTS/$APP.key"
CRT="$CERTS/$APP.crt"
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$APP/appinfo/info.xml")
OUT="build/$APP-$VERSION.tar.gz"

[ -f "$KEY" ] || { echo "Missing $KEY" >&2; exit 1; }
mkdir -p build && rm -rf build/stage && mkdir -p build/stage
# Only what the app runs: no tests, no development tooling.
tar -C . -cf - --exclude='.DS_Store' --exclude='appinfo/signature.json' "$APP" | tar -C build/stage -xf -

if [ -f "$CRT" ]; then
	docker run --rm -v "$PWD/build/stage/$APP:/app" -v "$CERTS:/certs:ro" -e APP="$APP" --entrypoint sh nextcloud:34-apache -c \
		'cd /usr/src/nextcloud && php occ integrity:sign-app --path=/app --privateKey=/certs/$APP.key --certificate=/certs/$APP.crt' \
		|| { echo "Code signing failed." >&2; exit 1; }
else
	echo "No $CRT yet: the archive is not code-signed (fine for testing, not for the App Store)." >&2
fi

tar -C build/stage -czf "$OUT" "$APP"
rm -rf build/stage
echo "Archive:   $OUT"
echo "Signature: $(openssl dgst -sha512 -sign "$KEY" "$OUT" | openssl base64 -A)"
