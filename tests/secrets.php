<?php
// Request-local tests. No API calls, key persistence or real mail.
define('WP_INSTALLING', true);
require getenv('MI_WP_ROOT') ?: '/var/www/html/wp-load.php';
require dirname(__DIR__) . '/mollie-incasso.php';
require __DIR__ . '/FixtureOptions.php';
use Lukraak\MollieIncasso\{Config, Failure};
use Lukraak\MollieIncasso\Tests\FixtureOptions;
FixtureOptions::start();
FixtureOptions::scope('mi_secret_test', '');
FixtureOptions::scope('mi_secret_live', '');
$name = 'MOLLIE_INCASSO_ENCRYPTION_KEY';
if (defined($name)) { throw new RuntimeException('Run in a fixture without an encryption-key constant.'); }
$previous = getenv($name);
putenv($name);
$checks=0;
function verify(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo "PASS $label\n"; }
$config = new Config();
$secret = 'test_MOCKKEYONLY000000000000000000000000';
try {
    $sealed = $config->encrypt($secret);
    verify(str_starts_with($sealed, 'wp1:') && !str_contains($sealed, $secret), 'WordPress default encrypts without a separate key');
    verify($config->decrypt($sealed) === $secret, 'WordPress default round trip');
    verify($sealed !== $config->encrypt($secret), 'fresh random nonce for each encryption');
    FixtureOptions::set('mi_secret_test', $sealed);
    verify($config->key('test') === $secret, 'saved WordPress ciphertext loads through normal key lookup');
    $raw=base64_decode(substr($sealed,4));$raw[28]=chr(ord($raw[28])^1);
    try { $config->decrypt('wp1:'.base64_encode($raw)); throw new RuntimeException('Tampered ciphertext accepted'); }
    catch (Failure $e) { verify($e->reason==='decryption_failed', 'tampered ciphertext rejected'); }
    putenv($name.'='.base64_encode(random_bytes(32)));
    $legacy=$config->encrypt($secret);
    verify(!str_starts_with($legacy,'wp1:') && $config->decrypt($legacy)===$secret, 'external-key ciphertext remains backward compatible');
    verify($config->decrypt($sealed)===$secret, 'adding an external key preserves existing WordPress ciphertext');
    putenv($name.'='.base64_encode(random_bytes(32)));
    try { $config->decrypt($legacy); throw new RuntimeException('Wrong external key accepted'); }
    catch (Failure $e) { verify($e->reason==='decryption_failed', 'wrong external key rejected'); }
    putenv($name.'=invalid');
    try { $config->encrypt($secret); throw new RuntimeException('Invalid external key silently ignored'); }
    catch (Failure $e) { verify($e->reason==='encryption_key_missing', 'invalid explicit external key does not silently downgrade'); }
    verify($config->decrypt($sealed)===$secret, 'existing WordPress ciphertext ignores unrelated external-key changes');
    putenv($name);
    try { $config->decrypt('wp1:broken'); throw new RuntimeException('Malformed ciphertext accepted'); }
    catch (Failure $e) { verify($e->reason==='decryption_failed', 'malformed ciphertext rejected'); }
    foreach (['missing', 'placeholder'] as $scenario) {
        $child = 'require ' . var_export(dirname(__DIR__).'/src/Failure.php', true) . ';require ' . var_export(dirname(__DIR__).'/src/Config.php', true) . ';';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $index => $constant) {
            if ($scenario==='missing' && $index===3) continue;
            $value=$scenario==='placeholder' && $index===3 ? 'put your unique phrase here' : str_repeat(chr(65+$index),64);
            $child .= 'define('.var_export($constant,true).','.var_export($value,true).');';
        }
        $child .= 'try{(new Lukraak\\MollieIncasso\\Config())->encrypt("mock");exit(1);}catch(Lukraak\\MollieIncasso\\Failure $e){exit($e->reason==="wordpress_keys_missing"?0:2);}';
        $process=proc_open([PHP_BINARY,'-r',$child],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot run isolated config test');
        foreach ($pipes as $pipe) fclose($pipe);
        verify(proc_close($process)===0, $scenario.' WordPress secrets block storage');
    }
    echo "RESULT $checks key-storage checks passed. No API calls or persistent settings.\n";
} finally {
    if ($previous===false) putenv($name); else putenv($name.'='.$previous);
    FixtureOptions::finish();
}
