# Mollie Incasso — versie 1.0.2

Zelfstandige WordPress-plugin voor één organisatie en één Mollie-profiel. Nederlandse, vertaalbare interface, werkt zelfstandig, met een optionele WooCommerce-checkout; geen WooCommerce Subscriptions of externe abonnementendienst nodig. Eerste iDEAL-betaling: **€ 0,01**, expliciet `sequenceType=first` bij een nieuwe, lokaal gekoppelde Mollie Customer. Alleen een betaald verificatieverzoek en een geldig `directdebit`-mandaat activeren toestemming. De eurocent blijft bij de organisatie, is geen abonnementsperiode en wordt niet stilzwijgend verrekend of terugbetaald.

## Vereisten en aannames

- WordPress **6.5+**, PHP **8.2+**, extensies curl, json en openssl, MySQL 5.7+/MariaDB 10.5+ met InnoDB, utf8mb4 en named locks. PHP 8.2.29 en WordPress 6.8.1 zijn lokaal getest. Zip/Composer zijn alleen voor de build nodig.
- Mollie-profiel met iDEAL voor first-betalingen én SEPA Direct Debit voor recurring betalingen. Gebruik **Mollie Incasso → Diagnose → Beschikbare methodes controleren**. Ook bij aanmelding en opdrachtvoorbereiding controleert de plugin de werkelijk beschikbare methodes.
- EUR, Nederlandse klanten, één organisatie. De regeling hoort bij dezelfde organisatie en hetzelfde Mollie-profiel. Financiële opdrachten blijven aan hun geaccepteerde regelingsversie gekoppeld. Bewerken van een regeling maakt een nieuwe versie; het verandert bestaande toestemming niet.
- Werking op één WordPress-site; geen netwerkbrede multisite-activatie. Database, hostingklok en cron moeten betrouwbaar functioneren. Databaserecords en tijdstippen zijn UTC; geplande incassodatums gebruiken Europe/Amsterdam.
- Volledige bankgegevens worden niet lokaal bewaard. De SDK ontvangt ze mogelijk van Mollie; de gateway houdt uitsluitend een gemaskeerde laatste-vier-tekens-indicatie over.
- Opnieuw aanmelden met hetzelfde **onbevestigde e-mailadres** creëert een afzonderlijke lokale klant en Customer. Alleen herhaling van dezelfde gebonden aanmeldsessie hergebruikt die Customer. E-mail is geen bewijs van eigenaarschap.

## Installeren

1. Upload `mollie-incasso-1.0.2.zip` via **Plugins → Nieuwe plugin → Plugin uploaden**. Bij een update kiest u de bestaande plugin te vervangen; verwijderen is niet nodig.
2. Open **Mollie Incasso → Installatiehulp**. Stap 1: organisatie en contactgegevens. Stap 2: plak uw `live_…` API-key uit Mollie Dashboard → Developers → API keys en klik op **Live activeren** (de plugin controleert eerst bij Mollie of iDEAL en SEPA-incasso live beschikbaar zijn). **Eerst risicoloos testen?** Zet onder **Instellingen → Geavanceerd → Testfase gebruiken** aan; dan kunt u een `test_…`-key gebruiken, naar testmodus schakelen en testgegevens wissen.
3. Stap 3: geef het eerste abonnement een naam (bijvoorbeeld ‘GCC doorlopend abonnement’), bedrag en maand/kwartaal/jaar. Controleer de zichtbare voorbeeldtekst van de incassoafspraak en pas die aan. De wizard kiest automatisch starten met 14 dagen vooraankondiging. U kunt meerdere abonnementen toevoegen; bestaande afspraken blijven aan hun eigen versie gekoppeld.
4. Stap 4: kies **aanmelden op een gewone pagina** of **via een WooCommerce-product**. Voor een pagina kunt u de shortcode kopiëren of een conceptpagina maken. Voor de winkel kunt u per regeling een eenvoudig virtueel product als concept klaarzetten. Herhaald klikken opent dezelfde pagina/product. Controleer het concept en publiceer het daarna.
5. Test de gekozen route bij voorkeur eerst in de testfase op HTTPS met de eigen Mollie-testkey, inclusief betaling, machtiging, webhooks, mail, cron en abonnement. Wis daarna de testgegevens en zet de testfase uit. De wizard is optioneel: geavanceerde regelingen en losse incasso’s blijven via Instellingen beschikbaar. De wizard publiceert niets.

### Aanmelden via WooCommerce

WooCommerce is uitsluitend nodig als u de winkelroute kiest. WooCommerce Subscriptions en de officiële Mollie-betaalplugin zijn hiervoor niet vereist. Mollie Incasso levert een **eigen, geïsoleerde iDEAL-aanmeldgateway** die de al ingestelde Mollie-key gebruikt. Andere winkelproducten kunnen hun bestaande betaalmethodes blijven gebruiken. Beide routes maken verse lokale klanten en Mollie Customers aan; er wordt nooit op alleen een e-mailadres een bestaand mandaat overgenomen.

