# Insyns-API:t – läs-API för Sambruks förvaltning

*Version 1.0, 14 oktober 2026.*

Med insyns-API:t kan Sambruk läsa uppgifter ur Stimma för förvaltningsråd, utskick och uppföljning. API:t kan inte ändra något. Den första användaren är Magnus Nilsson, Sambruks virtuella projektledare (AIPL), som körs i Sambruks egen drift.

- Bas-URL: `https://stimma.sambruk.se/api/v1/insyn/`
- Autentisering: `Authorization: Bearer sts_...` med en **systemnyckel**
- Metod: bara `GET`. Alla andra metoder ger `405`.
- Svar: JSON i UTF-8. Listor är sidindelade med `?page=` och `?per_page=` (standard 50, högst 200).

Exempelsvaren nedan finns i `docs/api-insyn-exempel/`. De är tagna mot testmiljön med testdata från tre organisationer.

## Nyckeln

**Skapa.** Logga in som superadmin och välj **Admin → Systemnycklar → Skapa systemnyckel**.
- Ange ett namn, till exempel "Magnus (AIPL)".
- Ange gärna också tillåtna IP-adresser. Både enskilda adresser och nät går, till exempel `203.0.113.10, 10.0.0.0/8`.
- Nyckeln visas **en gång**. Lägg den i mottagarens `.env` som `STIMMA_API_KEY`.

**Återkalla.** Välj **Admin → Systemnycklar → Återkalla** på nyckelns rad. Nyckeln slutar fungera direkt och kan inte aktiveras igen. Behövs en ny nyckel skapar man en ny.

**Egenskaper:**
- Det är en egen nyckeltyp i tabellen `system_api_keys`, med prefixet `sts_`. Organisationernas synknycklar ligger i `api_keys` och har prefixet `stm_`. Insyns-API:t godtar inte synknycklar, och synk-API:t godtar inte systemnycklar.
- Bara SHA-256-hashen av nyckeln lagras.
- Nyckeln gäller alla organisationer, men bara endpoints under `/api/v1/insyn/`.
- Adminsidan visar varje nyckels senaste användning och de 50 senaste anropen.

## Skydd

| | |
|---|---|
| Loggning | Varje anrop loggas i `system_api_log` med tid, metod, endpoint och frågesträng, status, nyckel-id, IP och svarstid. Det gäller även nekade anrop. Misslyckade autentiseringar loggas utan nyckel-id. |
| Rate limit | Högst 60 anrop per minut och 1 000 per timme per nyckel. Därutöver blir svaret `429` med `Retry-After`. |
| Felaktiga nycklar | Efter 20 misslyckade autentiseringar på 10 minuter från samma adress spärras adressen i 10 minuter (`429`), även för giltiga nycklar. |
| IP-begränsning | Valfri per nyckel (`403` från andra adresser). Bakom nginx läses klientens adress ur `X-Real-IP`. Rubriken godtas bara när anropet kommer från proxyn, det vill säga från en privat adress. |
| Inga sessioner | API-svaren sätter inga kakor och cachas inte (`Cache-Control: no-store`). |

## Uppgiftsminimering

- **Personuppgifter** lämnas bara ut om administratörer, redaktörer och undertecknare av PUB-avtal:
  - administratörer och redaktörer: namn och e-post
  - undertecknare: namn, titel och e-post
- **Telefonnummer och IP-adresser** ur PUB-avtalen lämnas inte ut.
- **Deltagare:** inga uppgifter om enskilda deltagares kursframsteg lämnas ut, varken namn, e-post, id eller enskilda rader. Statistiken är aggregerad.
- **Små grupper:** antal personer mellan 1 och 4 redovisas som `"<5"`. Talet 0 redovisas som 0.
- **Medelvärden:** över färre än 5 personer redovisas de inte alls, utan som `null`.

Behandlingen sker för förvaltning och uppföljning av tjänsten, och den behöver rymmas i PUB-avtalet (se *Att besluta* i rapporten).

## Organisationer i Stimma

En organisation är i Stimma något av följande:
- en **organisation** i tabellen `organizations`, som kan ha flera domäner (till exempel Kommunalförbundet ITSAM). Den har ett nummer som id, till exempel `"3"`.
- en **ensam domän** som inte är grupperad (de flesta kommuner, till exempel `ale.se`). Domänen är dess id.

Parametern `organization_id` tar både numret och vilken som helst av organisationens domäner. `?organization_id=3`, `?organization_id=itsam.se` och `?organization_id=vimmerby.se` ger alltså samma organisation. En okänd organisation ger `404`.

## Endpoints

