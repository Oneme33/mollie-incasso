# Testrapport — Mollie Incasso 1.0.1

Lokaal uitgevoerd op 13 en 14 oktober 2026: PHP 8.2.29, WordPress 6.8.1, bestaande Docker MySQL/InnoDB. Geen live geld, echte incasso of echte klantmail gebruikt. Dit rapport is geen productiecertificering.

## Wat daadwerkelijk is gecontroleerd

`tests/integration.php`: **88 assertions geslaagd** met geïsoleerde InnoDB-tabellen, fake Mollie gateway en onderschepte `wp_mail`.

- Exact EUR 0.01, iDEAL, first, customerId en eigen metadata; dubbele formulierinzending geeft één Customer en first Payment.
- Hetzelfde onbevestigde e-mailadres krijgt een afzonderlijke Customer. Terugkeer vóór webhook activeert niets.
- Mislukte first-betaling en pending/invalid mandaat blokkeren incasso. Valid mandaat na gecontroleerde betaling activeert toestemming.
- Dubbele betaal-/mandaatcontroles sturen dezelfde toestemmingsbevestiging maar één keer.
- Eén zakelijke referentie geeft één opdracht; afwijkende herhaling wordt geweigerd. Herhaalde bevestiging maakt geen extra opdracht.
- Een opdracht wacht tot de vooraankondiging is geaccepteerd. Voor handmatige uitvoering is de tijd in de database versneld om de toekomstige datum te simuleren; de test wacht geen dagen.
- Timeout ná mockverzending gevolgd door API-reconciliatie geeft één betaling. Buiten de idempotencyperiode wordt opnieuw aanmaken geblokkeerd.
- Een overlappende opdracht via **twee databaseverbindingen**, waarbij de tweede tijdens de create aanroept, wordt door de database-lock uitgesloten. Na voltooiing blijft één recurring Payment bestaan.
- Mislukte incasso/foutcode en latere chargeback blijven afzonderlijk zichtbaar, ook met paymentstatus paid.
- Mollie Subscription met toekomstige datum en maximum; onbekende nieuwe betaling correct gekoppeld op subscriptionId/customerId.
- Een abonnementsbetaling die vóór de lokale subscription-opslag binnenkomt, wordt niet weggegooid; na timeout-reconciliatie wordt hij gekoppeld zonder tweede abonnement.
- Mislukte externe cancel/revoke laat stop_pending/revoking staan en blokkeert nieuwe incasso’s. Daarna voltooien bij Mollie. Een stale stop kan ingetrokken toestemming niet terug activeren.
- Intrekken vóór first-betaling en later binnenkomende paid-webhook: geen heractivering; het later ontstane mandaat wordt ingetrokken.
- Ander extern actief abonnement op hetzelfde mandaat beschermt dat mandaat en houdt lokale incasso’s geblokkeerd.
- Webhooks krijgen pas 200 na duurzame opslag; dubbele events coalesceren. Een nieuwe eventgeneratie tijdens een werkende job blijft pending.
- Gehashte tokens, vervallen token, verkeerde omgeving, ontbrekende toegang, onjuiste klantreferentie, CSRF en test/live-isolatie.
- Beheer-POST zonder gebruiker of met een onjuiste nonce, aanmelding met CSRF-fout en intrekken via GET worden daadwerkelijk geweigerd vóór externe acties. Een andere sitebinding en gewijzigde key-fingerprint blokkeren uitvoering.
- Eigen hoofdoverzicht, optionele vierstapsinstallatiehulp, eenmalige ongepubliceerde conceptpagina en uitsluitend op eigen schermen verbergen van de Action Scheduler-melding gecontroleerd.
- Migratie naar schema 2 behoudt bestaande klantgegevens en onderscheidt hoofdlettergevoelige Mollie-ID’s.
- Spreadsheetformules in CSV geneutraliseerd; privacy-export bevat gerelateerde gegevens; actieve/te recente records blijven bij verwijderen behouden.
- Toestemming is expliciet en standaard uitgevinkt; labels horen bij invoervelden.

`tests/sdk.php`: **21 controles geslaagd** met de daadwerkelijke **Mollie PHP-SDK 3.15.0** en zijn eigen fake HTTP-adapter.