- Het virtuele product toont het abonnementsbedrag/frequentie plus de aparte € 0,01 verificatie. De winkelwagen rekent uitsluitend € 0,01 af: geen eerste abonnementsperiode, stilzwijgende verrekening of restitutie. De server corrigeert de verificatieprijs en weigert afwijkende ordertotalen, btw, verzending, kortingscodes/toeslagen of een gemengde winkelwagen.
- Eén abonnementaanmelding per bestelling, hoeveelheid één; gewone producten en andere abonnementen rekent de klant afzonderlijk af. Meerdere verschillende regelingen en bijbehorende producten worden ondersteund. Het samenvoegen van meerdere contracten op één checkout/mandaat is buiten deze versie.
- In de checkout staan de incassoafspraak, het bedrag, de frequentie, het opzeggen en een expliciete niet aangevinkte toestemming. Eén checkout is voldoende; geen tweede aanmeldformulier. Een gewone iDEAL-productbetaling of alleen de productnaam bewijst geen machtiging. De eigen gateway maakt de vereiste `first`-betaling bij de juiste Customer; API-controle en een geldig directdebit-mandaat zijn vereist.
- Ondersteunt de klassieke checkout en Checkout Blocks (officiële Payment Method Type + legacy Store API-verwerking), met WooCommerce-objectgetters/CRUD voor klassieke opslag en HPOS. De betaalmethode verschijnt alleen voor gekoppelde aanmeldproducten. Een eigen product koppelen kan bij Productgegevens → Algemeen via afzonderlijke test-/liveregelingen; gebruik een eenvoudig virtueel product. Test- en livekoppelingen worden niet onderling vervangen.
- Een duurzame `checkouts`-koppeling met unieke WooCommerce-order-ID voorkomt dubbele registratie bij retries. Bedrag, identiteit, regelingsversie en toestemming worden server-side gecontroleerd. Een onzekere API-uitkomst gaat naar de statuspagina en de lokale queue; er wordt niet gevraagd een tweede bestelling te plaatsen. Bij een nog open betaling staat op die pagina de gecontroleerde Mollie-checkoutlink.
- Een betaalde verificatieorder gaat direct naar **Voltooid** (niets te verzenden). Bij terugkeer op de bedankpagina controleert de plugin eerst bij Mollie, zodat WooCommerce de juiste status of zijn ‘betaling mislukt’-tekst toont. Een mislukte verificatieorder biedt geen ‘opnieuw betalen’; de klant plaatst een nieuwe bestelling, wat een nieuwe aanmelding start.
- Wijst Mollie het aanmaken van een abonnement af, dan staat Mollies uitleg (veld en reden, zonder persoonsgegevens) bij de opdracht onder Incasso’s, met de knop **Opnieuw proberen met nieuwe startdatum**; de klant krijgt dan een nieuwe vooraankondiging.
- WooCommerce annuleert onbetaalde aanmeldorders van deze gateway niet automatisch (voorraadreservering), zodat een vertraagde webhook of cron nooit een betaalde aanmelding intrekt. Bij handmatig annuleren controleert de plugin eerst bij Mollie of de € 0,01 toch betaald is; dan blijft de toestemming staan en volgt een ordernotitie.
- De WooCommerce-order wordt pas betaald gemeld na een via Mollie gecontroleerde betaalstatus. Toestemming/machtiging kan dan nog in behandeling zijn. Een geannuleerde onbetaalde aanmelding blokkeert de toestemming en plant intrekking; een latere betaalmelding start geen abonnement meer. Een eerder al betaalde verificatieorder annuleren is geen opzegging van het lopende abonnement: gebruik daarvoor Mollie Incasso.
- Vaste abonnementen met `auto_start` worden na geldig mandaat automatisch voorbereid en bevestigd met één vaste zakelijke opdrachtreferentie. De eerste incassodatum ligt veilig na de afgesproken vooraankondiging; de eerste vooraankondiging moet door de mailserver geaccepteerd zijn voordat de Subscription wordt aangemaakt. Mollie plant daarna de termijnen. Dezelfde automatische start werkt via het gewone websiteformulier. Bestaande regelingen zonder deze optie veranderen niet.
- **Vervolgbetalingen verschijnen in Mollie Incasso en Mollie.** Er worden geen periodieke WooCommerce-bestellingen, facturen, verzendopdrachten of Memberships-rechten gegenereerd. Dit vervangt het beschreven vaste incassoabonnement, niet alle product-/verzend-/kortings-/pro-rateringsfuncties van WooCommerce Subscriptions. Lopende bestaande WooCommerce-abonnementen worden niet automatisch gemigreerd.

Een WooCommerce- of Mollie-update kan integratiegedrag beïnvloeden. Lokaal getest met WooCommerce 9.9.5; de eigen checkout vereist geen Mollie Payments for WooCommerce-code. De exacte combinatie met de opgegeven 8.1.11, Flexible Shipping en andere bronsiteplugins is niet op die hosting getest. Maak een back-up en gebruik eerst testmodus.

Het beheer heeft een subtiele groene zijbalk met eigen logo, native dropdowns met een groen pijltje en een compacte kaart voor nieuwe incasso’s. De plugin levert een zelfstandig aanmeldformulier en een persoonlijke statuspagina met een moderne, mobiele vormgeving. CSS wordt uitsluitend geladen bij eigen formulieren, statuspagina’s en beheerschermen. Er zijn geen externe fonts, frontend-API-keys of zware JavaScript-afhankelijkheden. Alleen de optionele WooCommerce Blocks-checkout gebruikt een klein script met de bestaande WordPress/WooCommerce React-runtime.

