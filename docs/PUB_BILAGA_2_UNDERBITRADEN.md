# Bilaga 2 – Lista över underbiträden

## Till Personuppgiftsbiträdesavtal för tjänsten Stimma

*Denna bilaga innehåller en förteckning över de underbiträden som Personuppgiftsbiträdet anlitar för att tillhandahålla tjänsten Stimma.*

---

## 1. Inledning

### 1.1 Bakgrund

Enligt avsnitt 11 i Personuppgiftsbiträdesavtalet ska Personuppgiftsbiträdet upprätta och underhålla en lista över underbiträden som anlitas för behandling av personuppgifter för den Personuppgiftsansvariges räkning.

### 1.2 Godkännande

Genom undertecknande av Personuppgiftsbiträdesavtalet godkänner den Personuppgiftsansvarige att Personuppgiftsbiträdet anlitar de underbiträden som anges i denna bilaga.

### 1.3 Ändringar

Vid tillägg eller byte av underbiträde ska den Personuppgiftsansvarige informeras skriftligen minst 30 dagar i förväg, i enlighet med avsnitt 11.3 i Personuppgiftsbiträdesavtalet.

---

## 2. Förteckning över underbiträden

### 2.1 Aktiva underbiträden

| Nr | Underbiträde | Org.nr | Adress | Behandling | Plats för behandling | Skyddsåtgärd tredjeland |
|----|--------------|--------|--------|------------|---------------------|------------------------|
| 1 | [HOSTING-LEVERANTÖR] | [ORG.NR] | [ADRESS] | Drift och lagring av applikation och databas | [LAND, t.ex. Sverige/EU] | N/A (inom EU/EES) |
| 2 | [AI-LEVERANTÖR, t.ex. OpenAI] | [ORG.NR] | [ADRESS] | AI-tutorfunktion och bildgenerering | [LAND] | [t.ex. EU-US Data Privacy Framework / Standardavtalsklausuler] |
| 3 | [E-POSTLEVERANTÖR] | [ORG.NR] | [ADRESS] | Utskick av e-post (inloggningslänkar, påminnelser) | [LAND] | [Skyddsåtgärd om tredjeland] |

### 2.2 Detaljerad beskrivning per underbiträde

---

#### Underbiträde 1: Driftleverantör/Hosting

| Fält | Uppgift |
|------|---------|
| **Företagsnamn** | [NAMN] |
| **Organisationsnummer** | [ORG.NR] |
| **Adress** | [ADRESS] |
| **Kontaktperson** | [NAMN, E-POST, TELEFON] |
| **Webbplats** | [URL] |

**Behandling som utförs:**
- Drift av webbserver och applikation
- Drift av databasserver
- Lagring av personuppgifter
- Säkerhetskopiering
- Nätverksskydd och brandvägg

**Kategorier av personuppgifter som behandlas:**
- Alla personuppgifter som lagras i tjänsten (se Bilaga 1)

**Plats för behandling:**
- [ ] Sverige
- [ ] Annat EU/EES-land: ________________
- [ ] Tredjeland: ________________

**Skyddsåtgärder vid tredjeland:**
- [ ] Ej tillämpligt (behandling inom EU/EES)
- [ ] Beslut om adekvat skyddsnivå
- [ ] Standardavtalsklausuler (SCC)
- [ ] Bindande företagsbestämmelser (BCR)
- [ ] Annat: ________________

**Underbiträdesavtal:**
- [ ] Underbiträdesavtal upprättat enligt SKR:s mall
- [ ] Leverantörens standardavtal som uppfyller artikel 28 GDPR

---

#### Underbiträde 2: AI-tjänsteleverantör

| Fält | Uppgift |
|------|---------|
| **Företagsnamn** | [t.ex. OpenAI, LLC / Azure OpenAI Service] |
| **Organisationsnummer** | [ORG.NR] |
| **Adress** | [ADRESS] |
| **Kontaktperson** | [DPA-kontakt] |
| **Webbplats** | [URL] |

**Behandling som utförs:**
- AI-tutorfunktion (generera svar på användarfrågor)
- AI-bildgenerering (skapa bilder till kurser och lektioner)
- AI-generering av kursinnehåll

**Kategorier av personuppgifter som behandlas:**
- Användarens textfrågor till AI-tutorn (innehåller normalt inga personuppgifter, men kan teoretiskt inkludera sådana om användaren anger dem i sin fråga)

**Obs:** AI-tjänsten tar endast emot den text som användaren skriver i chattfunktionen, tillsammans med lektionsinnehållet som kontext. Inga andra personuppgifter som e-postadress, namn eller progression skickas till AI-tjänsten.

**Plats för behandling:**
- [ ] EU/EES
- [ ] USA
- [ ] Annat tredjeland: ________________

**Skyddsåtgärder vid tredjeland:**
- [ ] EU-US Data Privacy Framework
- [ ] Standardavtalsklausuler (SCC)
- [ ] Annat: ________________

**Underbiträdesavtal/DPA:**
- [ ] Data Processing Addendum (DPA) accepterat
- [ ] Länk till DPA: ________________

---

#### Underbiträde 3: E-postleverantör (SMTP)

