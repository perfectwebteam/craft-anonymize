# craft-anonymize

Een geanonimiseerde dump van de database van een Craft 5-installatie, met alleen
PHP: geen `mysqldump`, geen Docker, Python of Node. Wat het configbestand van het
project aanwijst wordt onderweg vervangen, zodat de echte waarden nergens worden
weggeschreven. Het script schrijft nooit in de database: het leest in één
read-only snapshot.

```bash
php craft anonymize/check                                  # klopt de config met deze database?
php craft anonymize/dump > kopie.sql                       # naar stdout
php craft anonymize/dump --gzip --output=kopie.sql.gz      # naar een bestand
php craft anonymize/placeholders                           # op de kopie: nepbestanden voor de assets
```

Craft 5.8 of nieuwer, MySQL of MariaDB, PHP 8.2 of nieuwer.

## Installeren

Het package staat niet op Packagist. Zet deze repository in de `composer.json` van
het project:

```json
"repositories": [
    {
        "type": "git",
        "url": "https://github.com/perfectwebteam/craft-anonymize"
    }
]
```

```bash
composer require perfectwebteam/craft-anonymize:^1.0
```

De servers hebben toegang tot deze private repository nodig, net als voor de andere
eigen packages.

Het is een Yii2-extensie: Craft laadt het commando zelf in. Er is geen
`plugin/install` nodig, project config verandert niet en in het CP verandert niets.
Daarna een `config/anonymize.php` maken (zie hieronder). `anonymize/check` zegt
wat er nog ontbreekt.

## Downloaden via de CLI

SSH geeft de uitvoer en de exitcode van het commando door. Er komt op de server
niets op schijf, en wat de server verlaat is al geanonimiseerd:

```bash
set -o pipefail
ssh deploy@server 'cd /pad/naar/project && php craft anonymize/dump --gzip' > project.sql.gz.part \
  && mv project.sql.gz.part project.sql.gz
gunzip < project.sql.gz | mariadb project_lokaal     # of: php craft db/restore
```

Een afgebroken download is herkenbaar:

- aan de exitcode;
- aan `gzip -t`;
- aan de slotregel `-- Dump completed on …`.

Zonder SSH schrijf je met `--output` naar een map buiten de webroot en haal je het
bestand op via SFTP. `--output` schrijft eerst naar `{pad}.part` (alleen leesbaar
voor de eigenaar) en hernoemt pas als alles goed ging. Bij een fout blijft er geen
bestand staan, en een bestaand bestand wordt nooit overschreven.

## Placeholders op de kopie

De dump bevat alleen de database. Komen de bestanden niet mee, dan schrijft
`anonymize/placeholders` op de kopie een nepbestand voor elk asset waarvan het
bestand ontbreekt. Zo openen afbeeldingen en documenten, en blijven downloads en
tekstextractie werken.

```bash
php craft anonymize/placeholders --dry-run   # alleen tellen
php craft anonymize/placeholders
```

Elk nepbestand heeft het type van het asset, met "Geanonimiseerd" en de (verzonnen)
titel erin:

- **Afbeeldingen** (svg, en met GD ook jpg, png, gif, webp en bmp) houden hun afmetingen, tot 2000 px.
- **pdf, csv, xml, json, docx en xlsx** worden een geldig bestand van dat type.
- **Andere types** worden een tekstbestand, met hun eigen extensie.

Het voegt alleen toe. Een bestand dat er al is, echt of een eerdere placeholder, blijft
staan, en er wordt niets verwijderd. Nog eens draaien kan dus altijd. Het schrijft via
het filesystem van het volume, zoals Craft zelf: draai het als de gebruiker die daar mag
schrijven. Wijst een volume op de kopie naar dezelfde opslag als productie, zoals een
gedeelde bucket, dan komen de placeholders daar terecht.

Het weigert:

- **in een andere omgeving** dan die in `placeholders.environments`. Standaard zijn dat `local`, `dev`, `development`, `test` en `staging`; `production`, `prod` en `live` kunnen er niet in;
- **als `--env` iets anders zegt** dan `CRAFT_ENVIRONMENT`;
- **op een database die niet uit `anonymize/dump` komt.** Elke dump eindigt met de tabel `anonymized` (met prefix), waarin de bron en het tijdstip staan.

## Het configbestand

`config/anonymize.php` geeft een array terug, zoals elk Craft-configbestand. Het
mag per omgeving opgesplitst worden (`'*' => […]`), en het mag `App::env()` en
closures gebruiken.

```php
<?php

use perfectwebteam\anonymize\Context;

return [
    'presets' => ['formie', 'retour'], // 'craft' staat altijd aan; zie Presets
    'strict' => true,                // standaard
    'tableCollations' => false,      // standaard: zie Collaties
    'handlers' => [],                // klassen voor wat een regel niet kan beschrijven; zie Handlers
    'scan' => [                      // zie PII-scan
        'allowEmails' => ['no-reply@klant.nl'],
        'allowDomains' => [],
        'fail' => false,
    ],
    'placeholders' => [              // zie Placeholders op de kopie
        'environments' => ['local', 'test'],
    ],

    // Wat nooit wordt aangeraakt. Gaat vóór elke andere regel.
    'keep' => [
        'users' => [                 // deze gebruikers houden alles, ook wachtwoord, 2FA en passkeys
            'emailDomains' => ['pwt.nl'],
            'emails' => [],
            'usernames' => [],
            'groups' => [],          // handles van gebruikersgroepen
            'ids' => [],
        ],
        'elements' => [              // inhoud zonder persoonsgegevens, of losse elementen
            'section:paginas',
            'entryType:tekstblok',
            'volume:logos',
            'entry:1234',
        ],
        'tables' => ['mijnplugin_instellingen'],
    ],

    // Een veld, in elke veldindeling waar het in staat
    'fields' => [
        'telefoon' => 'phone',
    ],

    // Per soort element; gaat vóór 'fields'
    'elements' => [
        'entryType:lid' => [
            'title' => 'fullName@lid',
            'fields' => [
                'voornaam' => 'firstName@lid',
                'achternaam' => 'lastName@lid',
                'geboortedatum' => 'birthDate',
                'adres' => 'streetAddress@adres',
                'woonplaats' => 'city@adres',
                'opmerkingen' => 'lorem',
                'lidnummer' => 'keep',
            ],
        ],
        'entryType:vestiging' => [
            'seed' => 'eigenaar',    // relatieveld: dezelfde verzonnen waarden als het element waar het naar wijst
            'title' => ['format' => 'Vestiging {city@adres}'],
            'fields' => ['adres' => 'streetAddress@adres', '*' => 'keep'],
        ],
        'volume:cv' => [
            'filename' => ['placeholder' => 'cv'],     // cv-{id}.pdf
            'title' => ['placeholder' => 'cv'],
            'alt' => 'null',
        ],
        // Elementen van een plugin: de titel moet een bestemming hebben ('keep' mag ook)
        'elementType:vendor\plugin\elements\Inschrijving' => [
            'title' => ['format' => 'Inschrijving {id}'],
        ],
    ],

    // Tabellen van plugins en modules, zonder prefix; * voor een reeks
    'tables' => [
        'mijnplugin_log' => 'empty',                    // alleen de structuur
        'mijnplugin_oud_*' => 'empty',
        'mijnplugin_aanmeldingen' => [
            'columns' => [
                'naam' => 'fullName@persoon',
                'email' => 'email@persoon',
                'ip' => 'ip',
                'referentie' => fn(Context $c) => 'REF-' . $c->row['id'],
            ],
            'keepRows' => ['userId' => 'keptUsers'],   // of 'keptElements', een lijst waarden, of een closure
            'dropRows' => false,                        // true: alles wat keepRows niet houdt valt weg
        ],
    ],

    // Een veldtype van een plugin dat geen vrije tekst bevat
    'fieldTypes' => [
        \vendor\plugin\fields\Kleurkiezer::class => 'keep',
    ],
];
```

