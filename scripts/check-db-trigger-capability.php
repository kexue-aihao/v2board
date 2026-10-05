<?php

// Read-only preflight using the same cached config / DATABASE_URL / socket /
// TLS options as Artisan. Do not boot providers or create package manifests.
$root = dirname(__DIR__);

try {
    require $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
    (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
    $factory = new Illuminate\Database\Connectors\ConnectionFactory($app);
    $manager = new Illuminate\Database\DatabaseManager($app, $factory);

    $connection = $manager->connection();
    $driver = $connection->getDriverName();

    if ($driver === 'sqlite') {
        // SQLite does not have MySQL binary logging and accepts the trigger
        // syntax used by AdminSecuritySchema through its own branch.
        echo "ready\n";
        exit(0);
    }

    if ($driver !== 'mysql') {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }

    // Always query the writer (also on installations with read replicas).
    $pdo = $connection->getPdo();
    $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN
        ('v2_admin_audit_no_update', 'v2_admin_audit_no_delete')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($triggers) === 2) {
        echo "ready\n";
        exit(0);
    }

    $row = $pdo->query('SELECT @@GLOBAL.log_bin AS log_bin,
        @@GLOBAL.log_bin_trust_function_creators AS trust_function_creators')->fetch(PDO::FETCH_ASSOC);
    if (!(int) $row['log_bin'] || (int) $row['trust_function_creators']) {
        echo "ready\n";
        exit(0);
    }
    // SUPER is sufficient even with trust=OFF. SYSTEM_VARIABLES_ADMIN alone
    // permits SET GLOBAL, but does not bypass the CREATE TRIGGER restriction.
    foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if (preg_match('/^GRANT (.*?) ON \*\.\* TO /i', $grant, $matches)
            && preg_match('/(?:^|,\s*)(SUPER|ALL PRIVILEGES)(?:,|$)/i', $matches[1])) {
            echo "ready\n";
            exit(0);
        }
    }
    // Check that the explicitly supplied admin client reaches this instance
    // before allowing it to change a global variable. No credentials are output.
    $identity = $pdo->query("SELECT SHA2(CONCAT_WS('/', @@hostname, @@port, @@server_id, @@version), 256)")->fetchColumn();
    echo "needs-trust|{$identity}\n";
} catch (Throwable $e) {
    // A connection exception may embed configuration. Keep credentials and
    // connection strings out of deployment logs.
    $detail = $e instanceof PDOException ? 'SQLSTATE ' . $e->getCode() : get_class($e);
    fwrite(STDERR, "Database trigger preflight failed ({$detail}). Check the application database configuration and vendor dependencies.\n");
    exit(1);
}
