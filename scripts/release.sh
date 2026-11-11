#!/bin/sh
# Builds the App Store archive build/phone_system-<version>.tar.gz and prints the signature the
# App Store asks for on upload. Key and certificate stay outside the repository:
#   ~/.nextcloud/certificates/phone_system.key (private, never committed)
#   ~/.nextcloud/certificates/phone_system.crt (issued by Nextcloud for the app id)
# With the certificate present, the app is also code-signed (appinfo/signature.json) with a
# disposable Nextcloud container, so servers can check its integrity.
set -eu
cd "$(dirname "$0")/.."
CERTS="${CERTS:-$HOME/.nextcloud/certificates}"
KEY="$CERTS/phone_system.key"
CRT="$CERTS/phone_system.crt"
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' phone_system/appinfo/info.xml)
OUT="build/phone_system-$VERSION.tar.gz"

[ -f "$KEY" ] || { echo "Missing $KEY" >&2; exit 1; }
rm -rf build && mkdir -p build/stage
# Only what the app runs: no tests, no development tooling.
tar -C . -cf - --exclude='.DS_Store' --exclude='appinfo/signature.json' phone_system | tar -C build/stage -xf -

if [ -f "$CRT" ]; then
	docker run --rm -v "$PWD/build/stage/phone_system:/app" -v "$CERTS:/certs:ro" --entrypoint sh nextcloud:34-apache -c \
		'cd /usr/src/nextcloud && php occ integrity:sign-app --path=/app --privateKey=/certs/phone_system.key --certificate=/certs/phone_system.crt' \
		|| { echo "Code signing failed." >&2; exit 1; }
else
	echo "No $CRT yet: the archive is not code-signed (fine for testing, not for the App Store)." >&2
fi

tar -C build/stage -czf "$OUT" phone_system
rm -rf build/stage
echo "Archive:   $OUT"
echo "Signature: $(openssl dgst -sha512 -sign "$KEY" "$OUT" | openssl base64 -A)"
