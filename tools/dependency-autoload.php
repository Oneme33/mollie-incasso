<?php
// Generated build loader: private namespaces only, without aliases or Composer globals.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lukraak\\MollieIncasso\\Vendor\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $class = substr($class, strlen($prefix));
    $map = ['Mollie\\Api\\'=>'mollie/mollie-api-php/src/', 'Nyholm\\Psr7\\'=>'nyholm/psr7/src/', 'Psr\\Http\\Client\\'=>'psr/http-client/src/', 'Psr\\Http\\Message\\'=>['psr/http-message/src/','psr/http-factory/src/'], 'Composer\\CaBundle\\'=>'composer/ca-bundle/src/'];
    foreach ($map as $namespace => $directory) {
        if (str_starts_with($class, $namespace)) {
            foreach((array)$directory as $path){$file = __DIR__ . '/' . $path . str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
                if (is_file($file)) { require $file; return; }}return;
        }
    }
});
