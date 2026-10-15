<?php

// Run against a disposable WordPress test database. No real Mollie requests or emails.
define('WP_INSTALLING', true);
define('MOLLIE_INCASSO_TEST_KEY', 'test_MOCKKEYONLY000000000000000000000000');
require getenv('MI_WP_ROOT') ?: '/var/www/html/wp-load.php';
require dirname(__DIR__) . '/mollie-incasso.php';
require __DIR__ . '/FakeGateway.php';
require __DIR__ . '/FixtureOptions.php';
use Lukraak\MollieIncasso\Tests\FixtureOptions;
FixtureOptions::start();
use Lukraak\MollieIncasso\{Plugin, Store, Config, Schemes, Tokens, Queue, Notifications, Service, Frontend, Failure, Privacy, Money};
use Lukraak\MollieIncasso\Tests\FakeGateway;
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo 'PASS ' . $message . "\n";
}
function rejects(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (Failure $e) {
        check($e->reason === $reason, 'blocked: ' . $reason);
        return;
    }
    throw new RuntimeException('Expected ' . $reason);
}
if ((int) $wpdb->get_var("SELECT GET_LOCK('mi_fixture_options',30)") !== 1) {
    throw new RuntimeException('Another fixture is running.');
}
$optionNames = ['mi_settings', 'mi_instance', 'mi_site_binding', 'mi_schema'];
$saved = [];
foreach ($optionNames as $name) {
    $saved[$name] = get_option($name, null);
}
$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$db->set_prefix('mi_test_' . bin2hex(random_bytes(3)) . '_');
$store = new Store($db);
$config = new Config();
$store->install();
FixtureOptions::set('mi_settings', ['mode' => 'test', 'organization' => 'Testorganisatie', 'address' => 'Testadres', 'contact' => 'test@example.invalid', 'webhook_url' => 'https://example.org/mi-webhook', 'retention_days' => 1], false);
FixtureOptions::set('mi_site_binding', $config->binding(), false);
FixtureOptions::set('mi_instance', wp_generate_uuid4(), false);
$fake = new FakeGateway();
$schemes = new Schemes($store, $config);
$tokens = new Tokens($store);
$queue = new Queue($store, $config);
$mail = new Notifications($store, $config, $tokens, $queue);
$service = new Service($store, $config, $schemes, $tokens, $queue, $mail, fn($mode) => $fake);
$p = new Plugin(fn($mode) => $fake);
$p->store = $store;
$p->config = $config;
$p->schemes = $schemes;
$p->tokens = $tokens;
$p->queue = $queue;
$p->mail = $mail;
$p->service = $service;
$mails = [];
add_filter('pre_wp_mail', function ($return, $args) use (&$mails) {
    $mails[] = $args;
    return true;
}, 10, 2);
function scheme(string $type): int
{
    global $schemes;
    return $schemes->save(['name' => 'Test ' . $type, 'type' => $type, 'amount' => '25.00', 'purpose' => 'Lidmaatschap', 'calculation' => 'Vast bedrag of afgesproken verbruik', 'frequency' => 'Volgens regeling', 'withdrawal' => 'Via uw persoonlijke link', 'consent' => 'Ik geef expliciet toestemming voor SEPA Direct Debit.', 'notice_days' => 1, 'notice_agreed' => 1, 'interval' => '1 month', 'times' => '3'], 'test');
}
function signup(int $scheme, string $email = 'customer@example.invalid'): array
{
    global $tokens, $service, $schemes;
    $raw = $tokens->issue('test', 'signup', 0, ['scheme_id' => $scheme, 'disclosure' => $schemes->disclosure($schemes->get($scheme, 'test'))], 'binding', 3600);
    $result = $service->signup('test', $raw, 'binding', 'Testklant', $email, true);
    $result['token'] = $raw;
    return $result;
}
function activate(array $result): array
{
    global $fake, $service;
    $payment = $result['payment']['remote_id'];
    $customer = $result['customer'];
    $fake->payments[$payment]['status'] = 'paid';
    $fake->mandateRows['mdt_' . $customer['id']] = ['id' => 'mdt_' . $customer['id'], 'customerId' => $customer['remote_id'], 'status' => 'valid', 'method' => 'directdebit', 'masked' => '•••• 1234'];
    $service->syncPayment($payment, 'test');
    $service->refreshMandate((int) $customer['id'], 'test');
    return $service->customer((int) $customer['id'], 'test');
}
function sendPre(int $command): void
{
    global $mail, $store;
    $row = $store->find('notifications', 'event_key', 'pre:command:' . $command, 'test');
    $mail->send((int) $row['id'], 'test');
}
try {
    $manual = scheme('manual');
    $fixed = scheme('fixed');
    $a = signup($manual);
    $cid = (int) $a['customer']['id'];
    $first = $fake->payments[$a['payment']['remote_id']];
    check($first['amount'] === ['currency' => 'EUR', 'value' => '0.01'] && $first['sequenceType'] === 'first' && $first['method'] === 'ideal', 'correct €0.01 first iDEAL payment');
    check($service->customer($cid, 'test')['status'] === 'pending', 'return before webhook does not activate consent');
    rejects(fn() => $service->refreshMandate($cid, 'test'), 'first_payment_not_paid');
    $again = $service->signup('test', $a['token'], 'binding', 'Testklant', 'customer@example.invalid', true);
    check($again['customer']['id'] === $a['customer']['id'] && $fake->calls['payment'] === 1, 'repeat signup produces one customer and first payment');
    $b = signup($manual);
    check($b['customer']['remote_id'] !== $a['customer']['remote_id'], 'same unverified email never inherits another customer or mandate');
    $fake->payments[$b['payment']['remote_id']]['status'] = 'failed';
    $service->syncPayment($b['payment']['remote_id'], 'test');
    rejects(fn() => $service->refreshMandate((int) $b['customer']['id'], 'test'), 'first_payment_not_paid');
    $active = activate($a);
    $mid = $active['mandate_id'];
    $fake->mandateRows[$mid]['status'] = 'pending';
    rejects(fn() => $service->refreshMandate($cid, 'test'), 'mandate_not_valid');
    $fake->mandateRows[$mid]['status'] = 'invalid';
    rejects(fn() => $service->refreshMandate($cid, 'test'), 'mandate_not_valid');
    $fake->mandateRows[$mid]['status'] = 'valid';
    $service->refreshMandate($cid, 'test');
    $consent = $store->find('notifications', 'event_key', 'consent:' . $cid, 'test');
    $mail->send((int) $consent['id'], 'test');
    $before = count($mails);
    $service->syncPayment($first['id'], 'test');
    $service->syncPayment($first['id'], 'test');
    $service->refreshMandate($cid, 'test');
    $mail->send((int) $consent['id'], 'test');
    check(count($mails) === $before, 'duplicate payment/mandate events send one consent confirmation');
    $date = wp_date('Y-m-d', time() + 2 * DAY_IN_SECONDS);
    $ref = wp_generate_uuid4();
    $command = $service->prepare($cid, 'test', 'manual', 1500, 'Bijdrage', $date, $ref);
    $again = $service->prepare($cid, 'test', 'manual', 1500, 'Bijdrage', $date, $ref);
    check($command['id'] === $again['id'], 'same business reference stores exactly one draft');
    rejects(fn() => $service->prepare($cid, 'test', 'manual', 1501, 'Bijdrage', $date, $ref), 'command_changed');
    $service->confirm((int) $command['id'], 'test');
    $service->confirm((int) $command['id'], 'test');
    rejects(fn() => $service->execute((int) $command['id'], 'test'), 'prenotification_not_sent');
    sendPre((int) $command['id']);
    $store->update('commands', ['due_at' => gmdate('Y-m-d H:i:s', time() - 1)], (int) $command['id'], 'test');
    $fake->timeout = true;
    rejects(fn() => $service->execute((int) $command['id'], 'test'), 'api_unavailable');
    $count = $fake->calls['payment'];
    $service->execute((int) $command['id'], 'test');
    $service->execute((int) $command['id'], 'test');
    check($fake->calls['payment'] === $count && $store->one('commands', (int) $command['id'], 'test')['state'] === 'completed', 'timeout after send reconciles, no duplicate charge');
    $payment = $store->select('SELECT * FROM ' . $store->table('payments') . ' WHERE command_id=%d', [(int) $command['id']])[0];
    $pid = $payment['remote_id'];
    $fake->payments[$pid]['status'] = 'failed';
    $fake->payments[$pid]['failure'] = 'MD01';
    $service->syncPayment($pid, 'test');
    check($store->find('payments', 'remote_id', $pid, 'test')['failure'] === 'MD01', 'failed debit and failure reason visible');
    $fake->payments[$pid]['status'] = 'paid';
    $fake->payments[$pid]['amountChargedBack']['value'] = '15.00';
    $service->syncPayment($pid, 'test');
    $financial = $store->find('payments', 'remote_id', $pid, 'test');
    check($financial['status'] === 'paid' && (int) $financial['charged_back'] === 1500, 'chargeback retained separately alongside paid status');
    $c = activate(signup($fixed));
    $subCommand = $service->prepare((int) $c['id'], 'test', 'subscription', 2500, 'Lidmaatschap', $date, wp_generate_uuid4());
    $service->confirm((int) $subCommand['id'], 'test');
    sendPre((int) $subCommand['id']);
    $service->execute((int) $subCommand['id'], 'test');
    $sub = $store->rows('subscriptions', 'test')[0];
    $fakeSub = $fake->subs[$sub['remote_id']];
    check($fakeSub['interval'] === '1 month', 'subscription created by Mollie gateway');
    check($fakeSub['startDate'] === $date && $fakeSub['times'] === 3, 'future first subscription date and optional maximum payments');
    rejects(fn()=>$service->prepare((int)$c['id'],'test','subscription',2500,'Dubbel abonnement',$date,wp_generate_uuid4()),'subscription_already_exists');
    $unknown = 'tr_subscription_generated';
    $fake->payments[$unknown] = ['id' => $unknown, 'mode' => 'test', 'status' => 'paid', 'customerId' => $c['remote_id'], 'subscriptionId' => $sub['remote_id'], 'amount' => Money::api(2500), 'sequenceType' => 'recurring', 'method' => 'directdebit', 'amountRefunded' => Money::api(0), 'amountChargedBack' => Money::api(0)];
    $service->syncPayment($unknown, 'test');
    check((int) $store->find('payments', 'remote_id', $unknown, 'test')['customer_id'] === (int) $c['id'], 'previously unknown subscription payment maps by customer and subscription');
    $fake->cancelFails = true;
    rejects(fn() => $service->stop((int) $sub['id'], 'test'), 'api_unavailable');
    check($service->customer((int) $c['id'], 'test')['status'] === 'stop_pending', 'external stop failure blocks new local debits');
    rejects(fn() => $service->prepare((int) $c['id'], 'test', 'subscription', 2500, 'Nieuwe termijn', $date, wp_generate_uuid4()), 'customer_not_active');
    $fake->cancelFails = false;
    $service->stop((int) $sub['id'], 'test');
    check($fake->subs[$sub['remote_id']]['status'] === 'canceled', 'stop completes at Mollie');
    $fake->revokeFails = true;
    rejects(fn() => $service->withdraw((int) $c['id'], 'test'), 'api_unavailable');
    check($service->customer((int) $c['id'], 'test')['status'] === 'revoking', 'failed external revoke remains incomplete');
    $fake->revokeFails = false;
    $service->finishWithdrawal((int) $c['id'], 'test');
    check($service->customer((int) $c['id'], 'test')['status'] === 'revoked', 'withdrawal cancels agreements and revokes mandate');
    rejects(fn() => $service->prepare((int) $c['id'], 'test', 'subscription', 2500, 'Nieuwe termijn', $date, wp_generate_uuid4()), 'consent_withdrawn');
    rejects(fn() => $service->stop((int) $sub['id'], 'test'), 'consent_withdrawn');
    check($service->customer((int) $c['id'], 'test')['status'] === 'revoked', 'stale stop cannot restore withdrawn consent');
    $late = signup($manual);
    $lateId = (int) $late['customer']['id'];
    $service->withdraw($lateId, 'test');
    check($service->customer($lateId, 'test')['status'] === 'revoked', 'withdraw before first payment settles');
    $fake->payments[$late['payment']['remote_id']]['status'] = 'paid';
    $lateMandate = 'mdt_' . $lateId;
    $fake->mandateRows[$lateMandate] = ['id' => $lateMandate, 'customerId' => $late['customer']['remote_id'], 'status' => 'valid', 'method' => 'directdebit'];
    $service->syncPayment($late['payment']['remote_id'], 'test');
    check($service->customer($lateId, 'test')['status'] === 'revoking', 'late paid verification schedules external withdrawal, never activation');
    $service->finishWithdrawal($lateId, 'test');
    check($fake->mandateRows[$lateMandate]['status'] === 'invalid', 'late mandate is revoked even when not previously stored locally');
    $shared = activate(signup($manual));
    $sharedId = (int) $shared['id'];
    $fake->subs['sub_foreign'] = ['id' => 'sub_foreign', 'customerId' => $shared['remote_id'], 'mandateId' => $shared['mandate_id'], 'status' => 'active'];
    rejects(fn() => $service->withdraw($sharedId, 'test'), 'shared_mandate_in_use');
    check($fake->mandateRows[$shared['mandate_id']]['status'] === 'valid' && $service->customer($sharedId, 'test')['status'] === 'revoking', 'shared mandate protected and new local debits blocked');
    $fake->subs['sub_foreign']['status'] = 'canceled';
    $service->finishWithdrawal($sharedId, 'test');
    $racing = activate(signup($fixed));
    $racingCommand = $service->prepare((int) $racing['id'], 'test', 'subscription', 2500, 'Race', $date, wp_generate_uuid4());
    $service->confirm((int) $racingCommand['id'], 'test');
    sendPre((int) $racingCommand['id']);
    $fake->timeout = true;
    rejects(fn() => $service->execute((int) $racingCommand['id'], 'test'), 'api_unavailable');
    $remoteSub = $fake->find('subscription', $racing['remote_id'], $racingCommand['reference'], (string) get_option('mi_instance'));
    $racePayment = 'tr_early_subscription';
    $fake->payments[$racePayment] = ['id' => $racePayment, 'mode' => 'test', 'status' => 'pending', 'customerId' => $racing['remote_id'], 'subscriptionId' => $remoteSub['id'], 'amount' => Money::api(2500), 'sequenceType' => 'recurring', 'method' => 'directdebit'];
    rejects(fn() => $service->syncPayment($racePayment, 'test'), 'subscription_not_yet_stored');
    $calls = $fake->calls['subscription'];
    $service->execute((int) $racingCommand['id'], 'test');
    $service->syncPayment($racePayment, 'test');
    check($fake->calls['subscription'] === $calls && $store->find('payments', 'remote_id', $racePayment, 'test') !== null, 'early subscription webhook retained and linked after reconciliation');
    $uncertain = $service->prepare($cid, 'test', 'manual', 100, 'Controle', $date, wp_generate_uuid4());
    $service->confirm((int) $uncertain['id'], 'test');
    sendPre((int) $uncertain['id']);
    $store->update('commands', ['due_at' => Store::now(), 'state' => 'unknown', 'attempted_at' => gmdate('Y-m-d H:i:s', time() - 4000)], (int) $uncertain['id'], 'test');
    $fake->hideFind = true;
    $count = $fake->calls['payment'];
    rejects(fn() => $service->execute((int) $uncertain['id'], 'test'), 'unknown_requires_review');
    check($fake->calls['payment'] === $count, 'expired idempotency window never blindly resends');
    $fake->hideFind = false;
    $parallel = activate(signup($manual));
    $parallelId = (int) $parallel['id'];
    $parallelCommand = $service->prepare($parallelId, 'test', 'manual', 200, 'Concurrency', $date, wp_generate_uuid4());
    $service->confirm((int) $parallelCommand['id'], 'test');
    sendPre((int) $parallelCommand['id']);
    $store->update('commands', ['due_at' => Store::now()], (int) $parallelCommand['id'], 'test');
    $parallelDb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $parallelDb->set_prefix($db->prefix);
    $parallelStore = new Store($parallelDb);
    $parallelTokens = new Tokens($parallelStore);
    $parallelQueue = new Queue($parallelStore, $config);
    $parallelMail = new Notifications($parallelStore, $config, $parallelTokens, $parallelQueue);
    $parallelService = new Service($parallelStore, $config, new Schemes($parallelStore, $config), $parallelTokens, $parallelQueue, $parallelMail, fn($mode) => $fake);
    $fake->duringCreate = fn() => rejects(fn() => $parallelService->execute((int) $parallelCommand['id'], 'test'), 'busy');
    $calls = $fake->calls['payment'];
    $service->execute((int) $parallelCommand['id'], 'test');
    $parallelService->execute((int) $parallelCommand['id'], 'test');
    check($fake->calls['payment'] === $calls + 1, 'overlapping command execution across connections creates one debit');
    $db2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $db2->set_prefix($db->prefix);
    $other = new Store($db2);
    $store->lock('parallel-test', fn() => rejects(fn() => $other->lock('parallel-test', fn() => null), 'busy'));
    check(true, 'database lock excludes overlapping connections');
    $queue->put('test', 'generation-test', 'test:generation', []);
    $queue->work(function ($kind, $data, $mode) use ($queue) {
        if ($kind === 'generation-test') {
            $queue->put($mode, $kind, 'test:generation', []);
        }
    });
    check($store->find('jobs', 'dedup', 'test:generation', 'test')['state'] === 'pending', 'webhook arriving during worker is retained for next generation');
    $request = new WP_REST_Request('POST', '/mollie-incasso/v1/payment/test');
    $request->set_param('mode', 'test');
    $request->set_param('id', $pid);
    check($p->webhook($request)->get_status() === 200 && $p->webhook($request)->get_status() === 200, 'webhook acknowledges only after durable deduplicated storage');
    $row = $store->find('jobs', 'dedup', 'payment:' . $pid, 'test');
    check($row !== null, 'webhook is persisted');
    $raw = $tokens->issue('test', 'access', $cid);
    check($store->find('tokens', 'token_hash', hash('sha256', $raw), 'test')['token_hash'] !== $raw, 'tokens are unpredictable and hashed in storage');
    rejects(fn() => $tokens->resolve('live', $raw, 'access'), 'access_denied');
    rejects(fn() => $tokens->resolve('test', $tokens->issue('test', 'access', $cid, [], '', -1), 'access'), 'access_denied');
    rejects(fn() => $tokens->verifyCsrf($raw, 'withdraw:0', 'bad'), 'csrf_failed');
    $tokens->verifyCsrf($raw, 'withdraw:0', $tokens->csrf($raw, 'withdraw:0'));
    rejects(fn() => $config->assertRun('live', true), 'environment_mismatch');
    $front = new Frontend($store, $config, $schemes, $tokens, $queue, $service);
    rejects(fn() => $front->authorize(['mi_mode' => 'test', 'mi_customer' => $cid]), 'access_denied');
    check((int) $front->authorize(['mi_mode' => 'test', 'mi_access' => $raw])['customer']['id'] === $cid, 'private link reads only its own customer');
    rejects(fn() => $front->authorize(['mi_mode' => 'test', 'mi_access' => $raw, 'mi_return' => $b['customer']['reference']]), 'access_denied');
    check(Money::csv('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")", 'CSV neutralizes spreadsheet formulas');
    $settings = $config->settings();
    $originalBinding = get_option('mi_site_binding');
    FixtureOptions::set('mi_site_binding', 'wrong-copy', false);
    rejects(fn() => $config->assertRun('test'), 'site_copy_blocked');
    FixtureOptions::set('mi_site_binding', $originalBinding, false);
    $store->update('commands', ['key_fingerprint' => str_repeat('0', 64), 'attempted_at' => Store::now()], (int) $uncertain['id'], 'test');
    $fake->hideFind = true;
    rejects(fn() => $service->execute((int) $uncertain['id'], 'test'), 'unknown_requires_review');
    $fake->hideFind = false;
    rejects(fn() => $service->signup('test', $a['token'], 'binding', 'Testklant', 'customer@example.invalid', false), 'invalid_signup');
    rejects(fn() => $service->prepare($parallelId, 'test', 'manual', 2600, 'Over maximum', $date, wp_generate_uuid4()), 'amount_outside_consent');
    $die = function () {
        return function ($message, $title, $args) {
            throw new RuntimeException('wpdie', is_array($args) ? $args['response'] ?? 500 : 500);
        };
    };
    add_filter('wp_die_handler', $die);
    $oldPost = $_POST;
    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldUser = get_current_user_id();
    $blocked = function (callable $callback, int $code, string $message) {
        try {
            $callback();
        } catch (RuntimeException $e) {
            check($e->getMessage() === 'wpdie' && $e->getCode() === $code, $message);
            return;
        }
        throw new RuntimeException('Expected HTTP denial');
    };
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        wp_set_current_user(0);
        $admin = new \Lukraak\MollieIncasso\Admin($p);
        $blocked(fn() => $admin->action(), 403, 'admin action rejects unauthenticated POST');
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        wp_set_current_user($admins[0]->ID);
        $_POST = ['operation' => 'export', 'mode' => 'test', 'mi_nonce' => 'bad'];
        $blocked(fn() => $admin->action(), 403, 'admin action rejects invalid nonce');
        $_POST = ['mode' => 'test', 'mi_nonce' => 'bad', 'form_token' => $a['token']];
        $blocked(fn() => $front->signup(), 400, 'signup rejects CSRF before API work');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $blocked(fn() => $front->customerAction(), 403, 'GET never withdraws consent');
    } finally {
        $_POST = $oldPost;
        if ($oldMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $oldMethod;
        }
        wp_set_current_user($oldUser);
        remove_filter('wp_die_handler', $die);
    }
    $privacy = new Privacy($p);
    check(count($privacy->export('customer@example.invalid')['data']) >= 3, 'WordPress privacy export includes linked customers and financial records');
    $erased = $privacy->erase('customer@example.invalid');
    check($erased['items_retained'] === true, 'privacy erasure retains active or in-retention records');
    $_COOKIE['mi_form'] = str_repeat('a', 64);
    $html = $front->shortcode(['regeling' => $manual]);
    check(str_contains($html, 'name="consent" value="1" required') && !str_contains($html, 'checked'), 'accessible form consent is explicit and unchecked');
    file_put_contents(sys_get_temp_dir() . '/mi-preview.html', '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="http://localhost:8008/wp-content/plugins/mollie-incasso/assets/style.css"></head><body class="mi-private">' . $html . '</body></html>');
    $oldUser = get_current_user_id();
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    wp_set_current_user($admins[0]->ID);
    $oldGet = $_GET;
    try {
        foreach (['overview', 'customers', 'commands', 'subscriptions', 'settings', 'setup', 'diagnostics'] as $view) {
            $_GET = ['page' => 'mollie-incasso-' . $view];
            ob_start();
            (new \Lukraak\MollieIncasso\Admin($p))->render();
            $adminHtml = ob_get_clean();
            check(substr_count($adminHtml, 'aria-current="page"') === 1 && str_contains($adminHtml, 'mi-admin-sidebar') && str_contains($adminHtml, 'assets/logo.svg'), 'admin ' . $view . ': sidebar, active page and logo');
            file_put_contents(sys_get_temp_dir() . '/mi-admin-' . $view . '.html', '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="http://localhost:8008/wp-admin/css/common.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-admin/css/forms.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-includes/css/buttons.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-content/plugins/mollie-incasso/assets/admin.css"><style>body.wp-admin{margin:0;padding:24px;background:#f0f0f1}.mi-admin{margin:0}@media(max-width:782px){body.wp-admin{padding:12px}}</style></head><body class="wp-admin">' . $adminHtml . '</body></html>');
        }
    } finally {
        $_GET = $oldGet;
        wp_set_current_user($oldUser);
    }
    $_GET = ['page' => 'mollie-incasso'];
    wp_set_current_user($admins[0]->ID);
    ob_start();
    (new \Lukraak\MollieIncasso\Admin($p))->render();
    $home = ob_get_clean();
    check(str_contains($home, 'mi-metrics') && !str_contains($home, 'mi-command-form'), 'main menu opens overview distinct from customers');
    check(!str_contains($home, 'mi-mode--'), 'no test badge in overview header');
    wp_set_current_user($oldUser);
    $_GET = $oldGet;
    wp_set_current_user($admins[0]->ID);
    $admin = new \Lukraak\MollieIncasso\Admin($p);
    $admin->register();
    $_GET = ['page' => 'mollie-incasso'];
    check(apply_filters('action_scheduler_check_pastdue_actions', true) === false, 'Action Scheduler notice suppressed only on incasso screens');
    $_GET = ['page' => 'wc-admin'];
    check(apply_filters('action_scheduler_check_pastdue_actions', true) === true, 'WooCommerce retains its Action Scheduler warnings');
    for ($step = 1; $step <= 4; $step++) {
        $_GET = ['page' => 'mollie-incasso-setup', 'step' => $step];
        ob_start();
        $admin->render();
        $wizard = ob_get_clean();
        check(str_contains($wizard, 'mi-wizard-steps') && str_contains($wizard, 'Installatiehulp verlaten') && substr_count($wizard, 'aria-current="step"') === 1, 'optional wizard step ' . $step . ' renders without mandatory flow');
        file_put_contents(sys_get_temp_dir() . '/mi-wizard-' . $step . '.html', '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="http://localhost:8008/wp-admin/css/common.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-admin/css/forms.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-includes/css/buttons.min.css"><link rel="stylesheet" href="http://localhost:8008/wp-content/plugins/mollie-incasso/assets/admin.css"><style>body.wp-admin{margin:0;padding:24px;background:#f0f0f1}.mi-admin{margin:0}@media(max-width:782px){body.wp-admin{padding:12px}}</style></head><body class="wp-admin">' . $wizard . '</body></html>');
    }
    $draft = 0;
    FixtureOptions::scope('mi_setup_page_test_' . $fixed, 0);
    try {
        $method = new ReflectionMethod($admin, 'draftPage');
        $draft = $method->invoke($admin, $schemes->get($fixed, 'test'), 'test');
        $again = $method->invoke($admin, $schemes->get($fixed, 'test'), 'test');
        check($draft === $again && get_post_status($draft) === 'draft', 'repeated wizard click creates one unpublished draft page');
        check(str_contains(get_post($draft)->post_content, 'regeling="' . $fixed . '"'), 'draft page contains server-selected scheme shortcode');
    } finally {
        if ($draft) {
            wp_delete_post($draft, true);
        }
    }
    wp_set_current_user($oldUser);
    $_GET = $oldGet;
    $before = $store->one('customers', $cid, 'test');
    FixtureOptions::set('mi_schema', 1);
    $store->query('ALTER TABLE ' . $store->table('customers') . ' MODIFY remote_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');
    $store->install();
    check($before === $store->one('customers', $cid, 'test') && (int) get_option('mi_schema') === Store::SCHEMA_VERSION, 'schema migration preserves existing customer data');
    $caseIds = [];
    foreach (['cst_CaseSensitive', 'cst_casesensitive'] as $remote) {
        $caseIds[] = $store->insert('customers', ['mode' => 'test', 'reference' => wp_generate_uuid4(), 'remote_id' => $remote, 'name' => 'Case fixture', 'email' => 'case@example.invalid', 'scheme_id' => $manual, 'created_at' => Store::now(), 'updated_at' => Store::now()]);
    }
    check(count($caseIds) === 2 && $store->find('customers', 'remote_id', 'cst_CaseSensitive', 'test')['id'] !== $store->find('customers', 'remote_id', 'cst_casesensitive', 'test')['id'], 'case-sensitive provider IDs stay distinct');
    $schemeInput=$schemes->get($manual,'test');$schemeInput['amount']='25.00';$schemeInput['times']='0';rejects(fn()=>$schemes->save($schemeInput,'test'),'invalid_scheme');$schemeInput['times']='3abc';rejects(fn()=>$schemes->save($schemeInput,'test'),'invalid_scheme');
    // 1.0 reliability: queue priority, polling that never resurrects failures, and bounded maintenance.
    $jobs = $store->table('jobs');
    $store->query('DELETE FROM ' . $jobs);
    $queue->put('test', 'mandate', 'test:background', [], gmdate('Y-m-d H:i:s', time() - 600), false);
    $queue->put('test', 'mail', 'test:urgent', [], null);
    $order = [];
    $queue->work(function ($kind) use (&$order) {
        $order[] = $kind;
    });
    check($order === ['mail', 'mandate'], 'urgent jobs run before older background polling');
    $queue->put('test', 'mandate', 'test:failed', []);
    $store->query('UPDATE ' . $jobs . ' SET state="failed",attempts=8 WHERE dedup="test:failed"');
    $queue->put('test', 'mandate', 'test:failed', [], null, false);
    check($store->find('jobs', 'dedup', 'test:failed', 'test')['state'] === 'failed', 'periodic polling keeps a failed job visible instead of silently resetting it');
    $queue->put('test', 'mandate', 'test:failed', []);
    $revived = $store->find('jobs', 'dedup', 'test:failed', 'test');
    check($revived['state'] === 'pending' && (int) $revived['attempts'] === 0, 'a new event revives a failed job');
    $store->query('DELETE FROM ' . $jobs);
    $many = 0;
    for ($i = 0; $i < 40; $i++) {
        $queue->put('test', 'bulk', 'test:bulk:' . $i, []);
    }
    $many = $queue->work(fn() => null);
    check($many === 40 && !$store->select('SELECT id FROM ' . $jobs . ' WHERE state="pending"'), 'one run keeps working through more than one batch within its budget');
    $fresh = activate(signup($manual, 'fresh@example.invalid'));
    $freshId = (int) $fresh['id'];
    $abandoned = signup($manual, 'abandoned@example.invalid');
    $abandonedId = (int) $abandoned['customer']['id'];
    $fake->payments[$abandoned['payment']['remote_id']]['status'] = 'expired';
    $service->syncPayment($abandoned['payment']['remote_id'], 'test');
    $open = signup($manual, 'open@example.invalid');
    $openId = (int) $open['customer']['id'];
    $store->query('DELETE FROM ' . $jobs);
    $service->maintenance(($freshId + 1) % 24);
    check(!$store->find('jobs', 'dedup', 'mandate:' . $freshId, 'test'), 'active customers are not mandate-polled every hour');
    $service->maintenance($freshId % 24);
    check($store->find('jobs', 'dedup', 'mandate:' . $freshId, 'test') !== null, 'active customer mandate is checked once a day in its own hour');
    $polled = array_column($store->select('SELECT dedup FROM ' . $jobs), 'dedup');
    check(!array_filter($polled, fn($d) => in_array($d, ['mandate:' . $abandonedId, 'mandate:' . $openId], true)), 'abandoned or unpaid signups are never mandate-polled');
    check(in_array('payment:' . $open['payment']['remote_id'], $polled, true), 'open verification payments are still checked');
    $store->query('DELETE FROM ' . $jobs);
    $bulkCustomers = $store->table('customers');
    for ($i = 0; $i < 240; $i++) {
        $store->insert('customers', ['mode' => 'test', 'reference' => wp_generate_uuid4(), 'remote_id' => 'cst_load_' . $i, 'name' => 'Load', 'email' => 'load@example.invalid', 'scheme_id' => $manual, 'status' => $i < 200 ? 'active' : 'verification_failed', 'created_at' => Store::now(), 'updated_at' => Store::now()]);
    }
    $service->maintenance(5);
    $mandateJobs = (int) $store->select('SELECT COUNT(*) AS n FROM ' . $jobs . ' WHERE kind="mandate"')[0]['n'];
    check($mandateJobs > 0 && $mandateJobs <= 20, 'maintenance for 200+ customers enqueues only one day-slice of mandate checks (' . $mandateJobs . ')');
    $store->query('DELETE FROM ' . $bulkCustomers . ' WHERE email="load@example.invalid"');
    $store->query('DELETE FROM ' . $jobs);
    $service->refreshMandate($freshId, 'test');
    check(!$store->find('jobs', 'dedup', 'checkout:' . $freshId, 'test') && !$store->find('jobs', 'dedup', 'auto-subscription:' . $freshId, 'test'), 'routine mandate check queues no follow-up work that is already done');
    // Stop completes immediately when Mollie confirms, without leaving the customer blocked.
    $stopper = activate(signup($fixed, 'stopper@example.invalid'));
    $stopCommand = $service->prepare((int) $stopper['id'], 'test', 'subscription', 2500, 'Stoppen', $date, wp_generate_uuid4());
    $service->confirm((int) $stopCommand['id'], 'test');
    sendPre((int) $stopCommand['id']);
    $service->execute((int) $stopCommand['id'], 'test');
    $stopSub = $store->select('SELECT * FROM ' . $store->table('subscriptions') . ' WHERE customer_id=%d', [(int) $stopper['id']])[0];
    $service->stop((int) $stopSub['id'], 'test');
    check($service->customer((int) $stopper['id'], 'test')['status'] === 'active', 'successful stop does not leave the customer in stop_pending');
    // Subscription payments refresh the subscription so the next pre-notification is planned promptly.
    $store->query('DELETE FROM ' . $jobs);
    $subPay = 'tr_subscription_refresh';
    $fake->subs[$stopSub['remote_id']]['status'] = 'active';
    $store->update('subscriptions', ['status' => 'active'], (int) $stopSub['id'], 'test');
    $fake->payments[$subPay] = ['id' => $subPay, 'mode' => 'test', 'status' => 'pending', 'customerId' => $stopper['remote_id'], 'subscriptionId' => $stopSub['remote_id'], 'amount' => Money::api(2500), 'sequenceType' => 'recurring', 'method' => 'directdebit', 'amountRefunded' => Money::api(0), 'amountChargedBack' => Money::api(0)];
    $service->syncPayment($subPay, 'test');
    check($store->find('jobs', 'dedup', 'subscription:' . $stopSub['id'], 'test') !== null, 'subscription payment webhook schedules a subscription sync');
    // Mails with an unknown outcome can be resolved by an administrator.
    $mailId = $mail->schedule('test', $freshId, 'access', 'access:resolve-test');
    $store->update('notifications', ['state' => 'unknown', 'error' => 'mail_outcome_unknown'], $mailId, 'test');
    $mail->resolve($mailId, 'test', false);
    check($store->one('notifications', $mailId, 'test')['state'] === 'pending', 'admin can resend a mail with unknown outcome');
    $store->update('notifications', ['state' => 'unknown'], $mailId, 'test');
    $mail->resolve($mailId, 'test', true);
    check($mail->sent('test', 'access:resolve-test'), 'admin can record a verified mail as sent');
    rejects(fn() => $mail->resolve($mailId, 'test', true), 'mail_not_resolvable');
    // Failed collections and chargebacks alert the administrator without a customer access link.
    $alert = $store->select('SELECT * FROM ' . $store->table('notifications') . ' WHERE kind="admin_alert" AND event_key LIKE %s', ['admin:' . $pid . ':%']);
    check(count($alert) === 2, 'failed debit and chargeback each create one admin alert');
    $sentBefore = count($mails);
    $mail->send((int) $alert[0]['id'], 'test');
    $last = end($mails);
    check(count($mails) === $sentBefore + 1 && $last['to'] === get_option('admin_email') && !str_contains($last['message'], 'mi_access'), 'admin alert goes to the site admin without a personal link');
    check(!str_contains(Notifications::date('2026-10-28'), '2026-10-28') && str_contains(Notifications::date('2026-10-28'), '2026'), 'mails show readable dates');
    // A monthly plan cannot have a notice period longer than one term allows.
    $long = $schemes->get($fixed, 'test');
    $long['amount'] = '25.00';
    $long['notice_days'] = 28;
    $long['notice_agreed'] = 1;
    $long['interval'] = '1 month';
    rejects(fn() => $schemes->save($long, 'test'), 'notice_too_long');
    $long['interval'] = '12 months';
    check($schemes->save($long, 'test') > 0, 'yearly plan accepts a longer agreed notice period');
    // Returning from iDEAL checks Mollie server-side and activates without waiting for cron.
    $returning = signup($manual, 'return@example.invalid');
    $returnId = (int) $returning['customer']['id'];
    $fake->payments[$returning['payment']['remote_id']]['status'] = 'paid';
    $fake->mandateRows['mdt_return'] = ['id' => 'mdt_return', 'customerId' => $returning['customer']['remote_id'], 'status' => 'valid', 'method' => 'directdebit'];
    check($service->verifiedFirstPayment($returnId, 'test', true) && $service->customer($returnId, 'test')['status'] === 'active', 'return page verifies at Mollie and activates immediately');
    // Rate limits use the real visitor only behind explicitly trusted proxies.
    $server = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.4';
    putenv('MOLLIE_INCASSO_TRUSTED_PROXIES');
    check($config->clientIp() === '10.0.0.5', 'forwarded headers ignored without trusted proxies');
    putenv('MOLLIE_INCASSO_TRUSTED_PROXIES=10.0.0.0/8');
    check($config->clientIp() === '203.0.113.9', 'trusted proxy yields the visitor address');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9';
    check($config->clientIp() === '203.0.113.9', 'spoofed left-most forwarded address is ignored');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    check($config->clientIp() === '198.51.100.7', 'untrusted sender cannot choose its rate-limit bucket');
    check(Config::inRanges('2001:db8::1', ['2001:db8::/32']) && !Config::inRanges('2001:db9::1', ['2001:db8::/32']), 'IPv6 proxy ranges');
    // A trusted proxy that passes through a client-supplied CF-Connecting-IP must not let visitors pick a bucket.
    putenv('MOLLIE_INCASSO_TRUSTED_PROXIES=10.0.0.0/8');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.123';
    check($config->clientIp() === '203.0.113.9', 'unconfigured CF-Connecting-IP is ignored even behind a trusted proxy');
    putenv('MOLLIE_INCASSO_CLIENT_IP_HEADER=CF-Connecting-IP');
    check($config->clientIp() === '192.0.2.123', 'explicitly configured proxy header is used from a trusted proxy');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    check($config->clientIp() === '198.51.100.7', 'configured header is ignored from an untrusted sender');
    putenv('MOLLIE_INCASSO_CLIENT_IP_HEADER');
    putenv('MOLLIE_INCASSO_TRUSTED_PROXIES');
    $_SERVER = $server;
    // Verification state is tri-state: an unreachable Mollie is "unknown", never "unpaid".
    $outage = signup($manual, 'outage@example.invalid');
    $fake->payments[$outage['payment']['remote_id']]['status'] = 'paid';
    $fake->readFails = true;
    check($service->firstPaymentState((int) $outage['customer']['id'], 'test') === 'unknown', 'Mollie outage yields unknown verification state, not unpaid');
    $fake->readFails = false;
    check($service->firstPaymentState((int) $outage['customer']['id'], 'test') === 'paid', 'verification state is paid once Mollie answers');
    $orphanCustomer = $store->insert('customers', ['mode' => 'test', 'reference' => wp_generate_uuid4(), 'remote_id' => 'cst_orphan', 'name' => 'Orphan', 'email' => 'orphan@example.invalid', 'scheme_id' => $manual, 'created_at' => Store::now(), 'updated_at' => Store::now()]);
    $store->insert('commands', ['mode' => 'test', 'reference' => 'first:' . wp_generate_uuid4(), 'customer_id' => $orphanCustomer, 'kind' => 'first', 'amount' => 1, 'payload' => '{}', 'payload_hash' => str_repeat('0', 64), 'idempotency_key' => wp_generate_uuid4(), 'state' => 'unknown', 'due_at' => Store::now(), 'attempted_at' => Store::now(), 'created_at' => Store::now(), 'updated_at' => Store::now()]);
    check($service->firstPaymentState($orphanCustomer, 'test') === 'unknown', 'an attempted first payment without stored result is unknown, not unpaid');
    // Without the form cookie (page builders, templates) the shortcode offers a safe way to start.
    $savedCookie = $_COOKIE['mi_form'];
    unset($_COOKIE['mi_form']);
    $fallback = $front->shortcode(['regeling' => $manual]);
    check(str_contains($fallback, 'mi_form_init') && !str_contains($fallback, 'form_token'), 'shortcode without cookie shows a start button instead of an unusable form');
    $_COOKIE['mi_form'] = $savedCookie;
    // 0.3.1, found in the live WooCommerce test: rejected subscriptions are explained and can be retried.
    $retry = activate(signup($fixed, 'retry@example.invalid'));
    $retryCommand = $service->prepare((int) $retry['id'], 'test', 'subscription', 2500, 'Herstart', $date, wp_generate_uuid4());
    $service->confirm((int) $retryCommand['id'], 'test');
    sendPre((int) $retryCommand['id']);
    $fake->rejectSubscription = true;
    rejects(fn() => $service->execute((int) $retryCommand['id'], 'test'), 'mollie_rejected');
    $rejected = $store->one('commands', (int) $retryCommand['id'], 'test');
    check($rejected['state'] === 'failed' && str_contains($rejected['error_detail'], 'startDate'), 'rejected subscription stores Mollie\'s explanation');
    $fake->rejectSubscription = false;
    $again = $service->retrySubscription((int) $retryCommand['id'], 'test');
    check($again['id'] !== $rejected['id'] && $again['state'] === 'scheduled' && $store->find('notifications', 'event_key', 'pre:command:' . $again['id'], 'test') !== null, 'retry prepares a new subscription with its own pre-notification');
    sendPre((int) $again['id']);
    $service->execute((int) $again['id'], 'test');
    check($store->one('commands', (int) $again['id'], 'test')['state'] === 'completed', 'retried subscription is created at Mollie');
    $sentSub = array_values(array_filter($fake->subs, fn($row) => ($row['metadata']['mi_command'] ?? '') === $again['reference']))[0];
    check(!empty($sentSub['mandateId']) && !array_key_exists('method', $sentSub), 'subscription request sends the mandate without a method, as Mollie requires');
    rejects(fn() => $service->retrySubscription((int) $again['id'], 'test'), 'invalid_command');
    // A failed verification does not queue a mandate check that can only fail.
    $failedFirst = signup($manual, 'failedfirst@example.invalid');
    $store->query('DELETE FROM ' . $store->table('jobs'));
    $fake->payments[$failedFirst['payment']['remote_id']]['status'] = 'failed';
    $service->syncPayment($failedFirst['payment']['remote_id'], 'test');
    check(!$store->find('jobs', 'dedup', 'mandate:' . $failedFirst['customer']['id'], 'test'), 'failed verification queues no mandate check');
    // Texts: consent sentence shown once, readable frequency, Dutch dates while the texts are Dutch.
    $shownScheme = $schemes->get($fixed, 'test');
    check(str_contains($schemes->disclosure($shownScheme), $shownScheme['consent']) && !str_contains($schemes->displayText($shownScheme), $shownScheme['consent']), 'agreement shown without repeating the checkbox sentence; stored text keeps it');
    check(Plugin::frequency('1 month') === 'Maandelijks' && Plugin::frequency('12 months') === 'Jaarlijks', 'wizard plans describe the frequency readably');
    check(Notifications::date('2026-10-29') === '29 oktober 2026', 'dates use Dutch month names with the Dutch texts');
    // Other plugins' admin notices are hidden on our screens only.
    $foreign = static function () {
        echo 'foreign';
    };
    add_action('admin_notices', 'wp_admin_bar_render');
    add_action('admin_notices', $foreign);
    $savedGet = $_GET;
    $_GET = ['page' => 'wc-admin'];
    do_action('in_admin_header');
    check(has_action('admin_notices', $foreign) !== false, 'other screens keep all admin notices');
    $_GET = ['page' => 'mollie-incasso-diagnostics'];
    do_action('in_admin_header');
    check(has_action('admin_notices', $foreign) === false && has_action('admin_notices', 'wp_admin_bar_render') === false, 'plugin screens hide notices from other code');
    $own = false;
    foreach ($GLOBALS['wp_filter']['admin_notices']->callbacks as $callbacks) {
        foreach ($callbacks as $callback) {
            $own = $own || ($callback['function'] instanceof \Closure && str_contains((string) (new ReflectionFunction($callback['function']))->getFileName(), '/src/Admin.php'));
        }
    }
    check($own, 'plugin screens keep their own notices');
    $_GET = $savedGet;
    // Test-data reset: only test rows, never live ones, and refused outside test mode.
    $liveCustomer = $store->insert('customers', ['mode' => 'live', 'reference' => wp_generate_uuid4(), 'name' => 'Live', 'email' => 'live@example.invalid', 'scheme_id' => 1, 'status' => 'active', 'created_at' => Store::now(), 'updated_at' => Store::now()]);
    $liveSettings = $config->settings();
    $liveSettings['mode'] = 'live';
    FixtureOptions::set('mi_settings', $liveSettings, false);
    rejects(fn() => $service->wipeTestData(), 'environment_mismatch');
    $liveSettings['mode'] = 'test';
    FixtureOptions::set('mi_settings', $liveSettings, false);
    check($service->wipeTestData() > 0, 'test data reset removes test records');
    $left = 0;
    foreach (['customers', 'consents', 'schemes', 'mandates', 'commands', 'payments', 'subscriptions', 'tokens', 'jobs', 'notifications', 'checkouts'] as $table) {
        $left += (int) $store->select('SELECT COUNT(*) AS n FROM ' . $store->table($table) . ' WHERE mode="test"')[0]['n'];
    }
    check($left === 0 && $store->one('customers', $liveCustomer, 'live') !== null, 'no test rows remain and live rows are untouched');
    // 0.4: live is the default; the test phase is an opt-in that existing test sites keep.
    $standard = $config->settings();
    FixtureOptions::set('mi_settings', [], false);
    check($config->mode() === 'live' && !$config->testTools(), 'a fresh installation defaults to live with the test phase hidden');
    FixtureOptions::set('mi_settings', ['mode' => 'test', 'organization' => 'Oud'], false);
    check($config->mode() === 'test' && $config->testTools(), 'a site saved in test before 0.4 keeps its test phase');
    FixtureOptions::set('mi_settings', ['mode' => 'live'], false);
    check(!$config->testTools(), 'a site saved in live before 0.4 has no test phase');
    $realBinding = get_option('mi_site_binding');
    FixtureOptions::set('mi_settings', [], false);
    FixtureOptions::set('mi_site_binding', '', false);
    Plugin::bindSite($config);
    check(get_option('mi_site_binding') === $config->binding() && $config->mode() === 'live', 'first activation binds the site and keeps the live default');
    FixtureOptions::set('mi_site_binding', 'binding-of-another-site', false);
    Plugin::bindSite($config);
    check($config->mode() === 'test' && $config->testTools() && get_option('mi_site_binding') === $config->binding(), 'a copied site falls back to test mode with the test phase visible');
    FixtureOptions::set('mi_site_binding', $realBinding, false);
    $adminUser = get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
    $previousUser = get_current_user_id();
    wp_set_current_user($adminUser);
    $save = new ReflectionMethod(\Lukraak\MollieIncasso\Admin::class, 'saveSettings');
    $adminScreen = new \Lukraak\MollieIncasso\Admin($p);
    $savedPost = $_POST;
    $_POST = ['target_mode' => 'test', 'test_tools' => '1', 'organization' => 'Testorganisatie', 'contact' => 'test@example.invalid', 'retention_days' => '1', 'webhook_url' => 'https://example.org/mi-webhook'];
    $save->invoke($adminScreen);
    check($config->mode() === 'test' && $config->testTools(), 'enabling the test phase allows test mode');
    $_POST = ['target_mode' => 'test', 'organization' => 'Testorganisatie', 'contact' => 'test@example.invalid', 'retention_days' => '1', 'webhook_url' => 'https://example.org/mi-webhook'];
    rejects(fn() => $save->invoke($adminScreen), 'live_disabled');
    check($config->mode() === 'test' && $config->testTools(), 'turning the test phase off is refused as a whole while live is not released here');
    // Review of PR #3: the real live form sends the hidden target_mode=live together with the new checkbox.
    FixtureOptions::set('mi_settings', ['mode' => 'live', 'test_tools' => 0, 'organization' => 'Testorganisatie', 'contact' => 'test@example.invalid'], false);
    $_POST = ['target_mode' => 'live', 'test_tools' => '1', 'organization' => 'Testorganisatie', 'contact' => 'test@example.invalid', 'retention_days' => '1', 'webhook_url' => 'https://example.org/mi-webhook'];
    $save->invoke($adminScreen);
    check($config->mode() === 'test' && $config->testTools(), 'a fresh live installation can switch the test phase on from the real form');
    FixtureOptions::set('mi_settings', ['mode' => 'live', 'test_tools' => 0, 'organization' => 'Oud', 'contact' => 'test@example.invalid'], false);
    $_POST = ['target_mode' => 'live', 'organization' => 'Nieuw', 'contact' => 'test@example.invalid', 'retention_days' => '1', 'webhook_url' => 'https://example.org/mi-webhook'];
    $save->invoke($adminScreen);
    check($config->settings()['organization'] === 'Nieuw' && $config->mode() === 'live', 'a fresh live installation saves its settings before live is activated');
    $_POST = $savedPost;
    FixtureOptions::set('mi_settings', ['mode' => 'live', 'test_tools' => 0, 'organization' => 'Testorganisatie', 'contact' => 'test@example.invalid'], false);
    $renderGet = $_GET;
    $_GET = ['page' => 'mollie-incasso-settings'];
    ob_start();
    $adminScreen->render();
    $liveSettingsHtml = ob_get_clean();
    check(str_contains($liveSettingsHtml, 'mi-section') && str_contains($liveSettingsHtml, 'Geavanceerd') && !str_contains($liveSettingsHtml, 'name="key_test"') && !str_contains($liveSettingsHtml, '<select name="target_mode"'), 'live default hides test key and environment switch behind Geavanceerd');
    $_GET = ['page' => 'mollie-incasso-setup', 'step' => 2];
    ob_start();
    $adminScreen->render();
    $liveWizardHtml = ob_get_clean();
    check(str_contains($liveWizardHtml, 'name="key_live"') && str_contains($liveWizardHtml, 'mi-live-panel') && !str_contains($liveWizardHtml, 'MOLLIE_INCASSO_ALLOW_LIVE'), 'setup wizard asks for the live key and offers Live activeren instead of wp-config work');
    $_GET = $renderGet;
    wp_set_current_user($previousUser);
    FixtureOptions::set('mi_settings', $standard, false);
    // 0.5: live is released in the plugin ("Live activeren"), bound to this site; wp-config is optional.
    FixtureOptions::scope('mi_live_activation', null);
    $beforeLive = $config->settings();
    putenv('MOLLIE_INCASSO_LIVE_KEY=live_' . str_repeat('L', 32));
    check(!$config->liveReleased(), 'live is not released before activation');
    $fake->methodsMissing = true;
    rejects(fn() => $service->activateLive(1), 'methods_not_enabled');
    check($config->mode() === 'test' && $config->liveActivation() === null, 'failed live check changes nothing');
    $fake->methodsMissing = false;
    // Review of PR #3: nothing may run in live while Mollie is still being asked.
    $seenDuringCheck = null;
    $fake->duringPreflight = function () use ($config, &$seenDuringCheck) {
        $seenDuringCheck = ['released' => $config->liveReleased(), 'mode' => $config->mode()];
    };
    $service->activateLive(1);
    $fake->duringPreflight = null;
    check($seenDuringCheck === ['released' => false, 'mode' => 'test'], 'live is released only after the methods check, never during it');
    check($config->mode() === 'live' && $config->liveReleased(), 'Live activeren switches to live after a successful live check');
    $config->assertRun('live');
    check(true, 'live API use is allowed after activation');
    $activation = $config->liveActivation();
    FixtureOptions::set('mi_live_activation', ['binding' => 'copied-site'] + $activation, false);
    check(!$config->liveReleased(), 'an activation from another site or path does not release live');
    FixtureOptions::set('mi_live_activation', $activation, false);
    putenv('MOLLIE_INCASSO_ALLOW_LIVE=false');
    check(!$config->liveReleased(), 'server brake MOLLIE_INCASSO_ALLOW_LIVE=false always blocks live');
    putenv('MOLLIE_INCASSO_ALLOW_LIVE');
    $service->deactivateLive();
    check($config->mode() === 'test' && $config->testTools() && $config->liveActivation() === null && !$config->liveReleased(), 'Live uitschakelen returns to the test phase');
    putenv('MOLLIE_INCASSO_LIVE_KEY');
    FixtureOptions::set('mi_settings', $beforeLive, false);
    // Mail advice and test mail
    $sentBefore = count($mails);
    check($mail->testMail('beheer@example.invalid') && count($mails) === $sentBefore + 1 && end($mails)['to'] === 'beheer@example.invalid', 'test mail is sent to the administrator');
    rejects(fn() => $mail->testMail('geen-adres'), 'mail_recipient_missing');
    echo 'RESULT ' . $checks . " checks passed. No Mollie network requests, no real emails.\n";
} finally {
    foreach (['customers', 'consents', 'schemes', 'mandates', 'commands', 'payments', 'subscriptions', 'tokens', 'jobs', 'notifications', 'limits', 'checkouts'] as $name) {
        $store->query('DROP TABLE IF EXISTS ' . $store->table($name));
    }
    foreach ($saved as $name => $value) {
        if ($value === null) {
            FixtureOptions::remove($name);
        } else {
            FixtureOptions::set($name, $value, false);
        }
    }
    FixtureOptions::finish();
}
