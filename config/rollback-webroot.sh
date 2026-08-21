#!/bin/bash
# Reverserar flytten till public/. Kör från /opt/app/stimma.
set -e
cd /opt/app/stimma
for d in admin api upload images docs; do [ -d "public/$d" ] && mv "public/$d" .; done
rm -f public/include public/lib public/allowed_domains.txt
mv public/*.php public/*.html public/favicon.ico . 2>/dev/null || true
rm -f public/.htaccess
rmdir public 2>/dev/null || true
sed -i '/vhost.conf:\/etc\/apache2\/sites-enabled/d' docker-compose.yml
docker compose up -d
echo "Rollback klar."
