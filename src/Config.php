<?php

namespace Lukraak\MollieIncasso;

final class Config
{
    public function settings(): array
    {
        return wp_parse_args(get_option('mi_settings', []), ['mode' => 'test', 'organization' => '', 'address' => '', 'contact' => '', 'privacy_url' => '', 'notifications' => 1, 'cleanup' => 0, 'retention_days' => 2555, 'webhook_url' => '']);
    }
    public function mode(): string
    {
        return $this->settings()['mode'] === 'live' ? 'live' : 'test';
    }
    public function assertRun(string $mode, bool $write = false): void
    {
        if (!in_array($mode, ['test', 'live'], true) || $mode !== $this->mode()) {
            throw new Failure('environment_mismatch');
        }
        if (defined('MOLLIE_INCASSO_DISABLED') && MOLLIE_INCASSO_DISABLED) {
            throw new Failure('automation_disabled');
        }
        if (!hash_equals((string) get_option('mi_site_binding', ''), $this->binding())) {
            throw new Failure('site_copy_blocked');
        }
        if ($mode === 'live' && (wp_get_environment_type() !== 'production' || !defined('MOLLIE_INCASSO_ALLOW_LIVE') || MOLLIE_INCASSO_ALLOW_LIVE !== true || !$this->settingSecret('MOLLIE_INCASSO_LIVE_SITE') || rtrim($this->settingSecret('MOLLIE_INCASSO_LIVE_SITE'), '/') !== rtrim(home_url(), '/'))) {
            throw new Failure('live_disabled');
        }
        if ($write && $mode === 'live' && !is_ssl() && PHP_SAPI !== 'cli') {
            throw new Failure('https_required');
        }
    }
    /**
     * Visitor IP for rate limits. A forwarded header counts only when REMOTE_ADDR is a proxy listed in
     * MOLLIE_INCASSO_TRUSTED_PROXIES (comma-separated IPs/CIDRs), and only the one header named in
     * MOLLIE_INCASSO_CLIENT_IP_HEADER (default X-Forwarded-For) is read. Other headers a proxy merely
     * passes through, such as a client-supplied CF-Connecting-IP, are ignored.
     */
    public function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = array_filter(array_map('trim', explode(',', $this->settingSecret('MOLLIE_INCASSO_TRUSTED_PROXIES'))));
        $ip = $remote;
        if ($trusted && self::inRanges($remote, $trusted)) {
            $header = strtolower(trim($this->settingSecret('MOLLIE_INCASSO_CLIENT_IP_HEADER'))) ?: 'x-forwarded-for';
            $value = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '');
            if ($header === 'x-forwarded-for') {
                // Walk from the right: the first address that is not one of our proxies is the visitor.
                foreach (array_reverse(array_map('trim', explode(',', $value))) as $hop) {
                    if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                        break;
                    }
                    $ip = $hop;
                    if (!self::inRanges($hop, $trusted)) {
                        break;
                    }
                }
            } elseif (filter_var(trim($value), FILTER_VALIDATE_IP)) {
                // Single-value headers (CF-Connecting-IP, X-Real-IP) are set by the trusted proxy itself.
                $ip = trim($value);
            }
        }
        return (string) apply_filters('mollie_incasso_client_ip', $ip, $remote);
    }
    public static function inRanges(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $netPacked = @inet_pton((string) $net);
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : max(0, min(strlen($packed) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($netPacked[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }
    public function binding(): string
    {
        return hash('sha256', home_url() . '|' . ABSPATH);
    }
    public function settingSecret(string $name): string
    {
        return defined($name) ? (string) constant($name) : (string) (getenv($name) ?: '');
    }
    public function key(string $mode): string
    {
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new Failure('invalid_mode');
        }
        $key = $this->settingSecret('MOLLIE_INCASSO_' . strtoupper($mode) . '_KEY');
        if (!$key && get_option('mi_secret_' . $mode)) {
            $key = $this->decrypt((string) get_option('mi_secret_' . $mode));
        }
        if (!preg_match('/^' . $mode . '_[a-zA-Z0-9]{30,100}$/D', $key)) {
            throw new Failure('api_key_missing');
        }
        return $key;
    }
    public function fingerprint(string $mode): string
    {
        return hash('sha256', $this->key($mode));
    }
    public function encryptionKey(): string
    {
        $key = base64_decode($this->settingSecret('MOLLIE_INCASSO_ENCRYPTION_KEY'), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new Failure('encryption_key_missing');
        }
        return $key;
    }
    private function wordpressEncryptionKey(): string
    {
        $parts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $name) {
            $value = defined($name) ? (string) constant($name) : '';
            if (strlen($value) < 32 || str_contains($value, 'put your unique phrase here')) {
                throw new Failure('wordpress_keys_missing');
            }
            $parts[] = $value;
        }
        // Use config constants directly: wp_salt() can fall back to database values.
        return hash_hmac('sha256', 'mollie-incasso:api-key:v1', implode("\0", $parts), true);
    }
    public function encrypt(string $secret): string
    {
        $wordpress = $this->settingSecret('MOLLIE_INCASSO_ENCRYPTION_KEY') === '';
        $prefix = $wordpress ? 'wp1:' : '';
        if ($wordpress) {
            try { $key = $this->wordpressEncryptionKey(); }
            catch (Failure $e) {
                if ($e->reason !== 'wordpress_keys_missing') throw $e;
                $key = (new KeyFile())->key(true);
                $prefix = 'file1:';
            }
        } else { $key = $this->encryptionKey(); }
        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($data === false) {
            throw new Failure('encryption_failed');
        }
        return $prefix . base64_encode($iv . $tag . $data);
    }
    public function decrypt(string $secret): string
    {
        $wordpress = str_starts_with($secret, 'wp1:');
        $file = str_starts_with($secret, 'file1:');
        $key = $file ? (new KeyFile())->key(false) : ($wordpress ? $this->wordpressEncryptionKey() : $this->encryptionKey());
        $raw = base64_decode($file ? substr($secret, 6) : ($wordpress ? substr($secret, 4) : $secret), true);
        if (!$raw || strlen($raw) < 29) {
            throw new Failure('decryption_failed');
        }
        $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($value === false) {
            throw new Failure('decryption_failed');
        }
        return $value;
    }
    public function webhook(string $mode): string
    {
        $custom = trim((string) $this->settings()['webhook_url']);
        $url = $custom ?: rest_url('mollie-incasso/v1/payment/' . $mode);
        if (!str_starts_with($url, 'https://') || !wp_http_validate_url($url)) {
            throw new Failure('webhook_https_required');
        }
        return $custom ? add_query_arg('mi_mode', $mode, $url) : $url;
    }
}
