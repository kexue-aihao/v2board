<?php
// Does not boot Laravel or access the database. Deployment verifies committed
// builds without requiring Node/npm on the production server.
$root = dirname(__DIR__);
$manifestFile = $root . '/resources/admin/build/manifest.json';
$manifest = is_file($manifestFile) ? json_decode(file_get_contents($manifestFile), true) : null;
if (!$manifest || ($manifest['version'] ?? null) !== 1) {
    fwrite(STDERR, "Missing 4A build manifest. Run npm ci --prefix scripts && npm run build --prefix scripts before deployment.\n");
    exit(1);
}
foreach ($manifest['sources'] as $file => $expected) {
    $path = $root . '/' . $file;
    $actual = is_file($path) ? hash('sha256', str_replace("\r\n", "\n", file_get_contents($path))) : '';
    if (!hash_equals($expected, $actual)) {
        fwrite(STDERR, "4A build is stale: {$file}. Rebuild and include the generated assets.\n");
        exit(1);
    }
}
foreach (['guest', 'super', 'operations', 'finance', 'support', 'marketing'] as $role) {
    $path = $root . ($role === 'guest' ? '/public/assets/admin/login.js' : '/resources/admin/build/' . $role . '.js');
    $actual = is_file($path) ? hash('sha256', str_replace("\r\n", "\n", file_get_contents($path))) : '';
    if (!hash_equals($manifest['roles'][$role]['sha256'] ?? '', $actual)) {
        fwrite(STDERR, "4A asset missing or changed: {$role}.\n");
        exit(1);
    }
}
foreach (['umi.js', 'vendors.async.js', 'components.async.js'] as $name) {
    if (file_exists($root . '/public/assets/admin/' . $name)) {
        fwrite(STDERR, "Unsafe old public administrator bundle remains: {$name}. Move it outside the web root before deployment.\n");
        exit(1);
    }
}
fwrite(STDOUT, "4A role bundles verified; original business modules are private.\n");
