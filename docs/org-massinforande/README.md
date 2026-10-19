# Organisation för varje vitlistad domän – AVVAKTAR (2026-10-19)

Syfte: alla medlemmar ska kunna beställa AI-tokens själva. Saldo och beställning kräver
`organization_id`, men bara 4 organisationer finns medan 166 vitlistade domäner saknar en.

Thomas kände sig osäker 2026-10-19 och bad om att vänta. **Kör inget utan nytt OK.**

## Filer
- `create_orgs_plan.tsv` – 159 organisationer / 163 domäner (namn, org.nr, domäner, PUB, startsaldo)
- `create_orgs.sql` – en transaktion. **Ögonblicksbild 2026-10-19: saldona bygger på oktobers
  förbrukning.** Generera om före körning: `match.py` (CRM-matchning) → `gen.py` (SQL).
  Indata (`crm.jsonl`, `dom_users.tsv`, `pub.tsv`, `used.tsv`) hämtas på nytt, se TODO.md.

## Förslaget
- 1:1 med domänen som primärdomän. Grupper: Arvika kommun (arvika.se, arvika.com),
  Göteborgs Stad (goteborg.se, educ., intraservice., storningsjouren.), Göteborgs Spårvägar AB.
- Undantag: gmail.com, elestio.app, vm.elestio.app.
- Namn och org.nr från EspoCRM (webbplats/e-postdomän). 6 utan träff får domänen som namn.
- Startsaldo = 50 000 minus oktobers förbrukning på domänen (annars dubbel gratisbas).
- PUB-flaggan kopieras bara till 1:1-organisationer (6 st). Inte i grupper – org-flaggan vinner
  över domänflaggan i `userHasPubAgreement()` och skulle ge PUB till domäner utan avtal.

## Konsekvenser som utreddes
- Behörigheter oförändrade vid 1:1, men primärdomän låser upp synkverktyget, batch-läsbehörighet
  och val av kurssynlighet för lokala admins.
- `auth.php:274` uppgraderar `public_only` → `domain` vid inloggning när domänen får org (3 användare).
- Insyns-API:t byter enhets-id från domän till org-id (Magnus nyckel påverkas).
- Anpassade domänkvoter skulle gå förlorade (inga finns 2026-10-19).
- Flytt av domän mellan organisationer: ta bort + lägg till under Organisationer. Saldo och
  beställningar följer inte med. Thomas: löses om det uppstår.

## Öppna frågor
1. Meddela Magnus före körning?
2. Göteborgs Stad startar på 0 (Störningsjouren har förbrukat 51 181) – eller fullt saldo?
3. Är Thomas trygg med förslaget alls?
