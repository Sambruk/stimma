#!/bin/sh
# Månatligt återställningstest (2026-10-06). Körs av Ofelia kl 04:00 den 1:a.
#
# Hämtar den senaste databasdumpen — från S3 om restic är konfigurerat,
# annars den lokala — läser in den i en tillfällig databas på samma
# MariaDB-server, jämför tabellerna mot den skarpa databasen och tar bort
# testdatabasen igen. Kontrollerar också att uppladdade filer finns i S3.
# Vid fel: samma e-postlarm som backup.sh.
set -eu
. /usr/local/bin/larm.sh
set -o pipefail

LOKAL=/backup/local
LOGG="$LOKAL/backup.log"
TESTDB=stimma_aterstallningstest
ARBETE=$(mktemp -d)
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"

logga() { echo "$(date '+%F %T') [återställningstest] $*" | tee -a "$LOGG"; }

larma() {
    logga "FEL: $1"
    skicka_larm "Återställningstestet misslyckades" "$(printf '%s\r\n\r\nSenaste loggraderna:\r\n%s' "$1" "$(tail -20 "$LOGG")")" \
        || logga "Kunde inte heller skicka larmmejlet"
}

stada() {
    mariadb -h db -u root -e "DROP DATABASE IF EXISTS \`$TESTDB\`" 2>/dev/null || true
    rm -rf "$ARBETE"
}

FELORSAK="okänt steg"
trap 'status=$?; [ $status -ne 0 ] && larma "$FELORSAK (kod $status)"; stada' EXIT

# ─── Hämta dumpen ──────────────────────────────────────────────────────────
if [ -n "${RESTIC_REPOSITORY:-}" ] && [ -n "${AWS_ACCESS_KEY_ID:-}" ]; then
    export RESTIC_CACHE_DIR="$LOKAL/.restic-cache"
    FELORSAK="Kunde inte hämta senaste dumpen ur S3"
    restic restore latest --host stimma --target "$ARBETE/s3" --include "/backup/local" >/dev/null
    DUMP=$(ls -1t "$ARBETE"/s3/backup/local/stimma-2*.sql.gz | head -1)
    KALLA="S3"
    FELORSAK="Uppladdade filer saknas i S3-backupen"
    FILER=$(restic ls latest --host stimma /data/upload | grep -c '^/data/upload/')
    [ "$FILER" -gt 0 ]
    logga "S3: $FILER uppladdade filer finns i senaste ögonblicksbilden"
else
    DUMP=$(ls -1t "$LOKAL"/stimma-2*.sql.gz | head -1)
    KALLA="lokal disk (S3 ej konfigurerat)"
fi
logga "Testar $(basename "$DUMP") från $KALLA"

# ─── Läs in i en tillfällig databas och jämför ─────────────────────────────
FELORSAK="Dumpen gick inte att läsa in"
mariadb -h db -u root -e "DROP DATABASE IF EXISTS \`$TESTDB\`; CREATE DATABASE \`$TESTDB\` CHARACTER SET utf8mb4"
gzip -dc "$DUMP" | mariadb -h db -u root "$TESTDB"

antal_tabeller() {
    mariadb -h db -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"
}
FELORSAK="Den återställda databasen saknar tabeller jämfört med den skarpa"
SKARP=$(antal_tabeller "$DB_DATABASE")
TEST=$(antal_tabeller "$TESTDB")
[ "$TEST" -eq "$SKARP" ]

FELORSAK="Den återställda databasen saknar användare eller kurser"
ANV=$(mariadb -h db -u root -N -e "SELECT COUNT(*) FROM \`$TESTDB\`.users")
KURS=$(mariadb -h db -u root -N -e "SELECT COUNT(*) FROM \`$TESTDB\`.courses")
[ "$ANV" -gt 0 ] && [ "$KURS" -gt 0 ]

logga "OK: $TEST av $SKARP tabeller, $ANV användare, $KURS kurser återställda"
