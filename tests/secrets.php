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
$fixtureRoot=sys_get_temp_dir().'/mi-keyfile-'.bin2hex(random_bytes(5));mkdir($fixtureRoot,0700);
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
        $childRoot=$fixtureRoot.'/'.$scenario;mkdir($childRoot,0700);
        $child = 'define("WP_CONTENT_DIR",'.var_export($childRoot,true).');define("ABSPATH",'.var_export(ABSPATH,true).');require '.var_export(dirname(__DIR__).'/src/KeyFile.php',true).';require ' . var_export(dirname(__DIR__).'/src/Failure.php', true) . ';require ' . var_export(dirname(__DIR__).'/src/Config.php', true) . ';';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $index => $constant) {
            if ($scenario==='missing' && $index===3) continue;
            $value=$scenario==='placeholder' && $index===3 ? 'put your unique phrase here' : str_repeat(chr(65+$index),64);
            $child .= 'define('.var_export($constant,true).','.var_export($value,true).');';
        }
        $child .= '$c=new Lukraak\\MollieIncasso\\Config();$s=$c->encrypt("mock");exit(str_starts_with($s,"file1:")&&$c->decrypt($s)==="mock"?0:1);';
        $process=proc_open([PHP_BINARY,'-r',$child],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot run isolated config test');
        foreach ($pipes as $pipe) fclose($pipe);
        verify(proc_close($process)===0, $scenario.' WordPress secrets use automatic file fallback');
    }
    $directory=$fixtureRoot.'/direct';
    $vault=new Lukraak\MollieIncasso\KeyFile($directory);
    $key=$vault->key(true);$path=glob($directory.'/*.php')[0];
    verify(strlen($key)===32 && $key===$vault->key(false),'file vault persists one random 32-byte key');
    verify((fileperms($directory)&0077)===0 && (fileperms($path)&0077)===0,'private directory and file permissions');
    $script='require '.var_export($path,true).';echo "LEAK";';
    $process=proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    verify(proc_close($process)===0 && $output==='' && $error==='','direct execution exits without exposing key');
    $raceDirectory=$fixtureRoot.'/race';$workers=[];
    $child='define("ABSPATH",'.var_export(ABSPATH,true).');require '.var_export(dirname(__DIR__).'/src/Failure.php',true).';require '.var_export(dirname(__DIR__).'/src/KeyFile.php',true).';echo hash("sha256",(new Lukraak\\MollieIncasso\\KeyFile('.var_export($raceDirectory,true).'))->key(true));';
    for($n=0;$n<2;$n++){ $proc=proc_open([PHP_BINARY,'-r',$child],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$proc,$pipes]; }
    $hashes=[];
    foreach($workers as [$proc,$pipes]){fclose($pipes[0]);$hashes[]=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);verify(proc_close($proc)===0 && $err==='','concurrent vault initialization succeeds');}
    verify(strlen($hashes[0])===64 && $hashes[0]===$hashes[1] && count(glob($raceDirectory.'/*.php'))===1,'concurrent requests share exactly one key');
    $bad='<?php corrupted';file_put_contents($path,$bad);
    try{$vault->key(true);throw new RuntimeException('Corrupt key replaced');}catch(Failure $e){verify($e->reason==='key_file_unavailable' && file_get_contents($path)===$bad,'corrupt vault key is never overwritten');}
    unlink($path);try{$vault->key(false);throw new RuntimeException('Missing key silently regenerated');}catch(Failure $e){verify($e->reason==='key_file_unavailable' && !file_exists($path),'decryption cannot regenerate a missing key');}
    $link=$fixtureRoot.'/linked';symlink($directory,$link);
    try{(new Lukraak\MollieIncasso\KeyFile($link))->key(true);throw new RuntimeException('Symlink accepted');}catch(Failure $e){verify($e->reason==='key_file_unavailable','symlink vault rejected');}
    $notDirectory=$fixtureRoot.'/not-a-directory';file_put_contents($notDirectory,'fixture');
    try{(new Lukraak\MollieIncasso\KeyFile($notDirectory))->key(true);throw new RuntimeException('Invalid directory accepted');}catch(Failure $e){verify($e->reason==='key_file_unavailable','unavailable filesystem fails without plaintext fallback');}
    echo "RESULT $checks key-storage checks passed. No API calls or persistent settings.\n";
} finally {
    if ($previous===false) putenv($name); else putenv($name.'='.$previous);
    FixtureOptions::finish();
    $cleanup=static function(string $path) use (&$cleanup):void { if(is_link($path)||!is_dir($path)){unlink($path);return;}foreach(new DirectoryIterator($path) as $entry){if(!$entry->isDot())$cleanup($entry->getPathname());}rmdir($path); };
    $cleanup($fixtureRoot);
}
