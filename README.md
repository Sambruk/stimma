# Stimma - Lär dig i små steg

Stimma är en e-learning plattform för mikroutbildning, utvecklad för svenska organisationer och kommuner.

## Funktioner

### Kärnfunktioner
- **AI-genererade kurser** - Skapa kurser automatiskt med hjälp av AI
- **AI-bildgenerering** - Generera kurs- och lektionsbilder med DALL-E 3
- **Kurs- och lektionshantering** - Drag-and-drop sortering, import/export
- **Quiz-funktionalitet** - Lägg till frågor i lektioner
- **AI-tutor** - Integrerad chattfunktion för stöd under lektioner
- **Taggbaserad organisation** - Organisera kurser med taggar
- **Rollbaserad åtkomstkontroll** - Admin, redaktör och användarroller
- **Organisationsbaserad separation** - Multi-tenant arkitektur baserad på e-postdomän

### Nya funktioner (v1.1)
- **Gamification** - XP-poäng, nivåer och achievements för ökad motivation
- **Certifikat** - Automatisk certifikatgenerering vid slutförd kurs
- **Dashboard** - Personlig översikt med framsteg och statistik
- **AI-leverantörsstöd** - Stöd för OpenAI, Anthropic, Google, Azure och OpenRouter
- **Förhandsvisning** - Testa lektioner utan att spara data
- **PUB-dokumentation** - Personuppgiftsbiträdesavtal och instruktioner

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
