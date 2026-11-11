# Phone System for Nextcloud

Make and take business calls in Nextcloud, with a phone system from a GCNA
partner. Voice runs in the browser with WebRTC.

- Phone window: dial, answer, mute and hang up while you work in Nextcloud.
- Click to call from the contacts menu.
- Missed-call notifications with "Call back", and recent calls on the dashboard.
- Administration: buy a phone system or connect an existing one, then give
  users an extension.

Guide for users and administrators: https://gcn-alliance.com/support/nextcloud/

## Layout

- `phone_system/` – the Nextcloud app (Nextcloud 33 and 34)
- `shared/` – the browser phone client (`phone-session.js` sign-in and
  signalling, `phone-calls.js` WebRTC calls), shared with the Odoo app;
  `phone_system/js/` holds a copy that must not drift (a test checks it)
- `tests/` – PHPUnit and Node tests
- `scripts/release.sh` – builds the App Store archive and its signature

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
