<?php

declare(strict_types=1);

$path = __DIR__ . '/../vendor/joanhey/adapterman/src/Http.php';

if (!is_file($path)) {
    exit(0);
}

$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Unable to read AdapterMan HTTP adapter.\n");
    exit(1);
}

$old = '\\json_decode($http_body, true, flags: \\JSON_THROW_ON_ERROR)';
$new = '\\json_decode($http_body === "" ? "{}" : $http_body, true)';

if (strpos($contents, $old) === false) {
    exit(0);
}

$patched = str_replace($old, $new, $contents);
if (file_put_contents($path, $patched) === false) {
    fwrite(STDERR, "Unable to patch AdapterMan HTTP adapter.\n");
    exit(1);
}

echo "AdapterMan JSON body compatibility patch applied.\n";
