# Quorum cPanel deployment

Target: https://gemb.co.za/vote/

## What this package provides

The original interface is hosted by PHP with a shared MariaDB workspace, a managing-agent username/password and mandatory authenticator-code gate, database-backed login rate limiting, CSRF protection, HTTPS session cookies, and revision-based conflict detection. Saves run once per second; wait for the saving banner to disappear before closing. Storage errors disable editing. Reloading after a conflict discards the local unsaved version. The first successful load seeds the database with the original fictional demo data (148 owners and 83 ballots).

This is a protected, persistent demonstration, not a production voting system. Owner registration, passwords, OTP, invitations, eligibility and ballot choices remain prototype browser flows. No email is sent. Anyone with the workspace password has full access to all stored data and can simulate any owner. Server-side ballot entitlement and immutable audit records are not implemented. Use fictional data only. One shared meeting workspace is supported. Do not distribute the workspace password to actual voters.

## Requirements

- cPanel Apache hosting with PHP 8.1 or newer and PDO MySQL enabled.
- MariaDB 10.5 or newer and InnoDB.
- Valid TLS certificate for gemb.co.za (enable cPanel AutoSSL).
- Apache mod_rewrite and .htaccess overrides enabled.
- No Node.js, Composer, or build process is required on the host.

## Installation

1. In cPanel, confirm the document root for gemb.co.za. These instructions assume `/home/CPANELUSER/public_html`.
2. Create a database and database user using cPanel's MySQL Database Wizard. Assign that user privileges on this database. Record the actual names, including the cPanel prefix.
3. Import `private/schema.sql` into that database with phpMyAdmin.
4. Create `/home/CPANELUSER/quorum-private/`, outside the public document root. Copy `private/config.example.php` there as `config.php` and enter the database name, username and password. Use `localhost` unless your host specifies another database hostname.
5. Copy `private/setup-login.php` into the private directory. In cPanel Terminal run:

   ```sh
   php /home/CPANELUSER/quorum-private/setup-login.php
   ```

   Choose a unique administrator username and a unique password of 16-72 characters (a password manager is recommended). Copy the three generated configuration values into `config.php`. Add the generated secret manually to a trusted authenticator app: SHA-1, six digits, 30 seconds. Keep the secret offline in a secure password manager for recovery; do not use a public QR generator. No default credentials or bypass/recovery URLs exist. Keep the server clock synchronized.
6. Upload the **contents** of `vote/`, including `.htaccess`, into `/home/CPANELUSER/public_html/vote/`. Copy the complete `private/assets/` folder to `/home/CPANELUSER/quorum-private/assets/`. Do not upload the `private/` folder or this complete ZIP into a publicly accessible directory. For an upgrade, remove old public app.js, persistence.js, style.css, xlsx.full.min.js and index.html files; the new deny-by-default .htaccess also blocks leftover files.
7. Set the private directory to permission 700 and config.php to 600 where supported by the PHP handler. Public directories normally use 755 and public files 644. Never use 777.
8. If the domain's document root differs from the assumed path, update `$configPath` in `vote/bootstrap.php` to the absolute path of your private configuration file. Keep it outside every public document root.
9. Visit `https://gemb.co.za/vote/`, sign in, and perform the acceptance checks below. HTTPS is required; local HTTP will not retain the secure session cookie.

Assets and API requests use relative URLs, so they resolve beneath `/vote/`. Session and redirect paths intentionally use `/vote/`. The canonical hostname in `.htaccess` is `gemb.co.za`.

## Acceptance checks on the host

