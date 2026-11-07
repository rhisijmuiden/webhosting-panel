# Dekwerk

Dekwerk is het hostingpaneel: websites, mail, DNS en backups op één eigen server. De huisstijl draait om één beeld: **de bolder met een touw eromheen**. Wat op Dekwerk draait, ligt goed afgemeerd. De naam komt van het dek van een schip: daar gebeurt het werk.

![Dekwerk logo](logo/dekwerk-logo.svg)

## Logo

Het logo is de bolder op een havenblauwe tegel, met het woordmerk **dekwerk** in Lexend Bold, in kleine letters. "dek" staat in diep, "werk" in golf. De letters zijn omgezet naar vaste vormen, dus het logo heeft geen lettertype nodig.

| Bestand | Gebruik |
| --- | --- |
| `dekwerk-logo.svg` | Standaard op licht (wit, schuim) |
| `dekwerk-logo-wit.svg` | Op diep, haven of andere donkere vlakken, bijvoorbeeld het menu |
| `dekwerk-logo-zwart.svg` | Eén kleur zwart: druk, stempel |
| `dekwerk-logo-mono-wit.svg` | Eén kleur wit op foto of donker vlak |
| `dekwerk-woordmerk.svg` / `-wit.svg` | Alleen het woord, als het icoon al ernaast staat |
| `dekwerk-icon.svg`, `dekwerk-icon-512.png`, `apple-touch-icon.png` | App-icoon, avatar |
| `dekwerk-mark.svg` | Alleen de bolder, zonder tegel |
| `favicon.svg`, `favicon.ico` | Browsertabblad (dikkere lijnen, leesbaar op 16 px) |

**Vrije ruimte:** houd rondom het logo minimaal de hoogte van de "k" vrij.
**Minimale maat:** logo 120 px breed op scherm; daaronder alleen het icoon.
**Niet doen:** de bolder of het touw een andere kleur geven (behalve in de eenkleurige versies), het woord in hoofdletters zetten, schaduwen of verlopen toevoegen.

## Kleur

| Naam | Hex | Gebruik |
| --- | --- | --- |
| Haven | `#123E7C` | Hoofdkleur: icoontegel, grote vlakken |
| Diep | `#0A2348` | Menu links, tekst, tekst op golf-knoppen |
| Golf | `#3FA9F5` | Accent: primaire knop, actief menu-item, voortgang |
| Touw | `#D9B98C` | Tweede accent, spaarzaam: markeringen, "test"-label |
| Schuim | `#F6F9FC` | Lichte achtergrond |

- Golf is het signaal: per scherm een paar keer, niet als vlakvulling.
- Tekst op golf is altijd **diep** (contrast 6:1), nooit wit.
- Links en accenttekst op licht: `#0F6CB5`; op donker: `#7CC6FF` (beide AA).
- Statuskleuren (groen, oranje, rood) zijn los van de merkkleuren en staan in `tokens/`.
- Licht en donker thema: zie `tokens/tokens.css`. Het menu is in beide thema's donker.

## Type

**Lexend** (variabel, 100-900), zelf gehost in `fonts/` (SIL Open Font License). Lexend is gemaakt voor leesbaarheid, ook op kleine schermen.

- Koppen: 700, iets strakker gezet (`letter-spacing: -0.015em`)
- Tekst: 400, 15 px, regelhoogte 1,55
- Labels en knoppen: 500-600
- Code: systeemmonospace

## Toon

Dekwerk praat zoals een goede systeembeheerder: rustig, concreet en zonder vakjargon waar dat niet hoeft. Engels is de standaardtaal, Nederlands de vertaling. Noem dingen naar wat de gebruiker ziet ("je website", "je mailbox"), niet naar hoe het technisch heet.

## Product en hostingpartij

Dekwerk is de naam van de software. Het hostingbedrijf dat de server draait (bijvoorbeeld RHIS) heeft een eigen naam; die stelt de beheerder in onder **Tools & settings > Branding** en verschijnt in teksten voor klanten, zoals "Laat RHIS je DNS verzorgen".