- Methods, Customer create/iterator, Payment create/get/customeriterator, Mandate get/iterator/revoke, Subscription create/get/iterator/cancel.
- Geldige jaarlijkse payload met `12 months`, toekomstige datum, mandateId, directdebit en maximum aantal.
- Hydration/metadata, checkout-extractie en weggooien van het volledige bankrekeningnummer.
- HTTP 404/422/429/503 worden veilig geclassificeerd; een tijdelijke create-fout houdt de financiële uitkomst onbekend.
- De drie create-verzoeken dragen hun juiste opgeslagen Idempotency-Key.
- Scoped en ongescopete Mollie SDK laden tegelijk in één PHP-proces zonder klasseconflict.

Daarnaast: PHP-syntax van eigen bron en alle gebundelde PHP-code; ZIP-structuur en installatie/activatie zonder WooCommerce; visuele controle in Chrome op desktop (1440px) en mobiel (390px), gekoppelde labels, stappen, knophoogte van minimaal 44px en afwezigheid van horizontale pagina-overflow. Ook Overzicht, de optionele wizard, Klanten en Instellingen zijn op beide breedtes gecontroleerd: groene zijbalk, actieve navigatie, geladen eigen logo, compacte incassokaart en natuurlijke knopbreedte, subtiele inputs en gelijke padding/line-height voor primaire en outlineknoppen. Een renderfixture gebruikt geen echte profielkey en is niet in de ZIP opgenomen. Servervalidatie blijft leidend; browsercontrols zijn geen incassobeveiliging.

## Sleutelopslag 0.1.2

`tests/secrets.php`: **23 controles geslaagd**: automatische WordPress-versleuteling en normaal uitlezen, willekeurige nonce, detectie van gewijzigde/beschadigde ciphertext, bestaande externe-key-opslag, later toevoegen/wijzigen van een externe key, blokkeren van een ongeldige expliciete encryptiesleutel, en automatische fallback bij ontbrekende/voorbeeld-WordPress-sleutels. Verder: duurzame willekeurige bestandssleutel, privébestandsrechten, rechtstreeks uitvoeren geeft geen sleutel terug, gelijktijdig initialiseren levert één sleutel op, corrupte bestanden worden niet overschreven, ontbrekende bestanden worden bij decryptie niet opnieuw gegenereerd, symlinks worden geweigerd en onbeschikbare opslag valt niet terug op plaintext. Geen API-calls of blijvende instellingen; ontbrekende/onveilige salts worden in aparte PHP-processen getest. Instellingen tonen eenvoudige plak-en-opslaan-uitleg met inklapbare stappen en optionele serverconfiguratie.

## WooCommerce en automatische abonnementstart (0.2.0)

`tests/woocommerce.php`: **60 controles geslaagd met klassieke orderopslag en dezelfde 60 met HPOS**, met echte WooCommerce **9.9.5** product-/order-CRUD, winkelwagen, gateway en de daadwerkelijke Store API Legacy-processor. De Mollie-gateway is nagebootst; alle mail is onderschept. Geen WooCommerce Subscriptions geïnstalleerd. Eigen fixtureproducten/orders worden exact op ID opgeruimd; alle pluginrecords staan in tijdelijke InnoDB-tabellen en instellingen blijven proceslokaal.

Getest: meerdere onafhankelijke plannen en producten; idempotente product-/wizardhulp; virtueel product en verificatieprijs; losse winkelwagen; uitsluiten van gewone gateways; dezelfde toestemmingstekst/hash in klassieke en Blocks-checkout; ontbrekende toestemming/stale hash/afwijkend bedrag/gateway/gewijzigde identiteit blokkeren; één Customer en first Payment bij herhalen; browserreturn is geen betaalbewijs; WooCommerce-order pas betaald na API-status; pending machtiging blokkeert abonnement; één automatische Subscription met juiste bedrag/interval/toekomstige datum na geaccepteerde vooraankondiging; Store API-retries; orderkey/eigenaarscontrole; timeout met reconciliatie; geen e-mailkoppeling; zelfstandige website-route en jaarplan; mislukte verificatie; test/live-isolatie; annulering vóór verificatie met late webhook; privacy-export/-verwijdering van nog ongekoppelde checkoutgegevens. Normale WordPress-bootstrap registreert zowel klassieke als Blocks-gateway en migreert schema naar 3.

Browsercontrole op 1440px en 390px: klassieke checkoutvelden en het echte React-component van de Blocks-integratie tonen bedrag/termijn/verificatie, onbevestigde toestemming en geen pagina-overflow. Het Blocks-component weigert zonder toestemming en levert na bevestiging de verwachte payment-data aan de server. De browserfixture gebruikt een nagebootste Blocks-eventregistry; dit is geen volledige browseraankoop via de echte winkel/Store API. Die serverroute is apart met de echte Legacy-processor getest. De wizard en beheerschermen zijn eveneens op desktop/mobiel gecontroleerd.

