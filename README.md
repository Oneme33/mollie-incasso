# Mollie Incasso — testversie 0.1.2

Zelfstandige WordPress-plugin voor één organisatie en één Mollie-profiel. Nederlandse, vertaalbare interface, geen WooCommerce of abonnementendienst. Eerste iDEAL-betaling: **€ 0,01**, expliciet `sequenceType=first` bij een nieuwe, lokaal gekoppelde Mollie Customer. Alleen een betaald verificatieverzoek en een geldig `directdebit`-mandaat activeren toestemming. De eurocent blijft bij de organisatie, is geen abonnementsperiode en wordt niet stilzwijgend verrekend of terugbetaald.

**Dit is een eerste testversie.** De lokale mocktests bewijzen niet dat uw profiel, hosting, bankverwerking of juridische afspraken geschikt zijn voor livegebruik. Er zijn tijdens ontwikkeling geen live betalingen of echte incasso’s uitgevoerd. Een Mollie-testkey en publiek bereikbaar HTTPS-adres waren niet beschikbaar voor een volledige externe test.

## Vereisten en aannames

- WordPress **6.5+**, PHP **8.2+**, extensies curl, json en openssl, MySQL 5.7+/MariaDB 10.5+ met InnoDB, utf8mb4 en named locks. PHP 8.2.29 en WordPress 6.8.1 zijn lokaal getest. Zip/Composer zijn alleen voor de build nodig.
- Mollie-profiel met iDEAL voor first-betalingen én SEPA Direct Debit voor recurring betalingen. Gebruik **Mollie Incasso → Diagnose → Beschikbare methodes controleren**. Ook bij aanmelding en opdrachtvoorbereiding controleert de plugin de werkelijk beschikbare methodes.
- EUR, Nederlandse klanten, één organisatie. De regeling hoort bij dezelfde organisatie en hetzelfde Mollie-profiel. Financiële opdrachten blijven aan hun geaccepteerde regelingsversie gekoppeld. Bewerken van een regeling maakt een nieuwe versie; het verandert bestaande toestemming niet.
- Werking op één WordPress-site; geen netwerkbrede multisite-activatie. Database, hostingklok en cron moeten betrouwbaar functioneren. Databaserecords en tijdstippen zijn UTC; geplande incassodatums gebruiken Europe/Amsterdam.
- Volledige bankgegevens worden niet lokaal bewaard. De SDK ontvangt ze mogelijk van Mollie; de gateway houdt uitsluitend een gemaskeerde laatste-vier-tekens-indicatie over.
- Opnieuw aanmelden met hetzelfde **onbevestigde e-mailadres** creëert een afzonderlijke lokale klant en Customer. Alleen herhaling van dezelfde gebonden aanmeldsessie hergebruikt die Customer. E-mail is geen bewijs van eigenaarschap.

## Installeren

1. Upload `mollie-incasso-0.1.2.zip` via **Plugins → Nieuwe plugin → Plugin uploaden** en activeer.
2. Stel een **testkey** buiten de database in, bij voorkeur in `wp-config.php` vóór het laden van WordPress:

   ```php
   define('MOLLIE_INCASSO_TEST_KEY', getenv('MOLLIE_INCASSO_TEST_KEY'));
   ```

   Gebruik de echte `test_…` key van dit profiel; zet deze nooit in Git, browsercode of exports.
3. Vul onder **Mollie Incasso → Instellingen en regelingen** de organisatie, adresgegevens, contact/intrekken, privacyverklaring en bewaartermijn in. Maak een regeling voor losse incasso’s of een vast abonnement.
4. Plaats `[mollie_incasso regeling="123"]` in een normale WordPress-pagina. Neem het echte regeling-ID uit het beheer over. `[mollie_incasso]` gebruikt de nieuwste actieve regeling in de huidige omgeving. Een WordPress-account is niet nodig.
5. Controleer de methodes, HTTPS-webhook, mailverzending en cron. Loop daarna de externe teststappen hieronder door.

