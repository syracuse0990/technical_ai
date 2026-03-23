#!/bin/bash
# VPS Deployment Script for technical_ai
# Run as root or with sudo on Ubuntu 22.04/24.04

set -e

APP_DIR="/var/www/technical_ai"
APP_USER="www-data"

echo "=== technical_ai VPS Deployment ==="

# 1. System packages
echo "[1/9] Installing system packages..."
apt update && apt install -y \
    nginx \
    postgresql postgresql-contrib \
    php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath \
    supervisor \
    tesseract-ocr \
    certbot python3-certbot-nginx \
    python3 python3-venv python3-pip \
    unzip git

# 2. Composer
echo "[2/9] Installing Composer..."
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# 3. Node.js (for building frontend)
echo "[3/9] Installing Node.js..."
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt install -y nodejs
fi

# 4. PostgreSQL setup
echo "[4/9] Setting up PostgreSQL..."
sudo -u postgres psql -c "CREATE DATABASE technical_ai;" 2>/dev/null || true
sudo -u postgres psql -c "CREATE USER technical_ai_user WITH PASSWORD 'CHANGE_THIS_PASSWORD';" 2>/dev/null || true
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE technical_ai TO technical_ai_user;" 2>/dev/null || true
sudo -u postgres psql -d technical_ai -c "GRANT ALL ON SCHEMA public TO technical_ai_user;" 2>/dev/null || true
sudo -u postgres psql -d technical_ai -c "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO technical_ai_user;" 2>/dev/null || true

# 5. Application setup
echo "[5/9] Setting up application..."
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env  # Edit .env before running migrations!
# php artisan key:generate
# php artisan migrate --force

# 6. Permissions
echo "[6/9] Setting permissions..."
chown -R "$APP_USER":"$APP_USER" "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# 7. Embedding server setup
echo "[7/9] Setting up embedding server..."
cd "$APP_DIR/embedding-server"
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
deactivate

cp "$APP_DIR/deployment/embedding-server.service" /etc/systemd/system/technical_ai-embedding.service
systemctl daemon-reload
systemctl enable technical_ai-embedding
systemctl start technical_ai-embedding

# 8. Config files
echo "[8/9] Installing config files..."
cd "$APP_DIR"

cp "$APP_DIR/deployment/nginx.conf" /etc/nginx/sites-available/technical_ai
ln -sf /etc/nginx/sites-available/technical_ai /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

cp "$APP_DIR/deployment/supervisor.conf" /etc/supervisor/conf.d/technical_ai.conf
cp "$APP_DIR/deployment/php-fpm.conf" /etc/php/8.4/fpm/pool.d/technical_ai.conf
rm -f /etc/php/8.4/fpm/pool.d/www.conf  # Remove default pool

# 9. Start services
echo "[9/9] Starting services..."
systemctl restart php8.4-fpm
supervisorctl reread
supervisorctl update
supervisorctl start technical_ai-workers:*

echo ""
echo "=== Deployment complete ==="
echo "Next steps:"
echo "  1. Edit $APP_DIR/.env with your database and API credentials"
echo "  2. Run: php artisan key:generate"
echo "  3. Run: php artisan migrate --force"
echo "  4. Run: certbot --nginx -d your-domain.com"
echo "  5. Update server_name in /etc/nginx/sites-available/technical_ai"
echo ""
echo "Manage workers:"
echo "  supervisorctl status technical_ai-workers:*"
echo "  supervisorctl restart technical_ai-workers:*"
echo ""
echo "Manage embedding server:"
echo "  systemctl status technical_ai-embedding"
echo "  systemctl restart technical_ai-embedding"
