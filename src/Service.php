<?php

namespace Lukraak\MollieIncasso;

final class Service
{
    public function __construct(private readonly Store $store, private readonly Config $config, private readonly Schemes $schemes, private readonly Tokens $tokens, private readonly Queue $queue, private readonly Notifications $mail, private readonly \Closure $gatewayFactory)
    {
    }
    private function api(string $mode): Gateway
    {
        $this->config->assertRun($mode);
        return ($this->gatewayFactory)($mode);
    }
    public function methods(string $mode, int $cents = 1): array
    {
        $api = $this->api($mode);
        $first = $api->methods('first', 1);
        $recurring = $api->methods('recurring', $cents);
        if (!in_array('ideal', $first, true) || !in_array('directdebit', $recurring, true)) {
            throw new Failure('methods_not_enabled');
        }
        return ['first' => $first, 'recurring' => $recurring];
    }
    public function signup(string $mode, string $formToken, string $binding, string $name, string $email, bool $accepted, int $userId = 0): array
    {
        $this->config->assertRun($mode, true);
        $name = sanitize_text_field($name);
        $email = strtolower(sanitize_email($email));
        if (!$accepted || !is_email($email) || $name === '' || mb_strlen($name) > 190 || strlen($email) > 190) {
            throw new Failure('invalid_signup');
        }
        $token = $this->tokens->resolve($mode, $formToken, 'signup', $binding);
        $tokenData = json_decode($token['data'], true);
        $hash = hash('sha256', wp_json_encode([$name, $email, (int) $tokenData['scheme_id']]));
        return $this->store->lock('signup:' . $mode . ':' . $token['id'], function () use ($mode, $token, $hash, $name, $email, $userId, $tokenData) {
            $current = $this->store->one('tokens', (int) $token['id'], $mode);
            $saved = json_decode($current['data'], true);
            if ($current['customer_id']) {
                if (!hash_equals((string) ($saved['submission_hash'] ?? ''), $hash)) {
                    throw new Failure('signup_already_used');
                }
                $customer = $this->customer((int) $current['customer_id'], $mode);
            } else {
                $scheme = $this->schemes->get((int) $tokenData['scheme_id'], $mode);
                if (isset($tokenData['disclosure']) && !hash_equals($tokenData['disclosure'], $this->schemes->disclosure($scheme))) {
                    throw new Failure('scheme_changed');
                }
                $this->methods($mode, $scheme['type'] === 'fixed' ? $scheme['amount'] : 1);
                if (!$this->config->settings()['organization'] || !$this->config->settings()['contact']) {
                    throw new Failure('organization_missing');
                }
                $customer = $this->store->transaction(function () use ($mode, $name, $email, $userId, $scheme, $current, $hash) {
                    $id = $this->store->insert('customers', ['mode' => $mode, 'reference' => wp_generate_uuid4(), 'name' => $name, 'email' => $email, 'user_id' => $userId ?: null, 'scheme_id' => $scheme['id'], 'created_at' => Store::now(), 'updated_at' => Store::now()]);
                    $this->store->insert('consents', ['mode' => $mode, 'customer_id' => $id, 'scheme_id' => $scheme['id'], 'version' => (string) $scheme['version'], 'consent_text' => $this->schemes->disclosure($scheme), 'snapshot' => wp_json_encode($scheme), 'accepted_at' => Store::now()]);
                    $this->store->update('tokens', ['customer_id' => $id, 'used_at' => Store::now(), 'data' => wp_json_encode(['scheme_id' => $scheme['id'], 'submission_hash' => $hash])], (int) $current['id'], $mode);
                    return $this->customer($id, $mode);
                });
            }
            return $this->firstPayment($customer, $mode);
        });
    }
    /**
     * Import an existing SEPA mandate the organisation already holds: creates the customer at Mollie and the mandate,
     * without a verification payment. The IBAN goes to Mollie only; locally just the last four digits.
     * Returns 'imported', 'exists' (same e-mail already on this plan) or throws.
     */
    public function importMandate(string $mode, int $schemeId, string $name, string $email, string $iban, string $signatureDate, string $mandateReference, int $userId): string
    {
        $this->config->assertRun($mode, true);
        $name = sanitize_text_field($name);
        $email = strtolower(sanitize_email($email));
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        $mandateReference = trim(sanitize_text_field($mandateReference));
        $signed = \DateTimeImmutable::createFromFormat('!Y-m-d', $signatureDate, new \DateTimeZone('Europe/Amsterdam'));
        if ($name === '' || mb_strlen($name) > 190 || !is_email($email) || strlen($email) > 190) {
            throw new Failure('import_invalid_customer');
        }
        if (!self::validIban($iban)) {
            throw new Failure('import_invalid_iban');
        }
        if (!$signed || $signed->format('Y-m-d') !== $signatureDate || $signatureDate > wp_date('Y-m-d', null, new \DateTimeZone('Europe/Amsterdam'))) {
            throw new Failure('import_invalid_date');
        }
        if (strlen($mandateReference) > 35 || $mandateReference !== '' && !preg_match('/^[A-Za-z0-9+?\/:().,\' -]+$/D', $mandateReference)) {
            throw new Failure('import_invalid_reference');
        }
        $scheme = $this->schemes->get($schemeId, $mode);
        if (!$this->config->settings()['organization'] || !$this->config->settings()['contact']) {
            throw new Failure('organization_missing');
        }
        $this->methods($mode, $scheme['type'] === 'fixed' ? $scheme['amount'] : 1);
        return $this->store->lock('import:' . $mode . ':' . $schemeId . ':' . $email, function () use ($mode, $scheme, $name, $email, $iban, $signatureDate, $mandateReference, $userId) {
            $existing = $this->store->select('SELECT id,status FROM ' . $this->store->table('customers') . ' WHERE mode=%s AND email=%s AND scheme_id=%d ORDER BY id DESC LIMIT 1', [$mode, $email, (int) $scheme['id']]);
            if ($existing && $existing[0]['status'] === 'active') {
                return 'exists';
            }
            if ($existing) {
                $id = (int) $existing[0]['id'];
                $consent = $this->consent($this->customer($id, $mode));
                if (empty($consent['import'])) {
                    // A normal sign-up in progress for this address: leave it alone.
                    throw new Failure('import_signup_in_progress');
                }
            } else {
                $snapshot = $scheme + ['import' => ['signature_date' => $signatureDate, 'mandate_reference' => $mandateReference, 'account' => '•••• ' . substr($iban, -4), 'imported_at' => Store::now(), 'user' => $userId]];
                $note = sprintf(__('Bestaande SEPA-machtiging, getekend op %1$s%2$s, overgezet naar Mollie door de organisatie.', 'mollie-incasso'), $signatureDate, $mandateReference !== '' ? ' (' . sprintf(__('kenmerk %s', 'mollie-incasso'), $mandateReference) . ')' : '');
                $id = $this->store->transaction(function () use ($mode, $name, $email, $scheme, $snapshot, $note) {
                    $id = $this->store->insert('customers', ['mode' => $mode, 'reference' => wp_generate_uuid4(), 'name' => $name, 'email' => $email, 'scheme_id' => $scheme['id'], 'created_at' => Store::now(), 'updated_at' => Store::now()]);
                    $this->store->insert('consents', ['mode' => $mode, 'customer_id' => $id, 'scheme_id' => $scheme['id'], 'version' => (string) $scheme['version'], 'consent_text' => $this->schemes->disclosure($scheme) . "\n\n" . $note, 'snapshot' => wp_json_encode($snapshot), 'accepted_at' => Store::now()]);
                    return $id;
                });
            }
            $customer = $this->customer($id, $mode);
            $customerCommand = $this->command($mode, 'customer:' . $customer['reference'], $id, 'customer', 0, ['name' => $customer['name'], 'email' => $customer['email'], 'locale' => 'nl_NL'], Store::now(), 'scheduled');
            $this->execute((int) $customerCommand['id'], $mode);
            $customer = $this->customer($id, $mode);
            if (!$customer['remote_id']) {
                throw new Failure('customer_creation_pending', true);
            }
            $payload = array_filter(['method' => 'directdebit', 'consumerName' => $name, 'consumerAccount' => $iban, 'signatureDate' => $signatureDate, 'mandateReference' => $mandateReference]);
            $this->api($mode)->createMandate($customer['remote_id'], $payload, hash('sha256', 'import-mandate:' . $customer['reference'] . ':' . $iban));
            $this->refreshMandate($id, $mode);
            return 'imported';
        });
    }
    public static function validIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $rest = 0;
        foreach (str_split($digits, 7) as $part) {
            $rest = (int) ($rest . $part) % 97;
        }
        return $rest === 1;
    }
    private function firstPayment(array $customer, string $mode, string $redirect = ''): array
    {
        return $this->store->lock('customer:' . $mode . ':' . $customer['id'], function () use ($customer, $mode, $redirect) {
                $id = (int) $customer['id'];
                $ref = $customer['reference'];
                $customerCommand = $this->command($mode, 'customer:' . $ref, $id, 'customer', 0, ['name' => $customer['name'], 'email' => $customer['email'], 'locale' => 'nl_NL'], Store::now(), 'scheduled');
                $first = $this->command($mode, 'first:' . $ref, $id, 'first', 1, ['description' => 'Verificatie automatische incasso — ' . $this->config->settings()['organization'], 'redirectUrl' => $redirect ?: add_query_arg(['mi_return' => $ref, 'mi_mode' => $mode], home_url('/')), 'webhookUrl' => $this->config->webhook($mode), 'locale' => 'nl_NL'], Store::now(), 'scheduled');
                $this->queue->put($mode, 'command', 'command:' . $customerCommand['id'], ['id'=>(int)$customerCommand['id']]);
                $this->queue->put($mode, 'command', 'command:' . $first['id'], ['id' => (int) $first['id']], gmdate('Y-m-d H:i:s', time() + 60));
                $this->execute((int) $customerCommand['id'], $mode, true);
                $customer = $this->customer($id, $mode);
                if (!$customer['remote_id']) {
                    throw new Failure('customer_creation_pending', true);
                }
                $this->execute((int) $first['id'], $mode, true);
                $payments = $this->store->select('SELECT * FROM ' . $this->store->table('payments') . ' WHERE command_id=%d AND mode=%s', [(int) $first['id'], $mode]);
                return ['customer' => $customer, 'payment' => $payments[0] ?? null, 'command' => $this->store->one('commands', (int) $first['id'], $mode)];
            });
    }

    /** Called only after the WooCommerce bridge has validated and durably saved the checkout. */
    public function checkoutSignup(int $checkoutId, string $mode): array
    {
        $this->config->assertRun($mode, true);
        return $this->store->lock('checkout-signup:'.$mode.':'.$checkoutId, function () use ($checkoutId, $mode) {
            $receipt = $this->store->one('checkouts', $checkoutId, $mode);
            if (!$receipt) throw new Failure('checkout_not_found');
            if ($receipt['state'] === 'canceled') throw new Failure('consent_withdrawn');
            $data = json_decode($receipt['payload'], true);
            $scheme = $this->schemes->get((int) $receipt['scheme_id'], $mode, false);
            if (!hash_equals((string) $data['disclosure_hash'], hash('sha256', (string)$data['disclosure']))) throw new Failure('scheme_changed');
            if (!$receipt['customer_id']) {
                if (!is_email($data['email']) || $data['name'] === '' || strlen($data['email']) > 190 || mb_strlen($data['name']) > 190) throw new Failure('invalid_signup');
                $this->methods($mode, $scheme['amount']);
                $customer = $this->store->transaction(function () use ($receipt, $data, $scheme, $mode) {
                    $id = $this->store->insert('customers', ['mode'=>$mode, 'reference'=>$receipt['reference'], 'name'=>$data['name'], 'email'=>$data['email'], 'user_id'=>$data['user_id'] ?: null, 'scheme_id'=>$scheme['id'], 'created_at'=>Store::now(), 'updated_at'=>Store::now()]);
                    $this->store->insert('consents', ['mode'=>$mode, 'customer_id'=>$id, 'scheme_id'=>$scheme['id'], 'version'=>(string)$scheme['version'], 'consent_text'=>$data['disclosure'], 'snapshot'=>wp_json_encode($scheme), 'accepted_at'=>$data['accepted_at']]);
                    $this->store->update('checkouts', ['customer_id'=>$id, 'updated_at'=>Store::now()], (int)$receipt['id'], $mode);
                    return $this->customer($id, $mode);
                });
            } else {
                $customer = $this->customer((int)$receipt['customer_id'], $mode);
                $this->consent($customer);
            }
            return $this->firstPayment($customer, $mode, $data['redirect_url']);
        });
    }

    public function startAutomaticSubscription(int $id, string $mode): void
    {
        $this->config->assertRun($mode, true);
        $customer = $this->customer($id, $mode);
        $scheme = $this->consent($customer);
        if (empty($scheme['auto_start']) || $scheme['type'] !== 'fixed') return;
        if ($customer['status'] !== 'active') throw new Failure('customer_not_active');
        $reference = $this->autoReference($customer);
        $command = $this->store->find('commands', 'reference', $reference, $mode);
        if (!$command) {
            $date = wp_date('Y-m-d', time() + (max(1,(int)$scheme['notice_days'])+1)*DAY_IN_SECONDS, new \DateTimeZone('Europe/Amsterdam'));
            $command = $this->prepare($id, $mode, 'subscription', (int)$scheme['amount'], $scheme['purpose'], $date, $reference);
        }
        if ($command['state'] === 'draft') $this->confirm((int)$command['id'], $mode);
    }

    /** One fixed business reference per customer, so an automatic start can never create a second subscription. */
    private function autoReference(array $customer): string
    {
        $hash = hash('sha256', 'automatic-subscription|' . $customer['reference']);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }
    /**
     * Retry a fixed subscription whose create Mollie rejected. A new business reference and a new,
     * properly announced start date are used; the failed command stays as history.
     */
    public function retrySubscription(int $commandId, string $mode): array
    {
        $this->config->assertRun($mode, true);
        $failed = $this->store->one('commands', $commandId, $mode);
        if (!$failed || $failed['kind'] !== 'subscription' || $failed['state'] !== 'failed') {
            throw new Failure('invalid_command');
        }
        $customer = $this->customer((int) $failed['customer_id'], $mode);
        $scheme = $this->consent($customer);
        $payload = json_decode($failed['payload'], true);
        $date = wp_date('Y-m-d', time() + (max(1, (int) $scheme['notice_days']) + 1) * DAY_IN_SECONDS, new \DateTimeZone('Europe/Amsterdam'));
        $command = $this->prepare((int) $customer['id'], $mode, 'subscription', (int) $failed['amount'], (string) $payload['description'], $date, wp_generate_uuid4());
        $this->confirm((int) $command['id'], $mode);
        return $this->store->one('commands', (int) $command['id'], $mode);
    }
    public function customer(int $id, string $mode): array
    {
        $row = $this->store->one('customers', $id, $mode);
        if (!$row) {
            throw new Failure('customer_not_found');
        }
        return $row;
    }
    public function consent(array $customer): array
    {
        $rows = $this->store->select('SELECT * FROM ' . $this->store->table('consents') . ' WHERE customer_id=%d AND mode=%s AND scheme_id=%d', [(int) $customer['id'], $customer['mode'], (int) $customer['scheme_id']]);
        if (!$rows || $rows[0]['withdrawn_at']) {
            throw new Failure('consent_withdrawn');
        }
        return json_decode($rows[0]['snapshot'], true);
    }
    private function command(string $mode, string $ref, int $customer, string $kind, int $amount, array $payload, string $due, string $state = 'draft'): array
    {
        $hash = hash('sha256', wp_json_encode([$customer, $kind, $amount, $payload, $due]));
        $existing = $this->store->find('commands', 'reference', $ref, $mode);
        if ($existing) {
            if (in_array($kind, ['customer', 'first'], true) && $existing['customer_id'] == $customer && $existing['kind'] === $kind) {
                return $existing;
            }
            if (!hash_equals($existing['payload_hash'], $hash)) {
                throw new Failure('command_changed');
            }
            return $existing;
        }
        $id = $this->store->insert('commands', ['mode' => $mode, 'reference' => $ref, 'customer_id' => $customer, 'kind' => $kind, 'amount' => $amount, 'payload' => wp_json_encode($payload), 'payload_hash' => $hash, 'idempotency_key' => wp_generate_uuid4(), 'key_fingerprint' => $this->config->fingerprint($mode), 'state' => $state, 'due_at' => $due, 'created_at' => Store::now(), 'updated_at' => Store::now()]);
        return $this->store->one('commands', $id, $mode);
    }
    public function prepare(int $customerId, string $mode, string $kind, int $amount, string $description, string $date, string $reference): array
    {
        $this->config->assertRun($mode, true);
        if (!in_array($kind, ['manual', 'subscription'], true) || !preg_match('/^[a-f0-9-]{36}$/D', $reference)) {
            throw new Failure('invalid_command');
        }
        return $this->store->lock('customer:' . $mode . ':' . $customerId, function () use ($customerId, $mode, $kind, $amount, $description, $date, $reference) {
            $customer = $this->customer($customerId, $mode);
            $scheme = $this->consent($customer);
            if ($customer['status'] !== 'active') {
                throw new Failure('customer_not_active');
            }
            if ($kind === 'manual' && $scheme['type'] !== 'manual' || $kind === 'subscription' && $scheme['type'] !== 'fixed') {
                throw new Failure('scheme_type_mismatch');
            }
            if ($amount < 1 || $amount > $scheme['amount'] || $scheme['type'] === 'fixed' && $amount !== $scheme['amount']) {
                throw new Failure('amount_outside_consent');
            }
            $description = sanitize_text_field($description);
            if (!$description || mb_strlen($description) > 255) {
                throw new Failure('description_required');
            }
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Amsterdam'));
            if (!$start || $start->format('Y-m-d') !== $date || $date < wp_date('Y-m-d', time() + max(1, (int) $scheme['notice_days']) * DAY_IN_SECONDS, new \DateTimeZone('Europe/Amsterdam'))) {
                throw new Failure('start_date_too_early');
            }
            $unsafe = $this->store->select('SELECT id FROM ' . $this->store->table('commands') . ' WHERE mode=%s AND customer_id=%d AND state IN ("unknown","processing","blocked") AND attempted_at IS NOT NULL LIMIT 1', [$mode, $customerId]);
            if ($unsafe) {
                throw new Failure('uncertain_command_blocks_new');
            }
            if($kind==='subscription'){
                $existing=$this->store->select('SELECT id FROM '.$this->store->table('subscriptions').' WHERE customer_id=%d AND mode=%s AND status NOT IN ("canceled","completed") LIMIT 1',[$customerId,$mode]);
                $pending=$this->store->select('SELECT id FROM '.$this->store->table('commands').' WHERE customer_id=%d AND mode=%s AND kind="subscription" AND reference!=%s AND state NOT IN ("completed","failed","canceled") LIMIT 1',[$customerId,$mode,$reference]);
                if($existing||$pending){throw new Failure('subscription_already_exists');}
            }
            $this->refreshMandate($customerId, $mode);
            $this->methods($mode, $amount);
            $payload = ['description' => $description, 'date' => $date, 'scheme_id' => $customer['scheme_id'], 'interval' => $scheme['interval'], 'times' => $scheme['times'], 'notice_days' => $scheme['notice_days']];
            return $this->command($mode, $reference, $customerId, $kind, $amount, $payload, $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        });
    }
    public function confirm(int $id, string $mode): void
    {
        $command = $this->store->one('commands', $id, $mode);
        if (!$command) {
            throw new Failure('command_not_found');
        }
        $this->store->lock('customer:' . $mode . ':' . $command['customer_id'], function () use ($id, $mode) {
            $command = $this->store->one('commands', $id, $mode);
            if ($command['state'] !== 'draft') {
                return;
            }
            $customer = $this->customer((int) $command['customer_id'], $mode);
            $this->consent($customer);
            if ($customer['status'] !== 'active') {
                throw new Failure('customer_not_active');
            }
            $data = json_decode($command['payload'], true);
            $this->store->transaction(function () use ($command, $data, $mode, $id) {
                $this->mail->schedule($mode, (int) $command['customer_id'], 'pre', 'pre:command:' . $id, ['command_id' => $id, 'amount' => (int) $command['amount'], 'date' => $data['date'], 'description' => $data['description']]);
                $this->store->update('commands', ['state' => 'scheduled', 'updated_at' => Store::now()], $id, $mode);
                $at = $command['kind'] === 'subscription' ? Store::now() : $command['due_at'];
                $this->queue->put($mode, 'command', 'command:' . $id, ['id' => $id], $at);
            });
        });
    }
    public function execute(int $id, string $mode, bool $locked = false): void
    {
        $command = $this->store->one('commands', $id, $mode);
        if (!$command) {
            throw new Failure('command_not_found');
        }
        if (!$locked) {
            $this->store->lock('customer:' . $mode . ':' . $command['customer_id'], fn() => $this->execute($id, $mode, true));
            return;
        }
        if (in_array($command['state'], ['completed', 'failed', 'canceled', 'draft'], true)) {
            return;
        }
        $this->config->assertRun($mode, true);
        $customer = $this->customer((int) $command['customer_id'], $mode);
        $api = $this->api($mode);
        $payload = json_decode($command['payload'], true);
        $kind = $command['kind'];
        if ($kind !== 'customer' && !$customer['remote_id']) {
            throw new Failure('customer_creation_pending', true);
        }
        if (in_array($kind, ['customer', 'first'], true)) {
            $this->consent($customer);
            if (in_array($customer['status'], ['revoking', 'revoked', 'stop_pending'], true)) {
                throw new Failure('consent_withdrawn');
            }
        }
        if (in_array($kind, ['manual', 'subscription'], true)) {
            $this->consent($customer);
            if ($customer['status'] !== 'active') {
                throw new Failure('customer_not_active');
            }
            if (!$this->mail->sent($mode, 'pre:command:' . $id)) {
                throw new Failure('prenotification_not_sent', true);
            }
            if ($kind === 'manual' && $command['due_at'] > Store::now()) {
                throw new Failure('command_not_due', true);
            }
        }
        $remote = null;
        if ($command['attempted_at']) {
            $remote = $api->find($kind === 'manual' ? 'payment' : $kind, (string) $customer['remote_id'], $command['reference'], (string) get_option('mi_instance'), $command['attempted_at']);
            if (!$remote && (!hash_equals($command['key_fingerprint'], $this->config->fingerprint($mode)) || time() - strtotime($command['attempted_at'] . ' UTC') >= 3300)) {
                $this->store->update('commands', ['state' => 'blocked', 'error' => 'unknown_requires_review', 'updated_at' => Store::now()], $id, $mode);
                throw new Failure('unknown_requires_review');
            }
        }
        if (!$remote) {
            if (in_array($kind, ['manual', 'subscription'], true)) {
                $mandate = $this->refreshMandate((int) $customer['id'], $mode);
                $payload['mandateId'] = $mandate['remote_id'];
            }
            $metadata = ['mi_instance' => (string) get_option('mi_instance'), 'mi_command' => $command['reference'], 'mi_customer' => $customer['reference'], 'mi_mode' => $mode];
            $body = $command['request_payload'] ? json_decode($command['request_payload'], true) : null;
            if (!$body) {
                if ($kind === 'customer') {
                    $body = $payload + ['metadata' => $metadata];
                } elseif ($kind === 'subscription') {
                    // No method: Mollie treats method and mandateId as mutually exclusive (found in the live test).
                    $body = ['amount' => Money::api((int) $command['amount']), 'interval' => $payload['interval'], 'startDate' => $payload['date'], 'description' => $payload['description'], 'mandateId' => $payload['mandateId'], 'webhookUrl' => $this->config->webhook($mode), 'metadata' => $metadata];
                    if ($payload['times']) {
                        $body['times'] = (int) $payload['times'];
                    }
                } else {
                    $body = ['amount' => Money::api((int) $command['amount']), 'customerId' => $customer['remote_id'], 'sequenceType' => $kind === 'first' ? 'first' : 'recurring', 'description' => $payload['description'], 'webhookUrl' => $payload['webhookUrl'] ?? $this->config->webhook($mode), 'metadata' => $metadata];
                    if ($kind === 'first') {
                        $body['method'] = 'ideal';
                        $body['redirectUrl'] = $payload['redirectUrl'];
                        $body['locale'] = 'nl_NL';
                    } else {
                        // Mollie rejects method together with mandateId; the directdebit mandate decides the method.
                        $body['mandateId'] = $payload['mandateId'];
                    }
                }
            }
            if (isset($body['mandateId']) && $body['mandateId'] !== $payload['mandateId']) {
                throw new Failure('mandate_changed_requires_review');
            }
            if ($kind === 'subscription' && $body['startDate'] <= wp_date('Y-m-d', null, new \DateTimeZone('Europe/Amsterdam'))) {
                throw new Failure('subscription_start_passed');
            }
            $this->store->update('commands', ['state' => 'processing', 'request_payload' => wp_json_encode($body), 'attempted_at' => $command['attempted_at'] ?: Store::now(), 'updated_at' => Store::now()], $id, $mode);
            try {
                $remote = match ($kind) {
                    'customer' => $api->createCustomer($body, $command['idempotency_key']),
                    'subscription' => $api->createSubscription($customer['remote_id'], $body, $command['idempotency_key']),
                    default => $api->createPayment($body, $command['idempotency_key']),
                };
            } catch (Failure $e) {
                $this->store->update('commands', ['state' => $e->unknown ? 'unknown' : ($e->temporary ? 'scheduled' : 'failed'), 'error' => $e->reason, 'error_detail' => $e->detail, 'updated_at' => Store::now()], $id, $mode);
                if ($e->temporary || $e->unknown) {
                    $this->queue->put($mode, 'command', 'command:' . $id, ['id' => $id], gmdate('Y-m-d H:i:s', time() + 60));
                }
                throw $e;
            }
        }
        try {
        $this->store->transaction(function () use ($kind, $remote, $command, $customer, $mode, $id) {
            if ($kind === 'customer') {
                $this->store->update('customers', ['remote_id' => $remote['id'], 'updated_at' => Store::now()], (int) $customer['id'], $mode);
            } elseif ($kind === 'subscription') {
                $this->storeSubscription($customer, $command, $remote);
            } else {
                $this->storePayment($customer, $command, $remote, null);
            }
            $this->store->update('commands', ['state' => 'completed', 'remote_id' => $remote['id'], 'error' => '', 'error_detail' => '', 'updated_at' => Store::now()], $id, $mode);
        });
        } catch (Failure $e) {
            // The resource exists at Mollie but was not accepted here: block it visibly instead of leaving it processing.
            $this->store->update('commands', ['state' => 'blocked', 'remote_id' => $remote['id'] ?? null, 'error' => $e->reason, 'error_detail' => mb_substr($e->detail, 0, 250), 'updated_at' => Store::now()], $id, $mode);
            throw $e;
        }
        if ($kind === 'first') {
            $this->queue->put($mode, 'payment', 'payment:' . $remote['id'], ['id' => $remote['id']]);
        }
    }
    public function refreshMandate(int $id, string $mode): array
    {
        $customer = $this->customer($id, $mode);
        $this->consent($customer);
        if (in_array($customer['status'], ['revoking', 'revoked', 'stop_pending'], true)) {
            throw new Failure('consent_withdrawn');
        }
        // Proof of the customer's account: the paid verification payment, or an existing mandate the organisation imported.
        $imported = !empty($this->consent($customer)['import']);
        $first = $this->store->select('SELECT * FROM ' . $this->store->table('payments') . ' WHERE customer_id=%d AND mode=%s AND kind="first" ORDER BY id DESC LIMIT 1', [$id, $mode]);
        if (!$imported && (!$first || $first[0]['status'] !== 'paid' || (int) $first[0]['amount'] !== 1 || (int) $first[0]['refunded'] || (int) $first[0]['charged_back'])) {
            throw new Failure('first_payment_not_paid');
        }
        $api = $this->api($mode);
        $mandates = $customer['mandate_id'] ? [$api->mandate($customer['remote_id'], $customer['mandate_id'])] : $api->mandates($customer['remote_id']);
        $valid = null;
        foreach ($mandates as $m) {
            if ($m['method'] !== 'directdebit') {
                continue;
            }
            if (!empty($m['customerId']) && $m['customerId'] !== $customer['remote_id']) {
                throw new Failure('mandate_mismatch');
            }
            $this->store->query('INSERT INTO ' . $this->store->table('mandates') . ' (mode,customer_id,remote_id,status,method,masked,checked_at) VALUES (%s,%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE status=VALUES(status),masked=VALUES(masked),checked_at=VALUES(checked_at)', [$mode, $id, $m['id'], $m['status'], $m['method'], $m['masked'] ?? '', Store::now()]);
            if ($m['status'] === 'valid') {
                $valid = $this->store->find('mandates', 'remote_id', $m['id'], $mode);
            }
        }
        if (!$valid) {
            $this->store->update('customers', ['status' => 'mandate_pending', 'updated_at' => Store::now()], $id, $mode);
            throw new Failure('mandate_not_valid', true);
        }
        $this->store->update('customers', ['status' => 'active', 'mandate_id' => $valid['remote_id'], 'updated_at' => Store::now()], $id, $mode);
        $this->mail->schedule($mode, $id, 'consent', 'consent:' . $id, $imported ? ['imported' => $this->consent($customer)['import']['signature_date']] : []);
        // Follow-up work only while it is still outstanding, not on every periodic mandate check.
        if (!empty($this->consent($customer)['auto_start'])) {
            $auto = $this->store->find('commands', 'reference', $this->autoReference($customer), $mode);
            if (!$auto || $auto['state'] === 'draft') {
                $this->queue->put($mode, 'auto_subscription', 'auto-subscription:' . $id, ['id' => $id]);
            }
        }
        if ($this->store->select('SELECT id FROM ' . $this->store->table('checkouts') . ' WHERE customer_id=%d AND mode=%s AND state="pending" LIMIT 1', [$id, $mode])) {
            $this->queue->put($mode, 'checkout', 'checkout:' . $id, ['id' => $id]);
        }
        return $valid;
    }
    private function validatePayment(array $customer, ?array $command, array $p, ?array $subscription): void
    {
        $mode = $customer['mode'];
        // What Mollie returned, without personal data, so a rejected payment is explainable in the admin.
        $seen = sprintf('mode=%s, sequenceType=%s, method=%s, currency=%s, status=%s', $p['mode'] ?? '-', $p['sequenceType'] ?? '-', $p['method'] ?? '-', $p['amount']['currency'] ?? '-', $p['status'] ?? '-');
        if (($p['mode'] ?? '') !== $mode || ($p['customerId'] ?? '') !== $customer['remote_id'] || ($p['amount']['currency'] ?? '') !== 'EUR') {
            throw new Failure('payment_mismatch', false, false, $seen . (($p['customerId'] ?? '') !== $customer['remote_id'] ? ', customer differs' : ''));
        }
        $expected = $command ? (int) $command['amount'] : (int) $subscription['amount'];
        if (Money::cents((string) $p['amount']['value']) !== $expected) {
            throw new Failure('payment_amount_mismatch', false, false, $seen . ', amount=' . ($p['amount']['value'] ?? '-'));
        }
        $first = $command && $command['kind'] === 'first';
        if (($p['sequenceType'] ?? '') !== ($first ? 'first' : 'recurring') || ($p['method'] ?? '') !== ($first ? 'ideal' : 'directdebit')) {
            throw new Failure('payment_method_mismatch', false, false, $seen);
        }
        if ($command && (($p['metadata']['mi_command'] ?? '') !== $command['reference'] || ($p['metadata']['mi_instance'] ?? '') !== (string) get_option('mi_instance'))) {
            throw new Failure('payment_reference_mismatch', false, false, $seen . ', metadata does not match this website');
        }
        if ($subscription && ($p['subscriptionId'] ?? '') !== $subscription['remote_id']) {
            throw new Failure('subscription_mismatch');
        }
    }
    private function storePayment(array $customer, ?array $command, array $p, ?array $subscription): void
    {
        $this->validatePayment($customer, $command, $p, $subscription);
        $mode = $customer['mode'];
        $existing = $this->store->find('payments', 'remote_id', $p['id'], $mode);
        $checkout = $p['checkout'] ?? '';
        if ($checkout) {
            $host = wp_parse_url($checkout, PHP_URL_HOST);
            if (!str_starts_with($checkout, 'https://') || $host !== 'mollie.com' && !str_ends_with((string) $host, '.mollie.com')) {
                throw new Failure('invalid_checkout_url');
            }
        }
        $data = ['mode' => $mode, 'remote_id' => $p['id'], 'customer_id' => (int) $customer['id'], 'command_id' => $command ? (int) $command['id'] : null, 'subscription_id' => $subscription['remote_id'] ?? null, 'kind' => $command ? $command['kind'] === 'first' ? 'first' : 'manual' : 'subscription', 'amount' => Money::cents((string) $p['amount']['value']), 'status' => sanitize_key($p['status']), 'refunded' => !empty($p['amountRefunded']['value']) ? Money::cents($p['amountRefunded']['value'], true) : 0, 'charged_back' => !empty($p['amountChargedBack']['value']) ? Money::cents($p['amountChargedBack']['value'], true) : 0, 'failure' => sanitize_text_field($p['failure'] ?? ''), 'checkout_url' => $checkout ?: null, 'created_at' => $existing['created_at'] ?? Store::now(), 'checked_at' => Store::now()];
        if ($existing) {
            $this->store->update('payments', $data, (int) $existing['id'], $mode);
        } else {
            $this->store->insert('payments', $data);
        }
        if ($data['kind'] !== 'first' && $this->config->settings()['notifications']) {
            $event = 'payment:' . $p['id'] . ':' . hash('sha256', wp_json_encode([$data['status'], $data['refunded'], $data['charged_back']]));
            $this->mail->schedule($mode, (int) $customer['id'], 'payment', $event, $data);
        }
        if ($data['kind'] !== 'first' && ($data['status'] === 'failed' || $data['charged_back'] > 0)) {
            $this->mail->schedule($mode, (int) $customer['id'], 'admin_alert', 'admin:' . $p['id'] . ':' . $data['status'] . ':' . $data['charged_back'], $data);
        }
    }
    public function testOutcomeUrl(int $paymentId, string $mode): string
    {
        $row = $mode === 'test' ? $this->store->one('payments', $paymentId, 'test') : null;
        if (!$row || $row['kind'] === 'first' || !in_array($row['status'], ['open', 'pending', 'paid'], true)) {
            throw new Failure('test_outcome_unavailable');
        }
        $url = (string) ($this->api('test')->payment($row['remote_id'])['change_state'] ?? '');
        if (!preg_match('#^https://(www\.)?mollie\.com/#', $url)) {
            throw new Failure('test_outcome_unavailable');
        }
        return $url;
    }
    public function syncPayment(string $id, string $mode): void
    {
        $api = $this->api($mode);
        try {
            $p = $api->payment($id);
        } catch (Failure $e) {
            if ($e->reason === 'resource_not_found') {
                return;
            }
            throw $e;
        }
        if (($p['mode'] ?? '') !== $mode) {
            return;
        }
        $existing = $this->store->find('payments', 'remote_id', $id, $mode);
        $command = null;
        $subscription = null;
        $customer = null;
        if ($existing) {
            $customer = $this->customer((int) $existing['customer_id'], $mode);
            if ($existing['command_id']) {
                $command = $this->store->one('commands', (int) $existing['command_id'], $mode);
            } else {
                $subscription = $this->store->find('subscriptions', 'remote_id', (string) $existing['subscription_id'], $mode);
            }
        } elseif (!empty($p['subscriptionId'])) {
            $subscription = $this->store->find('subscriptions', 'remote_id', $p['subscriptionId'], $mode);
            if ($subscription) {
                $customer = $this->customer((int) $subscription['customer_id'], $mode);
            }
        } elseif (($p['metadata']['mi_instance'] ?? '') === (string) get_option('mi_instance')) {
            $command = $this->store->find('commands', 'reference', (string) ($p['metadata']['mi_command'] ?? ''), $mode);
            if ($command) {
                $customer = $this->customer((int) $command['customer_id'], $mode);
            }
        }
        if (!$customer && !empty($p['subscriptionId']) && !empty($p['customerId'])) {
            $known = $this->store->find('customers', 'remote_id', $p['customerId'], $mode);
            if ($known && $this->store->select('SELECT id FROM ' . $this->store->table('commands') . ' WHERE customer_id=%d AND mode=%s AND kind="subscription" AND state IN ("processing","unknown","scheduled")', [(int) $known['id'], $mode])) {
                throw new Failure('subscription_not_yet_stored', true);
            }
        }
        if (!$customer) {
            return;
        }
        $this->store->lock('customer:' . $mode . ':' . $customer['id'], function () use ($customer, $command, $p, $subscription, $mode) {
            $this->store->transaction(function () use ($customer, $command, $p, $subscription, $mode) {
                $this->storePayment($customer, $command, $p, $subscription);
                if ($command) {
                    $this->store->update('commands', ['state' => 'completed', 'remote_id' => $p['id'], 'error' => '', 'updated_at' => Store::now()], (int) $command['id'], $mode);
                }
            });
            if ($command && $command['kind'] === 'first') {
                $this->queue->put($mode, 'checkout', 'checkout:' . $customer['id'], ['id' => (int) $customer['id']]);
            }
            if ($command && $command['kind'] === 'first' && (in_array($p['status'], ['failed', 'canceled', 'expired'], true) || Money::cents((string) ($p['amountChargedBack']['value'] ?? '0.00'), true) > 0 || Money::cents((string) ($p['amountRefunded']['value'] ?? '0.00'), true) > 0) && !in_array($customer['status'], ['revoked', 'revoking'], true)) {
                $this->store->update('customers', ['status' => 'verification_failed', 'updated_at' => Store::now()], (int) $customer['id'], $mode);
            }
            if ($command && $command['kind'] === 'first' && $p['status'] === 'paid') {
                $withdrawing = in_array($customer['status'], ['revoked', 'revoking'], true);
                if ($withdrawing) {
                    $this->store->update('customers', ['status' => 'revoking', 'updated_at' => Store::now()], (int) $customer['id'], $mode);
                }
                $kind = $withdrawing ? 'withdraw' : 'mandate';
                $this->queue->put($mode, $kind, $kind . ':' . $customer['id'], ['id' => (int) $customer['id']]);
            }
            if ($subscription) {
                // Mollie moves nextPaymentDate after each term; sync now so the next pre-notification is planned in time.
                $this->queue->put($mode, 'subscription', 'subscription:' . $subscription['id'], ['id' => (int) $subscription['id']]);
            }
            if (in_array($p['status'], ['failed', 'canceled', 'expired'], true) || Money::cents((string) ($p['amountChargedBack']['value'] ?? '0.00'), true) > 0) {
                if ($subscription) {
                    $this->queue->put($mode, 'subscription', 'subscription:' . $subscription['id'], ['id' => (int) $subscription['id']]);
                }
                // A failed verification has no mandate to re-check; that job could only fail.
                if (!$command || $command['kind'] !== 'first') {
                    $this->queue->put($mode, 'mandate', 'mandate:' . $customer['id'], ['id' => (int) $customer['id']]);
                }
            }
        });
    }
    /**
     * Server-side check of the newest €0.01 verification at Mollie (never the browser return itself).
     * Returns "paid", "unpaid" (Mollie confirms it is not paid) or "unknown" (Mollie could not be asked,
     * or a create attempt has no stored result yet). Callers must never treat "unknown" as unpaid.
     */
    public function firstPaymentState(int $customerId, string $mode, bool $activate = false): string
    {
        $first = fn() => $this->store->select('SELECT * FROM ' . $this->store->table('payments') . ' WHERE customer_id=%d AND mode=%s AND kind="first" ORDER BY id DESC LIMIT 1', [$customerId, $mode])[0] ?? null;
        $payment = $first();
        if (!$payment) {
            $attempted = $this->store->select('SELECT id FROM ' . $this->store->table('commands') . ' WHERE customer_id=%d AND mode=%s AND kind IN ("customer","first") AND attempted_at IS NOT NULL AND state!="completed" LIMIT 1', [$customerId, $mode]);
            return $attempted ? 'unknown' : 'unpaid';
        }
        if (in_array($payment['status'], ['open', 'pending', 'authorized'], true)) {
            try {
                $this->syncPayment($payment['remote_id'], $mode);
            } catch (Failure $e) {
                return 'unknown';
            }
            $payment = $first();
        }
        $paid = $payment['status'] === 'paid' && !(int) $payment['refunded'] && !(int) $payment['charged_back'];
        if ($paid && $activate && in_array($this->customer($customerId, $mode)['status'], ['pending', 'mandate_pending'], true)) {
            try {
                $this->refreshMandate($customerId, $mode);
            } catch (Failure $e) {
                // A pending mandate stays visible; the queued mandate job continues.
            }
        }
        return $paid ? 'paid' : 'unpaid';
    }
    public function verifiedFirstPayment(int $customerId, string $mode, bool $activate = false): bool
    {
        return $this->firstPaymentState($customerId, $mode, $activate) === 'paid';
    }
    private function storeSubscription(array $customer, array $command, array $s, bool $notify = true): void
    {
        $mode = $customer['mode'];
        $payload = json_decode($command['payload'], true);
        if (!empty($s['mode']) && $s['mode'] !== $mode || ($s['customerId'] ?? '') !== $customer['remote_id'] || ($s['amount']['currency'] ?? '') !== 'EUR' || Money::cents($s['amount']['value']) !== (int) $command['amount'] || ($s['interval'] ?? '') !== $payload['interval'] || ($s['metadata']['mi_command'] ?? '') !== $command['reference'] || ($s['metadata']['mi_instance'] ?? '') !== (string) get_option('mi_instance') || ($s['metadata']['mi_mode'] ?? '') !== $mode) {
            throw new Failure('subscription_mismatch');
        }
        $data = ['mode' => $mode, 'remote_id' => $s['id'], 'customer_id' => (int) $customer['id'], 'command_id' => (int) $command['id'], 'mandate_id' => $s['mandateId'], 'amount' => (int) $command['amount'], 'interval_value' => $payload['interval'], 'start_date' => $payload['date'], 'next_date' => $s['nextPaymentDate'] ?? null, 'status' => $s['status'], 'times' => $payload['times'], 'checked_at' => Store::now()];
        $existing = $this->store->find('subscriptions', 'remote_id', $s['id'], $mode);
        if ($existing) {
            $this->store->update('subscriptions', $data, (int) $existing['id'], $mode);
        } else {
            $this->store->insert('subscriptions', $data);
        }
        if (!$notify) {
            return;
        }
        $this->mail->schedule($mode, (int) $customer['id'], 'subscription', 'subscription:' . $s['id'], ['subscription_id' => $s['id'], 'amount' => (int) $command['amount'], 'interval' => $payload['interval'], 'date' => $payload['date']]);
        $this->schedulePre($customer, $s, (int) $command['amount']);
    }
    private function schedulePre(array $customer, array $s, int $amount): void
    {
        if ($s['status'] !== 'active' || empty($s['nextPaymentDate']) || $s['nextPaymentDate'] === ($s['startDate'] ?? '')) {
            return;
        }
        $scheme = $this->consent($customer);
        $at = (new \DateTimeImmutable($s['nextPaymentDate'], new \DateTimeZone('Europe/Amsterdam')))->modify('-' . (int) $scheme['notice_days'] . ' days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->mail->schedule($customer['mode'], (int) $customer['id'], 'pre', 'pre:subscription:' . $s['id'] . ':' . $s['nextPaymentDate'], ['subscription_id' => $s['id'], 'amount' => $amount, 'date' => $s['nextPaymentDate'], 'description' => $scheme['purpose']], $at);
    }
    public function syncSubscription(int $id, string $mode): void
    {
        $row = $this->store->one('subscriptions', $id, $mode);
        if (!$row) {
            return;
        }
        $customer = $this->customer((int) $row['customer_id'], $mode);
        $s = $this->api($mode)->subscription($customer['remote_id'], $row['remote_id']);
        if (($s['customerId'] ?? '') !== $customer['remote_id'] || $s['id'] !== $row['remote_id']) {
            throw new Failure('subscription_mismatch');
        }
        $this->store->update('subscriptions', ['status' => $s['status'], 'next_date' => $s['nextPaymentDate'] ?? null, 'checked_at' => Store::now()], $id, $mode);
        if ($customer['status'] === 'active') {
            $this->schedulePre($customer, $s, (int) $row['amount']);
        }
    }
    public function withdraw(int $id, string $mode): void
    {
        $this->config->assertRun($mode, true);
        $this->store->lock('customer:' . $mode . ':' . $id, function () use ($id, $mode) {
            $customer = $this->customer($id, $mode);
            if ($customer['status'] === 'revoked') {
                return;
            }
            $this->store->transaction(function () use ($id, $mode) {
                $this->store->update('customers', ['status' => 'revoking', 'updated_at' => Store::now()], $id, $mode);
                $this->store->query('UPDATE ' . $this->store->table('consents') . ' SET withdrawn_at=COALESCE(withdrawn_at,%s) WHERE customer_id=%d AND mode=%s', [Store::now(), $id, $mode]);
                $this->store->query('UPDATE ' . $this->store->table('commands') . ' SET state="canceled",error="consent_withdrawn" WHERE customer_id=%d AND mode=%s AND attempted_at IS NULL AND state IN ("draft","scheduled")', [$id, $mode]);
                $this->queue->put($mode, 'withdraw', 'withdraw:' . $id, ['id' => $id]);
            });
        });
        $this->finishWithdrawal($id, $mode);
    }
    public function finishWithdrawal(int $id, string $mode): void
    {
        $this->store->lock('customer:' . $mode . ':' . $id, function () use ($id, $mode) {
            $customer = $this->customer($id, $mode);
            if (!in_array($customer['status'], ['revoking', 'revoked'], true)) {
                throw new Failure('consent_withdrawn');
            }
            $api = $this->api($mode);
            $uncertain = $this->store->select('SELECT id FROM ' . $this->store->table('commands') . ' WHERE customer_id=%d AND mode=%s AND state IN ("unknown","processing","blocked") AND kind="subscription"', [$id, $mode]);
            foreach ($uncertain as $command) {
                $this->reconcileWithdrawnSubscription((int) $command['id'], $customer, $api);
            }
            $subs = $this->store->select('SELECT * FROM ' . $this->store->table('subscriptions') . ' WHERE customer_id=%d AND mode=%s AND status NOT IN ("canceled","completed")', [$id, $mode]);
            foreach ($subs as $sub) {
                $result = $api->subscription($customer['remote_id'], $sub['remote_id']);
                if (!in_array($result['status'], ['canceled', 'completed'], true)) {
                    $result = $api->cancel($customer['remote_id'], $sub['remote_id']);
                }
                if (!in_array($result['status'], ['canceled', 'completed'], true)) {
                    throw new Failure('cancellation_pending', true);
                }
                $this->store->update('subscriptions', ['status' => $result['status'], 'checked_at' => Store::now()], (int) $sub['id'], $mode);
            }
            if ($customer['remote_id']) {
                $externalSubs = $api->subscriptions($customer['remote_id']);
                $mandates = $api->mandates($customer['remote_id']);
                foreach ($mandates as $mandate) {
                    if ($mandate['method'] !== 'directdebit' || in_array($mandate['status'], ['invalid', 'revoked'], true)) {
                        continue;
                    }
                    foreach ($externalSubs as $other) {
                        if (!in_array($other['status'], ['canceled', 'completed'], true) && ($other['mandateId'] ?? '') === $mandate['id']) {
                            throw new Failure('shared_mandate_in_use', true);
                        }
                    }
                    $api->revoke($customer['remote_id'], $mandate['id']);
                }
                $this->store->query('UPDATE ' . $this->store->table('mandates') . ' SET status="revoked",checked_at=%s WHERE customer_id=%d AND mode=%s', [Store::now(), $id, $mode]);
            }
            $this->store->update('customers', ['status' => 'revoked', 'updated_at' => Store::now()], $id, $mode);
            $this->mail->schedule($mode, $id, 'ended', 'withdraw:' . $id);
        });
    }
    private function reconcileWithdrawnSubscription(int $id, array $customer, Gateway $api): void
    {
        $command = $this->store->one('commands', $id, $customer['mode']);
        $remote = $api->find('subscription', $customer['remote_id'], $command['reference'], (string) get_option('mi_instance'), $command['attempted_at']);
        if (!$remote) {
            throw new Failure('unknown_subscription_requires_review', true);
        }
        $this->storeSubscription($customer, $command, $remote, false);
        $this->store->update('commands', ['state' => 'completed', 'remote_id' => $remote['id'], 'error' => '', 'updated_at' => Store::now()], $id, $customer['mode']);
    }
    public function stop(int $subscriptionId, string $mode): void
    {
        $this->config->assertRun($mode, true);
        $row = $this->store->one('subscriptions', $subscriptionId, $mode);
        if (!$row) {
            throw new Failure('subscription_not_found');
        }
        $id = (int) $row['customer_id'];
        $this->store->lock('customer:' . $mode . ':' . $id, function () use ($subscriptionId, $id, $mode) {
            $customer = $this->customer($id, $mode);
            $row = $this->store->one('subscriptions', $subscriptionId, $mode);
            if (in_array($customer['status'], ['revoked', 'revoking'], true)) {
                throw new Failure('consent_withdrawn');
            }
            if (!in_array($row['status'], ['canceled', 'completed'], true)) {
                $this->store->transaction(function () use ($id, $mode, $row) {
                    $this->store->update('customers', ['status' => 'stop_pending', 'updated_at' => Store::now()], $id, $mode);
                    $this->store->update('subscriptions', ['status' => 'canceling', 'checked_at' => Store::now()], (int) $row['id'], $mode);
                    $this->queue->put($mode, 'stop', 'stop:' . $row['id'], ['id' => (int) $row['id']]);
                });
                $api = $this->api($mode);
                $result = $api->subscription($customer['remote_id'], $row['remote_id']);
                if (!in_array($result['status'], ['canceled', 'completed'], true)) {
                    $result = $api->cancel($customer['remote_id'], $row['remote_id']);
                }
                if (!in_array($result['status'], ['canceled', 'completed'], true)) {
                    throw new Failure('cancellation_pending', true);
                }
                $this->store->update('subscriptions', ['status' => $result['status'], 'checked_at' => Store::now()], (int) $row['id'], $mode);
            }
            // Re-read: the transaction above changed both rows, and the stop may have completed in this request.
            $customer = $this->customer($id, $mode);
            if ($customer['status'] === 'stop_pending') {
                $other = $this->store->select('SELECT id FROM ' . $this->store->table('subscriptions') . ' WHERE customer_id=%d AND mode=%s AND status="canceling"', [$id, $mode]);
                if (!$other) {
                    $this->store->update('customers', ['status' => 'mandate_pending', 'updated_at' => Store::now()], $id, $mode);
                    $this->refreshMandate($id, $mode);
                }
            }
            $this->mail->schedule($mode, $id, 'ended', 'stop:' . $row['remote_id']);
        });
    }
    public function assertErasure(array $customer): void
    {
        if ($customer['status'] !== 'revoked') {
            throw new Failure('consent_withdrawn');
        }
        $uncertain = $this->store->select('SELECT id FROM ' . $this->store->table('commands') . ' WHERE customer_id=%d AND mode=%s AND state IN ("unknown","processing","blocked")', [(int) $customer['id'], $customer['mode']]);
        $pending = $this->store->select('SELECT id FROM ' . $this->store->table('payments') . ' WHERE customer_id=%d AND mode=%s AND status IN ("pending","open","authorized")', [(int) $customer['id'], $customer['mode']]);
        if ($uncertain || $pending) {
            throw new Failure('unknown_requires_review');
        }
        if (!$customer['remote_id']) {
            return;
        }
        $api = $this->api($customer['mode']);
        foreach ($api->subscriptions($customer['remote_id']) as $s) {
            if (!in_array($s['status'], ['canceled', 'completed'], true)) {
                throw new Failure('shared_mandate_in_use');
            }
        }
        foreach ($api->mandates($customer['remote_id']) as $m) {
            if (!in_array($m['status'], ['invalid', 'revoked'], true)) {
                throw new Failure('mandate_not_valid');
            }
        }
    }
    /**
     * Hourly safety net next to webhooks. Work is spread over the day (id % 24) so the queue keeps
     * room for customer-facing jobs; unpaid or failed signups are never polled for mandates.
     */
    /**
     * Remove every test-mode record so a test site starts clean. Live rows are never touched, and the
     * call is refused unless the plugin itself runs in test mode. Settings and API keys are kept.
     */
    public function wipeTestData(): int
    {
        if ($this->config->mode() !== 'test') {
            throw new Failure('environment_mismatch');
        }
        $removed = 0;
        $this->store->transaction(function () use (&$removed) {
            foreach (['customers', 'consents', 'schemes', 'mandates', 'commands', 'payments', 'subscriptions', 'tokens', 'jobs', 'notifications', 'checkouts'] as $table) {
                $removed += $this->store->query('DELETE FROM ' . $this->store->table($table) . ' WHERE mode=%s', ['test']);
            }
            $this->store->query('DELETE FROM ' . $this->store->table('limits'));
        });
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->esc_like('mi_setup_page_test_') . '%', $wpdb->esc_like('mi_wizard_plan_test_') . '%', $wpdb->esc_like('mi_woo_product_test_') . '%'));
        foreach (['mi_last_webhook_test', 'mi_cron_error', 'mi_cleanup_blocked'] as $option) {
            delete_option($option);
        }
        return $removed;
    }
    /**
     * "Live activeren": an administrator confirms in the plugin. The live key must work and Mollie must
     * offer iDEAL and SEPA Direct Debit live; otherwise nothing changes. The release is bound to the
     * URL and path of this site, so a copy of the site can never collect.
     */
    public function activateLive(int $userId): void
    {
        $config = $this->config;
        if (wp_get_environment_type() !== 'production') {
            throw new Failure('live_environment_not_production');
        }
        if ($config->liveBrake()) {
            throw new Failure('live_disabled');
        }
        $config->key('live');
        // Check first, release after: until Mollie confirms, nothing anywhere can run in live.
        $methods = ($this->gatewayFactory)('live')->preflightMethods();
        if (!in_array('ideal', $methods['first'], true) || !in_array('directdebit', $methods['recurring'], true)) {
            throw new Failure('methods_not_enabled');
        }
        update_option('mi_methods_live', ['checked_at' => Store::now(), 'fingerprint' => $config->fingerprint('live')] + $methods, false);
        update_option('mi_live_activation', ['binding' => $config->binding(), 'url' => home_url(), 'user' => $userId, 'at' => Store::now()], false);
        update_option('mi_settings', ['mode' => 'live'] + $config->settings(), false);
    }
    /** Back to the test phase; external Mollie subscriptions are not touched. */
    public function deactivateLive(): void
    {
        // An empty release reads as "not released"; uninstall removes the option itself.
        update_option('mi_live_activation', [], false);
        $settings = $this->config->settings();
        $settings['mode'] = 'test';
        $settings['test_tools'] = 1;
        update_option('mi_settings', $settings, false);
    }
    public function maintenance(?int $hour = null): void
    {
        $mode = $this->config->mode();
        $this->config->assertRun($mode);
        $hour ??= (int) gmdate('G');
        $week = (int) gmdate('N') * 24 + $hour;
        $customers = $this->store->table('customers');
        $payments = $this->store->table('payments');
        foreach ($this->store->select('SELECT id FROM ' . $customers . ' WHERE mode=%s AND status="revoking"', [$mode]) as $c) {
            $this->queue->put($mode, 'withdraw', 'withdraw:' . $c['id'], ['id' => (int) $c['id']], null, false);
        }
        $paidFirst = 'EXISTS (SELECT 1 FROM ' . $payments . ' p WHERE p.customer_id=c.id AND p.mode=c.mode AND p.kind="first" AND p.status="paid")';
        $due = $this->store->select('SELECT c.id FROM ' . $customers . ' c WHERE c.mode=%s AND c.remote_id IS NOT NULL AND (c.status="mandate_pending" OR (c.status="pending" AND ' . $paidFirst . ') OR (c.status="active" AND c.id %% 24 = %d))', [$mode, $hour]);
        foreach ($due as $c) {
            $this->queue->put($mode, 'mandate', 'mandate:' . $c['id'], ['id' => (int) $c['id']], null, false);
        }
        foreach ($this->store->select('SELECT id,status FROM ' . $this->store->table('subscriptions') . ' WHERE mode=%s AND (status="canceling" OR (status NOT IN ("completed","canceled") AND id %% 24 = %d))', [$mode, $hour]) as $s) {
            $kind = $s['status'] === 'canceling' ? 'stop' : 'subscription';
            $this->queue->put($mode, $kind, $kind . ':' . $s['id'], ['id' => (int) $s['id']], null, false);
        }
        // Open payments hourly; settled ones daily while chargebacks are likely, then weekly up to 13 months.
        $recent = gmdate('Y-m-d H:i:s', time() - 70 * DAY_IN_SECONDS);
        $oldest = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        foreach ($this->store->select('SELECT remote_id FROM ' . $payments . ' WHERE mode=%s AND (status IN ("open","pending","authorized") OR (created_at>=%s AND id %% 24 = %d) OR (created_at<%s AND created_at>=%s AND id %% 168 = %d))', [$mode, $recent, $hour, $recent, $oldest, $week % 168]) as $p) {
            $this->queue->put($mode, 'payment', 'payment:' . $p['remote_id'], ['id' => $p['remote_id']], null, false);
        }
        foreach ($this->store->select('SELECT customer_id FROM ' . $this->store->table('checkouts') . ' WHERE mode=%s AND customer_id IS NOT NULL AND state="pending" AND created_at>=%s', [$mode, gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS)]) as $checkout) {
            $this->queue->put($mode, 'checkout', 'checkout:' . $checkout['customer_id'], ['id' => (int) $checkout['customer_id']], null, false);
        }
        $this->store->query('DELETE FROM ' . $this->store->table('limits') . ' WHERE expires_at<%s', [Store::now()]);
        $this->store->query('DELETE FROM ' . $this->store->table('tokens') . ' WHERE expires_at<%s', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $this->store->query('DELETE FROM ' . $this->store->table('jobs') . ' WHERE mode=%s AND state="done" AND updated_at<%s', [$mode, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)]);
    }
    public function job(string $kind, array $data, string $mode): void
    {
        match ($kind) {
            'auto_subscription' => $this->startAutomaticSubscription((int)$data['id'], $mode),
            'checkout' => do_action('mollie_incasso_sync_checkout', (int)$data['id'], $mode),
            'cancel_checkout' => do_action('mollie_incasso_cancel_checkout', (int) $data['order'], $mode),
            'payment' => $this->syncPayment((string) $data['id'], $mode),
            'mandate' => $this->refreshMandate((int) $data['id'], $mode),
            'subscription' => $this->syncSubscription((int) $data['id'], $mode),
            'command' => $this->execute((int) $data['id'], $mode),
            'mail' => $this->mail->send((int) $data['id'], $mode),
            'withdraw' => $this->finishWithdrawal((int) $data['id'], $mode),
            'stop' => $this->stop((int) $data['id'], $mode),
            default => throw new Failure('unknown_job'),
        };
    }
}