De gehoste checkout met echte Mollie-testkey, webhook, mail en cron is inmiddels online getest (zie ‘Online test en 0.3.1’). De exacte bronsitecombinatie met Mollie Payments for WooCommerce 8.1.11/Flexible Shipping is niet apart getest. Er is geen volledige WooCommerce Subscriptions-import, periodieke WooCommerce-ordergeneratie of algemene productiegeschiktheid aangetoond.

## Betrouwbaarheid en schaal (0.3.0)

Lokaal uitgevoerd op 14 oktober 2026 in dezelfde Docker-omgeving (PHP 8.2.29, WordPress 6.8.1, MySQL 8.0.32, WooCommerce 11.2.1 — eerdere rapporten noemden hier abusievelijk 9.9.5; die versie draait nu in CI). Resultaat: `integration.php` **121**, `sdk.php` **22**, `secrets.php` **23**, `woocommerce.php` **68 klassiek en 68 HPOS** — alle controles geslaagd. (De SDK-controle op een naast-geladen ongescopete SDK draait alleen als `mollie-incasso-build/vendor` aanwezig is.)

Nieuw getest:

- Urgente taken (mail, opdracht, webhook) lopen vóór oudere periodieke controles; één run werkt meerdere batches af binnen het tijdsbudget.
- Periodieke controles zetten een mislukte taak niet stilzwijgend terug; een nieuwe gebeurtenis wel.
- Onderhoud: een actieve klant krijgt één mandaatcontrole per dag in een eigen uur; afgehaakte of onbetaalde aanmeldingen worden nooit voor mandaten gepolld, hun open verificatie wel. Met 200 actieve en 40 mislukte klanten zet één onderhoudsrun hooguit één dagdeel aan mandaatcontroles klaar.
- Een routinematige mandaatcontrole zet geen overbodige checkout-/abonnementstaken klaar.
- Geslaagd stoppen laat de klant niet op `stop_pending` staan; een abonnementsbetaling zet direct een abonnementssynchronisatie klaar.
- Mail met onbekende uitkomst: opnieuw versturen of na controle als verzonden markeren; daarna niet nogmaals afhandelbaar.
- Mislukte incasso en stornering geven elk één beheerderssignaal zonder persoonlijke klantlink; datums in mails zijn leesbaar.
- Maandabonnement weigert een vooraankondiging langer dan 21 dagen; jaarabonnement accepteert een langere afgesproken termijn.
- Terugkeer van iDEAL: server-side controle bij Mollie activeert de klant direct bij betaalde verificatie en geldig mandaat.
- Rate-limit-IP: forwarded headers alleen achter vertrouwde proxies (IPv4/IPv6-CIDR), gespoofte linkerwaarden genegeerd.
- Shortcode zonder formuliercookie toont een startknop in plaats van een onbruikbaar formulier.
- Reconciliatie via de echte SDK stopt bij resources ouder dan de onzekere aanvraag en vindt de recente nog wel.
- WooCommerce: voorraadreservering annuleert alleen gewone onbetaalde orders, geen aanmeldorders; annuleren terwijl Mollie de verificatie al betaald meldt, laat de toestemming staan en voegt een ordernotitie toe.
- Na review van PR #1: is Mollie onbereikbaar tijdens het annuleren, dan is de uitkomst ‘onbekend’ en wordt de toestemming nooit ingetrokken; een queuetaak controleert opnieuw. Bij betaald blijft de toestemming staan, bij door Mollie bevestigd onbetaald volgt intrekken. Een gestarte first-betaling zonder opgeslagen resultaat telt ook als onbekend. Deze test faalt aantoonbaar op de eerdere PR-code.
- Na review: een door een vertrouwde proxy doorgegeven `CF-Connecting-IP` wordt genegeerd tenzij die header expliciet is ingesteld, en ook dan alleen vanaf vertrouwde proxyadressen.
- CI maakt de WooCommerce- en HPOS-ordertabellen expliciet aan; de WooCommerce-test stopt direct met een duidelijke melding als orders niet kunnen worden opgeslagen.

Deze ronde was lokaal; de online test met een echte Mollie-testkey volgt hieronder. Schaalgedrag onder echte cron/hosting is niet gemeten.

## Online test en 0.3.1 (14 oktober 2026)

