<?php
spl_autoload_register(static function ($class) {
    $prefix = 'PhpOffice\\PhpSpreadsheet\\';
    $baseDir = __DIR__ . '/PhpOffice/PhpSpreadsheet/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

return true;
