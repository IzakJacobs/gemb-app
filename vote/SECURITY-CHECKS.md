# Security verification

Preparation checks passed using PHP 8.4.24 CLI:

- Syntax lint on all shipped PHP files.
- Unauthenticated API and asset requests return 401 without protected content.
- Unauthenticated index renders only the login form, including authenticator input, without application scripts or CSRF bootstrap data.
- PHP rejects requests that the web server does not mark HTTPS.
- Authenticated requests can retrieve only the four fixed private assets.
- Asset traversal attempts return 404.
- Expired idle sessions, sessions older than eight hours, and sessions with an obsolete credential fingerprint cannot retrieve assets.
- API requests without a valid CSRF token fail before database access.
- Authenticator values match independently generated HMAC-SHA1 TOTP values; values outside the permitted time window fail.
- JavaScript syntax and original application behavior checks pass.

The executable smoke checks are in the prepared source workspace at `tests/security.py` and run with `python tests/security.py` when PHP is available. Set PHP_BIN if required.

These are CLI application checks, not live cPanel acceptance tests. Apache rule enforcement, cookie behavior over HTTPS, MariaDB concurrency, database-backed login attempt limits, and accepted-code replay rejection still require the host checks in DEPLOYMENT.md. No claim of an independent penetration test is made.

For upgrades: import the updated schema (it adds two authentication tables without deleting the workspace), install the new private configuration and assets, replace all public PHP files and .htaccess, and remove obsolete public static files. Until the private credentials and authentication tables are configured, the application fails closed.