### Secrets via instellingen

**Normaal gebruik: geen serverwerk nodig.** Kopieer de test-API-key uit Mollie Dashboard → Developers → API keys. Plak hem onder Instellingen of Installatiehulp en sla op. Controleer daarna de betaalmethodes met de controleknop. De plugin versleutelt de key automatisch met een afgeleide sleutel uit de bestaande `AUTH_KEY`, `SECURE_AUTH_KEY`, `AUTH_SALT` en `SECURE_AUTH_SALT` in `wp-config.php`. Als deze vier configuratiesleutels niet bruikbaar zijn, maakt de plugin automatisch een willekeurige eigen 32-byte sleutel in `wp-content/.mollie-incasso-keys/site-….php`. U hoeft hiervoor niets handmatig aan te maken. De plugin wijzigt `wp-config.php` niet. De map is privé (0700), het bestand 0600 op Unix; rechtstreeks uitvoeren van het PHP-bestand stopt onmiddellijk met HTTP 404 en geeft geen sleutel terug. Het bestand wordt als tekst gelezen, niet als code ingeladen. Exclusieve creatie en locks voorkomen dat gelijktijdige verzoeken een bestaande sleutel overschrijven. Onleesbare/corrupte sleutelbestanden worden nooit stilzwijgend vervangen; bij ontoereikende schrijfrechten blijft opslag geblokkeerd met een concrete melding.

**Optioneel voor beheerders:** API-keys rechtstreeks via constante/omgeving instellen blijft mogelijk en heeft voorrang. Een afzonderlijke base64-gecodeerde 32-byte `MOLLIE_INCASSO_ENCRYPTION_KEY` buiten de database blijft eveneens ondersteund. Deze extra encryptiesleutel is niet nodig voor normaal gebruik. Nieuwe opslag gebruikt deze sleutel wanneer hij is ingesteld; bestaande daarmee versleutelde keys blijven leesbaar. Bewaar hem buiten de database en maak veilig een back-up.

De opslag gebruikt AES-256-GCM. De markeringen `wp1:` en `file1:` onderscheiden WordPress-versleuteling en bestandversleuteling van de bestaande externe versleuteling, zodat later instellen van een extra encryptiesleutel bestaande opslag niet breekt. Bewaar `wp-config.php` en, indien aangemaakt, de volledige map `wp-content/.mollie-incasso-keys` veilig bij back-ups. De automatische map staat buiten de pluginmap en wordt niet verwijderd bij updates, deactivatie of uninstall. PHP moet op de hosting normaal worden verwerkt; behandel de sleutelmap als een serversecret, houd haar buiten openbare downloads/back-uparchieven en bewaar haar bij een verhuizing. De bestandsnaam hangt af van het serverpad en site-ID: stel op de nieuwe locatie de API-key opnieuw in of laat uw beheerder de sleutel gecontroleerd migreren. De automatische sleutel bevat geen Mollie-API-key en wordt nooit gebundeld in de ZIP of Git. Bij gewijzigde WordPress-beveiligingssleutels moet de API-key opnieuw worden ingevoerd of de oorspronkelijke configuratie worden hersteld; externe versleuteling vereist de oorspronkelijke externe sleutel. Een databasekopie alleen bevat de decryptiesleutel niet; iemand met toegang tot configuratie én database kan de API-key wel ontsleutelen. Er worden geen sleutels gegenereerd in de database. Instellingsoptions staan niet op autoload. Het keyveld blijft leeg/gemaskeerd bij uitlezen. Toegang: `manage_options`; betaalbeheer: eigen capability `manage_mollie_incasso`, standaard alleen administrators.

## Flows en bediening

### Toestemming

De klant ziet organisatie, doel, bedrag/berekeningswijze, frequentie/aanleiding, vooraankondigingstermijn, intrekken en behandeling van de eurocent. Expliciete toestemming is verplicht en nooit vooraf aangevinkt. De volledige tekst, versie, regeling en tijdstip worden vastgelegd. Browservelden kunnen geen bedrag of regeling vervangen: een serveropgeslagen token bindt het formulier aan de regeling en browsercookie. Een gewijzigde disclosure vraagt om een nieuw formulier.

Het formulier maakt de Customer en de € 0,01 first-betaling met customerId, metadata, iDEAL, redirect en webhook. Alleen de door Mollie geretourneerde HTTPS-checkout-URL wordt gebruikt. Terugkeer bewijst geen betaling, maar is wel het moment om de betaling server-side bij Mollie op te halen: de statuspagina en de WooCommerce-bedankpagina doen dat direct (begrensd per klant) en verversen automatisch zolang de aanmelding in behandeling is. Webhook/queue/API-controles werken de opgeslagen status bij; bij een pending mandaat blijft incasseren geblokkeerd. Een willekeurige overboeking van één cent activeert niets.

