#!/bin/sh
# Kör Stimmas automatiska tester mot den fristående testmiljön (tests/docker-compose.yml).
# Rör aldrig produktionsdatabasen. Användning: tests/run.sh [test_770.php ...]
set -e
cd /opt/app/stimma/tests
docker compose up -d --build --wait >/dev/null 2>&1 || docker compose up -d --build --wait
# Testerna körs i web-containern; de når testdatabasen på 172.17.0.1:23306
FILES="$*"
[ -z "$FILES" ] && FILES=$(ls test_*.php)
RC=0
for f in $FILES; do
  echo "################ $f"
  docker exec -w /var/www/html/tests stimma-test-web-1 php "$f" || RC=1
done
exit $RC