| Fält | Uppgift |
|------|---------|
| **Företagsnamn** | [NAMN, t.ex. egen SMTP-server / Postmark / SendGrid / etc.] |
| **Organisationsnummer** | [ORG.NR] |
| **Adress** | [ADRESS] |
| **Kontaktperson** | [NAMN, E-POST, TELEFON] |
| **Webbplats** | [URL] |

**Behandling som utförs:**
- Skicka inloggningslänkar via e-post
- Skicka påminnelser om ej slutförda kurser
- Skicka systemmeddelanden

**Kategorier av personuppgifter som behandlas:**
- E-postadress (mottagare)
- Namn (om inkluderat i meddelandet)
- Information om kursprogression (i påminnelsemeddelanden)

**Plats för behandling:**
- [ ] Sverige
- [ ] Annat EU/EES-land: ________________
- [ ] Tredjeland: ________________

**Skyddsåtgärder vid tredjeland:**
- [ ] Ej tillämpligt (behandling inom EU/EES)
- [ ] Beslut om adekvat skyddsnivå
- [ ] Standardavtalsklausuler (SCC)
- [ ] Annat: ________________

**Underbiträdesavtal:**
- [ ] Underbiträdesavtal upprättat
- [ ] Egen SMTP-server (inget underbiträde)

---

## 3. Underbiträden som inte längre anlitas

*Förteckning över underbiträden som tidigare anlitats men som inte längre behandlar personuppgifter.*

| Nr | Underbiträde | Anlitad period | Behandling | Bekräftelse på radering |
|----|--------------|----------------|------------|------------------------|
| - | - | - | - | - |

---

## 4. Ändringshistorik

| Version | Datum | Ändring | Godkänd av PuA |
|---------|-------|---------|----------------|
| 1.0 | [DATUM] | Ursprunglig version | [NAMN, DATUM] |

---

## 5. Instruktioner för ifyllnad

### 5.1 Obligatoriska uppgifter

Följande uppgifter **måste** fyllas i för varje underbiträde:

1. Företagsnamn och organisationsnummer
2. Beskrivning av behandlingen
3. Kategorier av personuppgifter som behandlas
4. Geografisk plats för behandlingen
5. Skyddsåtgärder vid överföring till tredjeland

### 5.2 Vanliga underbiträden för molntjänster

Beroende på hur Stimma driftas kan följande typer av underbiträden vara aktuella:

| Tjänstetyp | Exempel på leverantörer | Notering |
|------------|------------------------|----------|
| **Molninfrastruktur** | AWS, Azure, Google Cloud, Binero, City Network | Datacenteroperatör |
| **AI-tjänster** | OpenAI, Azure OpenAI, Anthropic | För AI-tutor och bildgenerering |
| **E-post (SMTP)** | Postmark, SendGrid, Mailgun, egen server | För utskick av e-post |
| **CDN** | Cloudflare, Fastly | Om innehåll cachas |

### 5.3 Särskilda överväganden

**AI-tjänster:**
- Kontrollera om AI-leverantören använder data för träning av modeller (bör undvikas)
- Verifiera var bearbetningen sker geografiskt
- Kontrollera att DPA (Data Processing Addendum) finns

**Molntjänster:**
- Kontrollera var datacentret fysiskt ligger
- Verifiera certifieringar (ISO 27001, SOC 2, etc.)
- Kontrollera underbiträdets egna underbiträden

---

## 6. Underskrifter

*Undertecknande sker genom godkännande av Personuppgiftsbiträdesavtalet med tillhörande bilagor.*

---

*Denna bilaga utgör Bilaga 2 till Personuppgiftsbiträdesavtalet för tjänsten Stimma.*

---

## Bilaga 2a – Mall för meddelande om nytt underbiträde

*Använd denna mall vid meddelande till den Personuppgiftsansvarige om nytt underbiträde.*

---

**Till:** [Den Personuppgiftsansvariges kontaktperson]

**Från:** [Personuppgiftsbiträdets kontaktperson]

**Datum:** [DATUM]

**Ärende:** Meddelande om nytt underbiträde för tjänsten Stimma

---

I enlighet med avsnitt 11.3 i Personuppgiftsbiträdesavtalet meddelar vi härmed att vi avser anlita följande nya underbiträde:

| Fält | Uppgift |
|------|---------|
| Underbiträdets namn | [NAMN] |
| Organisationsnummer | [ORG.NR] |
| Behandling som ska utföras | [BESKRIVNING] |
| Kategorier av personuppgifter | [KATEGORIER] |
| Plats för behandling | [LAND] |
| Skyddsåtgärd (om tredjeland) | [ÅTGÄRD] |
| Planerat startdatum | [DATUM] |

**Motivering:**
[Beskriv varför det nya underbiträdet behövs]

**Underbiträdesavtal:**
Underbiträdesavtal som uppfyller kraven i artikel 28 GDPR kommer att ingås innan behandlingen påbörjas.

---

Om ni har invändningar mot anlitandet av detta underbiträde, vänligen meddela oss skriftligen senast [DATUM, 30 dagar från meddelandet].

Vid frågor, kontakta [NAMN] på [E-POST] eller [TELEFON].

---

Med vänliga hälsningar,

[NAMN]
[TITEL]
[ORGANISATION]