**Selectors.** Een regel wijst aan met een selector:

- per soort: `section:handle`, `entryType:handle` (dekt ook geneste entries in Matrix en CKEditor), `volume:handle`, `globalSet:handle`, `categoryGroup:handle`, `tagGroup:handle`, `contentBlock:handle`, `user` en `address`;
- de elementen van een plugin: `elementType:Klasse`, of `elementType:vendor\plugin\*` voor alle klassen van een namespace. In `keep.elements` blijven ze heel; als regel krijgen ze een titel, en hun velden als ze een veldindeling hebben. Hun zoekwoorden worden dan leeg, behalve die van de titel;
- alleen in `keep.elements`: losse elementen (`entry:id`, `asset:id`, `user:id`, `element:id`).

Een `section:`-selector raakt geen geneste entries; die classificeer je met `entryType:`.

**Voorrang.** Bovenaan staat `keep`. Daarna komt de regel van het element voor dat veld, en dan de `'*'` van het element. Dan de regel bij het veld (`fields`), en ten slotte het veldtype (`fieldTypes`).

**Presets** liggen eronder. Het project overschrijft per tabel of per kolom; een
lijst in `keep` wordt samengevoegd.

**Strict** (standaard aan) weigert alles wat geen bestemming heeft:

- elk veld dat vrije tekst kan bevatten (tekst, e-mail, link, tabel, datum, getal, CKEditor, onbekende plugintypes);
- elke titel;
- elke bestandsnaam en alt-tekst van een volume;
- elke tabel en elk elementtype.

Keuzelijsten, schakelaars en relaties hoeven niet. Komt er later een veld bij, dan
stopt de volgende dump, in plaats van dat het veld meelekt.

Een naam die niet bestaat is altijd een fout, ook zonder strict: een handle, sectie,
tabel of kolom met een tikfout raakt niets en zou anders stil niets doen.

## Strategieën

| Strategie | Resultaat |
|---|---|
| `'keep'` | blijft zoals het is |
| `'null'` | leeg: NULL, of de sleutel weg uit de content |
| `'firstName'`, `'infix'`, `'surname'`, `'lastName'`, `'fullName'` | een verzonnen Nederlandse naam |
| `'email'` | `voornaam.achternaam-1234@example.invalid`, nooit afleverbaar |
| `'phone'` | `000-1234567` |
| `'street'`, `'houseNumber'`, `'streetAddress'`, `'postcode'`, `'city'`, `'province'`, `'latitude'`, `'longitude'` | een verzonnen straat in een bestaande plaats, met passende postcode en coördinaten |
| `'company'` | `Bakker B.V.` |
| `'date'`, `'birthDate'` | een datum; een geboortedatum tussen 1945 en 2007 |
| `'iban'` | een geldig IBAN bij bankcode TEST, die niet bestaat |
| `'ip'` | `192.0.2.x` (TEST-NET) |
| `'url'` | `https://example.invalid/…` |
| `'lorem'` | tekst van dezelfde lengte; HTML houdt zijn tags |
| `'digits:8'` | acht cijfers |
| `'token'` | een token dat buiten deze run niet terug te maken is |
| `['value' => …]` | deze waarde |
| `['format' => 'SPAR {surname@eigenaar} {city@adres}']` | generatoren in een tekst; `{id}` is het id |
| `['pseudonym' => 'Groep']` | `Groep 1`, `Groep 2`, …: één per echte waarde |
| `['placeholder' => 'document']` | bestandsnaam of titel van een asset: `document-{id}.pdf` |
| `fn(Context $c) => …` | maatwerk: `$c->value`, `$c->row`, `$c->seed`, `$c->id`, `$c->fake('city', 'adres')` |