Staat de shortcode niet in de pagina-inhoud zelf (page builders, FSE-templates, widgets), dan kan de beveiligingscookie niet vooraf worden gezet. Het formulier toont dan eerst een knop **Aanmeldformulier openen** die de cookie zet en terugkeert; er wordt nooit een formulier zonder browserbinding getoond.

### Losse incasso

Onder Klanten kiest de beheerder de klant en geaccepteerde regeling, een positief EUR-bedrag binnen de afgesproken maximumgrens, doel/omschrijving en toekomstige aanbieddatum. De bevestigingspagina toont de onveranderlijke opgeslagen opdracht. Pas na bevestiging volgt een vooraankondiging. Op de aanbieddatum controleert de plugin toestemming en mandaat opnieuw en maakt hij één Mollie recurring/directdebit Payment met customerId en mandateId. De status begint als in behandeling; aanmaken betekent niet betaald.

Een losse incasso loopt nooit vanzelf: de beheerder start hem altijd zelf. Een regeling voor losse incasso's heeft geen frequentie, wel een maximum per incasso, een optioneel **standaardbedrag** en een eigen vooraankondigingstermijn (standaard 14 dagen; korter, minimaal 1 dag, alleen als dat in de afspraak met de klant staat).

Regelingen zijn te **bewerken** (Instellingen → Regelingen → Bewerken): dat maakt een nieuwe versie; klanten die al toestemming gaven houden hun versie, nieuwe aanmeldingen, shortcodes en webshopproducten gebruiken de nieuwe. **Verwijderen** haalt een ongebruikte regeling weg; een regeling in gebruik wordt gearchiveerd (geen nieuwe aanmeldingen, bestaande afspraken blijven werken) en kan weer worden geactiveerd.

### Incasso-run

Onder **Incasso-run** incasseert de beheerder meerdere klanten tegelijk: klanten aanvinken (met het standaardbedrag van hun regeling vooraf ingevuld), per klant het bedrag controleren, één omschrijving voor het afschrift en één datum kiezen, en in één keer bevestigen. Elke klant krijgt een eigen vooraankondiging en een eigen opdracht; een bedrag boven het maximum wordt per klant geweigerd en gemeld. Hetzelfde formulier twee keer versturen maakt geen tweede opdracht.

Een WooCommerce-product kan ook aan een regeling voor losse incasso's hangen: de klant rekent € 0,01 af, geeft toestemming en heeft daarna een geldige machtiging. Er start geen abonnement; de beheerder incasseert zelf (los of met een incasso-run).

### Bestaande machtigingen importeren

Onder **Klanten → Bestaande machtigingen importeren** zet de beheerder SEPA-machtigingen die al bij de eigen bank zijn getekend over naar Mollie, zodat klanten zich niet opnieuw hoeven aan te melden. CSV met kopregel: naam, e-mail, IBAN, datum (ondertekeningsdatum) en optioneel kenmerk; `;` of `,` en datums als `2024-01-31` of `31-01-2024` worden herkend, maximaal 200 regels per keer. De plugin maakt per regel een Mollie-klant en een directdebit-mandaat (IBAN, naam, ondertekeningsdatum, kenmerk), zonder verificatiebetaling. Het IBAN gaat alleen naar Mollie; lokaal blijven de laatste vier cijfers. Elke klant krijgt een welkomstmail met uitleg en zijn statuslink. Dezelfde regel opnieuw importeren maakt geen tweede machtiging; een door Mollie geweigerde regel kan na correctie opnieuw worden geïmporteerd. **Vraag Mollie vooraf** of u bestaande machtigingen mag overzetten en of klanten bericht moeten krijgen over de nieuwe incassant.

### Vast abonnement

Kies een vaste regeling met bedrag, maand (`1 month`), kwartaal (`3 months`) of jaar (`12 months`) en optioneel 1–10000 betalingen. Dit maximum is een eigen v1-invoergrens; Mollie bepaalt de geldigheid van het daadwerkelijke verzoek. Na de eerste vooraankondiging maakt de plugin één **Mollie Subscription** met toekomstige startdatum, mandateId, directdebit, webhook en metadata. Ook bij een overeengekomen nuldagentermijn begint een abonnement niet op de verificatiedag. Mollie genereert de termijnen; WordPress maakt die termijnen niet zelf aan.

Per lokale klant/regeling is maximaal één lopend of voorbereid vast abonnement toegestaan. Zo veroorzaakt een nieuwe beheeractie geen tweede abonnement voor dezelfde afspraak.

Nieuwe payment-ID’s worden gekoppeld via de **bekende subscriptionId én customerId**, met controle op omgeving, bedrag, valuta en betaalmethode. Een nog niet lokaal opgeslagen abonnement wordt opnieuw gecontroleerd. Elke abonnementsbetaling (webhook) laat het abonnement direct opnieuw ophalen, zodat de volgende vooraankondiging tijdig wordt gepland; daarnaast wordt elk abonnement dagelijks via de API gecontroleerd. Mollie kan zelf mislukte abonnementsbetalingen opnieuw proberen; de plugin doet geen aanvullende financiële retries.

### Stoppen en intrekken

