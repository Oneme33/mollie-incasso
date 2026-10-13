<?php
// Optional development formatting with the parser already locked for PHP-Scoper.
require __DIR__.'/vendor/autoload.php';
$parser=(new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$printer=new PhpParser\PrettyPrinter\Standard();
$root=dirname(__DIR__);
$files=array_merge(glob($root.'/src/*.php'),glob($root.'/tests/*.php'),[$root.'/mollie-incasso.php',$root.'/uninstall.php']);
foreach($files as $file){$nodes=$parser->parse(file_get_contents($file));file_put_contents($file,$printer->prettyPrintFile($nodes)."\n");}