**Seeds.** De waarden zijn steeds dezelfde voor hetzelfde element (de seed is het id
van het canonieke element), dus een concept en zijn revisies krijgen dezelfde
verzonnen persoon. Waarden met dezelfde `@groep` horen bij elkaar:
`firstName@lid` en `lastName@lid` vormen één persoon, `postcode@adres` en
`city@adres` één adres. Achter een generator mag `|upper` of `|lower`.

**Lege waarden.** Alleen gevulde waarden worden vervangen; een leeg veld blijft leeg.
Uitzonderingen zijn `null` en een closure, die altijd worden toegepast.

**Kolommen die niet leeg mogen.** `'null'` op een kolom met NOT NULL is een fout in
`check`: de import zou hem weigeren. Kies dan bijvoorbeeld `['value' => '']`. Geeft
een closure er toch NULL, dan stopt de dump.

## Presets

Een preset is een gewone config onder die van het project, voor een plugin. `check`
noemt de preset als die een tabel of elementtype kent waar nog een bestemming
voor mist.

| Preset | Wat het doet |
|---|---|
| `craft` | Staat altijd aan, zie "Wat altijd gebeurt". |
| `formie` | **Inzendingen:** houden hun vorm met verzonnen inhoud (zie Handlers), en hun titel wordt `Inzending {id}`. **Verzonden notificaties:** verliezen hun mail. **Betalingen:** verliezen de antwoorden van de provider. **De oude `fmc_*`-tabellen van Formie 2:** gaan leeg. De formulieren blijven. |
| `retour` | De 404-statistiek verliest IP-adres, referrer en user agent. |
| `knock-knock` | De inlogpogingen gaan leeg: daar staan IP-adressen en ingetypte wachtwoorden in. |
| `webhooks` | De verstuurde requests gaan leeg, en de headers van een webhook (tokens) ook. |
| `wishlist` | Lijsten en items blijven; sessie en IP-adres van een lijst gaan. |
| `hyper` | Links blijven, en de caches gaan leeg. |
| `seomatic` | SEO-instellingen blijven. |
| `ckeditor` | De verwijzingen tussen teksten en elementen blijven. |

## Handlers

Voor inhoud die een regel niet kan beschrijven, zoals JSON van een plugin met een
eigen opbouw, implementeer je `perfectwebteam\anonymize\Handler` en noem je de klasse
in `handlers`. `prepare()` draait in de snapshot vóór de dump en mag queries doen.
`row()` krijgt elke rij van de tabellen die de handler noemt, en mag geen query doen.

`FormieSubmissions` (preset `formie`) is het voorbeeld. Formie 3 slaat een inzending op
als JSON op uid van elk veld, en genest voor groepen, herhalingen en de delen van een
naam, adres of datum:

- **Naam, e-mail, adres, telefoon en datum** krijgen nepwaarden: per inzending één persoon en één adres.
- **Een keuze** blijft alleen als die past bij wat het veld kan bevatten. Dat is een van zijn opties, een id, ja of nee, AM of PM, of een landcode. Iets anders erin wordt vervangen.
- **Handtekeningen en wachtwoord-hashes** gaan weg.
- **Al het andere, ook velden die de handler niet kent,** houdt zijn vorm met verzonnen inhoud.

Inzendingen van bewaarde gebruikers blijven zoals ze zijn.

## PII-scan

Elke tekstwaarde die de dump uitschrijft en een `@` bevat, wordt na het anonimiseren
nagekeken op e-mailadressen. Adressen worden gemeld als ze niet van een bewaarde
gebruiker zijn, niet verzonnen zijn, en niet in `scan.allowEmails` of op een domein in
`scan.allowDomains` staan. De melding komt per tabel en kolom op stderr, met een paar
voorbeelden, gemaskeerd (`j***@g***.nl`).

Zo vindt de scan wat geen regel raakte: een tabel van een plugin, een log, een oude
tabel, een veld dat niet strict hoefde. Met `'fail' => true` stopt de dump dan, en
blijft er geen bestand staan.

Sta liever losse adressen toe dan hele domeinen: op het domein van de klant werken
ook mensen.

## Collaties