Een beveiligde persoonlijke link is zeven dagen geldig en geeft uitsluitend toegang tot de eigen klant. Bij openen wordt het token naar een privé, httponly-cookie verplaatst en volgt een redirect naar een adres zonder token, zodat het niet in adresbalk, geschiedenis of serverlogs blijft staan. De statuspagina toont per betaling datum, soort, bedrag, status en terugbetaling/stornering. Alleen een SHA-256-hash van het willekeurige 256-bit token wordt opgeslagen. Een beheerder kan een nieuwe link laten mailen. Ingelogde klanten worden op eigenaarschap gecontroleerd.

**GET wijzigt nooit toestemming of abonnementen.** De klant bevestigt op de statuspagina via checkbox en beveiligde POST. Stoppen beëindigt het gekozen abonnement bij Mollie; intrekken blokkeert direct nieuwe lokale opdrachten, beëindigt de bijbehorende abonnementen en trekt directdebit-mandaten in. Bij een API-fout blijft de actie zichtbaar als niet voltooid. Een andere externe actieve afspraak op hetzelfde mandaat verhindert intrekken van dat mandaat. Het beheer moet die afspraak eerst bij Mollie behandelen. Een late verificatiewebhook na intrekken activeert geen toestemming en leidt opnieuw tot externe controle/intrekken.

Reeds ingezonden incasso’s blijven afzonderlijk te behandelen. De plugin biedt geen refunds, storneringsverweer, facturatie, proratering, variabele bulkincasso of pause-functie. Refund- en chargeback-bedragen worden wel opgehaald en apart getoond: `paid` kan tegelijk een stornering hebben. De netto-indicatie is het betaalbedrag minus refunds en chargebacks, exclusief Mollie-kosten; geen boekhoudkundige balans.

## Betrouwbaarheid, webhooks en cron

De reguliere payment-webhook is:

```
https://uw-site.nl/wp-json/mollie-incasso/v1/payment/test
https://uw-site.nl/wp-json/mollie-incasso/v1/payment/live
```

POST met payment `id`, zonder WordPress-login of nonce. De ontvangen status wordt nooit vertrouwd. Een 200 volgt pas na opslag in de InnoDB-queue. Opslagfouten geven 503, begrenzing 429. De worker haalt de betaling server-side bij Mollie op en controleert haar lokale koppeling. Niet-gerelateerde resources worden genegeerd zonder klantgegevens terug te geven. Een optionele HTTPS-proxyoverride moet `mi_mode=test/live` correct doorsturen naar de bijbehorende route; een eigen proxy is niet onderdeel van deze plugin.

**Rate-limits achter een proxy of CDN.** Formulier, aanmelding en webhook worden per bezoekers-IP begrensd. Standaard is dat `REMOTE_ADDR`. Staat de site achter Cloudflare, een load balancer of reverse proxy, stel dan de proxyadressen in, zodat niet alle bezoekers één limiet delen:

```php
define('MOLLIE_INCASSO_TRUSTED_PROXIES', '10.0.0.0/8, 2001:db8::/32');
```

Alleen als het verzoek van zo’n proxy komt, wordt één header gelezen: standaard `X-Forwarded-For` (van rechts naar links, tot het eerste niet-vertrouwde adres). Gebruik voor Cloudflare de Cloudflare-IP-ranges als vertrouwde proxies én kies hun header expliciet:

```php
define('MOLLIE_INCASSO_CLIENT_IP_HEADER', 'CF-Connecting-IP');
```

Andere headers worden genegeerd, ook als een proxy ze ongewijzigd doorgeeft; een bezoeker kan zijn eigen limiet dus niet kiezen. Hostingpartijen kunnen ook het filter `mollie_incasso_client_ip` gebruiken.

Sluit formulierpagina’s, persoonlijke `mi_access`/`mi_return`/`mi_customer`-URL’s, `admin-post.php` en webhookroutes uit van caching en CDN/full-page caching. Laat checkout-return, webhooks en Mollie API-verkeer door de firewall. Persoonlijke pagina’s gebruiken no-cache, noindex en no-referrer; upstream caches moeten deze ook respecteren.

Zakelijke opdrachten hebben database-unieke referenties en idempotency-keys. Transacties beschermen lokale wijzigingen; database-locks sluiten gelijktijdige verwerking per klant uit. De exacte uitgaande aanvraag wordt vóór verzending bewaard. Queuejobs hebben unieke sleutels, leases en een generatiecounter; een webhook tijdens verwerking blijft beschikbaar voor een volgende controle. De worker draait elke minuut en werkt per run door tot de queue leeg is, met een tijdsbudget van maximaal 40 seconden (ruim onder `max_execution_time`) en maximaal 400 taken. Taken hebben een prioriteit: opdrachten, mails, betaal-webhooks, stoppen en intrekken gaan vóór periodieke controles. Een lease duurt 180 seconden, maximaal acht automatische pogingen met back-off; mislukte taken zijn zichtbaar en herstelbaar in Diagnose. Periodieke controles zetten een mislukte taak niet stilzwijgend terug; alleen een nieuwe gebeurtenis (bijvoorbeeld een webhook) of herstel door de beheerder doet dat. SDK-netwerkretries zijn uitgeschakeld zodat de plugin eerst zelf reconcileert.

