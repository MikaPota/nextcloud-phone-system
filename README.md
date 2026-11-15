# Phone System for Nextcloud

Make and take business calls in Nextcloud, with a phone system from a GCNA
partner. Voice runs in the browser with WebRTC.

- Phone window: dial, answer, mute and hang up while you work in Nextcloud.
- Click to call from the contacts menu.
- Missed-call notifications with "Call back", and recent calls on the dashboard.
- Administration: buy a phone system or connect an existing one, then give
  users an extension.

Guide for users and administrators: https://gcn-alliance.com/support/nextcloud/

This repository also holds **BIZZKICKR**, a second app: every user's digital business card with its
QR code, sent by email or from the contacts menu; whoever scans it asks to connect, and confirmed
connections become contacts in the user's address book. BIZZKICKR is provided by COMWIZE and booked
through GCN-ALLIANCE; an administrator connects the Nextcloud once with a free GCNA account (or the
Phone System app's link).

## Layout

- `phone_system/` – the Phone System app (Nextcloud 33 and 34)
- `bizzkickr/` – the BIZZKICKR app (Nextcloud 33 and 34)
- `shared/` – the browser phone client (`phone-session.js` sign-in and
  signalling, `phone-calls.js` WebRTC calls), shared with the Odoo app;
  `phone_system/js/` holds a copy that must not drift (a test checks it)
- `tests/` – PHPUnit and Node tests
- `scripts/release.sh` – builds an app's App Store archive and its signature
  (`scripts/release.sh` for Phone System, `scripts/release.sh bizzkickr`)

## Develop

    docker run -d --name nc-phone-test -p 127.0.0.1:8089:80 -e SQLITE_DATABASE=nc \
      -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=<local password> \
      -v "$PWD/phone_system:/var/www/html/custom_apps/phone_system" nextcloud:34
    docker exec -u www-data nc-phone-test php occ app:enable phone_system

Tests (CI runs them on every push):

    composer install && vendor/bin/phpunit   # PHP, against Nextcloud's public API (nextcloud/ocp)
    npm test                                  # JavaScript

## Security

Please report vulnerabilities privately through this repository's
"Report a vulnerability" button (Security tab), not in public issues.

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