De hoofdpagina opent een eigen overzicht met aantallen en korte uitleg. Via **Installatiehulp** kunt u optioneel vier stappen doorlopen: organisatie opslaan, testkey instellen/betaalmethodes controleren, regeling vastleggen en het formulier plaatsen. Overslaan is altijd mogelijk. De hulp schakelt geen livebetalingen in en controle van methodes is geen volledige betaaltest. De knop **Conceptpagina maken** maakt een ongepubliceerde pagina met de servergekozen shortcode; herhaald klikken opent dezelfde pagina. Bestaande WordPress-pagina’s worden bij uninstall niet verwijderd.

Het beheer heeft een subtiele groene zijbalk met eigen logo, native dropdowns met een groen pijltje en een compacte kaart voor nieuwe incasso’s. De plugin levert een zelfstandig aanmeldformulier en een persoonlijke statuspagina met een moderne, mobiele vormgeving. CSS wordt uitsluitend geladen bij eigen formulieren, statuspagina’s en beheerschermen. Er zijn geen externe fonts, frontend-API-keys of JavaScript-afhankelijkheden.

### Secrets via instellingen

**Normaal gebruik: geen serverwerk nodig.** Kopieer de test-API-key uit Mollie Dashboard → Developers → API keys. Plak hem onder Instellingen of Installatiehulp en sla op. Controleer daarna de betaalmethodes met de controleknop. De plugin versleutelt de key automatisch met een afgeleide sleutel uit de bestaande `AUTH_KEY`, `SECURE_AUTH_KEY`, `AUTH_SALT` en `SECURE_AUTH_SALT` in `wp-config.php`. Als deze vier configuratiesleutels niet bruikbaar zijn, maakt de plugin automatisch een willekeurige eigen 32-byte sleutel in `wp-content/.mollie-incasso-keys/site-….php`. U hoeft hiervoor niets handmatig aan te maken. De plugin wijzigt `wp-config.php` niet. De map is privé (0700), het bestand 0600 op Unix; rechtstreeks uitvoeren van het PHP-bestand stopt onmiddellijk met HTTP 404 en geeft geen sleutel terug. Het bestand wordt als tekst gelezen, niet als code ingeladen. Exclusieve creatie en locks voorkomen dat gelijktijdige verzoeken een bestaande sleutel overschrijven. Onleesbare/corrupte sleutelbestanden worden nooit stilzwijgend vervangen; bij ontoereikende schrijfrechten blijft opslag geblokkeerd met een concrete melding.

**Optioneel voor beheerders:** API-keys rechtstreeks via constante/omgeving instellen blijft mogelijk en heeft voorrang. Een afzonderlijke base64-gecodeerde 32-byte `MOLLIE_INCASSO_ENCRYPTION_KEY` buiten de database blijft eveneens ondersteund. Deze extra encryptiesleutel is niet nodig voor normaal gebruik. Nieuwe opslag gebruikt deze sleutel wanneer hij is ingesteld; bestaande daarmee versleutelde keys blijven leesbaar. Bewaar hem buiten de database en maak veilig een back-up.

De opslag gebruikt AES-256-GCM. De markeringen `wp1:` en `file1:` onderscheiden WordPress-versleuteling en bestandversleuteling van de bestaande externe versleuteling, zodat later instellen van een extra encryptiesleutel bestaande opslag niet breekt. Bewaar `wp-config.php` en, indien aangemaakt, de volledige map `wp-content/.mollie-incasso-keys` veilig bij back-ups. De automatische map staat buiten de pluginmap en wordt niet verwijderd bij updates, deactivatie of uninstall. PHP moet op de hosting normaal worden verwerkt; behandel de sleutelmap als een serversecret, houd haar buiten openbare downloads/back-uparchieven en bewaar haar bij een verhuizing. De bestandsnaam hangt af van het serverpad en site-ID: stel op de nieuwe locatie de API-key opnieuw in of laat uw beheerder de sleutel gecontroleerd migreren. De automatische sleutel bevat geen Mollie-API-key en wordt nooit gebundeld in de ZIP of Git. Bij gewijzigde WordPress-beveiligingssleutels moet de API-key opnieuw worden ingevoerd of de oorspronkelijke configuratie worden hersteld; externe versleuteling vereist de oorspronkelijke externe sleutel. Een databasekopie alleen bevat de decryptiesleutel niet; iemand met toegang tot configuratie én database kan de API-key wel ontsleutelen. Er worden geen sleutels gegenereerd in de database. Instellingsoptions staan niet op autoload. Het keyveld blijft leeg/gemaskeerd bij uitlezen. Toegang: `manage_options`; betaalbeheer: eigen capability `manage_mollie_incasso`, standaard alleen administrators.