**Mollie bewaart idempotency slechts één uur, gekoppeld aan credentials.** Na een onzekere verzending zoekt de plugin eerst op eigen metadata. Bij geen gevonden resource na 55 minuten of na key-wijziging blokkeert hij een nieuwe create. Controleer zo’n opdracht en het betreffende profiel handmatig; herstel van een job omzeilt die blokkade niet. Er is geen knop die zonder bewijs een onbekende uitkomst als mislukt bestempelt. Een beheerder kan definitieve afhandeling pas na onderzoek via een gecontroleerde gegevenscorrectie door een ontwikkelaar laten vastleggen.

De achtergrondtaken draaien op de gewone WordPress-planning (WP-Cron), net als bij WooCommerce: die start bij websitebezoek, en elke Mollie-webhook telt ook als bezoek. Mollie voert abonnementstermijnen zelf uit; alleen vooraankondigingen, losse incasso’s en statuscontroles wachten op de planning en kunnen op een rustige site enkele uren later gaan, ruim binnen de vooraankondigingstermijn. Een server-cron is daarom **niet verplicht**. Diagnose waarschuwt als de taken langer dan drie uur niet hebben gedraaid. Wie het strakker wil, laat de hosting elke 5 minuten `wp-cron.php` aanroepen of gebruikt WP-CLI:

```cron
*/5 * * * * cd /pad/naar/wordpress && wp cron event run --due-now --quiet
```

Het uurlijkse onderhoud is een vangnet naast de webhooks en spreidt het werk over de dag: actieve klanten krijgen één mandaatcontrole per dag (in een vast uur per klant), klanten die op een geldige machtiging wachten elk uur. Afgehaakte of mislukte aanmeldingen (geen betaalde verificatie) worden niet meer voor mandaten gepolld; hun openstaande verificatiebetaling wordt gecontroleerd tot ze verloopt. Betalingen worden dagelijks gecontroleerd tot 70 dagen oud en daarna wekelijks tot 13 maanden (stornering). Afgehandelde taken ouder dan 30 dagen worden opgeruimd. `wp mollie-incasso queue` verwerkt de beperkte queue direct. Houd Diagnose in de gaten: mislukte taken, onzekere opdrachten, verzendfouten en laatste run. Een moduswissel blokkeert lokale verwerking van de andere modus; **externe Mollie-abonnementen blijven bestaan**.

## Mail en vooraankondiging

