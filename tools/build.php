<?php
// Usage: php tools/build.php /absolute/path/composer.phar [output.zip]
declare(strict_types=1);
date_default_timezone_set('UTC');
if (PHP_VERSION_ID < 80200 || !class_exists(ZipArchive::class)) { throw new RuntimeException('PHP 8.2+ and ext-zip required.'); }
$root = dirname(__DIR__);
$composer = $argv[1] ?? 'composer';
$out = $argv[2] ?? $root.'/dist/mollie-incasso-0.1.0.zip';
$build = $root.'/.build';
function run(array $args, string $cwd): void {
    $process = proc_open(implode(' ',array_map('escapeshellarg',$args)),[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$cwd);
    if (!is_resource($process) || proc_close($process)!==0) { throw new RuntimeException('Build command failed.'); }
}
function clean(string $path): void {
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path) as $file) { if ($file->isDir() && !$file->isLink()) { clean($file->getPathname()); } else { unlink($file->getPathname()); } } rmdir($path);
}
clean($build);mkdir($build,0755,true);
foreach(['composer.json','composer.lock','tools/scoper.inc.php'] as $file){copy($root.'/'.$file,$build.'/'.basename($file));}
$composerCommand = str_ends_with($composer,'.phar') || is_file($composer) ? [PHP_BINARY,$composer] : [$composer];
run([...$composerCommand,'install','--no-dev','--prefer-dist','--no-interaction','--no-scripts','--no-plugins'], $build);
run([...$composerCommand,'install','--prefer-dist','--no-interaction','--no-scripts','--no-plugins'], $root.'/tools');
run([PHP_BINARY,$root.'/tools/vendor/bin/php-scoper','add-prefix','--config=scoper.inc.php','--output-dir='.$root.'/dependencies','--force','--no-interaction'], $build);
copy($root.'/tools/dependency-autoload.php',$root.'/dependencies/autoload.php');
// Verify private SDK, its PSR dependencies and CA bundle without making an API call.
require $root.'/dependencies/autoload.php';
$client = new Lukraak\MollieIncasso\Vendor\Mollie\Api\MollieApiClient();
$ca = Lukraak\MollieIncasso\Vendor\Composer\CaBundle\CaBundle::getBundledCaBundlePath();
if (!is_file($ca) || class_exists('Mollie\\Api\\MollieApiClient',false)) { throw new RuntimeException('Dependency isolation failed.'); }
if (!is_dir(dirname($out))) { mkdir(dirname($out),0755,true); }
$zip = new ZipArchive();$zip->open($out,ZipArchive::CREATE|ZipArchive::OVERWRITE);
$files=[];
foreach(['mollie-incasso.php','uninstall.php','README.md','TESTING.md','LICENSE','src','assets','languages','dependencies'] as $entry){
    $path=$root.'/'.$entry;if(!file_exists($path)){throw new RuntimeException('Missing release file: '.$entry);}
    if(is_file($path)){$files[$entry]=$path;continue;}
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)) as $file){if($file->isFile()&&!$file->isLink()){$files[substr($file->getPathname(),strlen($root)+1)]=$file->getPathname();}}
}
ksort($files);
foreach($files as $name=>$path){if(str_ends_with($name,'.php')){$output=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1',$output,$code);if($code!==0){throw new RuntimeException(implode("\n",$output));}}$zip->addFile($path,'mollie-incasso/'.$name);$zip->setMtimeName('mollie-incasso/'.$name,1704067200);$zip->setCompressionName('mollie-incasso/'.$name,ZipArchive::CM_DEFLATE,9);$zip->setExternalAttributesName('mollie-incasso/'.$name,ZipArchive::OPSYS_UNIX,0100644<<16);}
$zip->close();echo 'Built: '.$out.' ('.hash_file('sha256',$out).")\n";
