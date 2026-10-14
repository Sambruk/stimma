# Insyns-API:t – rapport

*Rapport till Thomas Wennersten, 14 oktober 2026. Underlag: Magnus Nilsson (AIPL), 14 oktober 2026.*

API:t är byggt och testat. Det består av nio endpoints under `/api/v1/insyn/` och en ny nyckeltyp, systemnyckeln, som skapas och återkallas i **Admin → Systemnycklar**.
- **Status:** koden är driftsatt men inte aktiverad. Innan migrering 051 är körd svarar API:t `503` ("inte aktiverat ännu"). Migreringen väntar på ditt OK.
- **Dokumentation:** `docs/API-INSYN.md` beskriver endpoints, nyckelhantering, skydd och avvikelser.
- **Exempelsvar:** testdatans svar finns i `docs/api-insyn-exempel/`.

## Vad som finns

| | |
|---|---|
| Endpoints | `health`, `organizations`, `admins`, `editors`, `pub-agreements`, `courses`, `courses/{id}/stats`, `usage`, `learning-paths`. Bara GET. |
| Nyckel | `sts_` + 64 hex. Den ligger i en egen tabell (`system_api_keys`) och bara hashen lagras. Den gäller alla organisationer men bara `/insyn/`. IP-begränsning är valfri. |
| Skapa / återkalla | Superadmin i **Admin → Systemnycklar**. Nyckeln visas en gång. En återkallad nyckel slutar fungera direkt och kan inte aktiveras igen. |
| Skydd | Varje anrop loggas (`system_api_log`, de 50 senaste visas i adminvyn). Rate limit är 60 anrop per minut och 1 000 per timme. Efter 20 felaktiga nycklar på 10 minuter spärras adressen. |
| Uppgiftsminimering | Namn och e-post lämnas bara ut för administratörer, redaktörer och undertecknare av PUB-avtal. Inga uppgifter om enskilda deltagares framsteg lämnas ut. Grupper under 5 redovisas som `"<5"`, och medelvärden över färre än 5 personer som `null`. |

## Test

`tests/test_insyn.php` innehåller 105 tester, och alla är godkända. Hela sviten har 283 tester, alla gröna (de befintliga 178 är oförändrade). Testdatan omfattar tre organisationer. Testerna visar att:

- **nyckeln bara ger läsåtkomst:** POST, PUT, PATCH och DELETE ger `405`. Databasens kontrollsumma är dessutom oförändrad efter alla anrop.
- **organisationsnycklar (`stm_`) inte når `/insyn/`,** och systemnyckeln når inte synk-API:t.
- **inga deltagaruppgifter lämnas ut:** inga e-postadresser, namn, diplomnummer, telefonnummer eller IP-adresser för deltagare förekommer i något svar.
- **nyckeln hanteras av superadmin:** bara superadmin kan skapa och återkalla nycklar, och en org-admin nekas både sidan och POST.
- **skydden fungerar:** loggning, rate limit, IP-begränsning (även för nät), spärren och `503` före migreringen.

Jag provkörde också alla endpoints mot en kopia av nattens backup i testmiljöns tillfälliga databas. Kopian raderades efteråt. Alla anrop gav 200. Det långsammaste var `/organizations` med 200 organisationer per sida, som tog cirka 1 sekund. Det gav följande siffror:

- 168 organisationer, varav 4 grupperade.
- 60 administratörer och 123 redaktörer.
- 9 organisationer har gällande PUB-avtal, varav 6 på version 4.
- 146 kurser, varav 42 kopior (alla med käll-id).
- 42 baskurser.

## Två saker som upptäcktes på vägen

1. **`users.last_login_at` skrivs aldrig av Stimma.** Fältet är tomt för alla användare. API:t läser därför senaste inloggning ur aktivitetsloggen, både inloggning med e-postlänk och kom-ihåg-mig.
   - Med loggen syns 131 aktiva användare de senaste 30 dagarna och 276 de senaste 90.
   - Vill du att inloggningen också skriver fältet? Det är en enrads-ändring i inloggningen.
2. **`original_organization_domain` är satt även på originalkurser,** och är då lika med kursens egen domän. API:t räknar därför bara en kurs som kopia när ursprunget är en annan domän.

## Att besluta

1. **Köra migrering 051 i produktionen.** Den skapar två nya, tomma tabeller och rör ingen befintlig data.
   `docker exec -i stimma-db-1 sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < migrations/051_system_api_keys.sql`
2. **Skapa Magnus nyckel** och lägg `STIMMA_API_URL=https://stimma.sambruk.se/api/v1/insyn` och `STIMMA_API_KEY` i Magnus `.env`.
   - Förslag: skapa först en nyckel utan IP-begränsning och låt Magnus anropa `/health`.
   - Adminvyn visar då vilken adress anropet kom från.
   - Skapa sedan en ny nyckel som är låst till den adressen, och återkalla den första.
3. **PUB-avtalet.** Behandlingen gäller förvaltning och uppföljning, och mottagaren är Sambruks egen drift. Den behöver rymmas i PUB-avtalet, så det är ditt beslut om avtalstexten behöver kompletteras.
4. **Sambruks organisation omfattar domänen `gmail.com`.** Därför redovisas gmail-konton, även administratörer, som Sambruk i `/organizations` och `/admins`. Är det avsiktligt?
