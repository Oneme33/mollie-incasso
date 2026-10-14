<?php

namespace Lukraak\MollieIncasso;

final class Frontend
{
    public function __construct(private readonly Store $store, private readonly Config $config, private readonly Schemes $schemes, private readonly Tokens $tokens, private readonly Queue $queue, private readonly Service $service)
    {
    }
    public function register(): void
    {
        add_shortcode('mollie_incasso', [$this, 'shortcode']);
        add_action('template_redirect', [$this, 'route']);
        add_action('admin_post_nopriv_mi_signup', [$this, 'signup']);
        add_action('admin_post_mi_signup', [$this, 'signup']);
        add_action('admin_post_nopriv_mi_customer_action', [$this, 'customerAction']);
        add_action('admin_post_mi_customer_action', [$this, 'customerAction']);
    }
    private function cookie(string $name, string $value, int $ttl): void
    {
        if (headers_sent()) {
            throw new Failure('cookies_unavailable');
        }
        setcookie($name, $value, ['expires' => time() + $ttl, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[$name] = $value;
    }
    private function binding(): string
    {
        return hash('sha256', (string) ($_COOKIE['mi_form'] ?? ''));
    }
    private function assets(): void
    {
        wp_enqueue_style('mollie-incasso', plugins_url('assets/style.css', MOLLIE_INCASSO_FILE), [], MOLLIE_INCASSO_VERSION);
    }
    public function route(): void
    {
        if (isset($_GET['mi_access']) || isset($_GET['mi_return']) || isset($_GET['mi_customer'])) {
            $this->privatePage();
            exit;
        }
        // Fallback for builders/templates where the shortcode is not in post_content: set the cookie, then return.
        if (isset($_GET['mi_form_init'])) {
            nocache_headers();
            if (!preg_match('/^[a-f0-9]{64}$/D', (string) ($_COOKIE['mi_form'] ?? ''))) {
                $this->cookie('mi_form', bin2hex(random_bytes(32)), DAY_IN_SECONDS);
            }
            wp_safe_redirect(remove_query_arg('mi_form_init'), 303);
            exit;
        }
        global $post;
        if (is_singular() && $post && has_shortcode($post->post_content, 'mollie_incasso')) {
            if (!preg_match('/^[a-f0-9]{64}$/D', (string) ($_COOKIE['mi_form'] ?? ''))) {
                $this->cookie('mi_form', bin2hex(random_bytes(32)), DAY_IN_SECONDS);
            }
            global $post;
            if ($post && has_shortcode($post->post_content, 'mollie_incasso')) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                nocache_headers();
                $this->assets();
            }
        }
    }
    public function shortcode(array $atts = []): string
    {
        $this->assets();
        $mode = $this->config->mode();
        $atts = shortcode_atts(['regeling' => ''], $atts, 'mollie_incasso');
        try {
            $this->config->assertRun($mode);
            $this->config->key($mode);
            $this->config->webhook($mode);
            $settings = $this->config->settings();
            if (!$settings['organization'] || !$settings['contact']) {
                throw new Failure('organization_missing');
            }
            $id = (int) $atts['regeling'];
            if (!$id) {
                $rows = $this->store->select('SELECT id FROM ' . $this->store->table('schemes') . ' WHERE mode=%s AND active=1 ORDER BY id DESC LIMIT 1', [$mode]);
                $id = (int) ($rows[0]['id'] ?? 0);
            }
            $scheme = $this->schemes->get($id, $mode);
            if (empty($_COOKIE['mi_form']) && !headers_sent()) {
                $this->cookie('mi_form', bin2hex(random_bytes(32)), DAY_IN_SECONDS);
            }
            if (empty($_COOKIE['mi_form'])) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                return '<div class="mi-panel"><h2>' . esc_html($scheme['name']) . '</h2><p>' . esc_html__('Open het beveiligde aanmeldformulier om toestemming te geven.', 'mollie-incasso') . '</p><p><a class="mi-button" rel="nofollow" href="' . esc_url(add_query_arg('mi_form_init', '1')) . '">' . esc_html__('Aanmeldformulier openen', 'mollie-incasso') . '</a></p></div>';
            }
            $this->store->limit('form:' . $this->config->clientIp(), 60, 600);
            $token = $this->tokens->issue($mode, 'signup', 0, ['scheme_id' => $id, 'disclosure' => $this->schemes->disclosure($scheme)], $this->binding(), HOUR_IN_SECONDS);
            $uid = 'mi-' . substr(hash('sha256', $token), 0, 10);
        } catch (Failure $e) {
            return '<div class="mi-panel" role="status">' . esc_html(__('Aanmelden is nog niet beschikbaar. Neem contact op met de organisatie.', 'mollie-incasso')) . '</div>';
        }
        ob_start();
        ?>
        <div class="mi-panel">
            <?php
        if ($mode === 'test') {
            ?><p class="mi-notice"><?php
            esc_html_e('Testmodus: er worden geen echte incasso’s uitgevoerd.', 'mollie-incasso');
            ?></p><?php
        }
        ?>
            <header class="mi-heading"><p class="mi-eyebrow"><?php
        echo esc_html($settings['organization']);
        ?></p><h2><?php
        echo esc_html($scheme['name']);
        ?></h2><p class="mi-lead"><?php
        esc_html_e('Eenmalig toestemming geven. Daarna alles op één plek beheren.', 'mollie-incasso');
        ?></p></header>
            <ol class="mi-steps" aria-label="<?php
        esc_attr_e('Stappen van uw aanmelding', 'mollie-incasso');
        ?>"><li class="mi-step-current"><span>1</span><?php
        esc_html_e('Uw gegevens', 'mollie-incasso');
        ?></li><li><span>2</span><?php
        esc_html_e('Verifiëren via iDEAL', 'mollie-incasso');
        ?></li><li><span>3</span><?php
        esc_html_e('Toestemming actief', 'mollie-incasso');
        ?></li></ol>
            <h3 class="mi-section-title"><?php
        esc_html_e('Uw incassoafspraak', 'mollie-incasso');
        ?></h3>
            <div class="mi-disclosure"><?php
        echo nl2br(esc_html($this->schemes->disclosure($scheme)));
        ?></div>
            <form action="<?php
        echo esc_url(admin_url('admin-post.php'));
        ?>" method="post" class="mi-form">
                <input type="hidden" name="action" value="mi_signup"><input type="hidden" name="mode" value="<?php
        echo esc_attr($mode);
        ?>"><input type="hidden" name="form_token" value="<?php
        echo esc_attr($token);
        ?>">
                <?php
        wp_nonce_field('mi_signup', 'mi_nonce');
        ?>
                <div class="mi-fields"><div class="mi-field"><label for="<?php
        echo esc_attr($uid);
        ?>-name"><?php
        esc_html_e('Naam', 'mollie-incasso');
        ?></label><input id="<?php
        echo esc_attr($uid);
        ?>-name" name="name" maxlength="190" autocomplete="name" required></div>
                <div class="mi-field"><label for="<?php
        echo esc_attr($uid);
        ?>-email"><?php
        esc_html_e('E-mailadres', 'mollie-incasso');
        ?></label><input id="<?php
        echo esc_attr($uid);
        ?>-email" type="email" name="email" maxlength="190" autocomplete="email" required></div></div>
                <div class="mi-honey" aria-hidden="true"><label><?php
        esc_html_e('Laat dit veld leeg', 'mollie-incasso');
        ?><input name="website" tabindex="-1" autocomplete="off"></label></div>
                <label class="mi-consent"><input type="checkbox" name="consent" value="1" required> <span><?php
        echo esc_html($scheme['consent']);
        ?> <?php
        esc_html_e('Ik heb de regeling en de voorwaarden hierboven gelezen.', 'mollie-incasso');
        ?></span></label>
                <?php
        if ($settings['privacy_url']) {
            ?><p><a href="<?php
            echo esc_url($settings['privacy_url']);
            ?>"><?php
            esc_html_e('Privacyverklaring', 'mollie-incasso');
            ?></a></p><?php
        }
        ?>
                <button type="submit"><?php
        esc_html_e('Toestemming geven via iDEAL (€ 0,01)', 'mollie-incasso');
        ?></button>
                <p class="mi-muted"><?php
        esc_html_e('U wordt doorgestuurd naar Mollie. Toestemming wordt pas actief na een gecontroleerde betaling en een geldige machtiging.', 'mollie-incasso');
        ?></p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    private function field(array $data, string $key, int $max = 1000): string
    {
        $value = $data[$key] ?? '';
        return is_scalar($value) ? substr(wp_unslash((string) $value), 0, $max) : '';
    }
    public function signup(): void
    {
        $mode = $this->field($_POST, 'mode', 4);
        $raw = $this->field($_POST, 'form_token', 64);
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($this->field($_POST, 'mi_nonce'), 'mi_signup') || $this->field($_POST, 'website') !== '') {
                throw new Failure('csrf_failed');
            }
            $this->store->limit('signup:' . $this->config->clientIp(), 10, 600);
            $this->tokens->resolve($mode, $raw, 'signup', $this->binding());
            $result = $this->service->signup($mode, $raw, $this->binding(), $this->field($_POST, 'name', 190), $this->field($_POST, 'email', 190), $this->field($_POST, 'consent') === '1', get_current_user_id());
            $this->accessCookie((int) $result['customer']['id'], $mode);
            if (!empty($result['payment']['checkout_url']) && in_array($result['payment']['status'], ['open', 'pending'], true)) {
                wp_redirect($result['payment']['checkout_url'], 303);
                exit;
            }
            wp_safe_redirect(add_query_arg(['mi_return' => $result['customer']['reference'], 'mi_mode' => $mode], home_url('/')), 303);
            exit;
        } catch (Failure $e) {
            if ($e->temporary || $e->unknown) {
                try {
                    $saved = $this->tokens->resolve($mode, $raw, 'signup', $this->binding());
                    if ($saved['customer_id']) {
                        $customer = $this->service->customer((int) $saved['customer_id'], $mode);
                        $this->accessCookie((int) $customer['id'], $mode);
                        wp_safe_redirect(add_query_arg(['mi_return' => $customer['reference'], 'mi_mode' => $mode], home_url('/')), 303);
                        exit;
                    }
                } catch (Failure $ignored) {
                }
            }
            wp_die(esc_html(Plugin::message($e->reason)), esc_html__('Mollie Incasso', 'mollie-incasso'), ['response' => $e->reason === 'rate_limited' ? 429 : 400]);
        }
    }
    private function accessCookie(int $customer, string $mode): void
    {
        $raw = $this->tokens->issue($mode, 'access', $customer);
        $this->cookie('mi_access_' . $mode, $raw, 7 * DAY_IN_SECONDS);
    }
    public function authorize(array $data): array
    {
        $mode = $this->field($data, 'mi_mode', 4);
        $this->config->assertRun($mode);
        $raw = $this->field($data, 'mi_access', 64) ?: $this->field($_COOKIE, 'mi_access_' . $mode, 64);
        $token = null;
        if ($raw) {
            $token = $this->tokens->resolve($mode, $raw, 'access');
            $customer = $this->service->customer((int) $token['customer_id'], $mode);
        } else {
            $customer = $this->service->customer((int) $this->field($data, 'mi_customer', 20), $mode);
            if (!$customer['user_id'] || !get_current_user_id() || $customer['user_id'] != get_current_user_id()) {
                throw new Failure('access_denied');
            }
        }
        if (get_current_user_id() && $customer['user_id'] && $customer['user_id'] != get_current_user_id()) {
            throw new Failure('access_denied');
        }
        $reference = $this->field($data, 'mi_return', 64);
        if ($reference && !hash_equals($customer['reference'], $reference)) {
            throw new Failure('access_denied');
        }
        return ['mode' => $mode, 'customer' => $customer, 'raw' => $raw, 'token' => $token];
    }
    public function customerAction(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $this->field($_POST, 'confirm') !== '1') {
                throw new Failure('csrf_failed');
            }
            $auth = $this->authorize($_POST);
            $action = $this->field($_POST, 'mi_operation', 32);
            $id = (int) $this->field($_POST, 'subscription', 20);
            if (!in_array($action, ['withdraw', 'stop'], true)) {
                throw new Failure('invalid_command');
            }
            if ($auth['raw']) {
                $this->tokens->verifyCsrf($auth['raw'], $action . ':' . $id, $this->field($_POST, 'mi_csrf', 64));
            } else if (!wp_verify_nonce($this->field($_POST, 'mi_nonce'), 'mi_customer_' . $action . '_' . $id)) {
                throw new Failure('csrf_failed');
            }
            if ($action === 'withdraw') {
                $this->service->withdraw((int) $auth['customer']['id'], $auth['mode']);
            } else {
                $sub = $this->store->one('subscriptions', $id, $auth['mode']);
                if (!$sub || (int) $sub['customer_id'] !== (int) $auth['customer']['id']) {
                    throw new Failure('access_denied');
                }
                $this->service->stop($id, $auth['mode']);
            }
        } catch (Failure $e) {
            if (!in_array($e->reason, ['api_unavailable', 'api_rate_limited', 'cancellation_pending', 'shared_mandate_in_use', 'unknown_subscription_requires_review'], true)) {
                wp_die(esc_html(Plugin::message($e->reason)), esc_html__('Mollie Incasso', 'mollie-incasso'), ['response' => 403]);
            }
        }
        $args = ['mi_mode' => $auth['mode'], 'mi_customer' => $auth['customer']['id']];
        if ($auth['raw'] && $this->field($_COOKIE, 'mi_access_' . $auth['mode'], 64) !== $auth['raw']) {
            $args['mi_access'] = $auth['raw'];
        }
        wp_safe_redirect(add_query_arg($args, home_url('/')), 303);
        exit;
    }
    private function actionForm(array $auth, string $action, int $subscription = 0): void
    {
        ?>
        <form action="<?php
        echo esc_url(admin_url('admin-post.php'));
        ?>" method="post" class="mi-form">
            <input type="hidden" name="action" value="mi_customer_action"><input type="hidden" name="mi_operation" value="<?php
        echo esc_attr($action);
        ?>"><input type="hidden" name="mi_mode" value="<?php
        echo esc_attr($auth['mode']);
        ?>"><input type="hidden" name="mi_customer" value="<?php
        echo (int) $auth['customer']['id'];
        ?>"><input type="hidden" name="subscription" value="<?php
        echo $subscription;
        ?>">
            <?php
        if ($auth['raw']) {
            ?><input type="hidden" name="mi_access" value="<?php
            echo esc_attr($auth['raw']);
            ?>"><input type="hidden" name="mi_csrf" value="<?php
            echo esc_attr($this->tokens->csrf($auth['raw'], $action . ':' . $subscription));
            ?>"><?php
        } else {
            wp_nonce_field('mi_customer_' . $action . '_' . $subscription, 'mi_nonce');
        }
        ?>
            <label class="mi-consent"><input name="confirm" type="checkbox" value="1" required><span><?php
        echo esc_html($action === 'withdraw' ? __('Ik wil mijn toestemming intrekken en de bijbehorende abonnementen beëindigen. Reeds ingezonden incasso’s worden hiermee niet teruggedraaid.', 'mollie-incasso') : __('Ik wil dit abonnement beëindigen. Reeds ingezonden incasso’s worden hiermee niet teruggedraaid.', 'mollie-incasso'));
        ?></span></label>
            <button type="submit"><?php
        echo esc_html($action === 'withdraw' ? __('Toestemming intrekken', 'mollie-incasso') : __('Abonnement beëindigen', 'mollie-incasso'));
        ?></button>
        </form><?php
    }
    private function privatePage(): void
    {
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        try {
            $auth = $this->authorize($_GET);
            $this->store->limit('status:' . $auth['customer']['id'], 60, 600);
        } catch (Failure $e) {
            $message = isset($_GET['mi_clean']) ? __('Uw browser heeft de beveiligde cookie niet bewaard. Sta cookies voor deze website toe en open de link uit de e-mail opnieuw.', 'mollie-incasso') : __('Deze persoonlijke link is ongeldig, verlopen of hoort niet bij uw account. Vraag de organisatie om een nieuwe link.', 'mollie-incasso');
            wp_die(esc_html($message), esc_html__('Geen toegang', 'mollie-incasso'), ['response' => 403]);
        }
        // Move the mailed token out of the address bar (and server logs/history) into a private cookie.
        if (!empty($_GET['mi_access']) && !headers_sent()) {
            $this->cookie('mi_access_' . $auth['mode'], $auth['raw'], 7 * DAY_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['mi_customer' => (int) $auth['customer']['id'], 'mi_mode' => $auth['mode'], 'mi_clean' => 1], home_url('/')), 303);
            exit;
        }
        $customer = $auth['customer'];
        $mode = $auth['mode'];
        // Returning from iDEAL proves nothing, but it is the moment to ask Mollie server-side.
        if (in_array($customer['status'], ['pending', 'mandate_pending'], true)) {
            try {
                $this->store->limit('return-sync:' . $customer['id'], 10, 600);
                $this->service->verifiedFirstPayment((int) $customer['id'], $mode, true);
                $customer = $this->service->customer((int) $customer['id'], $mode);
            } catch (Failure $e) {
            }
        }
        $waiting = in_array($customer['status'], ['pending', 'mandate_pending', 'revoking', 'stop_pending'], true);
        $payments = $this->store->select('SELECT * FROM ' . $this->store->table('payments') . ' WHERE customer_id=%d AND mode=%s ORDER BY id DESC LIMIT 50', [(int) $customer['id'], $mode]);
        foreach ($payments as $p) {
            if (in_array($p['status'], ['open', 'pending'], true)) {
                $this->queue->put($mode, 'payment', 'payment:' . $p['remote_id'], ['id' => $p['remote_id']]);
            }
        }
        $subs = $this->store->select('SELECT * FROM ' . $this->store->table('subscriptions') . ' WHERE customer_id=%d AND mode=%s ORDER BY id DESC', [(int) $customer['id'], $mode]);
        ?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><?php
        if ($waiting) {
            ?><meta http-equiv="refresh" content="20"><?php
        }
        ?><title><?php
        esc_html_e('Uw incassostatus', 'mollie-incasso');
        ?></title><link rel="stylesheet" href="<?php
        echo esc_url(plugins_url('assets/style.css', MOLLIE_INCASSO_FILE));
        ?>"></head><body class="mi-private"><main class="mi-panel">
        <header class="mi-heading"><p class="mi-eyebrow"><?php
        echo esc_html($this->config->settings()['organization']);
        ?></p><h1><?php
        esc_html_e('Uw incassostatus', 'mollie-incasso');
        ?></h1></header><p><?php
        echo esc_html($customer['name']);
        ?> — <?php
        echo esc_html($customer['email']);
        ?></p>
        <div class="mi-status-block"><span class="mi-status-dot" aria-hidden="true"></span><p><?php
        echo esc_html(Plugin::status($customer['status']));
        ?></p></div>
        <?php
        if (in_array($customer['status'], ['pending', 'mandate_pending'], true)) {
            ?><p><?php
            esc_html_e('Wij controleren uw verificatie bij Mollie. Deze pagina ververst automatisch.', 'mollie-incasso');
            ?></p><?php
        }
        if (in_array($customer['status'], ['revoking', 'stop_pending'], true)) {
            ?><p><?php
            esc_html_e('Uw verzoek is ontvangen. Externe verwerking is nog niet voltooid; nieuwe lokale incasso’s zijn geblokkeerd.', 'mollie-incasso');
            ?></p><?php
        }
        ?>
        <h2><?php
        esc_html_e('Betalingen', 'mollie-incasso');
        ?></h2><div class="mi-scroll"><table><thead><tr><th><?php
        esc_html_e('Datum', 'mollie-incasso');
        ?></th><th><?php
        esc_html_e('Soort', 'mollie-incasso');
        ?></th><th><?php
        esc_html_e('Bedrag', 'mollie-incasso');
        ?></th><th><?php
        esc_html_e('Status', 'mollie-incasso');
        ?></th><th><?php
        esc_html_e('Terugbetaald / stornering', 'mollie-incasso');
        ?></th></tr></thead><tbody>
        <?php
        foreach ($payments as $p) {
            ?><tr><td><?php
            echo esc_html(Notifications::date($p['created_at'], true));
            ?></td><td><?php
            echo esc_html(Plugin::kind($p['kind']));
            ?></td><td><?php
            echo esc_html(Money::eur((int) $p['amount']));
            ?></td><td><?php
            echo esc_html(Plugin::status($p['status']));
            ?></td><td><?php
            echo esc_html(Money::eur((int) $p['refunded']) . ' / ' . Money::eur((int) $p['charged_back']));
            ?></td></tr><?php
        }
        ?></tbody></table></div>
        <?php
        foreach ($payments as $p) {
            if ($p['kind'] === 'first' && $p['checkout_url'] && $p['status'] === 'open') {
                ?><p><a class="mi-button" href="<?php
                echo esc_url($p['checkout_url']);
                ?>"><?php
                esc_html_e('Verificatiebetaling afronden', 'mollie-incasso');
                ?></a></p><?php
            }
        }
        ?>
        <?php
        if ($subs) {
            ?><h2><?php
            esc_html_e('Abonnementen', 'mollie-incasso');
            ?></h2><?php
            foreach ($subs as $s) {
                ?><div class="mi-card"><p><?php
                echo esc_html(Money::eur((int) $s['amount']) . ' / ' . Plugin::interval($s['interval_value']) . ' — ' . Plugin::status($s['status']));
                ?></p><?php
                if (!in_array($s['status'], ['canceled', 'completed'], true)) {
                    $this->actionForm($auth, 'stop', (int) $s['id']);
                }
                ?></div><?php
            }
        }
        ?>
        <?php
        if ($customer['status'] !== 'revoked') {
            ?><h2><?php
            esc_html_e('Toestemming intrekken', 'mollie-incasso');
            ?></h2><?php
            $this->actionForm($auth, 'withdraw');
        }
        ?>
        <p class="mi-muted"><?php
        esc_html_e('Terugkeren van de betaalpagina is geen betaalbewijs. De status hierboven volgt uit servercontroles bij Mollie. Mailverzending is geen garantie op aflevering.', 'mollie-incasso');
        ?></p></main></body></html><?php
    }
}