## Flows en bediening

### Toestemming

De klant ziet organisatie, doel, bedrag/berekeningswijze, frequentie/aanleiding, vooraankondigingstermijn, intrekken en behandeling van de eurocent. Expliciete toestemming is verplicht en nooit vooraf aangevinkt. De volledige tekst, versie, regeling en tijdstip worden vastgelegd. Browservelden kunnen geen bedrag of regeling vervangen: een serveropgeslagen token bindt het formulier aan de regeling en browsercookie. Een gewijzigde disclosure vraagt om een nieuw formulier.

Het formulier maakt de Customer en de € 0,01 first-betaling met customerId, metadata, iDEAL, redirect en webhook. Alleen de door Mollie geretourneerde HTTPS-checkout-URL wordt gebruikt. Terugkeer bewijst geen betaling. Webhook/queue/API-controles werken de opgeslagen status bij; bij een pending mandaat blijft incasseren geblokkeerd. Een willekeurige overboeking van één cent activeert niets.

### Losse incasso

Onder Klanten kiest de beheerder de klant en geaccepteerde regeling, een positief EUR-bedrag binnen de afgesproken maximumgrens, doel/omschrijving en toekomstige aanbieddatum. De bevestigingspagina toont de onveranderlijke opgeslagen opdracht. Pas na bevestiging volgt een vooraankondiging. Op de aanbieddatum controleert de plugin toestemming en mandaat opnieuw en maakt hij één Mollie recurring/directdebit Payment met customerId en mandateId. De status begint als in behandeling; aanmaken betekent niet betaald.

### Vast abonnement

Kies een vaste regeling met bedrag, maand (`1 month`), kwartaal (`3 months`) of jaar (`12 months`) en optioneel 1–10000 betalingen. Dit maximum is een eigen v1-invoergrens; Mollie bepaalt de geldigheid van het daadwerkelijke verzoek. Na de eerste vooraankondiging maakt de plugin één **Mollie Subscription** met toekomstige startdatum, mandateId, directdebit, webhook en metadata. Ook bij een overeengekomen nuldagentermijn begint een abonnement niet op de verificatiedag. Mollie genereert de termijnen; WordPress maakt die termijnen niet zelf aan.

Per lokale klant/regeling is maximaal één lopend of voorbereid vast abonnement toegestaan. Zo veroorzaakt een nieuwe beheeractie geen tweede abonnement voor dezelfde afspraak.

Nieuwe payment-ID’s worden gekoppeld via de **bekende subscriptionId én customerId**, met controle op omgeving, bedrag, valuta en betaalmethode. Een nog niet lokaal opgeslagen abonnement wordt opnieuw gecontroleerd. Abonnementsstatussen worden elk uur via de API gesynchroniseerd. Mollie kan zelf mislukte abonnementsbetalingen opnieuw proberen; de plugin doet geen aanvullende financiële retries.

### Stoppen en intrekken

Een beveiligde persoonlijke link is zeven dagen geldig en geeft uitsluitend toegang tot de eigen klant. Alleen een SHA-256-hash van het willekeurige 256-bit token wordt opgeslagen. Een beheerder kan een nieuwe link laten mailen. Ingelogde klanten worden op eigenaarschap gecontroleerd.

**GET wijzigt nooit toestemming of abonnementen.** De klant bevestigt op de statuspagina via checkbox en beveiligde POST. Stoppen beëindigt het gekozen abonnement bij Mollie; intrekken blokkeert direct nieuwe lokale opdrachten, beëindigt de bijbehorende abonnementen en trekt directdebit-mandaten in. Bij een API-fout blijft de actie zichtbaar als niet voltooid. Een andere externe actieve afspraak op hetzelfde mandaat verhindert intrekken van dat mandaat. Het beheer moet die afspraak eerst bij Mollie behandelen. Een late verificatiewebhook na intrekken activeert geen toestemming en leidt opnieuw tot externe controle/intrekken.

