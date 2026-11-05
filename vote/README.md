# Quorum · HOA voting workspace

Interactive private prototype with managing-agent and owner flows. Open dist/index.html through static hosting.

Features: Excel/CSV accounting-file imports with validation, levy and entitlement review, registration/meeting invitation previews, demo account and OTP flow, complete ballots with For/Against/Abstained, one ballot per owner, participation and results, owner-linked vote exports, audit events, closing and final audit package with payload checksum.

## Runtime scope
This is explicitly a demonstration. State is in memory and resets on reload. Credentials are not stored or authenticated; OTP is shown as a demo email. No email is delivered. Never use real passwords or owner data.

Before a real AGM: implement protected server storage, transactional submission uniqueness, authentication and MA roles, password hashing, expiring signed invitation tokens, rate-limited email OTP, delivery service, accounting connector and synchronization, immutable retained audit records, privacy controls, and approved jurisdiction/HOA-specific rules (weights, co-ownership, proxies, quorum and thresholds). Current rules: one owner per unit, one vote per owner, eligible-owner denominator, ordinary majority >50%, special >=75%, abstentions excluded from approval denominator. Submitted owners cannot be replaced during imports. The audit checksum is not a signature or tamper-proof storage.

## Validation
Run `node --check dist/app.js` and `node check.mjs`. The latter covers rendering all screens, registration, OTP, incomplete/duplicate ballots, ineligible/closed access, import validation, preserving submitted ownership, and CSV formula safety. Browser QA unavailable because the managed preview tools were not installed.

The environment did not contain Sites project or source helpers, so this buildless static app is committed using Git and packaged with tar using the Sites archive contract.
