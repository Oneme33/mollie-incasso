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
        $binding = $plugin->config->binding();
        if (get_option('mi_site_binding') !== $binding) {
            $settings = $plugin->config->settings();
            $settings['mode'] = 'test';
            update_option('mi_settings', $settings, false);
            update_option('mi_site_binding', $binding, false);
        }
        get_role('administrator')?->add_cap('manage_mollie_incasso');
        self::schedule();
    }
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('mi_tick');
        wp_clear_scheduled_hook('mi_maintenance');
    }
    public static function schedule(): void
    {
        add_filter('cron_schedules', static function ($s) {
            $s['mi_five_minutes'] = ['interval' => 300, 'display' => __('Elke vijf minuten (Mollie Incasso)', 'mollie-incasso')];
            return $s;
        });
        if (!wp_next_scheduled('mi_tick')) {
            wp_schedule_event(time() + 60, 'mi_five_minutes', 'mi_tick');
        }
        if (!wp_next_scheduled('mi_maintenance')) {
            wp_schedule_event(time() + 300, 'hourly', 'mi_maintenance');
        }
    }
    public function boot(): void
    {
        load_plugin_textdomain('mollie-incasso', false, dirname(plugin_basename(MOLLIE_INCASSO_FILE)) . '/languages');
        if ((int) get_option('mi_schema') !== Store::SCHEMA_VERSION) {
            $this->store->install();
        }
        self::schedule();
        (new Frontend($this->store, $this->config, $this->schemes, $this->tokens, $this->queue, $this->service))->register();
        (new Admin($this))->register();
        (new Privacy($this))->register();
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
            $this->store->limit('webhook:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 1000, 60);
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
    public static function message(string $reason): string
    {
        $map = ['subscription_already_exists'=>__('Er is al een lopend of voorbereid abonnement voor deze klant. Rond dat eerst af.','mollie-incasso'),'invalid_api_key' => __('Gebruik een geldige API-key voor de gekozen test- of livemodus.', 'mollie-incasso'), 'page_creation_failed' => __('De conceptpagina kon niet worden aangemaakt. Er is niets gepubliceerd.', 'mollie-incasso'), 'api_key_missing' => __('Stel eerst de juiste Mollie API-key in.', 'mollie-incasso'), 'sdk_missing' => __('De gebundelde SDK ontbreekt. Installeer de gebouwde ZIP.', 'mollie-incasso'), 'organization_missing' => __('Vul organisatie en contactgegevens in.', 'mollie-incasso'), 'methods_not_enabled' => __('iDEAL voor de eerste betaling en SEPA Direct Debit moeten beschikbaar zijn in dit Mollie-profiel.', 'mollie-incasso'), 'environment_mismatch' => __('De omgeving klopt niet. Test- en livegegevens blijven strikt gescheiden.', 'mollie-incasso'), 'live_disabled' => __('Live is geblokkeerd. Controleer de expliciete live-constanten en productieomgeving.', 'mollie-incasso'), 'site_copy_blocked' => __('Deze installatie lijkt gekopieerd. Bind haar opnieuw in testmodus voordat u taken uitvoert.', 'mollie-incasso'), 'automation_disabled' => __('De automatisering is uitgeschakeld in de serverconfiguratie.', 'mollie-incasso'), 'webhook_https_required' => __('Stel een publiek bereikbaar HTTPS-webhookadres in.', 'mollie-incasso'), 'https_required' => __('Gebruik HTTPS.', 'mollie-incasso'), 'invalid_signup' => __('Vul naam, een geldig e-mailadres en expliciete toestemming in.', 'mollie-incasso'), 'scheme_unavailable' => __('Deze regeling is niet beschikbaar. Herlaad het formulier.', 'mollie-incasso'), 'scheme_changed' => __('De regeling of toestemmingsinformatie is veranderd. Herlaad het formulier.', 'mollie-incasso'), 'invalid_scheme' => __('Vul alle verplichte regelingsgegevens en een geldig bedrag in.', 'mollie-incasso'), 'notice_agreement_required' => __('Bevestig een expliciet afgesproken afwijkende vooraankondigingstermijn.', 'mollie-incasso'), 'invalid_amount' => __('Vul een positief EUR-bedrag met maximaal twee decimalen in.', 'mollie-incasso'), 'amount_outside_consent' => __('Het bedrag valt buiten de geaccepteerde regeling.', 'mollie-incasso'), 'scheme_type_mismatch' => __('Deze actie past niet bij de geaccepteerde regeling.', 'mollie-incasso'), 'customer_not_active' => __('Incasseren is nog niet toegestaan voor deze klant.', 'mollie-incasso'), 'first_payment_not_paid' => __('Een betaalde verificatiebetaling is nog niet aangetoond.', 'mollie-incasso'), 'mandate_not_valid' => __('De directdebit-machtiging is nog niet geldig. Er wordt niet geïncasseerd.', 'mollie-incasso'), 'consent_withdrawn' => __('De toestemming is ingetrokken of de beëindiging is nog in behandeling.', 'mollie-incasso'), 'start_date_too_early' => __('Kies een geldige toekomstige datum met voldoende tijd voor de afgesproken vooraankondiging.', 'mollie-incasso'), 'subscription_start_passed' => __('De abonnementsstartdatum is verstreken. Er is geen nieuw abonnement aangemaakt.', 'mollie-incasso'), 'prenotification_not_sent' => __('De vooraankondiging is nog niet door de mailserver geaccepteerd. De opdracht wacht.', 'mollie-incasso'), 'unknown_requires_review' => __('Uitkomst onbekend buiten de veilige retryperiode of na key-wijziging. Controleer Mollie; de plugin maakt geen tweede opdracht.', 'mollie-incasso'), 'uncertain_command_blocks_new' => __('Een eerdere opdracht is nog onzeker. Reconcileer die eerst.', 'mollie-incasso'), 'api_unavailable' => __('Mollie kon niet betrouwbaar worden bereikt. De actie is nog niet voltooid.', 'mollie-incasso'), 'api_rate_limited' => __('Mollie begrenst de verzoeken tijdelijk. De taak wordt later opnieuw gecontroleerd.', 'mollie-incasso'), 'mollie_rejected' => __('Mollie heeft het verzoek afgewezen. Controleer regeling, profiel en bedrag.', 'mollie-incasso'), 'api_access_denied' => __('Deze API-key heeft geen toegang tot de gevraagde resource.', 'mollie-incasso'), 'access_denied' => __('Geen toegang tot deze gegevens.', 'mollie-incasso'), 'csrf_failed' => __('De bevestiging is ongeldig of verlopen. Herlaad de pagina.', 'mollie-incasso'), 'rate_limited' => __('Te veel verzoeken. Probeer later opnieuw.', 'mollie-incasso'), 'busy' => __('Een andere verwerking is bezig. Probeer later opnieuw.', 'mollie-incasso'), 'wp_mail_failed' => __('De mailserver heeft de mail niet geaccepteerd. Controleer uw mailconfiguratie.', 'mollie-incasso'), 'mail_outcome_unknown' => __('De verzenduitkomst is onbekend. Automatisch opnieuw versturen is geblokkeerd om dubbele bevestigingen te vermijden.', 'mollie-incasso'), 'shared_mandate_in_use' => __('Een ander extern abonnement gebruikt dit mandaat nog. Nieuwe lokale incasso’s zijn geblokkeerd; controleer en beëindig die afspraak bij Mollie.', 'mollie-incasso'), 'cancellation_pending' => __('Mollie heeft de beëindiging nog niet bevestigd.', 'mollie-incasso'), 'encryption_key_missing' => __('De optionele encryptiesleutel op de server is ongeldig. Laat uw beheerder deze controleren.', 'mollie-incasso'), 'wordpress_keys_missing' => __('De WordPress-beveiligingssleutels in wp-config.php ontbreken of zijn onveilig. Laat uw beheerder deze instellen; daarna kunt u de API-key hier opslaan.', 'mollie-incasso'), 'decryption_failed' => __('De opgeslagen API-key kan niet worden gelezen. Voer de key opnieuw in of herstel met uw beheerder de oorspronkelijke beveiligingssleutels.', 'mollie-incasso'), 'cookies_unavailable' => __('Cookies kunnen niet veilig worden gezet. Sluit de aanmeldpagina uit van caching.', 'mollie-incasso')];
        return isset($map[$reason]) ? $map[$reason] : __('De actie kon niet worden voltooid. Controleer de diagnose; er wordt niet blind opnieuw geïncasseerd.', 'mollie-incasso');
    }
}