Reeds ingezonden incasso’s blijven afzonderlijk te behandelen. De plugin biedt geen refunds, storneringsverweer, facturatie, proratering, variabele bulkincasso of pause-functie. Refund- en chargeback-bedragen worden wel opgehaald en apart getoond: `paid` kan tegelijk een stornering hebben. De netto-indicatie is het betaalbedrag minus refunds en chargebacks, exclusief Mollie-kosten; geen boekhoudkundige balans.

## Betrouwbaarheid, webhooks en cron

De reguliere payment-webhook is:

```
https://uw-site.nl/wp-json/mollie-incasso/v1/payment/test
https://uw-site.nl/wp-json/mollie-incasso/v1/payment/live
```

POST met payment `id`, zonder WordPress-login of nonce. De ontvangen status wordt nooit vertrouwd. Een 200 volgt pas na opslag in de InnoDB-queue. Opslagfouten geven 503, begrenzing 429. De worker haalt de betaling server-side bij Mollie op en controleert haar lokale koppeling. Niet-gerelateerde resources worden genegeerd zonder klantgegevens terug te geven. Een optionele HTTPS-proxyoverride moet `mi_mode=test/live` correct doorsturen naar de bijbehorende route; een eigen proxy is niet onderdeel van deze plugin.

Sluit formulierpagina’s, persoonlijke `mi_access`/`mi_return`/`mi_customer`-URL’s, `admin-post.php` en webhookroutes uit van caching en CDN/full-page caching. Laat checkout-return, webhooks en Mollie API-verkeer door de firewall. Persoonlijke pagina’s gebruiken no-cache, noindex en no-referrer; upstream caches moeten deze ook respecteren.

Zakelijke opdrachten hebben database-unieke referenties en idempotency-keys. Transacties beschermen lokale wijzigingen; database-locks sluiten gelijktijdige verwerking per klant uit. De exacte uitgaande aanvraag wordt vóór verzending bewaard. Queuejobs hebben unieke sleutels, leases en een generatiecounter; een webhook tijdens verwerking blijft beschikbaar voor een volgende controle. Maximaal 20 jobs/40 seconden per run, een lease van 180 seconden, maximaal acht automatische pogingen met back-off; mislukte taken zijn zichtbaar en herstelbaar in Diagnose. SDK-netwerkretries zijn uitgeschakeld zodat de plugin eerst zelf reconcileert.

**Mollie bewaart idempotency slechts één uur, gekoppeld aan credentials.** Na een onzekere verzending zoekt de plugin eerst op eigen metadata. Bij geen gevonden resource na 55 minuten of na key-wijziging blokkeert hij een nieuwe create. Controleer zo’n opdracht en het betreffende profiel handmatig; herstel van een job omzeilt die blokkade niet. Er is geen knop die zonder bewijs een onbekende uitkomst als mislukt bestempelt. Een beheerder kan definitieve afhandeling pas na onderzoek via een gecontroleerde gegevenscorrectie door een ontwikkelaar laten vastleggen.

WP-Cron hangt af van bezoek. Gebruik op de server bijvoorbeeld elke minuut:

```cron
* * * * * cd /pad/naar/wordpress && /usr/local/bin/wp cron event run --due-now --quiet >> /pad/buiten/webroot/wp-cron.log 2>&1
```

Met een betrouwbare server-cron kan `DISABLE_WP_CRON` op true. `wp mollie-incasso queue` verwerkt de beperkte queue direct. Houd Diagnose in de gaten: mislukte taken, onzekere opdrachten, verzendfouten en laatste run. Een moduswissel blokkeert lokale verwerking van de andere modus; **externe Mollie-abonnementen blijven bestaan**.

## Mail en vooraankondiging

