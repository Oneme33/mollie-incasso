<?php

namespace Lukraak\MollieIncasso;

final class Plugin
{
    public Store $store;
    public Config $config;
    public Schemes $schemes;
    public Tokens $tokens;
    public Queue $queue;
    public Notifications $mail;
    public Service $service;
    public function __construct(?\Closure $gateway = null)
    {
        global $wpdb;
        $this->store = new Store($wpdb);
        $this->config = new Config();
        $this->schemes = new Schemes($this->store, $this->config);
        $this->tokens = new Tokens($this->store);
        $this->queue = new Queue($this->store, $this->config);
        $this->mail = new Notifications($this->store, $this->config, $this->tokens, $this->queue);
        $factory = $gateway ?? fn(string $mode) => new MollieGateway($this->config, $mode);
        $this->service = new Service($this->store, $this->config, $this->schemes, $this->tokens, $this->queue, $this->mail, $factory);
    }
    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(esc_html__('Activeer Mollie Incasso per site, niet netwerkbreed.', 'mollie-incasso'));
        }
        if (PHP_VERSION_ID < 80200 || !extension_loaded('curl') || !extension_loaded('openssl')) {
            wp_die(esc_html__('PHP 8.2, curl en openssl zijn vereist.', 'mollie-incasso'));
        }
        $plugin = new self();
        $plugin->store->install();
        if (!get_option('mi_instance')) {
            add_option('mi_instance', wp_generate_uuid4(), '', 'no');
        }
        self::bindSite($plugin->config);
        get_role('administrator')?->add_cap('manage_mollie_incasso');
        self::schedule();
    }
    /**
     * First activation binds the site and keeps the live default. A different existing binding means
     * a copied site: it is forced into test mode (with the test phase visible) before anything runs.
     */
    public static function bindSite(Config $config): void
    {
        $binding = $config->binding();
        $current = (string) get_option('mi_site_binding', '');
        if ($current === $binding) {
            return;
        }
        if ($current !== '') {
            $settings = $config->settings();
            $settings['mode'] = 'test';
            $settings['test_tools'] = 1;
            update_option('mi_settings', $settings, false);
        }
        update_option('mi_site_binding', $binding, false);
    }
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('mi_tick');
        wp_clear_scheduled_hook('mi_maintenance');
    }
    public static function schedule(): void
    {
        add_filter('cron_schedules', static function ($s) {
            $s['mi_minute'] = ['interval' => 60, 'display' => __('Elke minuut (Mollie Incasso)', 'mollie-incasso')];
            return $s;
        });
        // 0.2 and older ran every five minutes; move existing installs to the one-minute worker.
        if (wp_next_scheduled('mi_tick') && wp_get_schedule('mi_tick') !== 'mi_minute') {
            wp_clear_scheduled_hook('mi_tick');
        }
        if (!wp_next_scheduled('mi_tick')) {
            wp_schedule_event(time() + 60, 'mi_minute', 'mi_tick');
        }
        if (!wp_next_scheduled('mi_maintenance')) {
            wp_schedule_event(time() + 300, 'hourly', 'mi_maintenance');
        }
    }
    // Plugin language: auto follows WordPress (any English variant uses en_US), or a fixed choice.
    // Loaded explicitly: the just-in-time loader only looks for the exact WordPress locale.
    private static ?string $textLocale = null;
    /** Locale of the plugin texts actually shown: the Dutch source unless a translation was loaded. */
    public static function textLocale(): string
    {
        return self::$textLocale ?? (is_textdomain_loaded('mollie-incasso') ? determine_locale() : 'nl_NL');
    }
    public static function loadLanguage(string $choice): void
    {
        self::$textLocale = 'nl_NL';
        unload_textdomain('mollie-incasso', $choice !== 'nl_NL');
        if ($choice === 'nl_NL') {
            // Dutch is the source language: also block just-in-time loading of a translation file.
            $GLOBALS['l10n_unloaded'] = (array) ($GLOBALS['l10n_unloaded'] ?? []);
            $GLOBALS['l10n_unloaded']['mollie-incasso'] = true;
            return;
        }
        $own = dirname(MOLLIE_INCASSO_FILE) . '/languages/';
        // WordPress keeps translations per active locale, so the file is stored under that locale even when it is another one.
        $active = determine_locale();
        $locale = $choice === 'en_US' ? 'en_US' : $active;
        if (str_starts_with($locale, 'en') && !is_file($own . 'mollie-incasso-' . $locale . '.mo')) {
            $locale = 'en_US';
        }
        foreach ([WP_LANG_DIR . '/plugins/', $own] as $dir) {
            if (load_textdomain('mollie-incasso', $dir . 'mollie-incasso-' . $locale . '.mo', $active)) {
                self::$textLocale = $locale;
                return;
            }
        }
        load_plugin_textdomain('mollie-incasso', false, dirname(plugin_basename(MOLLIE_INCASSO_FILE)) . '/languages');
        self::$textLocale = null;
    }
    public function boot(): void
    {
        self::loadLanguage((string) ($this->config->settings()['language'] ?? 'auto'));
        if ((int) get_option('mi_schema') !== Store::SCHEMA_VERSION) {
            $this->store->install();
        }
        self::schedule();
        (new Frontend($this->store, $this->config, $this->schemes, $this->tokens, $this->queue, $this->service))->register();
        (new Admin($this))->register();
        (new Privacy($this))->register();
        (new WooCommerceBridge($this))->register();
        add_action('mi_tick', fn() => $this->tick());
        add_action('mi_maintenance', function () {
            try {
                $this->service->maintenance();
            } catch (Failure $e) {
                update_option('mi_cron_error', ['reason' => $e->reason, 'at' => Store::now()], false);
            }
        });
        add_action('rest_api_init', function () {
            register_rest_route('mollie-incasso/v1', '/payment/(?P<mode>test|live)', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [$this, 'webhook']]);
        });
        add_filter('plugin_row_meta', static function ($links, $file) {
            if ($file === plugin_basename(MOLLIE_INCASSO_FILE)) {
                $links[] = esc_html__('Deactiveren stopt geen Mollie-abonnementen. Beëindig afspraken eerst in de plugin.', 'mollie-incasso');
            }
            return $links;
        }, 10, 2);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('mollie-incasso queue', function () {
                $this->tick();
                \WP_CLI::success(__('Queue verwerkt; controleer mislukte taken in het beheer.', 'mollie-incasso'));
            });
        }
    }
    public function tick(): void
    {
        try {
            $this->queue->work(fn($kind, $data, $mode) => $this->service->job($kind, $data, $mode));
            update_option('mi_last_tick', Store::now(), false);
        } catch (Failure $e) {
            update_option('mi_cron_error', ['reason' => $e->reason, 'at' => Store::now()], false);
        }
    }
    public function webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $mode = (string) $request->get_param('mode');
        $id = $request->get_param('id');
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{4,160}$/D', $id)) {
            return new \WP_REST_Response(['received' => true], 200);
        }
        try {
            $this->store->limit('webhook:' . $this->config->clientIp(), 1000, 60);
            $this->queue->put($mode, 'payment', 'payment:' . $id, ['id' => $id]);
            update_option('mi_last_webhook_' . $mode, Store::now(), false);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (Failure $e) {
            return new \WP_REST_Response(['received' => false], $e->reason === 'rate_limited' ? 429 : 503);
        }
    }
    public static function status(string $value): string
    {
        $map = ['working' => __('Wordt verwerkt', 'mollie-incasso'), 'suspended' => __('Opgeschort door Mollie', 'mollie-incasso'), 'pending' => __('In behandeling', 'mollie-incasso'), 'open' => __('Wacht op betaling', 'mollie-incasso'), 'paid' => __('Betaald', 'mollie-incasso'), 'failed' => __('Mislukt', 'mollie-incasso'), 'expired' => __('Verlopen', 'mollie-incasso'), 'canceled' => __('Geannuleerd', 'mollie-incasso'), 'active' => __('Actief', 'mollie-incasso'), 'valid' => __('Geldig', 'mollie-incasso'), 'invalid' => __('Ongeldig', 'mollie-incasso'), 'revoked' => __('Ingetrokken', 'mollie-incasso'), 'revoking' => __('Intrekken nog niet voltooid', 'mollie-incasso'), 'stop_pending' => __('Beëindigen nog niet voltooid', 'mollie-incasso'), 'mandate_pending' => __('Wacht op een geldige machtiging', 'mollie-incasso'), 'verification_failed' => __('Verificatie mislukt of teruggedraaid', 'mollie-incasso'), 'completed' => __('Voltooid', 'mollie-incasso'), 'canceling' => __('Beëindigen nog niet voltooid', 'mollie-incasso'), 'authorized' => __('Geautoriseerd, nog niet betaald', 'mollie-incasso'), 'draft' => __('Wacht op bevestiging', 'mollie-incasso'), 'scheduled' => __('Gepland', 'mollie-incasso'), 'processing' => __('Wordt verwerkt', 'mollie-incasso'), 'unknown' => __('Uitkomst onbekend; controle vereist', 'mollie-incasso'), 'blocked' => __('Geblokkeerd; controle vereist', 'mollie-incasso'), 'sent' => __('Door mailserver geaccepteerd', 'mollie-incasso'), 'sending' => __('Mail wordt aangeboden', 'mollie-incasso'), 'done' => __('Verwerkt', 'mollie-incasso')];
        return $map[$value] ?? sanitize_text_field($value);
    }
    public static function kind(string $value): string
    {
        return match ($value) {
            'customer' => __('Klantaanmaak', 'mollie-incasso'),
            'first' => __('Verificatiebetaling', 'mollie-incasso'),
            'manual' => __('Losse incasso', 'mollie-incasso'),
            'fixed', 'subscription' => __('Vast abonnement', 'mollie-incasso'),
            'payment' => __('Betaling controleren', 'mollie-incasso'),
            'mandate' => __('Mandaat controleren', 'mollie-incasso'),
            'mail' => __('Melding verzenden', 'mollie-incasso'),
            'withdraw' => __('Toestemming intrekken', 'mollie-incasso'),
            'stop' => __('Abonnement beëindigen', 'mollie-incasso'),
            'cancel_checkout' => __('Geannuleerde webshopaanmelding controleren', 'mollie-incasso'),
            'command' => __('Opdracht naar Mollie sturen', 'mollie-incasso'),
            'checkout' => __('Webshopbestelling bijwerken', 'mollie-incasso'),
            'auto_subscription' => __('Abonnement automatisch starten', 'mollie-incasso'),
            default => sanitize_text_field($value),
        };
    }
    public static function interval(string $value): string
    {
        return match ($value) {
            '1 month' => __('maand', 'mollie-incasso'),
            '3 months' => __('kwartaal', 'mollie-incasso'),
            '12 months' => __('jaar', 'mollie-incasso'),
            default => sanitize_text_field($value),
        };
    }
    public static function frequency(string $value): string
    {
        return match ($value) {
            '1 month' => __('Maandelijks', 'mollie-incasso'),
            '3 months' => __('Elk kwartaal', 'mollie-incasso'),
            '12 months' => __('Jaarlijks', 'mollie-incasso'),
            default => sanitize_text_field($value),
        };
    }
    public static function wipeWord(): string
    {
        /* translators: confirmation word the admin types to wipe test data, in capitals */
        return strtoupper(__('WISSEN', 'mollie-incasso'));
    }
    /** Plan name with kind and amount, so plans with the same name stay distinguishable in lists. */
    public static function planLabel(array $data): string
    {
        $amount = Money::eur((int) $data['amount']);
        return $data['name'] . ' — ' . ($data['type'] === 'manual' ? sprintf(__('losse incasso, max %s', 'mollie-incasso'), $amount) : sprintf(__('vast abonnement, %1$s per %2$s', 'mollie-incasso'), $amount, self::interval($data['interval'])));
    }
    public static function message(string $reason): string
    {
        $map = ['subscription_already_exists'=>__('Er is al een lopend of voorbereid abonnement voor deze klant. Rond dat eerst af.','mollie-incasso'),'invalid_api_key' => __('Gebruik een geldige API-key voor de gekozen test- of livemodus.', 'mollie-incasso'), 'page_creation_failed' => __('De conceptpagina kon niet worden aangemaakt. Er is niets gepubliceerd.', 'mollie-incasso'), 'api_key_missing' => __('Stel eerst de juiste Mollie API-key in.', 'mollie-incasso'), 'sdk_missing' => __('De gebundelde SDK ontbreekt. Installeer de gebouwde ZIP.', 'mollie-incasso'), 'organization_missing' => __('Vul organisatie en contactgegevens in.', 'mollie-incasso'), 'methods_not_enabled' => __('iDEAL voor de eerste betaling en SEPA Direct Debit moeten beschikbaar zijn in dit Mollie-profiel.', 'mollie-incasso'), 'environment_mismatch' => __('De omgeving klopt niet. Test- en livegegevens blijven strikt gescheiden.', 'mollie-incasso'), 'live_disabled' => __('Live is nog niet geactiveerd voor deze website. Gebruik Instellingen → Live activeren.', 'mollie-incasso'), 'live_environment_not_production' => __('Deze website staat ingesteld als test- of stagingomgeving (WP_ENVIRONMENT_TYPE). Live is daar nooit mogelijk.', 'mollie-incasso'), 'live_confirmation_required' => __('Bevestig dat er vanaf nu echt geld wordt geïncasseerd.', 'mollie-incasso'), 'site_copy_blocked' => __('Deze installatie lijkt gekopieerd. Bind haar opnieuw in testmodus voordat u taken uitvoert.', 'mollie-incasso'), 'automation_disabled' => __('De automatisering is uitgeschakeld in de serverconfiguratie.', 'mollie-incasso'), 'webhook_https_required' => __('Stel een publiek bereikbaar HTTPS-webhookadres in.', 'mollie-incasso'), 'https_required' => __('Gebruik HTTPS.', 'mollie-incasso'), 'invalid_signup' => __('Vul naam, een geldig e-mailadres en expliciete toestemming in.', 'mollie-incasso'), 'scheme_unavailable' => __('Deze regeling is niet beschikbaar. Herlaad het formulier.', 'mollie-incasso'), 'scheme_changed' => __('De regeling of toestemmingsinformatie is veranderd. Herlaad het formulier.', 'mollie-incasso'), 'invalid_scheme' => __('Vul alle verplichte regelingsgegevens en een geldig bedrag in.', 'mollie-incasso'), 'notice_agreement_required' => __('Bevestig een expliciet afgesproken afwijkende vooraankondigingstermijn.', 'mollie-incasso'), 'invalid_amount' => __('Vul een positief EUR-bedrag met maximaal twee decimalen in.', 'mollie-incasso'), 'amount_outside_consent' => __('Het bedrag valt buiten de geaccepteerde regeling.', 'mollie-incasso'), 'scheme_type_mismatch' => __('Deze actie past niet bij de geaccepteerde regeling.', 'mollie-incasso'), 'customer_not_active' => __('Incasseren is nog niet toegestaan voor deze klant.', 'mollie-incasso'), 'first_payment_not_paid' => __('Een betaalde verificatiebetaling is nog niet aangetoond.', 'mollie-incasso'), 'mandate_not_valid' => __('De directdebit-machtiging is nog niet geldig. Er wordt niet geïncasseerd.', 'mollie-incasso'), 'consent_withdrawn' => __('De toestemming is ingetrokken of de beëindiging is nog in behandeling.', 'mollie-incasso'), 'start_date_too_early' => __('Kies een geldige toekomstige datum met voldoende tijd voor de afgesproken vooraankondiging.', 'mollie-incasso'), 'subscription_start_passed' => __('De abonnementsstartdatum is verstreken. Er is geen nieuw abonnement aangemaakt.', 'mollie-incasso'), 'prenotification_not_sent' => __('De vooraankondiging is nog niet door de mailserver geaccepteerd. De opdracht wacht.', 'mollie-incasso'), 'unknown_requires_review' => __('Uitkomst onbekend buiten de veilige retryperiode of na key-wijziging. Controleer Mollie; de plugin maakt geen tweede opdracht.', 'mollie-incasso'), 'uncertain_command_blocks_new' => __('Een eerdere opdracht is nog onzeker. Reconcileer die eerst.', 'mollie-incasso'), 'api_unavailable' => __('Mollie kon niet betrouwbaar worden bereikt. De actie is nog niet voltooid.', 'mollie-incasso'), 'api_rate_limited' => __('Mollie begrenst de verzoeken tijdelijk. De taak wordt later opnieuw gecontroleerd.', 'mollie-incasso'), 'mollie_rejected' => __('Mollie heeft het verzoek afgewezen. Controleer regeling, profiel en bedrag.', 'mollie-incasso'), 'api_access_denied' => __('Deze API-key heeft geen toegang tot de gevraagde resource.', 'mollie-incasso'), 'access_denied' => __('Geen toegang tot deze gegevens.', 'mollie-incasso'), 'csrf_failed' => __('De bevestiging is ongeldig of verlopen. Herlaad de pagina.', 'mollie-incasso'), 'rate_limited' => __('Te veel verzoeken. Probeer later opnieuw.', 'mollie-incasso'), 'busy' => __('Een andere verwerking is bezig. Probeer later opnieuw.', 'mollie-incasso'), 'wp_mail_failed' => __('De mailserver heeft de mail niet geaccepteerd. Controleer uw mailconfiguratie.', 'mollie-incasso'), 'mail_outcome_unknown' => __('De verzenduitkomst is onbekend. Automatisch opnieuw versturen is geblokkeerd om dubbele bevestigingen te vermijden.', 'mollie-incasso'), 'shared_mandate_in_use' => __('Een ander extern abonnement gebruikt dit mandaat nog. Nieuwe lokale incasso’s zijn geblokkeerd; controleer en beëindig die afspraak bij Mollie.', 'mollie-incasso'), 'cancellation_pending' => __('Mollie heeft de beëindiging nog niet bevestigd.', 'mollie-incasso'), 'encryption_key_missing' => __('De optionele encryptiesleutel op de server is ongeldig. Laat uw beheerder deze controleren.', 'mollie-incasso'), 'key_file_unavailable' => __('De automatische sleutelopslag is niet beschikbaar. Laat uw beheerder de schrijfrechten op wp-content controleren of de API-key rechtstreeks op de server instellen. Bestaande sleutelbestanden niet verwijderen.', 'mollie-incasso'), 'woocommerce_unavailable' => __('WooCommerce is niet actief. Gebruik de website-aanmelding of activeer WooCommerce voor een webshopproduct.', 'mollie-incasso'), 'checkout_not_found' => __('De webshopaanmelding kon niet worden gevonden. Neem contact op met de organisatie.', 'mollie-incasso'), 'woo_product_invalid' => __('Gebruik een eenvoudig virtueel product voor de incassoaanmelding.', 'mollie-incasso'), 'woo_product_mode' => __('Dit product is niet gekoppeld aan een regeling voor de huidige test- of livemodus.', 'mollie-incasso'), 'woo_scheme_automatic' => __('Kies een vast abonnement met automatisch starten, of een regeling voor losse incasso’s.', 'mollie-incasso'), 'woo_cart_invalid' => __('Reken één abonnementaanmelding apart af, zonder andere producten of extra aantallen.', 'mollie-incasso'), 'woo_order_invalid' => __('De aanmelding moet een afzonderlijke bestelling van € 0,01 zijn, zonder btw, verzending, kortingen of toeslagen.', 'mollie-incasso'), 'woo_order_changed' => __('De bestelling is gewijzigd na de incassoaanmelding. Neem contact op; er wordt geen tweede betaling gemaakt.', 'mollie-incasso'), 'woo_consent_required' => __('Geef toestemming voor de beschreven incassoafspraak voordat u betaalt.', 'mollie-incasso'), 'woo_verification_failed' => __('De verificatiebetaling is niet geslaagd. Er is geen nieuw betaalverzoek gemaakt. Neem contact op voor een nieuwe aanmelding.', 'mollie-incasso'), 'woo_order_missing' => __('De gekoppelde WooCommerce-bestelling ontbreekt. Controleer de aanmelding.', 'mollie-incasso'), 'woo_product_failed' => __('Het virtuele product kon niet worden opgeslagen.', 'mollie-incasso'), 'wordpress_keys_missing' => __('De eerder gebruikte WordPress-sleutels zijn gewijzigd of niet beschikbaar. Voer de API-key opnieuw in of herstel de oorspronkelijke configuratie.', 'mollie-incasso'), 'decryption_failed' => __('De opgeslagen API-key kan niet worden gelezen. Voer de key opnieuw in of herstel met uw beheerder de oorspronkelijke beveiligingssleutels.', 'mollie-incasso'), 'cancel_outcome_unknown' => __('Mollie kon niet worden bereikt om de verificatiebetaling te controleren. De toestemming blijft voorlopig staan; de annulering wordt opnieuw gecontroleerd.', 'mollie-incasso'), 'wipe_confirmation_required' => sprintf(__('Typ %s om het wissen van de testgegevens te bevestigen.', 'mollie-incasso'), self::wipeWord()), 'mail_not_resolvable' => __('Deze melding kan niet (meer) handmatig worden afgehandeld.', 'mollie-incasso'), 'marked_sent_by_admin' => __('Door een beheerder als verzonden gemarkeerd na controle.', 'mollie-incasso'), 'notice_too_long' => __('De vooraankondigingstermijn is te lang voor dit interval: maximaal 21 dagen bij maandelijks en 60 dagen bij kwartaal of jaar.', 'mollie-incasso'), 'live_needs_activation' => __('De instellingen en een eventuele live-key zijn opgeslagen. Live staat nog niet aan: rond dat af bij Live activeren hieronder.', 'mollie-incasso'), 'run_empty' => __('Vink minstens één klant aan.', 'mollie-incasso'), 'description_required' => __('Vul een omschrijving in (maximaal 255 tekens).', 'mollie-incasso'), 'default_above_maximum' => __('Het standaardbedrag mag niet hoger zijn dan het maximum per incasso.', 'mollie-incasso'), 'import_confirmation_required' => __('Bevestig dat deze klanten een geldige machtiging hebben gegeven en dat u die via Mollie mag gebruiken.', 'mollie-incasso'), 'import_file_invalid' => __('Kies een CSV-bestand van maximaal 500 kB.', 'mollie-incasso'), 'import_columns_missing' => __('De CSV mist een kopregel met de kolommen naam, e-mail, IBAN en datum.', 'mollie-incasso'), 'import_invalid_customer' => __('Naam of e-mailadres ontbreekt of is ongeldig.', 'mollie-incasso'), 'import_invalid_iban' => __('Het IBAN is ongeldig.', 'mollie-incasso'), 'import_invalid_date' => __('De ondertekeningsdatum is ongeldig of ligt in de toekomst.', 'mollie-incasso'), 'import_invalid_reference' => __('Het machtigingskenmerk is te lang (maximaal 35 tekens) of bevat ongeldige tekens.', 'mollie-incasso'), 'import_signup_in_progress' => __('Voor dit e-mailadres loopt al een gewone aanmelding op deze regeling; niet overgezet.', 'mollie-incasso'), 'customer_creation_pending' => __('De klant kon nog niet bij Mollie worden aangemaakt. Probeer het later opnieuw.', 'mollie-incasso'), 'payment_mismatch' => __('Mollie gaf een betaling terug die niet bij deze klant of modus past. Zie de foutregel bij Incasso’s.', 'mollie-incasso'), 'payment_amount_mismatch' => __('Mollie gaf een ander bedrag terug dan gevraagd. Zie de foutregel bij Incasso’s.', 'mollie-incasso'), 'payment_method_mismatch' => __('Mollie gaf een andere betaalmethode of soort betaling terug dan gevraagd. Zie de foutregel bij Incasso’s.', 'mollie-incasso'), 'payment_reference_mismatch' => __('De betaling bij Mollie hoort niet bij deze website. Zie de foutregel bij Incasso’s.', 'mollie-incasso'), 'test_outcome_unavailable' => __('Mollie biedt voor deze betaling geen testuitkomst aan. Dat kan alleen in de testfase bij een incasso die in behandeling of betaald is.', 'mollie-incasso'), 'cookies_unavailable' => __('Cookies kunnen niet veilig worden gezet. Sluit de aanmeldpagina uit van caching.', 'mollie-incasso')];
        return isset($map[$reason]) ? $map[$reason] : __('De actie kon niet worden voltooid. Controleer de diagnose; er wordt niet blind opnieuw geïncasseerd.', 'mollie-incasso') . ' (' . sprintf(__('code: %s', 'mollie-incasso'), sanitize_key($reason)) . ')';
    }
}
