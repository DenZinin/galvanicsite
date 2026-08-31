#!/usr/bin/env bash
# Idempotent setup for the galvanictech.ru static site.
# Installs Apache + PHP, configures a vhost that mirrors production
# (clean URLs via .htaccess, PHP handler for the Telegram contact form),
# and serves the site on port 8080.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCROOT="$REPO_ROOT/galvanictech.ru"
PORT=8080

echo "==> Installing Apache + PHP (idempotent)"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y --no-install-recommends \
  apache2 libapache2-mod-php php-cli php-curl

echo "==> Enabling Apache modules"
sudo a2enmod rewrite expires >/dev/null

echo "==> Listening on port $PORT"
sudo sed -i -E "s/^Listen[[:space:]]+80$/Listen $PORT/" /etc/apache2/ports.conf

echo "==> Writing virtual host (docroot: $DOCROOT)"
sudo tee /etc/apache2/sites-available/galvanictech.conf >/dev/null <<CONF
<VirtualHost *:$PORT>
    ServerName localhost
    DocumentRoot $DOCROOT

    <Directory $DOCROOT>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Forward Cloud Agent secrets (if provided) to the PHP handler.
    # load_env.php prefers server-provided vars over the .env file.
    PassEnv RECAPTCHA_SECRET_KEY TELEGRAM_BOT_TOKEN TELEGRAM_CHAT_ID

    ErrorLog \${APACHE_LOG_DIR}/galvanictech-error.log
    CustomLog \${APACHE_LOG_DIR}/galvanictech-access.log combined
</VirtualHost>
CONF

echo "==> Enabling site"
sudo a2dissite 000-default >/dev/null 2>&1 || true
sudo a2ensite galvanictech >/dev/null

# Create a local dev .env with placeholders so the contact-form handler runs
# past its config check. Real values (RECAPTCHA_SECRET_KEY, TELEGRAM_BOT_TOKEN,
# TELEGRAM_CHAT_ID) should be provided via the Secrets panel; PassEnv forwards
# them and they override these placeholders.
if [ ! -f "$REPO_ROOT/.env" ] && [ ! -f "$DOCROOT/.env" ]; then
  echo "==> Creating placeholder $REPO_ROOT/.env for local dev"
  cat > "$REPO_ROOT/.env" <<'ENVEOF'
RECAPTCHA_SECRET_KEY=dev-placeholder-recaptcha-secret
TELEGRAM_BOT_TOKEN=dev-placeholder-bot-token
TELEGRAM_CHAT_ID=dev-placeholder-chat-id
ENVEOF
fi

# Ensure the tmpfs-backed runtime dirs exist (empty on fresh boots) so
# apache2ctl can run here and on every start.
sudo mkdir -p /var/run/apache2 /var/lock /run/lock

# Validate config without needing a running server.
sudo apache2ctl configtest

echo "==> Install complete. Start with: .cursor/start.sh (served on :$PORT)"
