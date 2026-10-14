<?php

namespace Lukraak\MollieIncasso;

final class Admin
{
    public function __construct(private readonly Plugin $p)
    {
    }
    public function register(): void
    {
        add_action('admin_menu', function () {
            add_menu_page(__('Mollie Incasso', 'mollie-incasso'), __('Mollie Incasso', 'mollie-incasso'), 'manage_mollie_incasso', 'mollie-incasso', [$this, 'render'], 'dashicons-money-alt', 57);
            foreach (['overview' => 'Overzicht', 'customers' => 'Klanten', 'commands' => 'Incasso’s / opdrachten', 'subscriptions' => 'Abonnementen', 'settings' => 'Instellingen en regelingen', 'setup' => 'Installatiehulp', 'diagnostics' => 'Diagnose en berichten'] as $view => $label) {
                add_submenu_page('mollie-incasso', __($label, 'mollie-incasso'), __($label, 'mollie-incasso'), 'manage_mollie_incasso', $view === 'overview' ? 'mollie-incasso' : 'mollie-incasso-' . $view, [$this, 'render']);
            }
        });
        add_filter('action_scheduler_check_pastdue_actions', static function ($check) {
            $page = sanitize_key($_GET['page'] ?? '');
            return $page === 'mollie-incasso' || str_starts_with($page, 'mollie-incasso-') ? false : $check;
        });
        add_action('admin_post_mi_admin', [$this, 'action']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if (str_contains($hook, 'mollie-incasso')) {
                wp_enqueue_style('mi-admin', plugins_url('assets/admin.css', MOLLIE_INCASSO_FILE), [], MOLLIE_INCASSO_VERSION . '.' . filemtime(dirname(MOLLIE_INCASSO_FILE) . '/assets/admin.css'));
            }
        });
        // Admin notices from other plugins are hidden on our own screens only; they remain everywhere else.
        add_action('in_admin_header', function () {
            if (!str_starts_with((string) ($_GET['page'] ?? ''), 'mollie-incasso')) {
                return;
            }
            global $wp_filter;
            foreach (['admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices'] as $hook) {
                if (!isset($wp_filter[$hook])) {
                    continue;
                }
                foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $key => $callback) {
                        if (empty($callback['function']) || !($callback['function'] instanceof \Closure) || !self::own($callback['function'])) {
                            remove_action($hook, $callback['function'], $priority);
                        }
                    }
                }
            }
        }, PHP_INT_MAX);
        add_action('admin_notices', function () {
            if (!current_user_can('manage_mollie_incasso') || !str_contains((string) ($_GET['page'] ?? ''), 'mollie-incasso')) {
                return;
            }
            if (isset($_GET['mi_error'])) {
                echo '<div class="notice notice-error"><p>' . esc_html(Plugin::message(sanitize_key($_GET['mi_error']))) . '</p></div>';
            } elseif (isset($_GET['mi_saved']) && ($_GET['page'] ?? '') === 'mollie-incasso-diagnostics' && ($m = get_option('mi_methods_' . $this->p->config->mode(), [])) && !empty($m['checked_at']) && strtotime($m['checked_at'] . ' UTC') > time() - 120) {
                echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Betaalmethodes gecontroleerd: iDEAL voor de eerste betaling en SEPA-incasso voor vervolgbetalingen zijn beschikbaar (%1$s / %2$s).', 'mollie-incasso'), implode(', ', $m['first']), implode(', ', $m['recurring']))) . '</p></div>';
            } elseif (isset($_GET['mi_saved'])) {
                echo '<div class="notice notice-success"><p>' . esc_html__('Actie opgeslagen. Controleer de actuele status hieronder.', 'mollie-incasso') . '</p></div>';
            }
        });
    }
    private static function own(\Closure $callback): bool
    {
        $file = (new \ReflectionFunction($callback))->getFileName();
        return is_string($file) && str_starts_with(wp_normalize_path($file), trailingslashit(wp_normalize_path(__DIR__)));
    }
    private function url(string $view = 'overview', array $args = []): string
    {
        return add_query_arg(['page' => $view === 'overview' ? 'mollie-incasso' : 'mollie-incasso-' . $view] + $args, admin_url('admin.php'));
    }
    private function field(string $key, int $length = 2000): string
    {
        $v = $_POST[$key] ?? '';
        return is_scalar($v) ? substr(wp_unslash((string) $v), 0, $length) : '';
    }
    private function start(string $operation, int $id = 0): void
    {
        ?>
        <form method="post" action="<?php
        echo esc_url(admin_url('admin-post.php'));
        ?>" class="mi-admin-form<?php
        echo $operation === 'prepare' ? ' mi-command-form' : '';
        ?>"><input type="hidden" name="action" value="mi_admin"><input type="hidden" name="operation" value="<?php
        echo esc_attr($operation);
        ?>"><input type="hidden" name="id" value="<?php
        echo $id;
        ?>"><input type="hidden" name="mode" value="<?php
        echo esc_attr($this->p->config->mode());
        ?>"><?php
        wp_nonce_field('mi_admin_' . $operation . '_' . $id . '_' . $this->p->config->mode(), 'mi_nonce');
    }
    private function end(string $label, bool $primary = false): void
    {
        submit_button(__($label, 'mollie-incasso'), $primary ? 'primary' : 'secondary', 'submit', false);
        echo '</form>';
    }
    private function quick(string $operation, int $id, string $label): void
    {
        $this->start($operation, $id);
        $this->end($label);
    }
    public function action(): void
    {
        if (!current_user_can('manage_mollie_incasso') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Geen toegang.', 'mollie-incasso'), '', 403);
        }
        $operation = sanitize_key($this->field('operation', 32));
        $id = (int) $this->field('id', 20);
        $mode = $this->field('mode', 4);
        if ($mode !== $this->p->config->mode() || !wp_verify_nonce($this->field('mi_nonce', 128), 'mi_admin_' . $operation . '_' . $id . '_' . $mode)) {
            wp_die(esc_html(Plugin::message('csrf_failed')), '', 403);
        }
        $view = str_starts_with($operation, 'setup_') ? 'setup' : (in_array($operation, ['settings', 'scheme', 'bind'], true) ? 'settings' : 'customers');
        $args = ['mi_saved' => 1];
        try {
            switch ($operation) {
                case 'settings':
                    $this->saveSettings();
                    break;
                case 'scheme':
                    if (!current_user_can('manage_options')) {
                        throw new Failure('access_denied');
                    }
                    $this->p->schemes->save($_POST, $mode);
                    break;
                case 'bind':
                    if (!current_user_can('manage_options')) {
                        throw new Failure('access_denied');
                    }
                    $s = $this->p->config->settings();
                    $s['mode'] = 'test';
                    update_option('mi_settings', $s, false);
                    update_option('mi_site_binding', $this->p->config->binding(), false);
                    break;
                case 'diagnose':
                    $this->checkMethods($mode);
                    $view = 'diagnostics';
                    break;
                case 'setup_profile':
                    if (!current_user_can('manage_options')) {
                        throw new Failure('access_denied');
                    }
                    $settings = $this->p->config->settings();
                    $name = sanitize_text_field($this->field('organization', 190));
                    $contact = sanitize_textarea_field($this->field('contact'));
                    if (!$name || !$contact) {
                        throw new Failure('organization_missing');
                    }
                    $settings['organization'] = $name;
                    $settings['address'] = sanitize_textarea_field($this->field('address'));
                    $settings['contact'] = $contact;
                    $settings['privacy_url'] = esc_url_raw($this->field('privacy_url'));
                    update_option('mi_settings', $settings, false);
                    $view = 'setup';
                    $args = ['mi_saved' => 1, 'step' => 2];
                    break;
                case 'setup_key':
                    if (!current_user_can('manage_options')) {
                        throw new Failure('access_denied');
                    }
                    $this->p->config->assertRun($mode);
                    if ($mode !== 'test') {
                        throw new Failure('environment_mismatch');
                    }
                    $key = trim($this->field('key_test', 120));
                    if (!preg_match('/^test_[A-Za-z0-9]{30,100}$/D', $key)) {
                        throw new Failure('invalid_api_key');
                    }
                    update_option('mi_secret_test', $this->p->config->encrypt($key), false);
                    $view = 'setup';
                    $args = ['mi_saved' => 1, 'step' => 2];
                    break;
                case 'setup_plan':
                    $this->wizardPlan($_POST, $mode);
                    $view='setup';$args=['mi_saved'=>1,'step'=>4];
                    break;
                case 'setup_check':
                    $this->checkMethods($mode);
                    $view = 'setup';
                    $args = ['mi_saved' => 1, 'step' => 2];
                    break;
                case 'setup_product':
                    if (!class_exists('WC_Product_Simple')) throw new Failure('woocommerce_unavailable');
                    $product=(new WooCommerceBridge($this->p))->createProduct((int)$this->field('scheme',20));
                    wp_safe_redirect(get_edit_post_link($product,'raw'),303);
                    exit;
                case 'setup_page':
                    if (!current_user_can('manage_options') || !current_user_can('edit_pages')) {
                        throw new Failure('access_denied');
                    }
                    $scheme = $this->p->schemes->get((int) $this->field('scheme', 20), $mode);
                    $post = $this->draftPage($scheme, $mode);
                    wp_safe_redirect(get_edit_post_link($post, 'raw'), 303);
                    exit;
                case 'prepare':
                    $command = $this->p->service->prepare((int) $this->field('customer'), $mode, $this->field('kind', 24), Money::cents($this->field('amount', 24)), $this->field('description', 255), $this->field('date', 10), $this->field('reference', 36));
                    $view = 'commands';
                    $args = ['preview' => $command['id']];
                    break;
                case 'confirm':
                    $this->p->service->confirm($id, $mode);
                    $view = 'commands';
                    break;
                case 'retry_subscription':
                    $this->p->service->retrySubscription($id, $mode);
                    $view = 'commands';
                    break;
                case 'reconcile':
                    $this->p->service->execute($id, $mode);
                    $view = 'commands';
                    break;
                case 'refresh':
                    $c = $this->p->service->customer($id, $mode);
                    foreach ($this->p->store->select('SELECT remote_id FROM ' . $this->p->store->table('payments') . ' WHERE mode=%s AND customer_id=%d ORDER BY id DESC LIMIT 20', [$mode, $id]) as $p) {
                        $this->p->service->syncPayment($p['remote_id'], $mode);
                    }
                    $this->p->service->refreshMandate($id, $mode);
                    break;
                case 'withdraw':
                    $this->p->service->withdraw($id, $mode);
                    break;
                case 'stop':
                    $this->p->service->stop($id, $mode);
                    $view = 'subscriptions';
                    break;
                case 'sync_sub':
                    $this->p->service->syncSubscription($id, $mode);
                    $view = 'subscriptions';
                    break;
                case 'mail_link':
                    $this->p->service->customer($id, $mode);
                    $this->p->mail->schedule($mode, $id, 'access', 'access:' . $id . ':' . wp_generate_uuid4());
                    break;
                case 'queue':
                    $this->p->tick();
                    $view = 'diagnostics';
                    break;
                case 'recover':
                    $this->p->queue->recover($id, $mode);
                    $view = 'diagnostics';
                    break;
                case 'mail_retry':
                case 'mail_sent':
                    $this->p->mail->resolve($id, $mode, $operation === 'mail_sent');
                    $view = 'diagnostics';
                    break;
                case 'export':
                    $this->csv($mode);
                    exit;
                default:
                    throw new Failure('invalid_command');
            }
        } catch (Failure $e) {
            $args = ['mi_error' => $e->reason];
            if ($view === 'setup') {
                $args['step'] = $operation === 'setup_profile' ? 1 : ($operation === 'setup_plan' ? 3 : (in_array($operation,['setup_page','setup_product'],true) ? 4 : 2));
            }
        }
        wp_safe_redirect($this->url($view, $args), 303);
        exit;
    }
    private function saveSettings(): void
    {
        if (!current_user_can('manage_options')) {
            throw new Failure('access_denied');
        }
        $old = $this->p->config->settings();
        $new = ['mode' => $this->field('target_mode') === 'live' ? 'live' : 'test', 'organization' => sanitize_text_field($this->field('organization', 190)), 'address' => sanitize_textarea_field($this->field('address')), 'contact' => sanitize_textarea_field($this->field('contact')), 'privacy_url' => esc_url_raw($this->field('privacy_url')), 'notifications' => $this->field('notifications') === '1' ? 1 : 0, 'cleanup' => $this->field('cleanup') === '1' ? 1 : 0, 'retention_days' => max(1, min(10950, (int) $this->field('retention_days', 8))), 'webhook_url' => esc_url_raw($this->field('webhook_url'))];
        foreach (['test', 'live'] as $mode) {
            $key = trim($this->field('key_' . $mode, 120));
            if ($key && !preg_match('/^' . $mode . '_[A-Za-z0-9]{30,100}$/D', $key)) {
                throw new Failure('invalid_api_key');
            }
            if ($key) {
                update_option('mi_secret_' . $mode, $this->p->config->encrypt($key), false);
            }
            if ($this->field('delete_' . $mode) === '1') {
                delete_option('mi_secret_' . $mode);
            }
        }
        update_option('mi_settings', $new, false);
        try {
            $this->p->config->assertRun($new['mode']);
        } catch (Failure $e) {
            $new['mode'] = $old['mode'];
            update_option('mi_settings', $new, false);
            throw $e;
        }
    }
    public function render(): void
    {
        if (!current_user_can('manage_mollie_incasso')) {
            wp_die(esc_html__('Geen toegang.', 'mollie-incasso'));
        }
        $view = str_replace('mollie-incasso-', '', sanitize_key($_GET['page'] ?? 'overview'));
        if ($view === 'mollie-incasso') {
            $view = 'overview';
        }
        $mode = $this->p->config->mode();
        $views = ['overview' => ['label' => __('Overzicht', 'mollie-incasso'), 'description' => __('Uw incassoafspraken in één oogopslag.', 'mollie-incasso')], 'customers' => ['label' => __('Klanten', 'mollie-incasso'), 'description' => __('Bekijk toestemmingen en machtigingen, of bereid een nieuwe incasso voor.', 'mollie-incasso')], 'commands' => ['label' => __('Incasso’s', 'mollie-incasso'), 'description' => __('Controleer opdrachten, betaalstatussen en de actuele financiële situatie.', 'mollie-incasso')], 'subscriptions' => ['label' => __('Abonnementen', 'mollie-incasso'), 'description' => __('Beheer vaste bedragen en de periodieke afspraken die Mollie uitvoert.', 'mollie-incasso')], 'settings' => ['label' => __('Instellingen', 'mollie-incasso'), 'description' => __('Verbind uw account en leg vast waarmee uw klanten akkoord gaan.', 'mollie-incasso')], 'setup' => ['label' => __('Installatiehulp', 'mollie-incasso'), 'description' => __('Een korte rondleiding om uw koppeling in te stellen. U kunt deze altijd overslaan.', 'mollie-incasso')], 'diagnostics' => ['label' => __('Diagnose en berichten', 'mollie-incasso'), 'description' => __('Controleer webhooks, achtergrondtaken en de verzending van meldingen.', 'mollie-incasso')]];
        if (!isset($views[$view])) {
            $view = 'customers';
        }
        echo '<div class="wrap mi-admin"><div class="mi-admin-shell"><aside class="mi-admin-sidebar">';
        echo '<a class="mi-brand" href="' . esc_url($this->url()) . '"><img src="' . esc_url(plugins_url('assets/logo.svg', MOLLIE_INCASSO_FILE)) . '" width="40" height="40" alt=""><span>' . esc_html__('Mollie Incasso', 'mollie-incasso') . '<small>' . esc_html__('Automatische incasso', 'mollie-incasso') . '</small></span></a>';
        echo '<nav class="mi-side-nav" aria-label="' . esc_attr__('Incassobeheer', 'mollie-incasso') . '">';
        foreach ($views as $key => $item) {
            if ($key === 'customers' || $key === 'settings') {
                echo '<p class="mi-nav-label">' . esc_html($key === 'customers' ? __('Beheer', 'mollie-incasso') : __('Configuratie', 'mollie-incasso')) . '</p>';
            }
            echo '<a ' . ($view === $key ? 'aria-current="page" ' : '') . 'href="' . esc_url($this->url($key)) . '">' . $this->icon($key) . '<span>' . esc_html($item['label']) . '</span></a>';
        }
        echo '</nav><div class="mi-sidebar-foot"><span class="mi-version">v' . esc_html(MOLLIE_INCASSO_VERSION) . '</span><p>' . esc_html__('Eén overzicht voor uw incassoafspraken.', 'mollie-incasso') . '</p></div></aside><main class="mi-admin-content">';
        echo '<header class="mi-content-header"><div><h1>' . esc_html($views[$view]['label']) . '</h1><p>' . esc_html($views[$view]['description']) . '</p></div></header>';
        match ($view) {
            'overview' => $this->overview(),
            'settings' => $this->settings(),
            'setup' => $this->setup(),
            'commands' => $this->commands(),
            'subscriptions' => $this->subscriptions(),
            'diagnostics' => $this->diagnostics(),
            default => $this->customers(),
        };
        echo '<footer class="mi-admin-note">' . esc_html__('Deactiveren stopt geen externe abonnementen. Een aangemaakte incasso is nog niet betaald. Statussen en vooraankondigingen vereisen een werkende cron.', 'mollie-incasso') . '</footer></main></div></div>';
    }
    private function icon(string $view): string
    {
        $path = match ($view) {
            'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'customers' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.9"/><circle cx="9" cy="7" r="4"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
            'commands' => '<path d="M4 7h15m-4-4 4 4-4 4M20 17H5m4 4-4-4 4-4"/>',
            'subscriptions' => '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 6.1A8 8 0 0 1 20 12M4 12a8 8 0 0 0 13.9 5.9"/>',
            'setup' => '<path d="m4 20 12-12m-4-1 5 5M5 3v4M3 5h4M19 15v6m-3-3h6M17 2v4m-2-2h4"/>',
            'settings' => '<path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
            default => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        };
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }
    private function help(string $text): void
    {
        echo '<p class="mi-help">' . esc_html($text) . '</p>';
    }
    private function overview(): void
    {
        $mode = $this->p->config->mode();
        $store = $this->p->store;
        $count = fn(string $table, string $where = '1=1') => (int) $store->select('SELECT COUNT(*) AS n FROM ' . $store->table($table) . ' WHERE mode=%s AND ' . $where, [$mode])[0]['n'];
        $metrics = [[__('Klanten', 'mollie-incasso'), $count('customers'), __('Aanmeldingen en vastgelegde toestemmingen', 'mollie-incasso'), 'customers'], [__('Incasso’s in behandeling', 'mollie-incasso'), $count('payments', 'kind!="first" AND status IN ("pending","open","authorized")'), __('Ingezonden, nog niet als betaald bevestigd', 'mollie-incasso'), 'commands'], [__('Actieve abonnementen', 'mollie-incasso'), $count('subscriptions', 'status="active"'), __('Mollie verzorgt de volgende termijnen', 'mollie-incasso'), 'subscriptions'], [__('Te controleren', 'mollie-incasso'), $count('commands', 'state IN ("unknown","blocked")') + $count('jobs', 'state="failed"'), __('Onzekere opdrachten en mislukte achtergrondtaken', 'mollie-incasso'), 'diagnostics']];
        echo '<div class="mi-metrics">';
        foreach ($metrics as [$title, $number, $text, $view]) {
            echo '<a class="mi-metric" href="' . esc_url($this->url($view)) . '"><span>' . esc_html($title) . '</span><strong>' . (int) $number . '</strong><small>' . esc_html($text) . '</small></a>';
        }
        echo '</div>';
        echo '<div class="mi-setup-invite"><div><strong>' . esc_html__('Hulp bij het instellen?', 'mollie-incasso') . '</strong><p>' . esc_html__('Doorloop de vier stappen, of beheer alles rechtstreeks via Instellingen.', 'mollie-incasso') . '</p></div><a class="button" href="' . esc_url($this->url('setup')) . '">' . esc_html__('Installatiehulp openen', 'mollie-incasso') . '</a></div>';
        echo '<section class="mi-overview-guide"><h2>' . esc_html__('Zo werkt automatische incasso', 'mollie-incasso') . '</h2><ol>';
        foreach ([[__('Leg uw regeling vast', 'mollie-incasso'), __('Vul de organisatie, bedragen en afspraken in bij Instellingen. Plaats daarna het aanmeldformulier op uw website.', 'mollie-incasso')], [__('De klant geeft toestemming', 'mollie-incasso'), __('De klant betaalt één keer € 0,01 via iDEAL. Een gecontroleerde betaling én geldige machtiging zijn nodig voordat incasso mogelijk wordt.', 'mollie-incasso')], [__('Start een incasso of abonnement', 'mollie-incasso'), __('Kies bij Klanten een losse opdracht of vast abonnement. De klant krijgt eerst een vooraankondiging; een verzonden incasso is nog niet betaald.', 'mollie-incasso')]] as [$title, $text]) {
            echo '<li><strong>' . esc_html($title) . '</strong><p>' . esc_html($text) . '</p></li>';
        }
        echo '</ol><div class="mi-overview-actions"><a class="button button-primary" href="' . esc_url($this->url('settings')) . '">' . esc_html__('Instellingen bekijken', 'mollie-incasso') . '</a><a class="button" href="' . esc_url($this->url('customers')) . '">' . esc_html__('Naar klanten', 'mollie-incasso') . '</a></div></section>';
    }
    private function checkMethods(string $mode): void
    {
        $methods = $this->p->service->methods($mode);
        update_option('mi_methods_' . $mode, ['checked_at' => Store::now(), 'fingerprint' => $this->p->config->fingerprint($mode), 'first' => $methods['first'], 'recurring' => $methods['recurring']], false);
    }
    private function draftPage(array $scheme, string $mode): int
    {
        return $this->p->store->lock('setup-page:' . $mode . ':' . $scheme['id'], function () use ($scheme, $mode) {
            $option = 'mi_setup_page_' . $mode . '_' . $scheme['id'];
            $existing = (int) get_option($option);
            if ($existing && get_post_type($existing) === 'page' && get_post_status($existing) !== 'trash') {
                return $existing;
            }
            $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => __('Toestemming voor automatische incasso', 'mollie-incasso'), 'post_content' => '[mollie_incasso regeling="' . $scheme['id'] . '"]'], true);
            if (is_wp_error($id) || !$id) {
                throw new Failure('page_creation_failed');
            }
            update_option($option, (int) $id, false);
            return (int) $id;
        });
    }
    private function keyHelp(): void
    {
        $this->help(__('Uw Mollie-API-key is nodig om uw account te verbinden. Plak de key in het veld en klik op Opslaan. De plugin regelt de versleuteling automatisch. U hoeft geen bestand aan te maken of serverinstellingen te wijzigen.', 'mollie-incasso'));
        echo '<details><summary>' . esc_html__('Waar vind ik mijn key? En wat is de extra beveiligingsoptie?', 'mollie-incasso') . '</summary><ol><li>' . esc_html__('Open uw Mollie Dashboard en ga naar Developers → API keys.', 'mollie-incasso') . '</li><li>' . esc_html__('Kopieer eerst de testkey (begint met test_). Hiermee test u zonder echt geld.', 'mollie-incasso') . '</li><li>' . esc_html__('Plak de key hierboven en sla op. Controleer daarna iDEAL en SEPA-incasso met de controleknop. Deze controle test de verbinding en betaalmethodes, niet de volledige betaling.', 'mollie-incasso') . '</li></ol><p><a href="https://my.mollie.com/">' . esc_html__('Mollie Dashboard openen', 'mollie-incasso') . '</a></p><p>' . esc_html__('Optioneel voor uw beheerder: de API-key rechtstreeks op de server instellen of een aparte encryptiesleutel gebruiken via wp-config.php, het bestaande configuratiebestand van WordPress. Dit is niet nodig voor normaal opslaan via WordPress. Een op de server ingestelde API-key heeft voorrang op dit formulier.', 'mollie-incasso') . '</p><p>' . esc_html__('Meestal gebruikt de plugin de bestaande WordPress-beveiligingssleutels. Zijn die niet bruikbaar, dan maakt hij bij het opslaan zelf een afgeschermd sleutelbestand in wp-content/.mollie-incasso-keys. Bewaar bij back-ups daarom ook wp-config.php en deze map veilig. Verlies van de gebruikte sleutel maakt opgeslagen API-keys onleesbaar. Een livekey invoeren geeft livebetalingen nog niet vrij.', 'mollie-incasso') . '</p></details>';
    }
    private function wizardPlan(array $input, string $mode): int
    {
        if(!current_user_can('manage_options'))throw new Failure('access_denied');
        $reference=$this->field('plan_reference',36);
        if(!preg_match('/^[a-f0-9-]{36}$/D',$reference))throw new Failure('invalid_scheme');
        $interval=$this->field('interval',12);
        if(!in_array($interval,['1 month','3 months','12 months'],true))throw new Failure('invalid_scheme');
        $data=['slug'=>'wizard-'.$reference,'type'=>'fixed','auto_start'=>'1','name'=>$this->field('name',190),'amount'=>$this->field('amount',24),'interval'=>$interval,'purpose'=>$this->field('purpose'),'calculation'=>__('Het vaste bedrag voor dit abonnement. De verificatiebetaling van € 0,01 staat hier los van.','mollie-incasso'),'frequency'=>Plugin::frequency($interval),'withdrawal'=>$this->field('withdrawal'),'consent'=>$this->field('consent'),'notice_days'=>14,'times'=>''];
        return $this->p->store->lock('wizard-plan:'.$mode.':'.$reference,function()use($data,$mode,$reference){
            $option='mi_wizard_plan_'.$mode.'_'.$reference;$hash=hash('sha256',wp_json_encode($data));$previous=get_option($option,[]);
            if(is_array($previous)&&($previous['hash']??'')===$hash&&$this->p->store->one('schemes',(int)$previous['id'],$mode))return (int)$previous['id'];
            $id=$this->p->schemes->save($data,$mode);update_option($option,['id'=>$id,'hash'=>$hash],false);return $id;
        });
    }
    private function wooProductHelp(): void
    {
        echo '<h3>'.esc_html__('Of aanmelden via uw webshop','mollie-incasso').'</h3><p>'.esc_html__('Een gewoon virtueel product kan de aanmelding starten. De klant rekent eenmalig € 0,01 af en accepteert de incassoafspraak in dezelfde checkout. Vervolgbetalingen lopen via Mollie Incasso; WooCommerce Subscriptions is niet nodig.','mollie-incasso').'</p>';
        if(!class_exists('WC_Product_Simple')){echo '<p class="mi-help">'.esc_html__('WooCommerce is hier niet actief. De website-aanmelding hierboven werkt zonder webshop.','mollie-incasso').'</p>';return;}
        $schemes=[];foreach($this->p->store->rows('schemes',$this->p->config->mode(),500)as$row){$data=json_decode($row['data'],true);if($row['active']&&$data['type']==='fixed'&&!empty($data['auto_start']))$schemes[]=$row+['name'=>$data['name']];}
        if(!$schemes){echo '<p class="mi-help">'.esc_html__('Maak eerst een vaste regeling met automatisch starten ingeschakeld. Daarna kunt u hier het product klaarzetten.','mollie-incasso').'</p>';return;}
        $this->start('setup_product');echo '<label>'.esc_html__('Abonnement voor het product','mollie-incasso').'<select name="scheme">';foreach($schemes as$scheme)echo '<option value="'.esc_attr((string)$scheme['id']).'">'.esc_html($scheme['name']).'</option>';echo '</select></label>';$this->end('Virtueel product klaarzetten',true);
        echo '<p class="mi-help">'.esc_html__('Het product wordt als concept klaargezet: controleer het en publiceer het daarna. De gekoppelde betaalmethode verschijnt alleen voor dit product. Reken abonnementaanmeldingen apart af, zonder andere producten, kortingscodes of toeslagen.','mollie-incasso').'</p>';
    }
    private function setup(): void
    {
        $step = max(1, min(4, (int) ($_GET['step'] ?? 1)));
        $settings = $this->p->config->settings();
        $mode = $this->p->config->mode();
        echo '<nav class="mi-wizard-steps" aria-label="' . esc_attr__('Installatiestappen', 'mollie-incasso') . '">';
        foreach ([1 => __('Organisatie', 'mollie-incasso'), 2 => __('Mollie koppelen', 'mollie-incasso'), 3 => __('Abonnement instellen', 'mollie-incasso'), 4 => __('Aanmelding plaatsen', 'mollie-incasso')] as $number => $title) {
            echo '<a ' . ($number === $step ? 'aria-current="step" ' : '') . 'href="' . esc_url($this->url('setup', ['step' => $number])) . '"><span>' . $number . '</span>' . esc_html($title) . '</a>';
        }
        echo '</nav><section class="mi-wizard-card">';
        if ($step === 1) {
            echo '<h2>' . esc_html__('Wie incasseert?', 'mollie-incasso') . '</h2>';
            $this->help(__('Deze gegevens staan bij de toestemming en in de klantmails. Vermeld duidelijk namens wie u incasseert en waar klanten terechtkunnen.', 'mollie-incasso'));
            if (current_user_can('manage_options')) {
                $this->start('setup_profile');
                foreach (['organization' => __('Organisatienaam', 'mollie-incasso'), 'address' => __('Adres en organisatiegegevens', 'mollie-incasso'), 'contact' => __('Contact / hoe toestemming intrekken', 'mollie-incasso'), 'privacy_url' => __('URL privacyverklaring (optioneel)', 'mollie-incasso')] as $key => $label) {
                    echo '<label>' . esc_html($label) . '<input type="text" name="' . $key . '" value="' . esc_attr((string) $settings[$key]) . '" ' . (in_array($key, ['organization', 'contact'], true) ? 'required' : '') . '></label>';
                }
                $this->end('Opslaan en verder', true);
            }
        } elseif ($step === 2) {
            echo '<h2>' . esc_html__('Verbind uw Mollie-account', 'mollie-incasso') . '</h2>';
            $this->help(__('Begin met een testkey: daarmee zijn betalingen nagebootst en wordt geen echt geld gebruikt. Mollie activeert uw account en betaalmethodes; dat zet deze plugin niet automatisch op live.', 'mollie-incasso'));
            $configured = false;
            try {
                $this->p->config->key($mode);
                $configured = true;
            } catch (Failure $e) {
            }
            if ($configured) {
                echo '<p class="mi-setup-status">' . esc_html__('Er is een API-key ingesteld voor de huidige betaalmodus.', 'mollie-incasso') . '</p>';
            } elseif (current_user_can('manage_options')) {
                if ($mode === 'test') {
                    $this->start('setup_key');
                    echo '<label>' . esc_html__('Mollie test-API-key', 'mollie-incasso') . '<input type="password" name="key_test" value="" autocomplete="new-password" required></label>';
                    $this->keyHelp();
                    $this->end('Testkey opslaan', true);
                } else {
                    $this->help(__('Beheer de livekey via Instellingen. Deze installatiehulp schakelt zelf geen echte betalingen in.', 'mollie-incasso'));
                }
            }
            if ($configured) {
                $this->quick('setup_check', 0, 'iDEAL en SEPA-incasso controleren');
                $result = get_option('mi_methods_' . $mode, []);
                if (!empty($result['fingerprint']) && hash_equals($result['fingerprint'], $this->p->config->fingerprint($mode))) {
                    echo '<p class="mi-help">' . esc_html(sprintf(__('Beide betaalmethodes beschikbaar bij de laatste controle: %s UTC. Dit is geen volledige betaaltest.', 'mollie-incasso'), $result['checked_at'])) . '</p>';
                }
            }
            echo '<p><a href="https://my.mollie.com/dashboard" target="_blank" rel="noopener noreferrer">' . esc_html__('Mollie Dashboard openen', 'mollie-incasso') . '</a></p>';
        } elseif ($step === 3) {
            $hasPlans=false;foreach($this->p->store->rows('schemes',$mode,500) as $plan){$planData=json_decode($plan['data'],true);if($plan['active']&&$planData['type']==='fixed')$hasPlans=true;}
            echo '<h2>'.esc_html($hasPlans?__('Nog een abonnement toevoegen','mollie-incasso'):__('Stel uw eerste abonnement in','mollie-incasso')).'</h2>';
            $this->help(__('U kunt meerdere abonnementen aanbieden. Deze stap voegt een nieuw abonnement toe; bestaande afspraken blijven bewaard. Klanten kunnen na hun verificatie automatisch starten.','mollie-incasso'));
            $this->start('setup_plan');
            echo '<input type="hidden" name="plan_reference" value="'.esc_attr(wp_generate_uuid4()).'"><label>'.esc_html__('Naam van het abonnement','mollie-incasso').'<input name="name" maxlength="190" placeholder="'.esc_attr__('Bijvoorbeeld GCC doorlopend abonnement','mollie-incasso').'" required></label><label>'.esc_html__('Vast bedrag per periode (€)','mollie-incasso').'<input name="amount" inputmode="decimal" placeholder="20.00" required></label><label>'.esc_html__('Hoe vaak incasseren?','mollie-incasso').'<select name="interval"><option value="1 month">'.esc_html__('Elke maand','mollie-incasso').'</option><option value="3 months">'.esc_html__('Elk kwartaal','mollie-incasso').'</option><option value="12 months">'.esc_html__('Elk jaar','mollie-incasso').'</option></select></label>';
            echo '<details open><summary>'.esc_html__('Controleer de afspraak die uw klant accepteert','mollie-incasso').'</summary>';
            foreach(['purpose'=>['Waarvoor betaalt de klant?','Betaling voor het gekozen lidmaatschap.'],'withdrawal'=>['Hoe kan de klant stoppen?','Via de persoonlijke statuslink of door contact op te nemen met de organisatie.'],'consent'=>['Toestemmingstekst','Ik geef deze organisatie toestemming om het hierboven genoemde bedrag met de gekozen frequentie via SEPA Direct Debit van mijn rekening te incasseren.']]as$key=>[$label,$default])echo '<label>'.esc_html__($label,'mollie-incasso').'<textarea name="'.$key.'" rows="2" required>'.esc_textarea(__($default,'mollie-incasso')).'</textarea></label>';
            echo '</details><p class="mi-help">'.esc_html__('De klant betaalt eerst € 0,01 ter verificatie. Na een geldige machtiging plannen we de eerste incasso met minimaal 14 dagen vooraankondiging. Pas de voorbeeldteksten aan uw eigen afspraken aan.','mollie-incasso').'</p>';
            $this->end('Abonnement opslaan en verder',true);
            echo '<p><a href="'.esc_url($this->url('settings')).'#mi-regelingen">'.esc_html__('Geavanceerde regelingen en losse incasso’s','mollie-incasso').'</a></p>';
        } else {
            echo '<h2>' . esc_html__('Kies hoe klanten zich aanmelden', 'mollie-incasso') . '</h2>';
            $this->help(__('Plaats de shortcode in een WordPress-pagina. Een klant ziet uw afspraken en kan expliciet toestemming geven via de eerste iDEAL-betaling van € 0,01.', 'mollie-incasso'));
            $schemes = $this->p->store->select('SELECT id FROM ' . $this->p->store->table('schemes') . ' WHERE mode=%s AND active=1 ORDER BY id DESC LIMIT 10', [$mode]);
            if (!$schemes) {
                echo '<p>' . esc_html__('Maak eerst een regeling bij stap 3.', 'mollie-incasso') . '</p>';
            }
            foreach ($schemes as $row) {
                $scheme = $this->p->schemes->get((int) $row['id'], $mode);
                echo '<div class="mi-shortcode-card"><strong>' . esc_html($scheme['name']) . '</strong><code>' . esc_html('[mollie_incasso regeling="' . $scheme['id'] . '"]') . '</code>';
                if (current_user_can('manage_options') && current_user_can('edit_pages')) {
                    $this->start('setup_page');
                    echo '<input type="hidden" name="scheme" value="' . (int) $scheme['id'] . '">';
                    $this->end('Conceptpagina maken');
                }
                echo '</div>';
            }
            $this->help(__('Een conceptpagina wordt niet gepubliceerd. Controleer het formulier en doorloop daarna een echte Mollie-testbetaling, webhook en mail. Pas na die controles beoordeelt u livegang.', 'mollie-incasso'));
        }
        if ($step === 4) $this->wooProductHelp();
        echo '</section><div class="mi-wizard-footer">';
        if ($step > 1) {
            echo '<a class="button" href="' . esc_url($this->url('setup', ['step' => $step - 1])) . '">' . esc_html__('Vorige', 'mollie-incasso') . '</a>';
        }
        echo '<a class="mi-wizard-skip" href="' . esc_url($this->url('overview')) . '">' . esc_html__('Installatiehulp verlaten', 'mollie-incasso') . '</a>';
        if ($step < 4) {
            echo '<a class="button button-primary" href="' . esc_url($this->url('setup', ['step' => $step + 1])) . '">' . esc_html__('Volgende stap', 'mollie-incasso') . '</a>';
        }
        echo '</div>';
    }
    private function customers(): void
    {
        $mode = $this->p->config->mode();
        $this->help(__('Een machtiging is de toestemming bij Mollie om van de rekening te incasseren. Pas bij een geldige machtiging en bevestigde eerste betaling wordt een klant actief.', 'mollie-incasso'));
        $this->quick('export', 0, 'Beperkte CSV-export (maximaal 500 klanten)');
        echo '<div class="mi-scroll"><table class="widefat striped"><thead><tr>';
        foreach (['Klant', 'Regeling', 'Toestemming / machtiging', 'Rekeningindicatie', 'Acties'] as $h) {
            echo '<th>' . esc_html__($h, 'mollie-incasso') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->p->store->rows('customers', $mode) as $c) {
            $mandate = $c['mandate_id'] ? $this->p->store->find('mandates', 'remote_id', $c['mandate_id'], $mode) : null;
            try {
                $scheme = $this->p->schemes->get((int) $c['scheme_id'], $mode, false);
                $name = $scheme['name'] . ' v' . $scheme['version'];
            } catch (Failure $e) {
                $name = '—';
            }
            echo '<tr><td>' . esc_html($c['name']) . '<br>' . esc_html($c['email']) . '<br><small>' . esc_html($c['remote_id'] ?? '') . '</small></td><td>' . esc_html($name) . '</td><td>' . esc_html(Plugin::status($c['status'])) . '<br>' . esc_html($mandate ? Plugin::status($mandate['status']) : __('Geen machtiging', 'mollie-incasso')) . '</td><td>' . esc_html($mandate['masked'] ?? '—') . '</td><td>';
            $this->quick('refresh', (int) $c['id'], 'Status ophalen');
            $this->quick('mail_link', (int) $c['id'], 'Persoonlijke link mailen');
            if ($c['status'] !== 'revoked') {
                $this->quick('withdraw', (int) $c['id'], 'Toestemming intrekken');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><h2>' . esc_html__('Nieuwe incasso of vast abonnement', 'mollie-incasso') . '</h2>';
        $this->help(__('U bereidt hier alleen een opdracht voor. Op het volgende scherm controleert u klant, bedrag en doel; pas na bevestiging wordt de vooraankondiging verzonden.', 'mollie-incasso'));
        $this->start('prepare');
        echo '<label>' . esc_html__('Klant en geaccepteerde regeling', 'mollie-incasso') . '<select name="customer" required>';
        foreach ($this->p->store->rows('customers', $mode, 500) as $c) {
            if ($c['status'] === 'active') {
                echo '<option value="' . (int) $c['id'] . '">' . esc_html($c['name'] . ' — ' . $c['email'] . ' — ' . $this->p->schemes->get((int) $c['scheme_id'], $mode, false)['name']) . '</option>';
            }
        }
        echo '</select></label>';
        echo '<label>' . esc_html__('Type opdracht', 'mollie-incasso') . '<select name="kind"><option value="manual">' . esc_html__('Losse incasso', 'mollie-incasso') . '</option><option value="subscription">' . esc_html__('Vast abonnement', 'mollie-incasso') . '</option></select></label><label>' . esc_html__('Bedrag in EUR', 'mollie-incasso') . '<input name="amount" inputmode="decimal" required></label><label>' . esc_html__('Omschrijving / doel', 'mollie-incasso') . '<input name="description" maxlength="255" required></label><label>' . esc_html__('Aanbieddatum / eerste abonnementsdatum', 'mollie-incasso') . '<input name="date" type="date" value="' . esc_attr(wp_date('Y-m-d', time() + 14 * DAY_IN_SECONDS)) . '" required></label><input name="reference" type="hidden" value="' . esc_attr(wp_generate_uuid4()) . '">';
        $this->help(__('Losse incasso: u kiest één bedrag en datum. Vast abonnement: Mollie incasseert het afgesproken bedrag elke maand, elk kwartaal of elk jaar.', 'mollie-incasso'));
        $this->help(__('Kies een toekomstige datum die past bij de afgesproken vooraankondigingstermijn, standaard 14 dagen. Een abonnement begint niet op de verificatiedag.', 'mollie-incasso'));
        $this->end('Opdracht controleren', true);
    }
    private function commands(): void
    {
        $mode = $this->p->config->mode();
        $preview = (int) ($_GET['preview'] ?? 0);
        if ($preview) {
            $c = $this->p->store->one('commands', $preview, $mode);
            if ($c && $c['state'] === 'draft') {
                $customer = $this->p->service->customer((int) $c['customer_id'], $mode);
                $data = json_decode($c['payload'], true);
                echo '<section class="mi-card"><h2>' . esc_html__('Controleer vóór bevestiging', 'mollie-incasso') . '</h2><p>' . esc_html($customer['name'] . ' — ' . $customer['email']) . '</p><p><strong>' . esc_html(Money::eur((int) $c['amount'])) . '</strong> — ' . esc_html($data['description']) . '</p><p>' . esc_html__('Datum:', 'mollie-incasso') . ' ' . esc_html($data['date']) . ' — ' . esc_html($c['kind'] === 'subscription' ? Plugin::interval($data['interval']) : __('eenmalig', 'mollie-incasso')) . '</p><p>' . esc_html__('De vooraankondiging wordt eerst verzonden. Zonder geaccepteerde mailverzending start de opdracht niet.', 'mollie-incasso') . '</p>';
                $this->quick('confirm', $preview, 'Opdracht bevestigen');
                echo '</section>';
            }
        }
        $this->help(__('Een opdracht is uw verzoek om te incasseren; een betaling is het resultaat bij Mollie. In behandeling betekent nog niet betaald. Een onbekende uitkomst moet eerst worden gecontroleerd.', 'mollie-incasso'));
        echo '<h2>' . esc_html__('Opdrachten', 'mollie-incasso') . '</h2><div class="mi-scroll"><table class="widefat striped"><thead><tr>';
        foreach (['Opdracht', 'Klant', 'Bedrag', 'Datum', 'Status', 'Actie'] as $h) {
            echo '<th>' . esc_html__($h, 'mollie-incasso') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->p->store->rows('commands', $mode) as $c) {
            $customer = $this->p->store->one('customers', (int) $c['customer_id'], $mode);
            echo '<tr><td>' . esc_html($c['reference']) . '<br>' . esc_html(Plugin::kind($c['kind'])) . '</td><td>' . esc_html($customer['name'] ?? '') . '</td><td>' . esc_html(Money::eur((int) $c['amount'])) . '</td><td>' . esc_html($c['due_at']) . ' UTC</td><td>' . esc_html(Plugin::status($c['state']));
            if ($c['error']) {
                echo '<br>' . esc_html(Plugin::message($c['error']));
                if (!empty($c['error_detail'])) {
                    echo '<br><small>' . esc_html__('Melding van Mollie:', 'mollie-incasso') . ' ' . esc_html($c['error_detail']) . '</small>';
                }
                if ($c['kind'] === 'subscription' && $c['state'] === 'failed') {
                    $this->quick('retry_subscription', (int) $c['id'], 'Opnieuw proberen met nieuwe startdatum');
                }
            }
            echo '</td><td>';
            if ($c['state'] === 'draft') {
                echo '<a href="' . esc_url($this->url('commands', ['preview' => $c['id']])) . '">' . esc_html__('Controleren', 'mollie-incasso') . '</a>';
            }
            if (in_array($c['state'], ['unknown', 'blocked', 'processing'], true)) {
                $this->quick('reconcile', (int) $c['id'], 'Bij Mollie controleren');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><h2>' . esc_html__('Incasso’s en betalingen', 'mollie-incasso') . '</h2><div class="mi-scroll"><table class="widefat striped"><thead><tr>';
        foreach (['Mollie-ID', 'Klant', 'Bedrag', 'Status', 'Refund / stornering', 'Netto excl. kosten', 'Datum / fout'] as $h) {
            echo '<th>' . esc_html__($h, 'mollie-incasso') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->p->store->rows('payments', $mode) as $p) {
            $c = $this->p->store->one('customers', (int) $p['customer_id'], $mode);
            $net = $p['status'] === 'paid' ? (int) $p['amount'] - (int) $p['refunded'] - (int) $p['charged_back'] : 0;
            echo '<tr><td>' . esc_html($p['remote_id']) . '</td><td>' . esc_html($c['name'] ?? '') . '</td><td>' . esc_html(Money::eur((int) $p['amount'])) . '</td><td>' . esc_html(Plugin::status($p['status'])) . '</td><td>' . esc_html(Money::eur((int) $p['refunded']) . ' / ' . Money::eur((int) $p['charged_back'])) . '</td><td>' . esc_html(Money::eur($net)) . '</td><td>' . esc_html($p['created_at'] . ' UTC — ' . $p['failure']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private function subscriptions(): void
    {
        $this->help(__('Mollie voert deze vaste incassoafspraken uit. Stoppen beëindigt toekomstige termijnen; een incasso die al is ingezonden moet afzonderlijk worden behandeld.', 'mollie-incasso'));
        echo '<div class="mi-scroll"><table class="widefat striped"><thead><tr>';
        foreach (['Abonnement', 'Klant', 'Bedrag / frequentie', 'Start / volgende datum', 'Status', 'Acties'] as $h) {
            echo '<th>' . esc_html__($h, 'mollie-incasso') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->p->store->rows('subscriptions', $this->p->config->mode()) as $s) {
            $c = $this->p->store->one('customers', (int) $s['customer_id'], $s['mode']);
            echo '<tr><td>' . esc_html($s['remote_id']) . '</td><td>' . esc_html($c['name'] ?? '') . '</td><td>' . esc_html(Money::eur((int) $s['amount']) . ' / ' . Plugin::interval($s['interval_value'])) . '</td><td>' . esc_html($s['start_date'] . ' / ' . ($s['next_date'] ?? '—')) . '</td><td>' . esc_html(Plugin::status($s['status'])) . '</td><td>';
            $this->quick('sync_sub', (int) $s['id'], 'Status ophalen');
            if (!in_array($s['status'], ['completed', 'canceled'], true)) {
                $this->quick('stop', (int) $s['id'], 'Abonnement beëindigen');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private function settings(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<p>' . esc_html__('Alleen beheerders met instellingenrechten kunnen dit wijzigen.', 'mollie-incasso') . '</p>';
            return;
        }
        $s = $this->p->config->settings();
        $this->start('settings');
        echo '<h2>' . esc_html__('Account en organisatie', 'mollie-incasso') . '</h2><label>' . esc_html__('Omgeving', 'mollie-incasso') . '<select name="target_mode"><option value="test" ' . selected($s['mode'], 'test', false) . '>' . esc_html__('Test — geen echt geld', 'mollie-incasso') . '</option><option value="live" ' . selected($s['mode'], 'live', false) . '>' . esc_html__('Live — echte betalingen', 'mollie-incasso') . '</option></select></label><p>' . esc_html__('Test gebruikt nagebootste betalingen. Mollie controleert uw account en activeert de betaalmethodes; de plugin schakelt daarna niet vanzelf naar live. Voor live zijn een livekey en vrijgave op de productiesite nodig. Wisselen stopt geen bestaande Mollie-abonnementen.', 'mollie-incasso') . '</p>';
        foreach (['organization' => 'Organisatienaam', 'address' => 'Adres en organisatiegegevens', 'contact' => 'Contact en intrekken', 'privacy_url' => 'URL privacyverklaring', 'webhook_url' => 'HTTPS-webhookoverride voor reverse proxy (optioneel)', 'retention_days' => 'Bewaartermijn in dagen'] as $key => $label) {
            echo '<label>' . esc_html__($label, 'mollie-incasso') . '<input name="' . esc_attr($key) . '" value="' . esc_attr((string) $s[$key]) . '"></label>';
        }
        foreach (['test', 'live'] as $mode) {
            $configured = $this->p->config->settingSecret('MOLLIE_INCASSO_' . strtoupper($mode) . '_KEY') || get_option('mi_secret_' . $mode);
            echo '<label>' . esc_html(sprintf(__('Nieuwe %s API-key (leeg = behouden)', 'mollie-incasso'), $mode)) . '<input type="password" name="key_' . esc_attr($mode) . '" value="" autocomplete="new-password" placeholder="' . esc_attr($configured ? '•••••••• — ' . __('ingesteld', 'mollie-incasso') : __('niet ingesteld', 'mollie-incasso')) . '"></label><label><input type="checkbox" name="delete_' . $mode . '" value="1">' . esc_html(sprintf(__('Opgeslagen %s-key verwijderen (omgeving/config blijft leidend)', 'mollie-incasso'), $mode)) . '</label>';
        }
        $this->keyHelp();
        foreach (['notifications' => 'Statusmails voor betalingen', 'cleanup' => 'Bij uninstall opruimen, uitsluitend wanneer extern veilig en bewaarbeleid dit toestaat'] as $key => $label) {
            echo '<label><input type="checkbox" name="' . $key . '" value="1" ' . checked((bool) $s[$key], true, false) . '>' . esc_html__($label, 'mollie-incasso') . '</label>';
        }
        $this->end('Instellingen opslaan', true);
        if (get_option('mi_site_binding') !== $this->p->config->binding()) {
            $this->quick('bind', 0, 'Deze installatie binden en naar testmodus zetten');
        }
        $this->help(__('Een regeling beschrijft waarvoor, hoeveel en wanneer een klant toestemming geeft. De naam is zichtbaar in het aanmeldformulier. Gebruik een nieuwe versie als de afspraken veranderen.', 'mollie-incasso'));
        echo '<h2 id="mi-regelingen">' . esc_html__('Regelingen', 'mollie-incasso') . '</h2><div class="mi-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__('Naam / versie', 'mollie-incasso') . '</th><th>' . esc_html__('Bedrag / type', 'mollie-incasso') . '</th><th>Shortcode</th></tr></thead><tbody>';
        foreach ($this->p->store->rows('schemes', $this->p->config->mode()) as $row) {
            $data = json_decode($row['data'], true);
            echo '<tr><td>' . esc_html($data['name'] . ' v' . $row['version'] . ($row['active'] ? '' : ' — ' . __('archief', 'mollie-incasso'))) . '</td><td>' . esc_html(Money::eur($data['amount']) . ' / ' . Plugin::kind($data['type'])) . '</td><td><code>' . esc_html('[mollie_incasso regeling="' . $row['id'] . '"]') . '</code></td></tr>';
        }
        echo '</tbody></table></div><h3>' . esc_html__('Nieuwe regeling / nieuwe versie', 'mollie-incasso') . '</h3><p>' . esc_html__('Bestaande toestemming blijft aan de geaccepteerde versie gekoppeld. De voorbeeldteksten zijn een startpunt en moeten voor uw organisatie worden beoordeeld.', 'mollie-incasso') . '</p>';
        $this->start('scheme');
        foreach (['slug' => ['Vaste regelingscode (dezelfde code maakt een nieuwe versie)', ''], 'name' => ['Naam', 'Lidmaatschap'], 'purpose' => ['Doel', 'Betaling voor het afgesproken lidmaatschap.'], 'amount' => ['Vast bedrag of maximaal bedrag bij losse incasso', '10.00'], 'calculation' => ['Bedrag / berekeningswijze', 'Het vooraf aangekondigde bedrag volgens onze overeenkomst.'], 'frequency' => ['Frequentie of aanleiding', 'Maandelijks, of uitsluitend na een afzonderlijke aankondiging conform de gekozen regeling.'], 'withdrawal' => ['Hoe toestemming kan worden ingetrokken', 'Via uw persoonlijke statuslink of door contact met ons op te nemen.'], 'consent' => ['Expliciete toestemmingstekst', 'Ik geef deze organisatie toestemming om de hiervoor beschreven bedragen via SEPA Direct Debit van mijn rekening te incasseren.'], 'notice_days' => ['Vooraankondiging in kalenderdagen', '14'], 'times' => ['Maximum aantal betalingen (optioneel, maximaal 10000)', '']] as $key => [$label, $default]) {
            echo '<label>' . esc_html__($label, 'mollie-incasso');
            if (in_array($key, ['purpose', 'calculation', 'frequency', 'withdrawal', 'consent'], true)) {
                echo '<textarea name="' . $key . '" rows="3" required>' . esc_textarea(__($default,'mollie-incasso')) . '</textarea>';
            } else {
                echo '<input name="' . $key . '" value="' . esc_attr($default) . '" ' . (!in_array($key, ['slug', 'times'], true) ? 'required' : '') . '>';
            }
            echo '</label>';
        }
        echo '<label><input type="checkbox" name="auto_start" value="1" checked> ' . esc_html__('Vast abonnement automatisch starten na verificatie en geldige machtiging (alleen bij vast bedrag).', 'mollie-incasso') . '</label>';
        echo '<label>' . esc_html__('Type', 'mollie-incasso') . '<select name="type"><option value="manual">' . esc_html__('Losse incasso’s', 'mollie-incasso') . '</option><option value="fixed">' . esc_html__('Vast abonnement', 'mollie-incasso') . '</option></select></label><label>' . esc_html__('Vaste frequentie', 'mollie-incasso') . '<select name="interval"><option value="1 month">' . esc_html__('Maand', 'mollie-incasso') . '</option><option value="3 months">' . esc_html__('Kwartaal', 'mollie-incasso') . '</option><option value="12 months">' . esc_html__('Jaar', 'mollie-incasso') . '</option></select></label><label><input type="checkbox" name="notice_agreed" value="1">' . esc_html__('Een afwijkende vooraankondigingstermijn is expliciet met de klant afgesproken en opgenomen in deze regeling.', 'mollie-incasso') . '</label>';
        $this->end('Regeling opslaan', true);
    }
    private function diagnostics(): void
    {
        $mode = $this->p->config->mode();
        $this->help(__('Webhooks zijn statusmeldingen van Mollie. Achtergrondtaken controleren deze meldingen en versturen mails. Een mislukte taak kan hier opnieuw worden gecontroleerd; dat maakt niet automatisch een tweede incasso.', 'mollie-incasso'));
        echo '<h2>' . esc_html__('Bereikbaarheid en achtergrondwerk', 'mollie-incasso') . '</h2>';
        try {
            $url = $this->p->config->webhook($mode);
            echo '<p>Webhook: <code>' . esc_html($url) . '</code></p>';
        } catch (Failure $e) {
            echo '<p>' . esc_html(Plugin::message($e->reason)) . '</p>';
        }
        echo '<p>' . esc_html__('Deze URL moet extern via HTTPS bereikbaar zijn zonder login, caching of firewallblokkade. Alleen een geslaagde echte Mollie-test bevestigt bereikbaarheid.', 'mollie-incasso') . '</p><p>' . esc_html__('Laatste webhook:', 'mollie-incasso') . ' ' . esc_html((string) get_option('mi_last_webhook_' . $mode, '—')) . ' UTC</p><p>' . esc_html__('Laatste queue-run:', 'mollie-incasso') . ' ' . esc_html((string) get_option('mi_last_tick', '—')) . ' UTC</p>';
        $methods = get_option('mi_methods_' . $mode, []);
        if (!empty($methods['checked_at'])) {
            echo '<p>' . esc_html(sprintf(__('Laatste methodecontrole (%1$s): eerste betaling %2$s; terugkerende incasso %3$s.', 'mollie-incasso'), Notifications::date($methods['checked_at'], true), implode(', ', $methods['first']), implode(', ', $methods['recurring']))) . '</p>';
        }
        $this->quick('diagnose', 0, 'Beschikbare iDEAL- en directdebit-methodes controleren');
        $this->quick('queue', 0, 'Achtergrondwerk nu uitvoeren');
        $error = get_option('mi_cron_error', []);
        if ($error) {
            echo '<p>' . esc_html(Plugin::message($error['reason'])) . ' — ' . esc_html($error['at']) . '</p>';
        }
        echo '<h2>' . esc_html__('Taken', 'mollie-incasso') . '</h2><div class="mi-scroll"><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Type / status', 'mollie-incasso') . '</th><th>' . esc_html__('Pogingen / eerstvolgende run', 'mollie-incasso') . '</th><th>' . esc_html__('Fout / herstel', 'mollie-incasso') . '</th></tr></thead><tbody>';
        $jobs = $this->p->store->select('SELECT * FROM ' . $this->p->store->table('jobs') . ' WHERE mode=%s ORDER BY state="failed" DESC, id DESC LIMIT 100', [$mode]);
        foreach ($jobs as $job) {
            echo '<tr><td>' . (int) $job['id'] . '</td><td>' . esc_html(Plugin::kind($job['kind']) . ' / ' . Plugin::status($job['state'])) . '</td><td>' . (int) $job['attempts'] . ' / ' . esc_html($job['run_at']) . ' UTC</td><td>';
            if ($job['error']) {
                echo esc_html(Plugin::message($job['error']));
            }
            if ($job['state'] === 'failed') {
                $this->quick('recover', (int) $job['id'], 'Taak opnieuw controleren');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><h2>' . esc_html__('Meldingen en mailstatus', 'mollie-incasso') . '</h2><div class="mi-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__('Gebeurtenis', 'mollie-incasso') . '</th><th>' . esc_html__('Status', 'mollie-incasso') . '</th><th>' . esc_html__('Fout', 'mollie-incasso') . '</th><th>' . esc_html__('Afhandelen', 'mollie-incasso') . '</th></tr></thead><tbody>';
        $mails = $this->p->store->select('SELECT * FROM ' . $this->p->store->table('notifications') . ' WHERE mode=%s ORDER BY state IN ("unknown","failed") DESC, id DESC LIMIT 100', [$mode]);
        foreach ($mails as $mail) {
            echo '<tr><td>' . esc_html($mail['event_key']) . '</td><td>' . esc_html(Plugin::status($mail['state'])) . '</td><td>' . esc_html($mail['error'] ? Plugin::message($mail['error']) : '') . '</td><td>';
            if (in_array($mail['state'], ['unknown', 'failed'], true)) {
                $this->quick('mail_retry', (int) $mail['id'], 'Opnieuw versturen');
                if ($mail['state'] === 'unknown') {
                    $this->quick('mail_sent', (int) $mail['id'], 'Markeer als verzonden');
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p>' . esc_html__('Door wp_mail geaccepteerd betekent geen gegarandeerde aflevering. Een onbekende verzenduitkomst wordt niet automatisch opnieuw verstuurd: controleer uw mailserverlog. Kies "Markeer als verzonden" alleen als de mail aantoonbaar is verstuurd; anders "Opnieuw versturen". Een wachtende incasso gaat pas door als de vooraankondiging als verzonden geldt.', 'mollie-incasso') . '</p>';
    }
    private function csv(string $mode): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="mollie-incasso-' . $mode . '.csv"');
        $stream = fopen('php://output', 'w');
        fputcsv($stream, ['Omgeving', 'Naam', 'E-mail', 'Status', 'Mollie-klant', 'Gemaskeerde rekening'], ',', '"', '');
        foreach ($this->p->store->rows('customers', $mode, 500) as $c) {
            $m = $c['mandate_id'] ? $this->p->store->find('mandates', 'remote_id', $c['mandate_id'], $mode) : null;
            fputcsv($stream, array_map([Money::class, 'csv'], [$mode, $c['name'], $c['email'], $c['status'], $c['remote_id'] ?? '', $m['masked'] ?? '']), ',', '"', '');
        }
        fclose($stream);
    }
}