De standaard-collatie van een tabel (`COLLATE=…` achter de kolommen) laat de dump
weg. De database die importeert geeft dan elke tabel de standaard-collatie van zijn
karakterset, voor alle tabellen dezelfde. Twee tabellen die verschilden,
bijvoorbeeld `utf8mb3_general_ci` van Craft naast `utf8mb3_unicode_ci` van een
plugin, geven dan geen "Illegal mix of collations" meer in een join.

Een collatie die een kolom zelf heeft, zoals `utf8mb4_bin` op een JSON-kolom, blijft
staan. Met `'tableCollations' => true` gaat ook de standaard van elke tabel mee.

## Wat altijd gebeurt

De preset `craft` (zie `presets/craft.php`) doet dit:

- **Gebruikers** die niet bewaard blijven krijgen een verzonnen naam en e-mailadres (`voornaam.achternaam.{id}@example.invalid`) en géén wachtwoord. Inloggen als hen gaat via impersonation. Hun 2FA, passkeys, SSO-koppelingen en Craft ID-tokens vallen weg.
- **Adressen** worden verzonnen, behalve die van bewaarde gebruikers en elementen.
- **Sessies, tokens, de wachtrij, deprecation-logs en de asset-index** worden leeggemaakt.
- **GraphQL-tokens** worden vervangen, behalve het publieke.
- **Notities bij concepten en revisies** worden leeggemaakt.
- **Tabellen die van Craft 4 zijn blijven staan** (`content`, `matrixcontent_*`) worden leeggemaakt. Craft 5 leest ze niet, maar er staan oude veldwaarden in.

Verder doet het script zelf:

- **De prullenbak** wordt leeggemaakt (`verwijderd-{id}`), ook in secties die je bewaart. Alleen wat los bewaard wordt (`entry:id`, een bewaarde gebruiker) houdt het.
- **Drafts en revisies** volgen hun origineel.
- **De zoekindex** van alles wat verandert wordt opnieuw uitgerekend uit wat er geschreven is. Wat daarvoor een query zou vragen (relaties, CKEditor) wordt leeggemaakt; na de import maakt `php craft resave/entries --update-search-index` dat weer compleet.
- **Sleutels in de content** van velden die niet meer in de veldindeling staan, verdwijnen bij elementen die veranderen, zoals bij een save.
- **De tabel `anonymized`** komt als laatste in de dump, met de bron en het tijdstip. Zo herkent `anonymize/placeholders` een kopie. Heeft de bron zelf zo'n tabel (een kopie van een kopie), dan komt alleen de nieuwe mee.

## Wat het niet doet

- **Bestanden meenemen.** Volumes, `storage/` en logs vallen erbuiten; alleen de database gaat mee. De dump vervangt wel bestandsnamen en titels, en op de kopie maakt `anonymize/placeholders` nepbestanden.
- **Importeren.** Dat doet de mariadb-client of `craft db/restore`.
- **Andere zoekindexen.** Meilisearch en Elasticsearch, en caches in Redis, vallen erbuiten.
- **Integraties uitzetten.** Een kopie waarop webhooks of een koppeling naar een CRM aanstaan, stuurt naar echte systemen.
- **PostgreSQL en Craft 4.**

## In een ander project

1. **Voeg het package toe** zoals onder "Installeren".
2. **Begin met een kale config** in `config/anonymize.php`, met de presets van de
   plugins die het project heeft:

   ```php
   <?php

   return [
       'presets' => ['formie', 'retour', 'knock-knock', 'webhooks', 'hyper', 'seomatic', 'ckeditor'],
       'keep' => ['users' => ['emailDomains' => ['pwt.nl', 'perfectwebteam.nl']]],
   ];
   ```

   Bewaar alleen domeinen waarvan je zeker bent dat er geen klanten of leden op
   staan.
