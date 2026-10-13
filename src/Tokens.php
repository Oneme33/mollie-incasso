<?php

namespace Lukraak\MollieIncasso;

final class Tokens
{
    public function __construct(private readonly Store $store)
    {
    }
    public function issue(string $mode, string $purpose, int $customer = 0, array $data = [], string $binding = '', int $ttl = 604800): string
    {
        $token = bin2hex(random_bytes(32));
        $this->store->insert('tokens', ['mode' => $mode, 'purpose' => $purpose, 'customer_id' => $customer, 'token_hash' => hash('sha256', $token), 'data' => wp_json_encode($data), 'binding' => $binding, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl)]);
        return $token;
    }
    public function resolve(string $mode, string $token, string $purpose, ?string $binding = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new Failure('access_denied');
        }
        $row = $this->store->find('tokens', 'token_hash', hash('sha256', $token), $mode);
        if (!$row || $row['purpose'] !== $purpose || strtotime($row['expires_at'] . ' UTC') < time() || $binding !== null && !hash_equals($row['binding'], $binding)) {
            throw new Failure('access_denied');
        }
        return $row;
    }
    public function csrf(string $token, string $action): string
    {
        return hash_hmac('sha256', $token . '|' . $action . '|' . gmdate('Y-m-d'), wp_salt('nonce'));
    }
    public function verifyCsrf(string $token, string $action, string $value): void
    {
        $today = $this->csrf($token, $action);
        $yesterday = hash_hmac('sha256', $token . '|' . $action . '|' . gmdate('Y-m-d', time() - 86400), wp_salt('nonce'));
        if (!hash_equals($today, $value) && !hash_equals($yesterday, $value)) {
            throw new Failure('csrf_failed');
        }
    }
}