| Endpoint | Innehåll |
|---|---|
| `GET /health` | Status, version, tid, antal organisationer, nyckelns namn och prefix, gränser. Används för att testa kopplingen. |
| `GET /organizations` | id, typ, namn, org.nr, domäner, skapad. Antal användare och aktiva användare de senaste 30 och 90 dagarna. Antal kurser. AI: månadskvot, förbrukning denna månad (token och kostnad), saldo. AI-tutor av eller på, med antal kurser. PUB-status. |
| `GET /admins?organization_id=` | Administratörer och superadministratörer: namn, e-post, roll (`admin`/`superadmin`), organisation, senaste inloggning (datum). |
| `GET /editors?organization_id=` | Redaktörer: namn, e-post, organisation, om personen har redaktörsrollen, och vilka kurser personen är redaktör för (med kursens organisation). |
| `GET /pub-agreements?organization_id=&status=` | Per organisation: status (`gällande`/`saknas`), om den gällande versionen är det aktiva dokumentets version, PUB-flaggan i Stimma, det gällande avtalet och alla avtal. För varje avtal: avtals-id, version, datum, status (`gällande`/`ersatt`/`ogiltigförklarat`), undertecknarens namn, titel och e-post, kontrollsumma. `status=gällande` eller `status=saknas` filtrerar. |
| `GET /courses?organization_id=&status=&baskurs=` | id, titel, organisation, status (`active`/`inactive`), intern, publik, global, stegvis, kopierad från (organisation, domän, kurs-id), antal lektioner och infosidor, AI-tutor, baskurs, taggar, skapad, senast ändrad. |
| `GET /courses/{id}/stats` | Aggregerat: påbörjat, slutfört, diplom, avbrutit. Genomsnittlig andel rätt i quiz. Genomsnittlig tid och mediantid till klar. Allt även per organisation. |
| `GET /usage` | Användning per funktion de senaste 30 och 90 dagarna, och antal organisationer som använder funktionen. Funktionerna är kursimport, kurskopiering, AI-generering, AI-tutor, lärvägar, användarsynk, ljudklipp och frågetyper. Där ingår också läget nu: aktiva lärvägar, lektioner med ljud, kurser med AI-tutor, domäner med synk och frågetyper totalt. Inga personuppgifter. |
| `GET /learning-paths?organization_id=` | Lärvägar: id, titel, status, organisation, global, delad med domäner, kurser i ordning. |

Svar på listor:

```json
{ "page": 1, "per_page": 50, "total": 3, "total_pages": 1, "data": [ ... ] }
```

Fel: `{"error": "..."}` med statuskod 400 (ogiltig eller okänd parameter), 401, 403, 404, 405, 429 eller 500. En felstavad parameter, som `organisation_id`, ger `400`. Den ger inte tyst alla organisationer.

## Avvikelser från underlaget och hur de ska läsas

- **Organisations-id** är numret eller domänen, inte bara ett nummer. Skälet är att de flesta organisationer i Stimma inte finns i tabellen `organizations`, se ovan.
- **"Kopierad från" kurs-id** hämtas ur aktivitetsloggen. Kursen själv sparar bara källans domän. För äldre kopior utan loggrad är `course_id` `null`.
- **Intern** betyder att kursen varken är publik eller global, alltså att den bara syns för den egna organisationen.
- **AI-tutor av/på** finns inte som inställning per organisation, eftersom AI-chatten slås på per lektion. Det som redovisas är om någon aktiv lektion har den påslagen, och i hur många kurser.
- **Genomsnittlig tid:** Stimma mäter inte hur lång tid deltagarna lägger på en kurs. Det som redovisas är kalenderdagar från inskrivning eller första lektion till sista avklarade lektion, för dem som är klara.
- **Andel rätt** bygger på det senast sparade svaret per fråga.
- **Klar** följer Stimmas regel: alla aktiva lektioner är klara, och infosidor krävs inte. Talet beräknas ur framstegen, så det stämmer även innan migreringen `backfill_course_completion` har körts.
- **Användning** bygger på aktivitetsloggen (import, kopiering, lärvägar, ljud), AI-loggen och synkloggen. Händelser från konton utan organisation, till exempel gmail.com, räknas i `events` men inte i `organizations`.
- **Aktiva användare** räknas utifrån senaste inloggning (`users.last_login_at`).

## Driftsättning

1. Ta backup.
2. Kör migreringen:
   `docker exec -i stimma-db-1 sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < migrations/051_system_api_keys.sql`
3. Skapa nyckeln i **Admin → Systemnycklar**.
4. Prova kopplingen:
   `curl -H "Authorization: Bearer sts_..." https://stimma.sambruk.se/api/v1/insyn/health`

Innan migreringen är körd svarar API:t `503` ("inte aktiverat ännu") på alla anrop med GET, och adminsidan visar att tabellerna saknas.

## Tester

`tests/test_insyn.php` (kör `tests/run.sh test_insyn.php`) visar att:
- nyckeln bara skapas och återkallas av superadmin
- bara hashen lagras
- nyckeln bara ger läsåtkomst: POST, PUT, PATCH och DELETE ger `405`, och databasens kontrollsumma är oförändrad efter alla anrop
- organisationsnycklar (`stm_`) inte når `/insyn/`, och systemnyckeln inte når synk-API:t
- inga deltagaruppgifter förekommer i något svar
- små grupper redovisas som `"<5"`
- loggning, rate limit, IP-begränsning och spärren efter misslyckade försök fungerar
