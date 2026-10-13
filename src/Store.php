<?php

namespace Lukraak\MollieIncasso;

final class Store
{
    public const SCHEMA_VERSION = 2;
    public function __construct(private readonly \wpdb $db)
    {
    }
    public function table(string $name): string
    {
        if (!in_array($name, ['customers', 'consents', 'schemes', 'mandates', 'commands', 'payments', 'subscriptions', 'tokens', 'jobs', 'notifications', 'limits'], true)) {
            throw new Failure('invalid_table');
        }
        return $this->db->prefix . 'mi_' . $name;
    }
    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $schemas = ['customers' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, reference varchar(64) NOT NULL, remote_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, user_id bigint unsigned DEFAULT NULL, name varchar(190) NOT NULL, email varchar(190) NOT NULL, scheme_id bigint unsigned NOT NULL, status varchar(24) NOT NULL DEFAULT 'pending', mandate_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY local_reference (mode,reference), UNIQUE KEY remote_customer (mode,remote_id), KEY email (email), KEY mode_status (mode,status)", 'schemes' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, slug varchar(64) NOT NULL, version int unsigned NOT NULL, active tinyint NOT NULL DEFAULT 1, data longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY scheme_version (mode,slug,version)", 'consents' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, customer_id bigint unsigned NOT NULL, scheme_id bigint unsigned NOT NULL, version varchar(64) NOT NULL, consent_text longtext NOT NULL, snapshot longtext NOT NULL, accepted_at datetime NOT NULL, withdrawn_at datetime DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY customer_consent (mode,customer_id,scheme_id)", 'mandates' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, customer_id bigint unsigned NOT NULL, remote_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, status varchar(24) NOT NULL, method varchar(24) NOT NULL, masked varchar(32) NOT NULL DEFAULT '', checked_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY remote_mandate (mode,remote_id), KEY customer (mode,customer_id)", 'commands' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, reference varchar(64) NOT NULL, customer_id bigint unsigned NOT NULL, kind varchar(24) NOT NULL, amount bigint NOT NULL DEFAULT 0, payload longtext NOT NULL, request_payload longtext DEFAULT NULL, payload_hash varchar(64) NOT NULL, idempotency_key varchar(64) NOT NULL, key_fingerprint varchar(64) NOT NULL DEFAULT '', state varchar(24) NOT NULL DEFAULT 'draft', remote_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, due_at datetime NOT NULL, attempted_at datetime DEFAULT NULL, error varchar(64) NOT NULL DEFAULT '', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY business_command (mode,reference), UNIQUE KEY idempotency (mode,idempotency_key), KEY customer_state (mode,customer_id,state)", 'payments' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, remote_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, customer_id bigint unsigned NOT NULL, command_id bigint unsigned DEFAULT NULL, subscription_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL, kind varchar(24) NOT NULL, amount bigint NOT NULL, status varchar(24) NOT NULL, refunded bigint NOT NULL DEFAULT 0, charged_back bigint NOT NULL DEFAULT 0, failure varchar(190) NOT NULL DEFAULT '', checkout_url text DEFAULT NULL, created_at datetime NOT NULL, checked_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY remote_payment (mode,remote_id), UNIQUE KEY command_payment (mode,command_id), KEY customer (mode,customer_id)", 'subscriptions' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, remote_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, customer_id bigint unsigned NOT NULL, command_id bigint unsigned NOT NULL, mandate_id varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, amount bigint NOT NULL, interval_value varchar(24) NOT NULL, start_date date NOT NULL, next_date date DEFAULT NULL, status varchar(24) NOT NULL, times int unsigned DEFAULT NULL, checked_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY remote_subscription (mode,remote_id), UNIQUE KEY subscription_command (mode,command_id), KEY customer_status (mode,customer_id,status)", 'tokens' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, token_hash varchar(64) NOT NULL, purpose varchar(24) NOT NULL, customer_id bigint unsigned NOT NULL DEFAULT 0, binding varchar(64) NOT NULL DEFAULT '', data longtext NOT NULL, expires_at datetime NOT NULL, used_at datetime DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY token (mode,token_hash), KEY expires (expires_at)", 'jobs' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, kind varchar(32) NOT NULL, dedup varchar(190) NOT NULL, payload longtext NOT NULL, state varchar(16) NOT NULL DEFAULT 'pending', generation bigint unsigned NOT NULL DEFAULT 1, attempts int unsigned NOT NULL DEFAULT 0, run_at datetime NOT NULL, lease varchar(64) DEFAULT NULL, lease_until datetime DEFAULT NULL, error varchar(64) NOT NULL DEFAULT '', updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY job (mode,dedup), KEY due_jobs (mode,state,run_at)", 'notifications' => "id bigint unsigned NOT NULL AUTO_INCREMENT, mode varchar(4) NOT NULL, event_key varchar(190) NOT NULL, customer_id bigint unsigned NOT NULL, kind varchar(32) NOT NULL, data longtext NOT NULL, state varchar(24) NOT NULL DEFAULT 'pending', attempts int unsigned NOT NULL DEFAULT 0, sent_at datetime DEFAULT NULL, error varchar(64) NOT NULL DEFAULT '', updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event (mode,event_key), KEY mail_state (mode,state)", 'limits' => "id bigint unsigned NOT NULL AUTO_INCREMENT, bucket varchar(64) NOT NULL, hits int unsigned NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY bucket (bucket)"];
        foreach ($schemas as $name => $body) {
            $body = str_replace(', ', ",\n", $body);
            dbDelta('CREATE TABLE ' . $this->table($name) . ' (' . $body . ") ENGINE=InnoDB {$collate};");
        }
        foreach (array_keys($schemas) as $name) {
            $engine = $this->db->get_var($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $this->table($name)));
            if (strtolower((string) $engine) !== 'innodb') {
                throw new Failure('innodb_required');
            }
        }
        // Migration 2: provider IDs are case sensitive; dbDelta does not migrate collations.
        foreach (['customers' => ['remote_id' => false, 'mandate_id' => false], 'commands' => ['remote_id' => false], 'mandates' => ['remote_id' => true], 'payments' => ['remote_id' => true, 'subscription_id' => false], 'subscriptions' => ['remote_id' => true, 'mandate_id' => true]] as $table => $columns) {
            foreach ($columns as $column => $required) {
                $this->query('ALTER TABLE ' . $this->table($table) . ' MODIFY ' . $column . ' varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin ' . ($required ? 'NOT NULL' : 'DEFAULT NULL'));
            }
        }
        update_option('mi_schema', self::SCHEMA_VERSION, false);
    }
    public function insert(string $table, array $data): int
    {
        if ($this->db->insert($this->table($table), $data) === false) {
            throw new Failure('database_write_failed', true);
        }
        return (int) $this->db->insert_id;
    }
    public function update(string $table, array $data, int $id, string $mode): void
    {
        if ($this->db->update($this->table($table), $data, ['id' => $id, 'mode' => $mode]) === false) {
            throw new Failure('database_write_failed', true);
        }
    }
    public function one(string $table, int $id, string $mode): ?array
    {
        return $this->select('SELECT * FROM ' . $this->table($table) . ' WHERE id=%d AND mode=%s', [$id, $mode])[0] ?? null;
    }
    public function find(string $table, string $column, string $value, string $mode): ?array
    {
        if (!in_array($column, ['reference', 'remote_id', 'token_hash', 'event_key', 'dedup'], true)) {
            throw new Failure('invalid_column');
        }
        return $this->select('SELECT * FROM ' . $this->table($table) . " WHERE {$column}=%s AND mode=%s", [$value, $mode])[0] ?? null;
    }
    public function rows(string $table, string $mode, int $limit = 100): array
    {
        return $this->select('SELECT * FROM ' . $this->table($table) . ' WHERE mode=%s ORDER BY id DESC LIMIT %d', [$mode, min(500, max(1, $limit))]);
    }
    public function query(string $sql, array $args = []): int
    {
        $r = $this->db->query($args ? $this->db->prepare($sql, ...$args) : $sql);
        if ($r === false) {
            throw new Failure('database_write_failed', true);
        }
        return (int) $r;
    }
    public function select(string $sql, array $args = []): array
    {
        $rows = $this->db->get_results($args ? $this->db->prepare($sql, ...$args) : $sql, ARRAY_A);
        if ($this->db->last_error) {
            throw new Failure('database_read_failed', true);
        }
        return $rows ?? [];
    }
    public function transaction(callable $callback): mixed
    {
        $this->query('START TRANSACTION');
        try {
            $value = $callback();
            $this->query('COMMIT');
            return $value;
        } catch (\Throwable $e) {
            $this->query('ROLLBACK');
            throw $e;
        }
    }
    public function lock(string $resource, callable $callback): mixed
    {
        $name = 'mi:' . hash('sha1', $this->db->prefix . '|' . $resource);
        if ((int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)', $name)) !== 1) {
            throw new Failure('busy', true);
        }
        try {
            return $callback();
        } finally {
            $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
    public function limit(string $bucket, int $max, int $seconds): void
    {
        $key = hash_hmac('sha256', $bucket, wp_salt('nonce'));
        $now = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + $seconds);
        $this->query('INSERT INTO ' . $this->table('limits') . ' (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=IF(expires_at<%s,1,hits+1),expires_at=IF(expires_at<%s,VALUES(expires_at),expires_at)', [$key, $expires, $now, $now]);
        $hits = $this->db->get_var($this->db->prepare('SELECT hits FROM ' . $this->table('limits') . ' WHERE bucket=%s', $key));
        if ((int) $hits > $max) {
            throw new Failure('rate_limited');
        }
    }
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
