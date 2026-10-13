<?php
use Symfony\Component\Finder\Finder;
return [
    'prefix'=>'Lukraak\\MollieIncasso\\Vendor',
    'finders'=>[Finder::create()->files()->in(getcwd().'/vendor')->exclude(['tests','Tests','docs','.github'])->notPath('#^composer/(?!ca-bundle/)#')->notName(['autoload.php','composer.json','composer.lock'])],
    'expose-global-classes'=>false,'expose-global-functions'=>false,'expose-global-constants'=>false,
];