Toestemming, abonnement, beëindiging en vooraankondigingen gebruiken een duurzame outbox met unieke gebeurtenissleutels. Betaalstatusmails zijn optioneel. Standaard termijn: **14 kalenderdagen**; een andere termijn vraagt een expliciete bevestiging van een afwijkende klantafspraak in de regeling. Beoordeel teksten, termijn, SEPA-verplichtingen en consumentenrechten vóór livegang. De voorbeeldtekst is geen verklaring van juridische geschiktheid.

De eerste vooraankondiging moet door `wp_mail` zijn geaccepteerd vóór een financiële opdracht wordt verzonden. Vervolgvooraankondigingen volgen Mollies `nextPaymentDate`, verminderd met de regelingstermijn. Te late cronruns en ongewone externe wijzigingen vragen operationele opvolging: de plugin kan reeds bij Mollie lopende termijnen niet door een vertraagde mail automatisch tegenhouden. Gebruik betrouwbare SMTP, monitoring en tijdige profielinstellingen.

`wp_mail=true` betekent geaccepteerd door de mailserver, **geen gegarandeerde aflevering**. False wordt met herstelbare fouten vastgelegd. Bij een procescrash tijdens verzending wordt de uitkomst onbekend en niet blind opnieuw verstuurd. Diagnose toont dit. Persoonlijke linktokens worden uitsluitend in het geheugen voor de betreffende mail samengesteld; volledige mailteksten/tokens staan niet in de lokale queue of logs.

## Test/live, staging en kopieën

Test is de standaard. Records, tokens, keys en jobs zijn per mode gescheiden. Live werkt alleen wanneer alle volgende voorwaarden gelden:

```php
define('WP_ENVIRONMENT_TYPE', 'production');
define('MOLLIE_INCASSO_ALLOW_LIVE', true);
define('MOLLIE_INCASSO_LIVE_SITE', 'https://uw-productiesite.nl');
define('MOLLIE_INCASSO_LIVE_KEY', getenv('MOLLIE_INCASSO_LIVE_KEY'));
```

HTTPS is vereist voor live POST’s en voor alle webhookadressen. Een sitebinding op home-URL én serverpad blokkeert taken op een kopie; opnieuw binden zet de kopie in testmodus. Staging moet expliciet `WP_ENVIRONMENT_TYPE=staging` gebruiken, zonder livekey of live-vlag. Zet bij migraties/back-ups waar nodig `MOLLIE_INCASSO_DISABLED=true`. Een byte-identieke database/config-kopie onder exact dezelfde URL en hetzelfde pad is technisch niet te onderscheiden: zet altijd een stagingomgeving en veilige secrets op de kopie. Een moduswissel of deactivering stopt nooit externe abonnementen.

## Privacy en opruimen

WordPress **Gereedschap → Persoonsgegevens exporteren/verwijderen** is ondersteund. Export bevat klant, toestemming en gerelateerde betaal-, abonnements- en gemaskeerde mandaatgegevens. Bewaartermijn is configureerbaar, standaard 2555 dagen. De termijn begint conservatief na de meest recente klantwijziging of betaling. Er is geen automatische bulkverwijdering: verwijderen volgt een expliciet WordPress-privacyverzoek. Beoordeel bewaarplicht, grondslag en beleid voor uw organisatie.

Een verwijderverzoek behoudt gegevens bij actieve/onzekere afspraken, onbekende betaaluitkomsten, lopende termijn of onbereikbare externe verificatie. Beëindig eerst extern; pas na de termijn en een actuele API-controle worden klant en bijbehorende gegevens daadwerkelijk verwijderd. Betalingen bij Mollie blijven onder hun eigen bewaarbeleid vallen. Een lege lokale status stopt geen extern abonnement.

Deactivatie bewaart gegevens **en externe abonnementen**. Uninstall ruimt standaard niets op. De Action Scheduler-waarschuwing van andere plugins wordt uitsluitend op eigen incassoschermen verborgen; de externe taken en meldingen op andere schermen blijven behouden.

Alleen met expliciet aangevinkte opruimkeuze, verstreken bewaartermijnen en actuele controle van alle klantafspraken kan de plugin tabellen/secrets verwijderen. Als een andere mode niet kan worden gecontroleerd, blijven gegevens behouden. Neem eerst een beveiligde back-up; verwijdering kan niet ongedaan worden gemaakt. Logbestanden bevatten alleen beperkte foutcodes, geen API-keys of volledige API-payloads. De CSV is begrensd op 500 klanten en neutraliseert spreadsheetformules.