Op de online WooCommerce-site (Mollie-testkey, echte webhooks en mail; drie testorders) werkten: betaalde verificatie met directe status ‘Actief’ op de bedankpagina, echte webhook, geldig mandaat, toestemmings- en vooraankondigingsmail (de toestemmingsmail kwam in spam), mislukte verificatie → order Failed, afgebroken betaling blijft open, winkelwagenregels en toestemmingsplicht. Het aanmaken van het abonnement werd door Mollie afgewezen; de reden werd niet bewaard. 0.3.1 lost de gevonden punten op: Mollies uitleg bij afwijzing bewaren en tonen, opnieuw proberen met nieuwe startdatum, geen zinloze mandaatcontrole na mislukte verificatie, status bijwerken vóór de bedankpagina, verificatieorders direct voltooid en zonder ‘opnieuw betalen’ na mislukking, zichtbare uitkomst van de methodecontrole, toestemmingszin één keer tonen, leesbare frequentie, Nederlandse taaknamen en maandnamen, organisatie als afzendernaam en geen meldingen van andere plugins op de eigen schermen.

Lokaal: `integration.php` **133**, `sdk.php` **24**, `secrets.php` **23**, `woocommerce.php` **71 klassiek en 71 HPOS** — alle controles geslaagd. Na installatie van 0.3.1 gaf een nieuwe poging Mollies reden: *“The 'method' and 'mandateId' parameters are mutually exclusive.”* 0.3.2 stuurt bij een mandaat geen `method` meer mee (abonnementen en losse incasso’s); de nagebootste Mollie in de tests hanteert dezelfde regel, waardoor de oude code nu aantoonbaar faalt. Lokaal: `integration.php` **134**, `sdk.php` **24**, `secrets.php` **23**, `woocommerce.php` **71 klassiek en 71 HPOS**.

## Testgegevens wissen (0.3.3)

