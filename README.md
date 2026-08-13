# Stimma - Lär dig i små steg

Stimma är en e-learning plattform för mikroutbildning, utvecklad för svenska organisationer och kommuner.

## Funktioner

### Kärnfunktioner
- **AI-genererade kurser** - Skapa kurser från en fri idé eller stegvis med AI (stöd för OpenAI, Anthropic, Google, Azure och OpenRouter)
- **AI-bildgenerering** - Generera kurs- och lektionsbilder med DALL-E 3
- **AI-tutor** - Integrerad chattfunktion för stöd under lektioner
- **Kurs- och lektionshantering** - Flikbaserad redigerare, drag-and-drop-sortering, ZIP-baserad export/import med bilder
- **Quiz-funktionalitet** - 9 frågetyper inkl. hotspot, per-lektion quiz-läge med live tally och per-fråga-bedömning
- **Gamification** - XP-poäng, nivåer och achievements
- **Diplom** - Automatisk diplomgenerering med Sambruks kontrasignering
- **Dashboard** - Personlig översikt med framsteg och statistik
- **Taggbaserad organisation** - Organisera kurser med taggar
- **Rollbaserad åtkomstkontroll** - Admin, redaktör och användarroller
- **Organisationsbaserad separation** - Multi-tenant arkitektur baserad på e-postdomän
- **PUB-avtal** - Klickbara avtal med digital signering, SMS-verifiering och PDF-stämpling

### Nytt i v2.1
- **Lärvägar** - Paketera flera kurser i en namngiven, ordnad grupp med rekommenderad ordning (ingen låsning)
- **Deltagarvy för lärvägar** - Status per kurs (genomförd/påbörjad/registrerad) och samlad procent, plus sektion på översikten
- **Lärvägsstatistik** - Matris över genomförda, påbörjade och registrerade per kurs och användare, med domänfilter

### Nytt i v2.0
- **PowerPoint-import** - Importera befintliga PPTX-filer och låt AI utveckla texten till en Stimma-kurs
- **AI-genererad enstaka lektion** - Lägg till AI-skrivna lektioner till befintliga kurser
- **Stegvisa kurser** - Schemalagd utrullning med e-postmallar, kö, autostart och logg (publik eller riktad)
- **Domänbaserad kursdelning** - Publicera kurser publikt eller rikta till specifika domäner i organisationen
- **Kursens landningssida** - Egen startsida per kurs med kopiera-länk, lektionsnavigering och kursavslutssida
- **Hotspot-frågor** - Klickbara frågor med koordinater och uppladdade bilder
- **Användarsynk via REST API** - Synka användare från HR-system, med domänutökning för admin på primärdomän
- **Video- och ljuduppladdning** - YouTube + uppladdning upp till 100 MB; ljudstöd för tillgänglighet
- **Bildinfogning i lektionsinnehåll** - Lägg in bilder direkt i texten
- **Anpassningsbar headertext** per organisation/domän
- **Ofelia-scheduler** - Docker-native cron för bakgrundsjobb
- **Tillgänglighetsredogörelse** - Enligt DOS-lagen och WCAG 2.1 AA
- **Versionshantering för AI-promptar**
- **Säkerhetshärdning** - Åtgärdade kritiska, höga och medium fynd från säkerhetsaudit och beroendegranskning

## Installation

### Krav

- Docker och Docker Compose
- MySQL/MariaDB databas
- OpenAI API-nyckel (för AI-funktioner)

### Snabbstart

1. Klona repot:
```bash
git clone https://github.com/Sambruk/stimma.git
cd stimma
```

2. Kopiera och konfigurera miljövariabler:
```bash
cp env.example .env
```

3. Redigera `.env` med dina inställningar:
```
DB_HOST=localhost
DB_DATABASE=stimma
DB_USERNAME=stimma
DB_PASSWORD=your_password
AI_API_KEY=your_openai_api_key
```

4. Starta med Docker Compose:
```bash
docker-compose up -d
```

5. Importera databasschemat:
```bash
mysql -u root -p stimma < init.sql
```

6. Öppna webbläsaren och gå till `http://localhost`

## Konfiguration

### Miljövariabler

| Variabel | Beskrivning |
|----------|-------------|
| `DB_HOST` | Databasserver |
| `DB_DATABASE` | Databasnamn |
| `DB_USERNAME` | Databasanvändare |
| `DB_PASSWORD` | Databaslösenord |
| `AI_API_KEY` | OpenAI API-nyckel |
| `AI_API_SERVER` | OpenAI API-server (standard: api.openai.com) |
| `AI_MODEL` | AI-modell för kursgenerering (standard: gpt-4) |
| `SMTP_HOST` | SMTP-server för e-post |
| `SMTP_PORT` | SMTP-port |

## Användning

### Admin-panel

Gå till `/admin` för att hantera:
- Kurser och lektioner
- Användare och behörigheter
- Taggar och kategorier
- AI-inställningar
- Statistik och loggar

### AI-kursgenerering

1. Gå till Admin > Kurser
2. Klicka på "Skapa AI-kurs"
3. Fyll i kursnamn och beskrivning
4. Välj antal lektioner och svårighetsgrad
5. Klicka "Generera"

### AI-bildgenerering

I kurs- eller lektionsredigeraren:
1. Klicka på "Generera AI-bild"
2. Vänta medan DALL-E 3 skapar bilden
3. Bilden sparas automatiskt

## Licens

Copyright (C) 2025 Christian Alfredsson

Detta program är fri programvara; licensierat under GPL v2.
Se LICENSE för detaljer.

Namnet "Stimma" är ett varumärke och omfattas av begränsningar.

## Utvecklat av

- [Sambruk](https://github.com/Sambruk)
- Christian Alfredsson
