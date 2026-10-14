<?php

namespace Lukraak\MollieIncasso;

final class Notifications
{
    public function __construct(private readonly Store $store, private readonly Config $config, private readonly Tokens $tokens, private readonly Queue $queue)
    {
    }
    /** Readable Dutch date: plain dates are Amsterdam calendar dates, datetimes are stored in UTC. */
    public static function date(?string $value, bool $utc = false): string
    {
        if (!$value) {
            return '';
        }
        $zone = new \DateTimeZone('Europe/Amsterdam');
        try {
            $date = $utc ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : new \DateTimeImmutable($value, $zone);
        } catch (\Exception $e) {
            return sanitize_text_field($value);
        }
        return wp_date($utc ? 'j F Y H:i' : 'j F Y', $date->getTimestamp(), $zone);
    }
    public function schedule(string $mode, int $customer, string $kind, string $event, array $data = [], ?string $at = null): int
    {
        $this->store->query('INSERT IGNORE INTO ' . $this->store->table('notifications') . ' (mode,event_key,customer_id,kind,data,updated_at) VALUES (%s,%s,%d,%s,%s,%s)', [$mode, $event, $customer, $kind, wp_json_encode($data), Store::now()]);
        $row = $this->store->find('notifications', 'event_key', $event, $mode);
        if (!$row) {
            throw new Failure('mail_storage_failed', true);
        }
        if (in_array($row['state'], ['pending', 'failed'], true)) {
            $this->queue->put($mode, 'mail', 'mail:' . $row['id'], ['id' => (int) $row['id']], $at);
        }
        return (int) $row['id'];
    }
    /**
     * Admin resolution of a mail whose outcome is unknown or failed: send again, or record that the
     * administrator verified delivery elsewhere (for example in the SMTP log).
     */
    public function resolve(int $id, string $mode, bool $markSent): void
    {
        $this->store->lock('mail:' . $mode . ':' . $id, function () use ($id, $mode, $markSent) {
            $row = $this->store->one('notifications', $id, $mode);
            if (!$row || !in_array($row['state'], ['unknown', 'failed'], true)) {
                throw new Failure('mail_not_resolvable');
            }
            if ($markSent) {
                $this->store->update('notifications', ['state' => 'sent', 'sent_at' => Store::now(), 'error' => 'marked_sent_by_admin', 'updated_at' => Store::now()], $id, $mode);
                return;
            }
            $this->store->update('notifications', ['state' => 'pending', 'error' => '', 'updated_at' => Store::now()], $id, $mode);
            $this->queue->put($mode, 'mail', 'mail:' . $id, ['id' => $id]);
        });
    }
    /** Replies go to the organisation's contact address when one is configured. */
    private function headers(): array
    {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $this->config->settings()['contact'], $m) && is_email($m[0])) {
            $headers[] = 'Reply-To: ' . $m[0];
        }
        return $headers;
    }
    /** Internal signal for failed collections and chargebacks; never contains a customer access link. */
    private function sendAdminAlert(array $row, array $customer, array $data, string $mode): void
    {
        $to = (string) apply_filters('mollie_incasso_admin_alert_email', get_option('admin_email'));
        if (!is_email($to)) {
            $this->store->update('notifications', ['state' => 'canceled', 'error' => 'mail_recipient_missing', 'updated_at' => Store::now()], (int) $row['id'], $mode);
            return;
        }
        $this->store->update('notifications', ['state' => 'sending', 'attempts' => (int) $row['attempts'] + 1, 'updated_at' => Store::now()], (int) $row['id'], $mode);
        $text = ($mode === 'test' ? "[TESTMODUS]\n" : '') . sprintf(__('Een incasso vraagt aandacht. Klant: %1$s (%2$s). Mollie-betaling: %3$s. Status: %4$s. Bedrag: %5$s. Stornering: %6$s. Foutcode: %7$s.', 'mollie-incasso'), $customer['name'], $customer['email'], $data['remote_id'] ?? '', Plugin::status((string) ($data['status'] ?? '')), Money::eur((int) ($data['amount'] ?? 0)), Money::eur((int) ($data['charged_back'] ?? 0)), $data['failure'] ?: '—') . "\n\n" . __('Bekijk de klant onder Mollie Incasso → Klanten. De plugin probeert niet zelf opnieuw te incasseren.', 'mollie-incasso') . "\n" . admin_url('admin.php?page=mollie-incasso&view=customers');
        try {
            $sent = wp_mail($to, ($mode === 'test' ? '[TEST] ' : '') . __('Mollie Incasso: incasso mislukt of gestorneerd', 'mollie-incasso'), $text, ['Content-Type: text/plain; charset=UTF-8']);
        } catch (\Throwable $e) {
            $this->store->update('notifications', ['state' => 'unknown', 'error' => 'mail_outcome_unknown', 'updated_at' => Store::now()], (int) $row['id'], $mode);
            throw new Failure('mail_outcome_unknown');
        }
        $this->store->update('notifications', ['state' => $sent ? 'sent' : 'failed', 'sent_at' => $sent ? Store::now() : null, 'error' => $sent ? '' : 'wp_mail_failed', 'updated_at' => Store::now()], (int) $row['id'], $mode);
        if (!$sent) {
            throw new Failure('wp_mail_failed', true);
        }
    }
    public function sent(string $mode, string $event): bool
    {
        return ($this->store->find('notifications', 'event_key', $event, $mode)['state'] ?? '') === 'sent';
    }
    public function send(int $id, string $mode): void
    {
        $this->store->lock('mail:' . $mode . ':' . $id, function () use ($id, $mode) {
            $row = $this->store->one('notifications', $id, $mode);
            if (!$row || in_array($row['state'], ['sent', 'unknown'], true)) {
                return;
            }
            if ($row['state'] === 'sending') {
                $this->store->update('notifications', ['state' => 'unknown', 'error' => 'mail_outcome_unknown', 'updated_at' => Store::now()], $id, $mode);
                return;
            }
            $customer = $this->store->one('customers', (int) $row['customer_id'], $mode);
            if (!$customer || !is_email($customer['email'])) {
                throw new Failure('mail_recipient_missing');
            }
            $data = json_decode($row['data'], true);
            if ($row['kind'] === 'admin_alert') {
                $this->sendAdminAlert($row, $customer, $data, $mode);
                return;
            }
            if (in_array($row['kind'], ['pre', 'consent', 'subscription'], true)) {
                $cancel = in_array($customer['status'], ['revoked', 'revoking'], true);
                if (in_array($row['kind'], ['pre', 'subscription'], true) && !empty($data['subscription_id'])) {
                    $sub = $this->store->find('subscriptions', 'remote_id', $data['subscription_id'], $mode);
                    $cancel = $cancel || !$sub || $sub['status'] !== 'active';
                }
                if ($row['kind'] === 'pre' && !empty($data['command_id'])) {
                    $command = $this->store->one('commands', (int) $data['command_id'], $mode);
                    $cancel = $cancel || !$command || in_array($command['state'], ['canceled', 'failed'], true);
                }
                if ($cancel) {
                    $this->store->update('notifications', ['state' => 'canceled', 'error' => '', 'updated_at' => Store::now()], $id, $mode);
                    return;
                }
                if ($customer['status'] !== 'active') {
                    throw new Failure('customer_not_active', true);
                }
            }
            $this->store->update('notifications', ['state' => 'sending', 'attempts' => (int) $row['attempts'] + 1, 'updated_at' => Store::now()], $id, $mode);
            $data = json_decode($row['data'], true);
            $settings = $this->config->settings();
            $subjects = ['access' => __('Uw persoonlijke incassolink', 'mollie-incasso'), 'consent' => __('Toestemming voor automatische incasso bevestigd', 'mollie-incasso'), 'subscription' => __('Vast incasso-abonnement bevestigd', 'mollie-incasso'), 'ended' => __('Incasso beëindigd', 'mollie-incasso'), 'pre' => __('Vooraankondiging automatische incasso', 'mollie-incasso'), 'payment' => __('Status van uw incasso', 'mollie-incasso')];
            $text = sprintf(__('Beste %s,', 'mollie-incasso'), $customer['name']) . "\n\n" . $settings['organization'] . "\n";
            if ($row['kind'] === 'pre') {
                $text .= sprintf(__('Wij bieden op %1$s een incasso van %2$s aan voor: %3$s. De daadwerkelijke bankverwerking kan later plaatsvinden.', 'mollie-incasso'), self::date($data['date'] ?? ''), Money::eur((int) ($data['amount'] ?? 0)), $data['description'] ?? '');
            } elseif ($row['kind'] === 'consent') {
                $consent = $this->store->select('SELECT consent_text,version FROM ' . $this->store->table('consents') . ' WHERE customer_id=%d AND mode=%s', [(int) $customer['id'], $mode]);
                $text .= __('Uw eerste betaling is gecontroleerd en uw directdebit-mandaat is geldig.', 'mollie-incasso') . "\n\n" . ($consent[0]['consent_text'] ?? '');
            } elseif ($row['kind'] === 'subscription') {
                $text .= sprintf(__('Abonnement: %1$s per %2$s, startdatum %3$s. De verificatiebetaling van € 0,01 is geen abonnementsperiode.', 'mollie-incasso'), Money::eur((int) $data['amount']), Plugin::interval($data['interval']), self::date($data['date']));
            } elseif ($row['kind'] === 'access') {
                $text .= __('Hieronder vindt u uw beveiligde link om uw eigen status te bekijken, een abonnement te stoppen of toestemming in te trekken.', 'mollie-incasso');
            } elseif ($row['kind'] === 'ended') {
                $text .= __('Uw verzoek is bij Mollie verwerkt. Reeds ingezonden incasso’s kunnen nog worden verwerkt en moeten afzonderlijk worden behandeld.', 'mollie-incasso');
            } else {
                $text .= sprintf(__('Betaalstatus: %1$s. Bedrag: %2$s. Terugbetaald: %3$s. Stornering: %4$s.', 'mollie-incasso'), $data['status'] ?? '', Money::eur((int) ($data['amount'] ?? 0)), Money::eur((int) ($data['refunded'] ?? 0)), Money::eur((int) ($data['charged_back'] ?? 0)));
            }
            $raw = $this->tokens->issue($mode, 'access', (int) $customer['id']);
            $link = add_query_arg(['mi_access' => $raw, 'mi_mode' => $mode], home_url('/'));
            $text .= "\n\n" . __('Bekijk uw status of trek uw toestemming in via deze persoonlijke link (7 dagen geldig):', 'mollie-incasso') . "\n" . $link . "\n\n" . $settings['contact'];
            if ($mode === 'test') {
                $text = "[TESTMODUS]\n" . $text;
            }
            try {
                $sent = wp_mail($customer['email'], ($mode === 'test' ? '[TEST] ' : '') . ($subjects[$row['kind']] ?? __('Mollie Incasso', 'mollie-incasso')), $text, $this->headers());
            } catch (\Throwable $e) {
                $this->store->update('notifications', ['state' => 'unknown', 'error' => 'mail_outcome_unknown', 'updated_at' => Store::now()], $id, $mode);
                throw new Failure('mail_outcome_unknown');
            }
            $this->store->update('notifications', ['state' => $sent ? 'sent' : 'failed', 'sent_at' => $sent ? Store::now() : null, 'error' => $sent ? '' : 'wp_mail_failed', 'updated_at' => Store::now()], $id, $mode);
            if (!$sent) {
                throw new Failure('wp_mail_failed', true);
            }
        });
    }
}
