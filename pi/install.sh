#!/bin/bash
# ============================================================
# GEMB Pi Node — Install Script
# Run as root on a fresh Raspberry Pi OS (Bookworm/Bullseye)
#
#   sudo bash install.sh
#
# What it does:
#   1. Installs Apache, PHP, required extensions
#   2. Deploys the GEMB Pi files to /var/www/html/gemb/
#   3. Locks down file permissions
#   4. Adds a cron job for 5-minute sync
#   5. Optionally sets a static IP
# ============================================================

set -e

GEMB_DIR="/var/www/html/gemb"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== GEMB Pi Node Install ==="

# ── 1. System packages ────────────────────────────────────
echo "[1/5] Installing packages..."
apt-get update -q
apt-get install -y apache2 php php-sqlite3 php-curl sqlite3 cron

# Enable Apache mod_rewrite (for .htaccess if needed later)
a2enmod rewrite

# ── 2. Deploy files ───────────────────────────────────────
echo "[2/5] Deploying files to ${GEMB_DIR}..."
mkdir -p "${GEMB_DIR}/data"

cp "${SCRIPT_DIR}/sync.php"           "${GEMB_DIR}/sync.php"
cp "${SCRIPT_DIR}/verify.php"        "${GEMB_DIR}/verify.php"
cp "${SCRIPT_DIR}/override_check.php" "${GEMB_DIR}/override_check.php"
# config.php is gitignored (contains secrets) — must be created manually
if [ ! -f "${GEMB_DIR}/config.php" ]; then
    echo "WARNING: ${GEMB_DIR}/config.php not found — create it manually before running sync.php"
fi

# Create a simple index that redirects to verify
cat > "${GEMB_DIR}/index.php" <<'PHP'
<?php header('Location: verify.php'); exit;
PHP

# .htaccess — deny direct access to config and data
cat > "${GEMB_DIR}/.htaccess" <<'HTACCESS'
Options -Indexes
<FilesMatch "^(config|sync)\.php$">
    Require all denied
</FilesMatch>
HTACCESS

# ── 3. Permissions ────────────────────────────────────────
echo "[3/5] Setting permissions..."
chown -R www-data:www-data "${GEMB_DIR}"
chmod 750 "${GEMB_DIR}/data"
chmod 640 "${GEMB_DIR}/config.php"
chmod 640 "${GEMB_DIR}/sync.php"
chmod 640 "${GEMB_DIR}/override_check.php"
chmod 644 "${GEMB_DIR}/verify.php"

# ── 4. Cron job (sync every 5 minutes) ───────────────────
echo "[4/5] Installing cron jobs..."
CRON_SYNC="*/5 * * * * php ${GEMB_DIR}/sync.php >> /var/log/gemb_sync.log 2>&1"
CRON_OVR1="* * * * * php ${GEMB_DIR}/override_check.php >> /var/log/gemb_override.log 2>&1"
CRON_OVR2="* * * * * sleep 30 && php ${GEMB_DIR}/override_check.php >> /var/log/gemb_override.log 2>&1"

EXISTING=$(crontab -l 2>/dev/null || true)
NEW_CRON="$EXISTING"
echo "$EXISTING" | grep -qF "gemb_sync"     || NEW_CRON="${NEW_CRON}"$'\n'"${CRON_SYNC}"
echo "$EXISTING" | grep -qF "override_check.php >> /var/log/gemb_override" || \
  NEW_CRON="${NEW_CRON}"$'\n'"${CRON_OVR1}"$'\n'"${CRON_OVR2}"
echo "$NEW_CRON" | crontab -

# Rotate sync log so it doesn't grow forever
cat > /etc/logrotate.d/gemb_sync <<'LOGROTATE'
/var/log/gemb_sync.log /var/log/gemb_override.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
LOGROTATE

# ── 5. Restart Apache ─────────────────────────────────────
echo "[5/5] Restarting Apache..."
systemctl restart apache2
systemctl enable apache2
systemctl enable cron

# ── Done ──────────────────────────────────────────────────
echo ""
echo "============================================"
echo " GEMB Pi node installed."
echo " Verify page: http://$(hostname -I | awk '{print $1}')/gemb/verify.php"
echo ""
echo " NEXT STEPS:"
echo "   1. Edit ${GEMB_DIR}/config.php"
echo "      Set PI_SYNC_KEY to match gemb.ink config.php"
echo "   2. Run the first sync manually:"
echo "      php ${GEMB_DIR}/sync.php"
echo "   3. Open the verify page in the guard's browser"
echo "   4. Write the IP on the guard house wall:"
echo "      OFFLINE MODE: http://$(hostname -I | awk '{print $1}')/gemb/verify.php"
echo "============================================"