Nieuwe knop in Diagnose. Getest: geweigerd buiten testmodus, verwijdert alle testrecords, laat liverecords ongemoeid. Daarnaast: de bedankpagina activeert een nog wachtende toestemming ook als de webhook de order al had afgerond (gereproduceerd uit de review van PR #2). Lokaal: `integration.php` **137**, `sdk.php` **24**, `secrets.php` **23**, `woocommerce.php` **73 klassiek en 73 HPOS**.

## Live als standaard (0.4.0)

Nieuwe installaties starten in live; de testfase staat onder Instellingen → Geavanceerd. Getest: verse installatie is live zonder testfase; een vóór 0.4 in test opgeslagen site houdt de testfase, een live-site niet; eerste activatie bindt de site en blijft live, een gekopieerde site valt terug naar test met zichtbare testfase; testfase aan maakt testmodus mogelijk; testfase uitzetten wordt in zijn geheel geweigerd zolang live niet is vrijgegeven; de live-instellingen tonen geen testkey of omgevingsschakelaar; de installatiehulp vraagt om de livekey en toont de ontbrekende `wp-config.php`-regels. Lokaal: `integration.php` **147**, `sdk.php` **24**, `secrets.php` **23**, `woocommerce.php` **73 klassiek en 73 HPOS**.

## Live activeren, mailadvies en cron (0.5.0)

Live wordt in de plugin geactiveerd in plaats van via `wp-config.php`. Getest: zonder activering geen live; een mislukte live-methodecontrole verandert niets; geslaagde activering schakelt naar live en staat live-API-gebruik toe; een activering van een andere site of ander pad geeft geen vrijgave; de serverrem `MOLLIE_INCASSO_ALLOW_LIVE=false` blokkeert altijd; uitschakelen zet de site terug in de testfase. De installatiehulp biedt Live activeren aan zonder wp-config-instructies. Testmail naar de beheerder werkt en weigert een ongeldig adres. Na review van PR #3: live wordt pas vrijgegeven ná een geslaagde alleen-lezen methodecontrole (tijdens de controle is live aantoonbaar niet vrijgegeven); een nieuwe live-installatie kan via het echte formulier de testfase aanzetten en haar instellingen opslaan vóór activering. Lokaal: `integration.php` **160**, `sdk.php` **26**, `secrets.php` **23**, `woocommerce.php` **73 klassiek en 73 HPOS**.

Beheerschermen (0.5.0): Instellingen, Diagnose en het blok ‘Nieuwe incasso of vast abonnement’ bij Klanten zijn ingedeeld in inklapbare thema’s met een status in de kop; een thema met een probleem staat open. Diagnose begint met een overzicht van achtergrondtaken, e-mail en Mollie. Alle beheerschermen renderen in de tests; visueel gecontroleerd op 1280px.

## Wat online is aangetoond en wat nog open staat

Online aangetoond (14–15 oktober 2026, WooCommerce-site, Mollie-testkey, echte webhooks, mail en cron): methodecontrole; betaalde, mislukte en verlopen € 0,01-verificatie; directe status op de bedankpagina; geldig mandaat; toestemmings-, vooraankondigings- en abonnementsmails; automatisch aangemaakt Mollie-abonnement (na de fix in 0.3.2); intrekken via de persoonlijke link met geannuleerd abonnement en ingetrokken mandaat; winkelwagenregels en toestemmingsplicht.

Nog niet aangetoond:

1. Een door Mollie daadwerkelijk gegenereerde abonnementstermijn en een losse testincasso op de aanbieddatum (vereist wachten tot de startdatum).
2. Mislukking en chargeback van een incasso, voor zover de testomgeving dat kan simuleren.
3. Stoppen van alleen een abonnement (zonder intrekken), gedeeld mandaat en herstel na externe fouten in de echte omgeving.
4. Mailaflevering is de verantwoordelijkheid van de website-eigenaar (SMTP met SPF/DKIM aanbevolen; zonder kwam een bevestiging in spam). Diagnose geeft advies en een testmail.
5. Gedrag op een zeer rustige site zonder server-cron (niet verplicht; Diagnose waarschuwt na drie uur), cache/firewall-instellingen en een veilige stagingkopie.
6. Juridische beoordeling van toestemmingstekst, SEPA-voorwaarden en vooraankondigingstermijn; ook de taal van de teksten op een Engelstalige winkel.

Geen live geld gebruiken voor deze acceptatie. Schakel live pas afzonderlijk in na succesvolle externe tests en operationele afspraken.

## Online test en 1.0.0

Online op geertruida.net (testkey, 15 tot 17 oktober 2026): aanmelding met verificatiebetaling van € 0,01 via iDEAL en echte webhook, mandaat, mails, automatisch Mollie-abonnement en intrekken via de statuslink zijn bewezen. De losse incasso van € 1 is op de geplande dag automatisch naar Mollie gestuurd en geaccepteerd; het abonnement staat actief bij Mollie. Mollie rondt een test-incasso nooit vanzelf af (status blijft in behandeling). Daarom biedt de testfase nu **Testuitkomst kiezen** bij een incasso in behandeling: dat opent de testpagina van Mollie (`changePaymentState`), waarna Mollie de webhook stuurt zoals bij een echte incasso. Getest: alleen in testmodus, alleen voor incasso's in behandeling of betaald (zodat ook een stornering te simuleren is), nooit voor de verificatiebetaling, alleen naar een mollie.com-adres. De echte afronding (betaald, mislukt, stornering) wordt bevestigd in de live pilot met een klein bedrag.

Engelse vertaling (`en_US` en `en_GB`) toegevoegd, met een taalkeuze onder Instellingen → Geavanceerd (automatisch, Nederlands of English); automatisch gebruikt Engels voor elke Engelse WordPress-taal, ook een kale `en`. Lokaal: `integration.php` **171**, `sdk.php` **26**, `secrets.php` **23**, `woocommerce.php` **73 klassiek en 73 HPOS**.

## 1.0.1: live-key en Live activeren

Gevonden op geertruida.net: wie in de testfase de live-key invulde en tegelijk live koos, kreeg een weigering waarbij de key niet werd bewaard; Live activeren vroeg daarna om een key die er niet was. Opgelost: Opslaan bewaart de key altijd en houdt de huidige modus tot live is geactiveerd (met een duidelijke melding en het live-blok open), en Live activeren vraagt zelf om de live-key als die nog ontbreekt, zodat invullen, bevestigen en activeren één stap is.

Installatiehulp: stap 3 biedt naast een vast abonnement nu ook een regeling voor **losse incasso's** (u start zelf elke incasso, tot een maximum per keer; Mollie incasseert via het mandaat). Een regeling voor losse incasso's heeft geen frequentie: die staat niet in de installatiehulp, niet in het regelingformulier (frequentie, aantal termijnen, automatisch starten en vaste periode verschijnen alleen bij een vast abonnement) en niet in de afspraaktekst voor de klant. Getest: de hulp maakt een losse regeling met maximumbedrag, zonder frequentie en zonder automatisch starten, en weigert er een zonder toestemmingstekst; een vast abonnement houdt zijn frequentie; in Chrome gecontroleerd dat de velden en voorbeeldteksten meewisselen met de gekozen soort. Lokaal: `integration.php` **175**, `sdk.php` **26**, `secrets.php` **23**, `woocommerce.php` **75 klassiek en 75 HPOS**.
