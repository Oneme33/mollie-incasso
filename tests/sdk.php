<?php

// The real official SDK with its fake HTTP adapter. No Mollie network requests.
define('WP_INSTALLING', true);
define('MOLLIE_INCASSO_TEST_KEY', 'test_MOCKKEYONLY000000000000000000000000');
require getenv('MI_WP_ROOT') ?: '/var/www/html/wp-load.php';
require dirname(__DIR__) . '/mollie-incasso.php';
require dirname(__DIR__) . '/dependencies/autoload.php';
require __DIR__ . '/FixtureOptions.php';
use Lukraak\MollieIncasso\Tests\FixtureOptions;
FixtureOptions::start();
use Lukraak\MollieIncasso\Vendor\Mollie\Api\MollieApiClient;
use Lukraak\MollieIncasso\Vendor\Mollie\Api\Fake\MockResponse;
use Lukraak\MollieIncasso\{MollieGateway, Config, Failure};
if ((int) $wpdb->get_var("SELECT GET_LOCK('mi_fixture_options',30)") !== 1) {
    throw new RuntimeException('Another fixture is running.');
}
$options = ['mi_settings' => get_option('mi_settings'), 'mi_site_binding' => get_option('mi_site_binding')];
$config = new Config();
FixtureOptions::set('mi_settings', ['mode' => 'test'], false);
FixtureOptions::set('mi_site_binding', $config->binding(), false);
function expect(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
    echo 'PASS ' . $name . "\n";
}
$prefix = 'Lukraak\MollieIncasso\Vendor\Mollie\Api\Http\Requests\\';
$metadata = ['mi_command' => 'ref', 'mi_instance' => 'instance', 'mi_mode' => 'test'];
$payment = ['resource' => 'payment', 'id' => 'tr_12345678', 'mode' => 'test', 'status' => 'open', 'amount' => ['currency' => 'EUR', 'value' => '0.01'], 'customerId' => 'cst_12345678', 'sequenceType' => 'first', 'method' => 'ideal', 'metadata' => $metadata, '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/test']]];
$subscription = ['resource' => 'subscription', 'id' => 'sub_12345678', 'customerId' => 'cst_12345678', 'mode' => 'test', 'status' => 'active', 'mandateId' => 'mdt_12345678', 'amount' => ['currency' => 'EUR', 'value' => '25.00'], 'interval' => '12 months', 'startDate' => '2027-01-01', 'metadata' => $metadata];
$list = fn($key, $records) => MockResponse::ok(['count' => count($records), '_embedded' => [$key => $records], '_links' => ['next' => null, 'previous' => null]]);
$mock = MollieApiClient::fake([$prefix . 'GetEnabledMethodsRequest' => $list('methods', [['resource' => 'method', 'id' => 'ideal', 'status' => 'activated']]), $prefix . 'CreateCustomerRequest' => MockResponse::created(['resource' => 'customer', 'id' => 'cst_12345678', 'metadata' => $metadata]), $prefix . 'CreatePaymentRequest' => MockResponse::created($payment), $prefix . 'GetPaymentRequest' => MockResponse::ok($payment), $prefix . 'GetPaginatedCustomerPaymentsRequest' => $list('payments', [$payment]), $prefix . 'GetPaginatedCustomerRequest' => $list('customers', [['resource' => 'customer', 'id' => 'cst_12345678', 'metadata' => $metadata]]), $prefix . 'GetMandateRequest' => MockResponse::ok(['resource' => 'mandate', 'id' => 'mdt_12345678', 'status' => 'valid', 'method' => 'directdebit', 'details' => ['consumerAccount' => 'NL00MOCK0000001234']]), $prefix . 'GetPaginatedMandateRequest' => $list('mandates', [['resource' => 'mandate', 'id' => 'mdt_12345678', 'status' => 'valid', 'method' => 'directdebit']]), $prefix . 'RevokeMandateRequest' => MockResponse::noContent(), $prefix . 'CreateMandateRequest' => MockResponse::created(['resource' => 'mandate', 'id' => 'mdt_imported1', 'status' => 'valid', 'method' => 'directdebit', 'details' => ['consumerAccount' => 'NL91ABNA0417164300']]), $prefix . 'CreateSubscriptionRequest' => MockResponse::created($subscription), $prefix . 'GetSubscriptionRequest' => MockResponse::ok($subscription), $prefix . 'GetPaginatedSubscriptionsRequest' => $list('subscriptions', [$subscription]), $prefix . 'CancelSubscriptionRequest' => MockResponse::ok(array_replace($subscription, ['status' => 'canceled']))], true);
try {
    $api = new MollieGateway($config, 'test', $mock);
    expect($api->methods('first') === ['ideal'], 'scoped SDK methods endpoint and hydration');
    expect($api->createCustomer(['name' => 'Mock', 'email' => 'mock@example.invalid', 'metadata' => $metadata], 'customer-key')['id'] === 'cst_12345678', 'customer endpoint');
    expect($api->createPayment(['amount' => ['currency' => 'EUR', 'value' => '0.01'], 'customerId' => 'cst_12345678', 'sequenceType' => 'first', 'method' => 'ideal', 'description' => 'Test', 'redirectUrl' => 'https://example.org/return', 'webhookUrl' => 'https://example.org/webhook', 'metadata' => $metadata], 'payment-key')['checkout'] === 'https://www.mollie.com/checkout/test', 'first payment endpoint and checkout extraction');
    expect($api->payment('tr_12345678')['mode'] === 'test', 'payment GET and environment');
    expect($api->find('payment', 'cst_12345678', 'ref', 'instance')['id'] === 'tr_12345678', 'payment reconciliation iterator and metadata');
    expect($api->find('customer', '', 'ref', 'instance')['id'] === 'cst_12345678', 'customer reconciliation iterator');
    expect($api->mandate('cst_12345678', 'mdt_12345678')['masked'] === '•••• 1234', 'full bank account discarded by gateway');
    expect(count($api->mandates('cst_12345678')) === 1, 'mandates iterator');
    $body = ['amount' => ['currency' => 'EUR', 'value' => '25.00'], 'interval' => '12 months', 'startDate' => '2027-01-01', 'description' => 'Test', 'mandateId' => 'mdt_12345678', 'webhookUrl' => 'https://example.org/webhook', 'metadata' => $metadata, 'times' => 3];
    expect($api->createSubscription('cst_12345678', $body, 'subscription-key')['id'] === 'sub_12345678', 'yearly subscription payload via real SDK');
    expect($api->subscription('cst_12345678', 'sub_12345678')['interval'] === '12 months', 'subscription GET');
    expect(count($api->subscriptions('cst_12345678')) === 1, 'subscription iterator');
    expect($api->find('subscription', 'cst_12345678', 'ref', 'instance')['id'] === 'sub_12345678', 'subscription reconciliation');
    expect($api->cancel('cst_12345678', 'sub_12345678')['status'] === 'canceled', 'cancel endpoint');
    $imported = $api->createMandate('cst_12345678', ['method' => 'directdebit', 'consumerName' => 'Jan Jansen', 'consumerAccount' => 'NL91ABNA0417164300', 'signatureDate' => '2024-01-31', 'mandateReference' => 'LID-007'], 'import-key');
    expect($imported['id'] === 'mdt_imported1' && $imported['status'] === 'valid' && $imported['masked'] === '•••• 4300', 'existing mandate created through the SDK, account masked');
    $api->revoke('cst_12345678', 'mdt_12345678');
    expect(true, 'revoke endpoint');
    $found = [];
    foreach ((new ReflectionProperty($mock, 'httpClient'))->getValue($mock)->recorded() as [$pending, $response]) {
        $request = $pending->createPsrRequest();
        if ($request->getMethod() === 'POST') {
            $found[] = $request->getHeaderLine('Idempotency-Key');
        }
    }
    expect($found === ['customer-key', 'payment-key', 'subscription-key', 'import-key'], 'every create sends the persisted idempotency key');
    foreach ([404 => 'resource_not_found', 422 => 'mollie_rejected', 429 => 'api_rate_limited', 503 => 'api_unavailable'] as $status => $reason) {
        $bad = MollieApiClient::fake([$prefix . 'GetPaymentRequest' => MockResponse::error($status, 'Error', 'Mock failure')]);
        $gateway = new MollieGateway($config, 'test', $bad);
        try {
            $gateway->payment('tr_12345678');
            throw new RuntimeException('Expected failure');
        } catch (Failure $e) {
            expect($e->reason === $reason, 'HTTP ' . $status . ' maps to safe failure');
        }
    }
    // Newest first: an unrelated recent customer, then a much older one that happens to carry the metadata.
    $older = MollieApiClient::fake([$prefix . 'GetPaginatedCustomerRequest' => $list('customers', [['resource' => 'customer', 'id' => 'cst_new00001', 'createdAt' => gmdate('c'), 'metadata' => []], ['resource' => 'customer', 'id' => 'cst_old00001', 'createdAt' => '2020-01-01T00:00:00+00:00', 'metadata' => $metadata]])]);
    expect((new MollieGateway($config, 'test', $older))->find('customer', '', 'ref', 'instance', gmdate('Y-m-d H:i:s')) === null, 'reconciliation stops at resources older than the uncertain create');
    $older = MollieApiClient::fake([$prefix . 'GetPaginatedCustomerRequest' => $list('customers', [['resource' => 'customer', 'id' => 'cst_new00001', 'createdAt' => gmdate('c'), 'metadata' => $metadata]])]);
    expect((new MollieGateway($config, 'test', $older))->find('customer', '', 'ref', 'instance', gmdate('Y-m-d H:i:s', time() - 60))['id'] === 'cst_new00001', 'reconciliation still finds the recent uncertain create');
    // Before live is released only the read-only preflight may reach Mollie with the live key.
    putenv('MOLLIE_INCASSO_LIVE_KEY=live_' . str_repeat('L', 32));
    $methodsList = fn() => $list('methods', [['resource' => 'method', 'id' => 'ideal', 'status' => 'activated'], ['resource' => 'method', 'id' => 'directdebit', 'status' => 'activated']]);
    $liveMock = MollieApiClient::fake([$prefix . 'GetEnabledMethodsRequest' => new \Lukraak\MollieIncasso\Vendor\Mollie\Api\Fake\SequenceMockResponse($methodsList(), $methodsList())]);
    $liveGateway = new MollieGateway($config, 'live', $liveMock);
    $preflight = $liveGateway->preflightMethods();
    expect(in_array('ideal', $preflight['first'], true) && in_array('directdebit', $preflight['recurring'], true), 'live methods preflight works before live is released');
    try {
        $liveGateway->methods('recurring');
        throw new RuntimeException('Expected refusal');
    } catch (Failure $e) {
        expect($e->reason === 'environment_mismatch', 'ordinary live calls stay refused until live is released');
    }
    putenv('MOLLIE_INCASSO_LIVE_KEY');
    $rejecting = MollieApiClient::fake([$prefix . 'CreateSubscriptionRequest' => MockResponse::error(422, 'Unprocessable Entity', 'The start date is invalid for klant@example.com, see https://docs.mollie.com/x', 'startDate')]);
    try {
        (new MollieGateway($config, 'test', $rejecting))->createSubscription('cst_12345678', $body, 'reject-key');
        throw new RuntimeException('Expected rejection');
    } catch (Failure $e) {
        expect($e->reason === 'mollie_rejected' && str_contains($e->detail, 'startDate') && str_contains($e->detail, 'The start date is invalid'), 'Mollie rejection keeps its field and explanation (' . $e->detail . ')');
        expect(!str_contains($e->detail, 'klant@example.com') && !str_contains($e->detail, 'https://'), 'rejection detail is stripped of e-mail addresses and URLs');
    }
    $bad = MollieApiClient::fake([$prefix . 'CreatePaymentRequest' => MockResponse::error(503, 'Error', 'Mock failure')]);
    $gateway = new MollieGateway($config, 'test', $bad);
    try {
        $gateway->createPayment(['amount' => ['currency' => 'EUR', 'value' => '0.01'], 'description' => 'Test', 'redirectUrl' => 'https://example.org'], 'safe-key');
        throw new RuntimeException('Expected timeout');
    } catch (Failure $e) {
        expect($e->unknown && $e->temporary, 'mutating temporary failure has unknown financial outcome');
    }
    if (is_file(dirname(__DIR__, 2) . '/mollie-incasso-build/vendor/autoload.php')) {
        require dirname(__DIR__, 2) . '/mollie-incasso-build/vendor/autoload.php';
        $other = new \Mollie\Api\MollieApiClient();
        expect(get_class($other) !== get_class($mock), 'unscoped Mollie SDK coexists in same process');
    }
} finally {
    foreach ($options as $key => $value) {
        FixtureOptions::set($key, $value, false);
    }
    FixtureOptions::finish();
}
