# Backup-tjänsten för Stimma (2026-10-06). Körs av Ofelia via job-exec,
# se labels på tjänsten `backup` i docker-compose.yml.
FROM alpine:3.20

RUN apk add --no-cache mariadb-client restic curl ca-certificates tzdata gzip

ENV TZ=Europe/Stockholm

COPY backup.sh restore-test.sh larm.sh /usr/local/bin/
RUN chmod 755 /usr/local/bin/backup.sh /usr/local/bin/restore-test.sh && chmod 644 /usr/local/bin/larm.sh

# Containern står och väntar; Ofelia kör skripten i den enligt schemat.
CMD ["sh", "-c", "trap 'exit 0' TERM; while :; do sleep 3600 & wait $!; done"]
