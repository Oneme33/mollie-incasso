<?php

namespace Lukraak\MollieIncasso;

final class Schemes
{
    public function __construct(private readonly Store $store, private readonly Config $config)
    {
    }
    public function get(int $id, string $mode, bool $active = true): array
    {
        $row = $this->store->one('schemes', $id, $mode);
        if (!$row || $active && !$row['active']) {
            throw new Failure('scheme_unavailable');
        }
        $data = json_decode($row['data'], true);
        $data['id'] = $id;
        $data['version'] = (int) $row['version'];
        return $data;
    }
    public function save(array $input, string $mode): int
    {
        $type = ($input['type'] ?? 'manual') === 'fixed' ? 'fixed' : 'manual';
        $data = ['name' => sanitize_text_field($input['name'] ?? ''), 'type' => $type, 'purpose' => sanitize_textarea_field($input['purpose'] ?? ''), 'calculation' => sanitize_textarea_field($input['calculation'] ?? ''), 'frequency' => sanitize_textarea_field($input['frequency'] ?? ''), 'withdrawal' => sanitize_textarea_field($input['withdrawal'] ?? ''), 'consent' => sanitize_textarea_field($input['consent'] ?? ''), 'notice_days' => max(0, min(60, (int) ($input['notice_days'] ?? 14))), 'amount' => Money::cents((string) ($input['amount'] ?? '0')), 'interval' => in_array($input['interval'] ?? '', ['1 month', '3 months', '12 months'], true) ? $input['interval'] : '1 month', 'times' => trim((string)($input['times']??''))===''?null:(int)$input['times']];
        $data['auto_start'] = $type === 'fixed' && in_array($input['auto_start'] ?? null, ['1', 1, true], true);
        if($data['times']!==null&&!preg_match('/^[1-9][0-9]{0,4}$/D',trim((string)$input['times']))){throw new Failure('invalid_scheme');}
        if (in_array('', array_intersect_key($data, array_flip(['name', 'purpose', 'calculation', 'frequency', 'withdrawal', 'consent'])), true) || $data['times'] !== null && ($data['times'] < 1 || $data['times'] > 10000)) {
            throw new Failure('invalid_scheme');
        }
        if ($data['notice_days'] !== 14 && empty($input['notice_agreed'])) {
            throw new Failure('notice_agreement_required');
        }
        $data['notice_agreed'] = $data['notice_days'] === 14 ? false : true;
        $slug = sanitize_key($input['slug'] ?? '') ?: wp_generate_uuid4();
        $rows = $this->store->select('SELECT MAX(version) AS version FROM ' . $this->store->table('schemes') . ' WHERE mode=%s AND slug=%s', [$mode, $slug]);
        $version = 1 + (int) ($rows[0]['version'] ?? 0);
        return $this->store->transaction(function () use ($mode, $slug, $version, $data) {
            $this->store->query('UPDATE ' . $this->store->table('schemes') . ' SET active=0 WHERE mode=%s AND slug=%s', [$mode, $slug]);
            return $this->store->insert('schemes', ['mode' => $mode, 'slug' => $slug, 'version' => $version, 'data' => wp_json_encode($data), 'active' => 1, 'created_at' => Store::now()]);
        });
    }
    public function disclosure(array $scheme): string
    {
        $settings = $this->config->settings();
        $amount = $scheme['type'] === 'fixed' ? Money::eur($scheme['amount']) : sprintf(__('maximaal %s per incasso', 'mollie-incasso'), Money::eur($scheme['amount']));
        $automatic = !empty($scheme['auto_start']) ? sprintf(__('Na de verificatie en een geldige machtiging start uw vaste abonnement automatisch. De eerste incasso vindt ten minste %d kalenderdagen na bevestiging plaats; u ontvangt het bedrag en de exacte datum vooraf per e-mail.', 'mollie-incasso'), max(1, (int) $scheme['notice_days'])) : '';
        return implode("\n\n", [sprintf(__('Organisatie: %s — %s', 'mollie-incasso'), $settings['organization'], $settings['address']), $scheme['purpose'], sprintf(__('Bedrag: %s. %s', 'mollie-incasso'), $amount, $scheme['calculation']), sprintf(__('Frequentie / aanleiding: %s', 'mollie-incasso'), $scheme['frequency']), sprintf(__('Vooraankondiging: %d kalenderdagen vóór het aanbieden van de incasso.', 'mollie-incasso'), $scheme['notice_days']), $scheme['withdrawal'], $automatic, sprintf(__('Contact: %s', 'mollie-incasso'), $settings['contact']), __('De eerste iDEAL-betaling van € 0,01 is uitsluitend een verificatiebetaling. Dit bedrag blijft bij de organisatie, telt niet als abonnementsperiode en wordt niet automatisch verrekend of terugbetaald.', 'mollie-incasso'), $scheme['consent']]);
    }
}