- HTTP redirects to HTTPS; an unsigned visitor sees only the workspace login.
- In browser developer tools, unsigned `api.php` requests return 401; signed requests without the CSRF header return 403.
- Change the meeting title, wait until the saving banner disappears, and reload. The title must persist.
- Open two tabs at the same saved revision. Save a change in one and then change the other. The second must report a conflict and disable editing.
- Complete a demo ballot and reload after saving. Verify it is retained and the interface prevents a second submission for that owner.
- Sign out; API access must fail. Sessions expire after 15 minutes without page/API activity and always after eight hours. Asset requests do not extend idle time. Password or authenticator-secret rotation invalidates existing sessions on the next request.
- Confirm `/vote/bootstrap.php` and `/vote/auth.php` return 403 and the private config is inaccessible by URL.
- Without signing in, request `/vote/asset.php?name=app.js`, `/vote/asset.php?name=style.css`, and `/vote/api.php`: each must return 401 without file/data content. Old static URLs must return 403 or 404, even with arbitrary query strings. Login must never include app JavaScript.
- Signed in, request `asset.php?name=../config.php`: it must return 404. Only four fixed asset names are allowed.
- Sign in using the correct password and a wrong authenticator code: access must be denied. Reusing a successfully accepted code must fail. Ten attempts from an IP or 30 total attempts in a 15-minute window must block further attempts until the window expires, including in fresh browser sessions. Limits count successful attempts too; the global limit can temporarily lock out an administrator under attack.
- Verify session cookies are Secure, HttpOnly and SameSite=Strict, and protected responses have no-store and Content-Security-Policy headers.
- Verify cPanel error logs show no PHP or database errors.

## Operations and troubleshooting

Back up the MariaDB database using cPanel backups and store a separate secure copy of configuration. The `quorum_workspace` row holds the whole shared prototype state, with a 2 MB API request limit; this is intended for demonstration-scale use. An owner-linked audit export remains a client-generated checksum, not tamper-proof evidence.

`Configuration missing`: check the private file path. `Storage unavailable`: check database credentials, schema import, PDO MySQL, and server error logs. A 500 before login may indicate unsupported .htaccess directives; ask the host to enable the stated Apache requirements. A redirect loop may indicate TLS termination at a reverse proxy; ask the host for the correct HTTPS rewrite rule. Do not trust arbitrary forwarded HTTPS headers.

To reset fictional data, sign out of all tabs, back up the database, then delete row id=1 from `quorum_workspace` in phpMyAdmin. The next signed-in load will seed the original demo again. This discards all saved changes.

## Validation performed before packaging

- JavaScript syntax checks for the app and persistence adapter.
- Original automated interface checks: all screens, registration, OTP rejection/verification, complete and duplicate ballots, ineligible and closed access, CSV quoting/formula safety, imports and submitted-owner preservation.
- See SECURITY-CHECKS.md for the preparation checks and their limits. Apache/MariaDB integration and HTTPS acceptance checks above are still required on the host before sharing the deployment.

## Work required before real voting

Replace the prototype owner flows with server-authenticated accounts, verified personal invitations, password hashing, rate-limited authentication and OTP delivery. Enforce meeting status, eligibility, complete ballots and unique owner submissions in database transactions. Add granular roles, retained server audit events, privacy controls, recovery procedures, and review the HOA's approved quorum and voting rules. The workspace-level password gate is not a substitute for these controls.

## Security boundary and recovery

Only index.php, api.php and asset.php are permitted as public files by Apache. Each enforces its own server-side login checks; asset.php serves an explicit fixed allowlist from private storage. Login is the only public page. Authentication never trusts a URL parameter, browser role, local storage value, or forwarded IP header. HTTPS must be reported by the server itself; reverse-proxy hosting must arrange trusted HTTPS configuration server-side. Do not remove the PHP HTTPS check to work around a proxy configuration problem.

The login layer protects the whole demonstration workspace. It does not provide separate voter accounts or make browser-submitted ballots authoritative. An authenticated administrator can alter the full demo state. Server security also depends on cPanel, filesystem access, Apache configuration and other applications on this hosting account; do not claim the deployment cannot ever be breached.

To recover a lost authenticator, use cPanel's authenticated File Manager/Terminal to replace the private TOTP secret, enroll the new secret offline, and reset `quorum_auth_state.last_totp_counter` to -1 in phpMyAdmin. To change the password, generate a new hash with the setup helper and replace the private hash. Never create a web-accessible reset script. Include the login-limit and authentication-state tables in backups. Private directory permissions must allow only the account's PHP user to read the files.
