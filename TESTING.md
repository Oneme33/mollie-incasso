# Testrapport — Mollie Incasso 0.3.0

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

De volledige gehoste checkout met echte Mollie-testkey/webhook, de exacte bronsitecombinatie met Mollie Payments for WooCommerce 8.1.11/Flexible Shipping en daadwerkelijke SMTP/cron moeten nog getest worden. Er is geen volledige WooCommerce Subscriptions-import, periodieke WooCommerce-ordergeneratie of algemene productiegeschiktheid aangetoond.

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

Niet getest in deze ronde: een echte Mollie-testkey met bereikbare webhook, en de schaalgedragingen onder echte cron/hosting.

## Nog nodig met credentials en bereikbare hosting

Een echte Mollie-testkey was niet beschikbaar. Daardoor zijn **niet** uitgevoerd: echte iDEAL-testcheckout, het werkelijke ontstaan/verloop van het accountmandaat, door Mollie bezorgde webhooks en retries, daadwerkelijk gegenereerde testtermijnen of externe bank-/storneringsverwerking. SMTP-aflevering en productiecron zijn evenmin bewezen.

Doorloop op HTTPS-testhosting:

1. Accountmethodes controleren; betaalde en mislukte € 0,01 first-checkout; mandaat pending/valid/invalid.
2. Vroege browserterugkeer, dubbele webhooks en onderbroken API-verkeer. Vergelijk lokale opdrachten met Mollie Dashboard.
3. Losse testincasso na vooraankondiging; maand/kwartaal/jaar en maximum; gegenereerde subscriptionbetaling.
4. Mislukking en chargeback indien dit door de actuele testomgeving kan worden gesimuleerd.
5. Stoppen, intrekken, gedeeld mandaat en herstel bij externe fouten.
6. Mail/SMTP, echte cron zonder sitebezoek, HTTPS/firewall/cache, privacy en veilige stagingkopie.
7. Tekst van toestemming, SEPA-voorwaarden, klantafspraken en vooraankondigingstermijn laten beoordelen vóór enig livegebruik.

Geen live geld gebruiken voor deze acceptatie. Schakel live pas afzonderlijk in na succesvolle externe tests en operationele afspraken.
