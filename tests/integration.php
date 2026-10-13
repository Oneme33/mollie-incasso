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
    check($before === $store->one('customers', $cid, 'test') && (int) get_option('mi_schema') === 2, 'schema migration preserves existing customer data');
    $caseIds = [];
    foreach (['cst_CaseSensitive', 'cst_casesensitive'] as $remote) {
        $caseIds[] = $store->insert('customers', ['mode' => 'test', 'reference' => wp_generate_uuid4(), 'remote_id' => $remote, 'name' => 'Case fixture', 'email' => 'case@example.invalid', 'scheme_id' => $manual, 'created_at' => Store::now(), 'updated_at' => Store::now()]);
    }
    check(count($caseIds) === 2 && $store->find('customers', 'remote_id', 'cst_CaseSensitive', 'test')['id'] !== $store->find('customers', 'remote_id', 'cst_casesensitive', 'test')['id'], 'case-sensitive provider IDs stay distinct');
    $schemeInput=$schemes->get($manual,'test');$schemeInput['amount']='25.00';$schemeInput['times']='0';rejects(fn()=>$schemes->save($schemeInput,'test'),'invalid_scheme');$schemeInput['times']='3abc';rejects(fn()=>$schemes->save($schemeInput,'test'),'invalid_scheme');
    echo 'RESULT ' . $checks . " checks passed. No Mollie network requests, no real emails.\n";
} finally {
    foreach (['customers', 'consents', 'schemes', 'mandates', 'commands', 'payments', 'subscriptions', 'tokens', 'jobs', 'notifications', 'limits'] as $name) {
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
