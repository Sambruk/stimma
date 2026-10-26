# Gemensam larmfunktion för backup.sh och restore-test.sh (2026-10-23).
#
# Skickar i första hand via LARM_MAIL_* (Postal) och i andra hand via den
# ordinarie vägen MAIL_* (Scaleway). Samma upplägg som cron/mail_health_alert.php:
# larmet ska komma fram även när den ordinarie e-postvägen är det som är trasigt.
#
# skicka_larm <ämne> <brödtext>   – returnerar 1 om ingen väg fungerade

# skicka_via <prefix> <brevfil>: prefix är LARM_MAIL eller MAIL
skicka_via() {
    eval "vard=\${${1}_HOST:-} port=\${${1}_PORT:-25} anv=\${${1}_USERNAME:-} los=\${${1}_PASSWORD:-} krypt=\${${1}_ENCRYPTION:-}"
    [ -n "$vard" ] || return 1
    # Port 465 / ssl = implicit TLS (smtps). Annars klartext, som Stimmas egen mail.php.
    schema=smtp
    { [ "$port" = "465" ] || [ "$krypt" = "ssl" ]; } && [ "$port" != "25" ] && schema=smtps
    set -- --url "$schema://$vard:$port" --mail-from "${MAIL_FROM_ADDRESS:-noreply@sambruk.se}" \
        --mail-rcpt "$BACKUP_ALERT_EMAIL" --upload-file "$2" --max-time 60 -s -S
    if [ -n "$anv" ]; then
        # Postal erbjuder CRAM-MD5 först, men det nekas (535) — LOGIN fungerar.
        set -- "$@" --user "$anv:$los" --login-options "AUTH=LOGIN"
    fi
    curl "$@"
}

skicka_larm() {
    [ -n "${BACKUP_ALERT_EMAIL:-}" ] || return 0
    larmbrev=$(mktemp)
    printf 'From: %s\r\nTo: %s\r\nSubject: [Stimma-larm] %s\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n%s\r\n' \
        "${MAIL_FROM_ADDRESS:-noreply@sambruk.se}" "$BACKUP_ALERT_EMAIL" "$1" "$2" > "$larmbrev"
    resultat=1
    if skicka_via LARM_MAIL "$larmbrev" || skicka_via MAIL "$larmbrev"; then
        resultat=0
    fi
    rm -f "$larmbrev"
    return $resultat
}
