# Testrapport — Mollie Incasso 0.1.0

Lokaal uitgevoerd op 13 oktober 2026: PHP 8.2.29, WordPress 6.8.1, bestaande Docker MySQL/InnoDB. Geen live geld, echte incasso of echte klantmail gebruikt. Dit rapport is geen productiecertificering.

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
