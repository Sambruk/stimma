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

# Påminnelse innan S3-nyckeln går ut (2026-11-11). Nyckeln (Scaleway IAM-applikationen backup-s3)
# delas med Årshjul, så varningen finns bara här. Mejl 30, 14, 7, 3, 2, 1 och 0 dagar före – och
# varje natt efteråt. Varningen stoppar inte backupen.
if [ -n "${BACKUP_S3_KEY_EXPIRES:-}" ]; then
    if utgang=$(date -d "$BACKUP_S3_KEY_EXPIRES" +%s 2>/dev/null); then
        # Hela dagar från midnatt i dag; ±12 h avrundar så att sommartid inte ger fel dag
        skillnad=$(( utgang - $(date -d "$(date +%F)" +%s) ))
        if [ "$skillnad" -ge 0 ]; then dagar=$(( (skillnad + 43200) / 86400 )); else dagar=$(( (skillnad - 43200) / 86400 )); fi
        case "$dagar" in
            30|14|7|3|2|1|0|-*)
                logga "VARNING: S3-nyckeln går ut $BACKUP_S3_KEY_EXPIRES ($dagar dagar kvar)"
                skicka_larm "S3-nyckeln går ut $BACKUP_S3_KEY_EXPIRES" "$(printf 'Backupens S3-nyckel (Scaleway, IAM-applikationen backup-s3) går ut %s – %s dagar kvar.\r\nNär den gått ut slutar backupen för både Stimma och Årshjul att nå S3.\r\n\r\nSå förnyar du:\r\n1. Scaleway: IAM -> Applications -> backup-s3 -> API keys -> Generate API key (Object Storage: Yes, projektet med bucketen sambruk-backup).\r\n2. Byt BACKUP_S3_ACCESS_KEY_ID och BACKUP_S3_SECRET_ACCESS_KEY i /opt/app/stimma/.env och /opt/app/yearwheel/.env.\r\n3. Sätt BACKUP_S3_KEY_EXPIRES till det nya datumet i /opt/app/stimma/.env.\r\n4. docker compose up -d backup i båda katalogerna.\r\n5. Radera den gamla nyckeln i Scaleway.' "$BACKUP_S3_KEY_EXPIRES" "$dagar")" \
                    || logga "Kunde inte skicka påminnelsen om S3-nyckeln"
                ;;
        esac
    else
        logga "BACKUP_S3_KEY_EXPIRES är inte ett datum: $BACKUP_S3_KEY_EXPIRES"
    fi
fi

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
# --group-by host,tags (2026-11-16): utan den grupperar restic på sökvägarna, och dumpens filnamn
# byts varje natt → varje kopia blev en egen grupp och INGEN gallrades (13 st kvar på fem dygn).
restic forget --host stimma --group-by host,tags --keep-daily 7 --keep-weekly 4 --keep-monthly 3 --prune 2>&1 \
    | tail -3 | while read -r rad; do logga "  $rad"; done

# Söndagar: kontrollera förrådet och läs en tiondel av datat
if [ "$(date +%u)" = "7" ]; then
    FELORSAK="restic check hittade fel i förrådet"
    restic check --read-data-subset=1/10 >/dev/null 2>&1
    logga "restic check ok"
fi

logga "Backup klar"
