#!/bin/sh
# Nattlig backup av Stimma (2026-10-06). Körs av Ofelia kl 02:30.
#
# 1. Konsekvent databasdump (--single-transaction, Stimma behöver inte stoppas)
#    till /backup/local — de 7 senaste sparas lokalt för snabb återställning.
# 2. Om S3 är konfigurerat: restic skickar dumpen, uppladdade filer,
#    PUB-dokument och konfiguration krypterat till S3, och gallrar enligt
#    beslutad lagringstid (7 dagliga, 4 veckovisa, 3 månatliga).
# 3. Vid fel: e-post till BACKUP_ALERT_EMAIL via larmvägen (Postal), se larm.sh.
set -eu
. /usr/local/bin/larm.sh
set -o pipefail

LOKAL=/backup/local
LOGG="$LOKAL/backup.log"
mkdir -p "$LOKAL"

logga() { echo "$(date '+%F %T') $*" | tee -a "$LOGG"; }

larma() {
    logga "FEL: $1"
    skicka_larm "Backup misslyckades" "$(printf '%s\r\n\r\nSenaste loggraderna:\r\n%s' "$1" "$(tail -20 "$LOGG")")" \
        || logga "Kunde inte heller skicka larmmejlet"
}

# Fel någonstans nedan → ett larm. set -e avbryter, trap fångar; FELORSAK
# sätts före steg som kan fallera så att mejlet säger vad som gick fel.
FELORSAK="okänt steg"
trap 'status=$?; rm -f "${FIL:-}.tmp"; [ $status -ne 0 ] && larma "$FELORSAK (kod $status)"' EXIT

# ─── 1. Databasdump ────────────────────────────────────────────────────────
STAMP=$(date +%Y%m%d-%H%M%S)
FIL="$LOKAL/stimma-$STAMP.sql.gz"
FELORSAK="Databasdumpen misslyckades"
logga "Databasdump startar"
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb-dump -h db -u root \
    --single-transaction --routines --triggers --events \
    --default-character-set=utf8mb4 "$DB_DATABASE" | gzip -9 > "$FIL.tmp"
# Rimlighetskontroll: en hel dump slutar med "Dump completed".
FELORSAK="Databasdumpen blev ofullständig"
gzip -dc "$FIL.tmp" | tail -1 | grep -q "Dump completed" || { rm -f "$FIL.tmp"; exit 1; }
mv "$FIL.tmp" "$FIL"
chmod 600 "$FIL"
ln -sf "$(basename "$FIL")" "$LOKAL/stimma-senaste.sql.gz"
logga "Databasdump klar: $(basename "$FIL") ($(du -h "$FIL" | cut -f1))"

# Lokalt: spara de 7 senaste dumparna
ls -1t "$LOKAL"/stimma-2*.sql.gz 2>/dev/null | tail -n +8 | while read -r gammal; do rm -f "$gammal"; done

# ─── 2. Kopia till S3 med restic ───────────────────────────────────────────
if [ -z "${RESTIC_REPOSITORY:-}" ] || [ -z "${AWS_ACCESS_KEY_ID:-}" ]; then
    logga "S3 är inte konfigurerat ännu — bara lokal dump"
    exit 0
fi
export RESTIC_CACHE_DIR="$LOKAL/.restic-cache"

FELORSAK="restic kunde inte nå eller initiera förrådet i S3"
if ! restic cat config >/dev/null 2>&1; then
    logga "Initierar restic-förrådet i $RESTIC_REPOSITORY"
    restic init
fi

FELORSAK="restic backup till S3 misslyckades"
logga "restic backup startar"
restic backup --tag nightly --host stimma \
    "$FIL" /data/upload /data/docs /data/config /data/env 2>&1 | tail -5 | while read -r rad; do logga "  $rad"; done

FELORSAK="restic forget/prune (gallring) misslyckades"
restic forget --host stimma --keep-daily 7 --keep-weekly 4 --keep-monthly 3 --prune 2>&1 \
    | tail -3 | while read -r rad; do logga "  $rad"; done

# Söndagar: kontrollera förrådet och läs en tiondel av datat
if [ "$(date +%u)" = "7" ]; then
    FELORSAK="restic check hittade fel i förrådet"
    restic check --read-data-subset=1/10 >/dev/null 2>&1
    logga "restic check ok"
fi

logga "Backup klar"