## Broncode en reproduceerbare build

De bron staat in de root van de eigen GitHub-repository (in het themaproject onder `mollie-incasso/`); eigen records/statussen in elf `wp_mi_*`-tabellen. Schema versie 1 legt de tabellen idempotent aan met `dbDelta`; migratie 2 maakt Mollie-ID’s expliciet hoofdlettergevoelig. Nieuwe schemawijzigingen krijgen een verhoogde migratieversie. De officiële SDK is vastgelegd in `composer.lock` (**v3.15.0**), PHP-Scoper in `tools/composer.lock` (**v0.18.20**). De ZIP bevat alle productieafhankelijkheden onder `Lukraak\MollieIncasso\Vendor`, met een eigen autoloader zonder globale aliases/Composer-registratie. Een bestaande Mollie/WooCommerce-SDK kan daardoor naast deze versie laden.

Ontwikkelmachine: PHP 8.2+, curl/openssl/zip, Composer. Hosting heeft **geen Composer** nodig.

```sh
cd mollie-incasso
php tools/build.php /absoluut/pad/composer.phar ../artifacts/mollie-incasso-0.1.2.zip
```

Met Composer op PATH: `php tools/build.php composer` (standaarduitvoer: `dist/mollie-incasso-0.1.2.zip`). De build gebruikt uitsluitend locks (`install`, geen `update`), scoped alle productiecode, controleert de SDK/CA-bundle en PHP-syntax en maakt een ZIP met gesorteerde bestanden en vaste tijdstempels/rechten. De ZIP bevat geen devtools, eigen testfixtures, secrets of ongescopete autoloaders. Zelfde bron, locks en toolomgeving produceren dezelfde ZIP. Dependencies en `.build/` zijn gegenereerd en staan niet in Git.

In dit repository is de build getest in de bestaande PHP 8.2.29 Docker-omgeving. Kopieer de pluginbron naar een tijdelijke map onder de lokaal gemounte `plugins/`, voer daar het bovenstaande PHP-buildcommando uit en kopieer de ZIP terug naar `artifacts/`. Composer 2.10.3 is daarvoor lokaal gebruikt. De bronmap buiten `plugins/` is leidend.

## Controles en externe acceptatie

Zie [TESTING.md](TESTING.md) voor het daadwerkelijke testrapport en reproduceerbare testcommando’s. Gebruik uitsluitend een **wegwerp/testdatabase**:

```sh
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/integration.php
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/sdk.php
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/secrets.php
```

De tests gebruiken geïsoleerde tabellen en proceslokale instellingen, maar een onderbreking kan testtabellen achterlaten. Een expliciet aangemaakte test-conceptpagina wordt na de controle verwijderd. Ze gebruiken een fake gateway/SDK-HTTP-adapter en onderscheppen `wp_mail`; geen echte incasso’s of mails. De SDK-test mag een reeds aanwezige ongescopete SDK laden voor conflictcontrole.

Nog uit te voeren op bereikbare HTTPS-testhosting met uw testprofiel: klant aanmelden, iDEAL-testcheckout betaald/mislukt, pending→valid mandaat controleren, echte webhook/retry, losse recurring testincasso, maand/kwartaal/jaar en maximaal aantal, gegenereerde abonnementsbetaling, eventuele mislukking/chargeback, API-stop/intrekken en een echte SMTP/cron-run. Mollie-testabonnementen kunnen door Mollie zelf na tien betalingen worden geannuleerd. Controleer de actuele testbeperkingen. Pas na deze controles, toetsing van toestemmings-/SEPA-afspraken en operationele monitoring kunt u livegang beoordelen.

## Geverifieerde officiële bronnen