3. **Draai lokaal `php craft anonymize/check`** (het leest alleen). Het noemt alles
   zonder bestemming. Werk de lijst af:
   - **Inhoud zonder persoonsgegevens** gaat in `keep.elements`: `section:…`, `globalSet:…`, en de bloktypes van Matrix als `entryType:…`.
   - **Entry types met persoonsgegevens** krijgen `elements['entryType:…']` met `title` en `fields`. Geef velden van één persoon of één adres dezelfde `@groep`.
   - **Volumes** krijgen `filename`, `title` en `alt`: `placeholder`, of `keep` voor bijvoorbeeld logo's.
   - **Velden op gebruikers** gaan in `elements['user']`. Daar staan bij ledensites de meeste persoonsgegevens.
   - **Elementen van een eigen plugin** gaan heel in `keep.elements` (`elementType:vendor\plugin\*`), of krijgen een regel met een titel.
   - **Tabellen van plugins** gaan in `keep.tables`, worden `'empty'`, of krijgen kolomregels. Waar een preset bestaat, noemt `check` die.
   - **Restanten van oude plugins** (een andere 2FA, oude linkvelden) worden meestal `'empty'`.
   - **Veldtypes van plugins zonder vrije tekst** gaan in `fieldTypes`.

   Herhaal tot er staat: `De config is in orde, ook strict.`
4. **Draai lokaal proef.**
   1. Zet `php craft anonymize/dump --output=…` op een kopie van de lokale database.
   2. Lees de PII-scan op stderr. Wat daar staat, krijgt een regel, of komt in `scan.allowEmails`.
   3. Importeer de dump in een lege database.
   4. Kijk na:
      - alleen jullie eigen gebruikers zijn echt;
      - entries met persoonsgegevens zijn vervangen;
      - de prullenbak is leeg;
      - zoeken werkt.

      Zoek in de kopie op een paar echte e-mailadressen en namen uit de bron, en tel
      alleen. Of zet in een kopie eerst herkenbare nepwaarden (canaries) en kijk of ze
      weg zijn.
5. **Commit** de config en de composer-bestanden en deploy zoals altijd. Het commando
   leest alleen.
6. **Maak de kopie** zoals onder "Downloaden via de CLI", en importeer hem. Draai
   daarna op de kopie:
   - `php craft anonymize/placeholders`, als de bestanden niet meekomen;
   - `php craft resave/entries --update-search-index`, voor de zoekwoorden van relaties en CKEditor;
   - wat het project zelf nodig heeft, zoals een externe zoekindex opnieuw vullen.

   Heet de testomgeving anders dan `test` of `staging`, zet hem dan in
   `placeholders.environments`.
7. **Ruim dummy-data op** waar die zat:
   - `php craft plugin/uninstall dummy-data`;
   - `composer remove quatrecentquatre/craft-dummy-data`;
   - `config/dummy-data.php` weg.
8. **Houd het bij.** Een nieuw veld of een nieuwe tabel laat `check` en de dump
   stoppen. Geef het in dezelfde pull request een bestemming.

## Hoe het werkt

`SqlDumpWriter` doet wat `mariadb-dump --single-transaction` doet:

- één consistente snapshot;
- `SHOW CREATE TABLE` per tabel;
- rijen unbuffered en in sleutelvolgorde gelezen, binair als hex, in utf8mb4 en UTC.

Zonder regels geeft de import dezelfde `CHECKSUM TABLE` als de bron, voor elke
tabel. Views, triggers, routines en events weigert hij liever dan ze stil weg te
laten.

`Plan` werkt de config uit tegen de database, in dezelfde snapshot: welke
content-sleutel van welke veldindeling in welke sectie welke strategie krijgt, en
wie er bewaard blijft. `Anonymizer` past dat toe op de rijen terwijl ze voorbijkomen.

Het geheugen groeit niet mee met de grootte van de database. Het groeit wel mee met:

- het hoogste element-id: een tot twee bytes per id, in een handvol kaarten;
- het aantal gebruikers en elementen dat verandert: een regel zoekwoorden elk.

Is de CLI-limiet van 256 MB niet genoeg, dan helpt `php -d memory_limit=512M craft anonymize/dump`.
