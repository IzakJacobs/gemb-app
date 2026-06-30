# GEMB Access System — Code Format Convention
## Canonical definition (all files must follow this)

| Prefix | Type             | Format          | Example      | Verify endpoint          |
|--------|------------------|-----------------|--------------|--------------------------|
| 3      | Visitor pass     | 3 + 5 digits    | 312847       | visitor_qr_verify.php    |
| 7      | Service provider | 7 + 5 digits    | 712394       | service_qr_verify.php    |
| 1      | Resident vehicle | 1 + 5 digits    | 156201       | (LPR / resident flow)    |

## Rules
- All codes are exactly 6 characters: one prefix digit + 5 random digits
- Codes are stored in the DB as VARCHAR(10) to allow future expansion
- The first digit alone determines which verify endpoint handles the code
- code_entry.php reads the first character and routes accordingly
- QR payload = full verify URL, e.g.:
    https://gemb.co.za/visitor_qr_verify.php?code=312847
    https://gemb.co.za/service_qr_verify.php?code=712394

## What was wrong (now fixed)
- service_qr_verify.php had regex ^9\d{5}$ — should be ^7\d{5}$
- visitor_qr.php had regex ^3\d{5}$ — correct but new codes like 78B8CD43
  were being generated in hex format; reverted to 3+5digit numeric format
- code_entry.php must route on first digit, not guess the endpoint

## Code generation (in add_visitor.php / add_service_provider.php)
  Visitor:          '3' . str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT)
  Service provider: '7' . str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT)