- [Recurring payments](https://docs.mollie.com/docs/recurring-payments)
- [Create payment](https://docs.mollie.com/reference/create-payment), [create subscription](https://docs.mollie.com/reference/create-subscription), [get mandate](https://docs.mollie.com/reference/get-mandate)
- [Payment webhooks](https://docs.mollie.com/reference/webhooks), [idempotency](https://docs.mollie.com/reference/api-idempotency), [methods](https://docs.mollie.com/reference/list-methods)
- [Cancel subscription](https://docs.mollie.com/reference/cancel-subscription), [revoke mandate](https://docs.mollie.com/reference/revoke-mandate)
- [Officiële Mollie PHP-SDK](https://github.com/mollie/mollie-api-php), [PHP-Scoper](https://github.com/humbug/php-scoper)
- [EPC 2025 SEPA Direct Debit Core Rulebook](https://www.europeanpaymentscouncil.eu/document-library/rulebooks/2025-sepa-direct-debit-core-rulebook-version-10)

Velden/signatures en het gedrag van de vastgelegde SDK zijn tijdens implementatie gecontroleerd. Mollie en scheme-regels kunnen veranderen; onderhoud en hernieuwde controles blijven nodig.

## Bestaande WooCommerce-abonnementen

**Onderzoek 14 oktober 2026; nog geen geïmplementeerde importfunctie.** De precieze betaal-/abonnementenplugin en versie van de bronsite zijn nog onbekend. De onderstaande route is onderzocht voor WooCommerce Subscriptions met de officiële Mollie Payments for WooCommerce-plugin. Andere abonnementenplugins vragen hun eigen adapter.

### Mogelijke routes

| Route | Toepassing | Beoordeling |
| --- | --- | --- |
| Rechtstreeks uitlezen op dezelfde WordPress-site | WooCommerce en Subscriptions staan tijdelijk nog geïnstalleerd | Voorkeur: WooCommerce-objecten gebruiken, bron-ID’s en metadata behouden; geen betaalde exportplugin nodig voor een eigen adapter. |
| CSV overnemen vanaf een andere site | De nieuwe plugin draait elders | Mogelijk met een afgesproken exportformaat inclusief Mollie-referenties, betaaldata en herkomst. Een gewone klanten-/productexport is onvoldoende. |
| Klanten opnieuw laten aanmelden | Machtiging, koppeling of bewijs ontbreekt; regeling verandert wezenlijk | Bestaande gegevens hoogstens ter voorbereiding overnemen; klant doorloopt zelf de normale toestemming en eerste betaling. |

### Aantoonbaar aanwezige bronkoppelingen

In de officiële Mollie WooCommerce-bron (onderzocht op `dev/develop`, commit `b2a4fcf06f6c006a48b804d64dedecb2c6a23bb5`) staan `_mollie_customer_id`, `_mollie_mandate_id`, `_mollie_payment_id` en `_mollie_payment_mode`. Koppelingen kunnen op het abonnement of de oorspronkelijke order staan; de handler heeft expliciete fallbacks naar die order. Oude versies kunnen afwijken. De renewal-handler krijgt een WooCommerce-betaalopdracht en maakt een Mollie recurring Payment; een WooCommerce-abonnementsnummer is dus geen Mollie subscriptionId.

Gebruik bij een adapter de WooCommerce/Subscriptions-objecten en `get_meta()`, niet uitsluitend SQL op `wp_postmeta`: HPOS kan abonnementen/orders in eigen tabellen opslaan. Lees klant, betaalgateway, abonnement-ID, totaalbedrag inclusief eventuele kosten/belasting, valuta, interval, volgende betaling in UTC plus sitetijdzone, eind-/proefdatums en openstaande renewal orders uit. E-mail alleen bewijst geen eigenaarschap van een Mollie-klant. API-keys horen niet in een export.

### Aanbevolen toekomstige importflow

1. **Alleen analyseren.** Detecteer exacte pluginversies en opslagtype; lees gepagineerd via ondersteunde bron-API’s. Laat een overzicht zien van geschikt, controle nodig en niet ondersteund. Deze stap maakt geen betalingen, klanten of abonnementen aan en verandert niets in WooCommerce.
2. **Valideren.** Controleer met dezelfde Mollie-organisatie en juiste test/live-omgeving de customer-, payment- en mandatekoppeling. Alleen een actuele geldige directdebit-machtiging past bij deze versie. Bewaar bewijs van bestaande toestemming en regeling als importherkomst; ontbrekende bewijsvoering niet aanvullen met een verzonnen acceptatie of verificatiebetaling.
3. **Import voorbereiden.** Kies een passende regeling, bewaar bron-site/abonnement-ID en een onveranderlijke snapshot. Unieke importreferenties moeten dubbel importeren voorkomen. Geïmporteerde records blijven geblokkeerd voor incasso zolang de migratie niet is afgerond.
4. **Gecontroleerd omschakelen.** Maak een definitieve momentopname. Handel bestaande openstaande betalingen/retries af en blokkeer alleen de relevante oude renewals via de ondersteunde bronplugin-flow. Controleer dat geen worker ze nog kan uitvoeren. Verander of trek het mandaat niet in als het nieuwe abonnement dit moet blijven gebruiken. Deelgebruik door andere abonnementen moet zichtbaar zijn. Geen generieke SQL-delete van Action Scheduler-taken.
5. **Nieuwe planning aanmaken.** Pas na gecontroleerde blokkering van de bronincasso en bevestiging door de beheerder een Mollie Subscription aanmaken met de juiste toekomstige startdatum. Respecteer de bestaande afspraken en vooraankondigingstermijn; te nabije datums vereisen expliciete afhandeling, geen stilzwijgend verschuiven of dubbel innen. Bij timeouts eerst op de bewaarde importreferentie reconciliëren. Nieuwe planning pas actief melden na bevestiging bij Mollie; falen blijft als onvoltooide migratie zichtbaar.

Een oude geldige machtiging kan technisch herbruikbaar zijn als dezelfde organisatie en afspraken blijven gelden; dit moet per bronrecord worden gecontroleerd. De huidige normale aanmeldflow vereist een eigen gecontroleerde eerste betaling en lokale toestemming. Een importer moet daarom een **afzonderlijke, geteste adoptieflow** toevoegen; alleen records als actief markeren omzeilt bestaande beveiliging. De bestaande grens van één actief abonnement per lokale klant/regeling vraagt eveneens expliciete mapping bij meerdere broncontracten.

Vaste EUR-bedragen per maand, kwartaal of jaar sluiten het beste aan. Creditcards, week-/dagintervallen, variabele bedragen, kortingen die later vervallen, proefperiodes, afwijkende belasting-/verzendlogica, gepauzeerde contracten, einddatums en openstaande incasso’s moeten afzonderlijk worden beoordeeld. Historische WooCommerce-orders blijven als administratie bewaard; een import maakt hiervan geen nieuwe incasso’s of facturen.

De inventarisatie is op documentatie en officiële broncode gebaseerd. Er is geen kopie/export van de werkelijke bronabonnementen onderzocht en er zijn geen externe afspraken veranderd. Eerst de precieze bronplugin/versie of een beveiligde representatieve export controleren; daarna kan de analyzer en bijbehorende adapter worden gebouwd en met fixtures getest.

Bronnen:

- [Mollie WooCommerce subscription-handler, onderzochte commit](https://github.com/mollie/WooCommerce/blob/b2a4fcf06f6c006a48b804d64dedecb2c6a23bb5/src/Subscription/MollieSubscriptionGatewayHandler.php)
- [Mollie WooCommerce renewal-payload](https://github.com/mollie/WooCommerce/blob/b2a4fcf06f6c006a48b804d64dedecb2c6a23bb5/src/Payment/MollieSubscription.php)
- [Mollie: WooCommerce-abonnementen](https://docs.mollie.com/docs/woo-set-up-subscriptions), [mandaten](https://docs.mollie.com/reference/mandates-api)
- [WooCommerce: HPOS en CRUD](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/)
- [WooCommerce: renewals](https://woocommerce.com/document/subscriptions/renewal-process/), [scheduled events](https://woocommerce.com/document/subscriptions/develop/complete-guide-to-scheduled-events-with-subscriptions/)
- [WooCommerce: migratieoverwegingen](https://woocommerce.com/document/subscriptions/migrating-subscribers-woocommerce-subscriptions/)
