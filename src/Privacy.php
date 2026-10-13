<?php

namespace Lukraak\MollieIncasso;

final class Privacy
{
    public function __construct(private readonly Plugin $plugin)
    {
    }
    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', function ($items) {
            $items['mollie-incasso'] = ['exporter_friendly_name' => __('Mollie Incasso', 'mollie-incasso'), 'callback' => [$this, 'export']];
            return $items;
        });
        add_filter('wp_privacy_personal_data_erasers', function ($items) {
            $items['mollie-incasso'] = ['eraser_friendly_name' => __('Mollie Incasso', 'mollie-incasso'), 'callback' => [$this, 'erase']];
            return $items;
        });
        add_action('admin_init', static function () {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content('Mollie Incasso', __('Wij bewaren uw naam, e-mail, regeling, vastgelegde toestemming, Mollie-referenties en betaalstatussen. Volledige rekeninggegevens worden niet lokaal bewaard. Standaard bewaartermijn: 7 jaar voor financiële en toestemmingsgegevens; beoordeel dit beleid voor uw organisatie. Persoonlijke toegangstokens worden gehasht en vervallen na 7 dagen. Verwijderen gebeurt pas nadat externe afspraken aantoonbaar zijn beëindigd.', 'mollie-incasso'));
            }
        });
    }
    private function customers(string $email, int $page): array
    {
        return $this->plugin->store->select('SELECT * FROM ' . $this->plugin->store->table('customers') . ' WHERE email=%s ORDER BY id LIMIT 50 OFFSET %d', [strtolower(sanitize_email($email)), max(0, $page - 1) * 50]);
    }
    public function export(string $email, int $page = 1): array
    {
        $items = [];
        $rows = $this->customers($email, $page);
        foreach ($rows as $row) {
            $data = [];
            foreach (['name', 'email', 'mode', 'status', 'created_at'] as $key) {
                $data[] = ['name' => $key, 'value' => $row[$key]];
            }
            $consents = $this->plugin->store->select('SELECT consent_text,version,accepted_at,withdrawn_at FROM ' . $this->plugin->store->table('consents') . ' WHERE customer_id=%d AND mode=%s', [(int) $row['id'], $row['mode']]);
            foreach ($consents as $c) {
                $data[] = ['name' => __('Toestemming', 'mollie-incasso'), 'value' => wp_json_encode($c, JSON_UNESCAPED_UNICODE)];
            }
            foreach (['payments', 'subscriptions', 'mandates'] as $table) {
                foreach ($this->plugin->store->select('SELECT * FROM ' . $this->plugin->store->table($table) . ' WHERE customer_id=%d AND mode=%s', [(int) $row['id'], $row['mode']]) as $record) {
                    unset($record['id'], $record['customer_id'], $record['checkout_url'], $record['command_id']);
                    $data[] = ['name' => $table, 'value' => wp_json_encode($record, JSON_UNESCAPED_UNICODE)];
                }
            }
            $items[] = ['group_id' => 'mollie-incasso', 'group_label' => __('Mollie Incasso', 'mollie-incasso'), 'item_id' => 'customer-' . $row['id'], 'data' => $data];
        }
        return ['data' => $items, 'done' => count($rows) < 50];
    }
    public function erase(string $email, int $page = 1): array
    {
        $removed = false;
        $retained = false;
        $messages = [];
        $key = 'mi_erasure_' . hash('sha256', strtolower(sanitize_email($email)));
        if ($page === 1) {
            $ids = $this->plugin->store->select('SELECT id FROM ' . $this->plugin->store->table('customers') . ' WHERE email=%s ORDER BY id', [strtolower(sanitize_email($email))]);
            set_transient($key, array_column($ids, 'id'), DAY_IN_SECONDS);
        }
        $ids = get_transient($key);
        if (!is_array($ids)) {
            return ['items_removed' => false, 'items_retained' => true, 'messages' => [__('Herstart het verwijderverzoek.', 'mollie-incasso')], 'done' => true];
        }
        $slice = array_slice($ids, max(0, $page - 1) * 50, 50);
        foreach ($slice as $id) {
            $rows = $this->plugin->store->select('SELECT * FROM ' . $this->plugin->store->table('customers') . ' WHERE id=%d', [(int) $id]);
            $c = $rows[0] ?? null;
            if (!$c) {
                continue;
            }
            $last = $this->plugin->store->select('SELECT MAX(created_at) AS latest FROM ' . $this->plugin->store->table('payments') . ' WHERE customer_id=%d AND mode=%s', [(int) $id, $c['mode']]);
            $age = time() - strtotime(max($last[0]['latest'] ?? '', $c['updated_at']) . ' UTC');
            try {
                if ($age < (int) $this->plugin->config->settings()['retention_days'] * DAY_IN_SECONDS) {
                    throw new Failure('retention_required');
                }
                $this->plugin->store->lock('customer:' . $c['mode'] . ':' . $id, function () use ($c, $id) {
                    $this->plugin->service->assertErasure($c);
                    $this->plugin->store->transaction(function () use ($c, $id) {
                        foreach (['tokens', 'notifications', 'consents', 'payments', 'subscriptions', 'mandates', 'commands'] as $table) {
                            $this->plugin->store->query('DELETE FROM ' . $this->plugin->store->table($table) . ' WHERE customer_id=%d AND mode=%s', [(int) $id, $c['mode']]);
                        }
                        $this->plugin->store->query('DELETE FROM ' . $this->plugin->store->table('customers') . ' WHERE id=%d AND mode=%s', [(int) $id, $c['mode']]);
                    });
                });
                $removed = true;
            } catch (Failure $e) {
                $retained = true;
                $messages[] = __('Gegevens behouden: externe afspraken of betaaluitkomsten zijn nog actief/onzeker, controle is niet beschikbaar of de bewaartermijn loopt nog.', 'mollie-incasso');
            }
        }
        $done = max(0, $page - 1) * 50 + count($slice) >= count($ids);
        if ($done) {
            delete_transient($key);
        }
        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => array_values(array_unique($messages)), 'done' => $done];
    }
}