Plugin-mails gebruiken de organisatienaam als afzendernaam. Datums volgen de taal van de plugin-teksten: Nederlands zolang er geen vertaling is geladen. **Aflevering is de verantwoordelijkheid van de website-eigenaar**, net als bij WooCommerce-mails. Diagnose geeft advies, met links naar [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/) en [FluentSMTP](https://wordpress.org/plugins/fluent-smtp/), herkent een actieve SMTP-koppeling en heeft een knop **Testmail naar mijzelf versturen**. Zonder geauthenticeerde verzending (SPF/DKIM) kunnen bevestigingen in spam belanden, zoals in de online test van 14 oktober 2026.

Toestemming, abonnement, beëindiging en vooraankondigingen gebruiken een duurzame outbox met unieke gebeurtenissleutels. Betaalstatusmails zijn optioneel. Standaard termijn: **14 kalenderdagen**; een andere termijn vraagt een expliciete bevestiging van een afwijkende klantafspraak in de regeling. Beoordeel teksten, termijn, SEPA-verplichtingen en consumentenrechten vóór livegang. De voorbeeldtekst is geen verklaring van juridische geschiktheid.

De eerste vooraankondiging moet door `wp_mail` zijn geaccepteerd vóór een financiële opdracht wordt verzonden. Vervolgvooraankondigingen volgen Mollies `nextPaymentDate`, verminderd met de regelingstermijn. Te late cronruns en ongewone externe wijzigingen vragen operationele opvolging: de plugin kan reeds bij Mollie lopende termijnen niet door een vertraagde mail automatisch tegenhouden. Gebruik betrouwbare SMTP, monitoring en tijdige profielinstellingen.

Mails tonen datums leesbaar (bijvoorbeeld ‘28 oktober 2026’) en krijgen een Reply-To naar het e-mailadres in de contactgegevens. Bij een mislukte incasso of stornering ontvangt het beheer-e-mailadres van WordPress (filter `mollie_incasso_admin_alert_email`) één signaalmail per gebeurtenis, zonder persoonlijke klantlink.

De vooraankondigingstermijn is bij maandelijkse abonnementen maximaal 21 dagen, zodat de volgende vooraankondiging binnen één termijn past; bij kwartaal en jaar maximaal 60 dagen.

`wp_mail=true` betekent geaccepteerd door de mailserver, **geen gegarandeerde aflevering**. False wordt met herstelbare fouten vastgelegd. Bij een procescrash tijdens verzending wordt de uitkomst onbekend en niet blind opnieuw verstuurd. Diagnose toont dit bovenaan, met **Opnieuw versturen** of — na controle van het mailserverlog — **Markeer als verzonden**. Een wachtende incasso gaat pas door zodra de vooraankondiging als verzonden geldt. Persoonlijke linktokens worden uitsluitend in het geheugen voor de betreffende mail samengesteld; volledige mailteksten/tokens staan niet in de lokale queue of logs.

## Test/live, staging en kopieën

**Live is de standaard** voor nieuwe installaties. De testfase (testmodus, testkey en testgegevens wissen) is een keuze onder **Instellingen → Geavanceerd**, standaard uit; installaties die vóór 0.4 in testmodus stonden houden de testfase aan. Records, tokens, keys en jobs zijn per mode gescheiden.

In de testfase rondt Mollie een incasso nooit vanzelf af; die blijft in behandeling. Kies bij **Incasso's** met **Testuitkomst kiezen** zelf of de incasso betaald of mislukt wordt; bij een betaalde incasso kunt u zo ook een stornering simuleren. Datums volgen de taal van de plugin. Mollie stuurt daarna de webhook zoals bij een echte incasso.

**Talen:** de plugin is in het Nederlands geschreven en heeft een Engelse vertaling (`en_US`, `en_GB`) in `languages/`. Onder **Instellingen → Geavanceerd → Taal van de plugin** kiest u Automatisch (volgt WordPress; elke Engelse taal geeft Engels), Nederlands of English. Nieuwe vertalingen maakt u vanuit `languages/mollie-incasso.pot`.

**Live activeren** (Instellingen of installatiehulp, vanaf 0.5): vul de livekey in, vink aan dat er vanaf nu echt geld wordt geïncasseerd en klik op **Live activeren**. De plugin controleert met de livekey of Mollie iDEAL en SEPA-incasso live aanbiedt; lukt dat niet, dan verandert er niets. De activering wordt met gebruiker en tijdstip vastgelegd en geldt alleen voor deze website op dit adres en serverpad. **Live uitschakelen** zet de site terug in de testfase; lopende Mollie-abonnementen blijven bestaan. Er is geen serverwerk nodig. Live is nooit mogelijk op een site met `WP_ENVIRONMENT_TYPE` staging/development/local of op een gekopieerde site. Optioneel voor beheerders:

```php
define('MOLLIE_INCASSO_ALLOW_LIVE', false); // noodrem: live altijd geblokkeerd
define('MOLLIE_INCASSO_ALLOW_LIVE', true);  // live vrijgeven zonder activering in de plugin
define('MOLLIE_INCASSO_LIVE_SITE', 'https://uw-productiesite.nl'); // live alleen op dit adres
define('MOLLIE_INCASSO_LIVE_KEY', getenv('MOLLIE_INCASSO_LIVE_KEY')); // key buiten de database
```

HTTPS is vereist voor live POST’s en voor alle webhookadressen. Een sitebinding op home-URL én serverpad blokkeert taken op een kopie; bij (her)activatie of opnieuw binden gaat een kopie naar testmodus, met de testfase zichtbaar. De eerste activatie op een nieuwe site houdt de live-standaard. Staging moet expliciet `WP_ENVIRONMENT_TYPE=staging` gebruiken, zonder livekey of live-vlag. Zet bij migraties/back-ups waar nodig `MOLLIE_INCASSO_DISABLED=true`. Een byte-identieke database/config-kopie onder exact dezelfde URL en hetzelfde pad is technisch niet te onderscheiden: zet altijd een stagingomgeving en veilige secrets op de kopie. Een moduswissel of deactivering stopt nooit externe abonnementen.

## Privacy en opruimen

WordPress **Gereedschap → Persoonsgegevens exporteren/verwijderen** is ondersteund. Export bevat klant, toestemming en gerelateerde betaal-, abonnements- en gemaskeerde mandaatgegevens. Bewaartermijn is configureerbaar, standaard 2555 dagen. De termijn begint conservatief na de meest recente klantwijziging of betaling. Er is geen automatische bulkverwijdering: verwijderen volgt een expliciet WordPress-privacyverzoek. Beoordeel bewaarplicht, grondslag en beleid voor uw organisatie.

Een verwijderverzoek behoudt gegevens bij actieve/onzekere afspraken, onbekende betaaluitkomsten, lopende termijn of onbereikbare externe verificatie. Beëindig eerst extern; pas na de termijn en een actuele API-controle worden klant en bijbehorende gegevens daadwerkelijk verwijderd. Betalingen bij Mollie blijven onder hun eigen bewaarbeleid vallen. Een lege lokale status stopt geen extern abonnement.

**Testgegevens wissen** (Diagnose, alleen in testmodus en voor beheerders, na het intypen van `WISSEN`) verwijdert alle testklanten, -regelingen, -opdrachten, -betalingen, -abonnementen, -taken en -meldingen van deze website. Livegegevens worden nooit aangeraakt; instellingen en API-keys blijven staan; Mollie-testdata en WooCommerce-bestellingen/-producten veranderen niet.

Deactivatie bewaart gegevens **en externe abonnementen**. Uninstall ruimt standaard niets op. De Action Scheduler-waarschuwing van andere plugins wordt uitsluitend op eigen incassoschermen verborgen; de externe taken en meldingen op andere schermen blijven behouden.

Alleen met expliciet aangevinkte opruimkeuze, verstreken bewaartermijnen en actuele controle van alle klantafspraken kan de plugin tabellen/secrets verwijderen. Als een andere mode niet kan worden gecontroleerd, blijven gegevens behouden. Neem eerst een beveiligde back-up; verwijdering kan niet ongedaan worden gemaakt. Logbestanden bevatten alleen beperkte foutcodes, geen API-keys of volledige API-payloads. De CSV is begrensd op 500 klanten en neutraliseert spreadsheetformules.

## Broncode en reproduceerbare build

De bron staat in de root van de eigen GitHub-repository (in het themaproject onder `mollie-incasso/`); eigen records/statussen in twaalf `wp_mi_*`-tabellen. Schema versie 1 legt de tabellen idempotent aan met `dbDelta`; migratie 2 maakt Mollie-ID’s expliciet hoofdlettergevoelig. Migratie 3 voegt de duurzame WooCommerce-checkoutkoppeling toe. Migratie 4 voegt een prioriteit aan queuetaken toe; de collatiemigratie draait alleen nog op installaties met schema 1. Nieuwe schemawijzigingen krijgen een verhoogde migratieversie. De officiële SDK is vastgelegd in `composer.lock` (**v3.15.0**), PHP-Scoper in `tools/composer.lock` (**v0.18.20**). De ZIP bevat alle productieafhankelijkheden onder `Lukraak\MollieIncasso\Vendor`, met een eigen autoloader zonder globale aliases/Composer-registratie. Een bestaande Mollie/WooCommerce-SDK kan daardoor naast deze versie laden.

Ontwikkelmachine: PHP 8.2+, curl/openssl/zip, Composer. Hosting heeft **geen Composer** nodig.

```sh
cd mollie-incasso
php tools/build.php /absoluut/pad/composer.phar ../artifacts/mollie-incasso-1.0.2.zip
```

Met Composer op PATH: `php tools/build.php composer` (standaarduitvoer: `dist/mollie-incasso-1.0.2.zip`). De build gebruikt uitsluitend locks (`install`, geen `update`), scoped alle productiecode, controleert de SDK/CA-bundle en PHP-syntax en maakt een ZIP met gesorteerde bestanden en vaste tijdstempels/rechten. De ZIP bevat geen devtools, eigen testfixtures, secrets of ongescopete autoloaders. Zelfde bron, locks en toolomgeving produceren dezelfde ZIP. Dependencies en `.build/` zijn gegenereerd en staan niet in Git.

In dit repository is de build getest in de bestaande PHP 8.2.29 Docker-omgeving. Kopieer de pluginbron naar een tijdelijke map onder de lokaal gemounte `plugins/`, voer daar het bovenstaande PHP-buildcommando uit en kopieer de ZIP terug naar `artifacts/`. Composer 2.10.3 is daarvoor lokaal gebruikt. De bronmap buiten `plugins/` is leidend.

## Controles en externe acceptatie

Zie [TESTING.md](TESTING.md) voor het daadwerkelijke testrapport en reproduceerbare testcommando’s. Gebruik uitsluitend een **wegwerp/testdatabase**:

```sh
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/integration.php
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/sdk.php
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/secrets.php
MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/woocommerce.php
MI_WOO_HPOS=1 MI_WP_ROOT=/pad/naar/wordpress/wp-load.php php tests/woocommerce.php
```

GitHub Actions (`.github/workflows/tests.yml`) controleert bij elke push de PHP-syntax op PHP 8.2–8.4 en draait alle suites op een verse WordPress-installatie: WordPress 6.8.1 + WooCommerce 9.9.5 op MySQL 8.0, en de nieuwste WordPress/WooCommerce op MariaDB 10.6.

De WooCommerce-test vereist geïnstalleerde WooCommerce-bestanden en gebruikt de echte CRUD/cart/Store API-klassen met nagebootste Mollie-antwoorden. Hij maakt alleen eigen fixtureproducten/orders en verwijdert die weer; gebruik een wegwerpdatabase. De tests gebruiken geïsoleerde tabellen en proceslokale instellingen, maar een onderbreking kan testtabellen achterlaten. Een expliciet aangemaakte test-conceptpagina wordt na de controle verwijderd. Ze gebruiken een fake gateway/SDK-HTTP-adapter en onderscheppen `wp_mail`; geen echte incasso’s of mails. De SDK-test mag een reeds aanwezige ongescopete SDK laden voor conflictcontrole.

Online getest met een Mollie-testkey (WooCommerce-route): aanmelden, iDEAL-testcheckout betaald/mislukt/verlopen, mandaat, echte webhooks, mail, automatisch abonnement en intrekken. Nog uit te voeren: losse recurring testincasso en gegenereerde abonnementsbetaling op de aanbieddatum, kwartaal/jaar en maximaal aantal, eventuele mislukking/chargeback, alleen stoppen, geauthenticeerde SMTP en een cron-run zonder sitebezoek. Mollie-testabonnementen kunnen door Mollie zelf na tien betalingen worden geannuleerd. Controleer de actuele testbeperkingen. Pas na deze controles, toetsing van toestemmings-/SEPA-afspraken en operationele monitoring kunt u livegang beoordelen.

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
