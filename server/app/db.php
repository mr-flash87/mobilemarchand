<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = $GLOBALS['config']['db'];
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function db_driver(): string
{
    return $GLOBALS['config']['db']['driver'] ?? 'mysql';
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(',', $cols),
        implode(',', array_map(fn($c) => ':' . $c, $cols))
    );
    q($sql, $data);
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    $params = [];
    foreach ($data as $k => $v) {
        $sets[] = "$k = :set_$k";
        $params["set_$k"] = $v;
    }
    $st = q(sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where), array_merge($params, $whereParams));
    return $st->rowCount();
}

/**
 * Schéma compatible MySQL et SQLite.
 */
function db_schema(string $driver): array
{
    $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $fk = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
    $text = $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';
    $engine = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $dt = $driver === 'sqlite' ? 'TEXT' : 'DATETIME';

    $t = [];
    $t[] = "CREATE TABLE IF NOT EXISTS users (
        id $pk,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS settings (
        name VARCHAR(100) NOT NULL PRIMARY KEY,
        value $text
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS merchants (
        id $pk,
        name VARCHAR(190) NOT NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(40) NULL,
        api_key_prefix VARCHAR(20) NOT NULL,
        api_key_hash CHAR(64) NOT NULL UNIQUE,
        webhook_url VARCHAR(500) NULL,
        webhook_secret VARCHAR(100) NOT NULL,
        active SMALLINT NOT NULL DEFAULT 1,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS devices (
        id $pk,
        name VARCHAR(190) NOT NULL,
        sim_number VARCHAR(40) NULL,
        token_hash CHAR(64) NULL UNIQUE,
        pair_code VARCHAR(10) NULL,
        pair_expires_at $dt NULL,
        last_seen_at $dt NULL,
        last_balance DECIMAL(14,2) NULL,
        battery SMALLINT NULL,
        charging SMALLINT NULL,
        app_version VARCHAR(20) NULL,
        active SMALLINT NOT NULL DEFAULT 1,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS payments (
        id $pk,
        reference VARCHAR(40) NOT NULL UNIQUE,
        merchant_id $fk NULL,
        external_ref VARCHAR(190) NULL,
        amount DECIMAL(14,2) NOT NULL,
        currency VARCHAR(5) NOT NULL DEFAULT 'XOF',
        payer_phone VARCHAR(40) NULL,
        payer_phone_key VARCHAR(12) NULL,
        description VARCHAR(255) NULL,
        metadata $text NULL,
        callback_url VARCHAR(500) NULL,
        return_url VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        sms_id $fk NULL,
        operator_txn_id VARCHAR(100) NULL,
        paid_amount DECIMAL(14,2) NULL,
        created_at $dt NOT NULL,
        expires_at $dt NOT NULL,
        paid_at $dt NULL
    )$engine";
    $t[] = "CREATE INDEX idx_pay_match ON payments (status, amount, payer_phone_key)";
    $t[] = "CREATE INDEX idx_pay_merchant ON payments (merchant_id, created_at)";
    $t[] = "CREATE TABLE IF NOT EXISTS sms_messages (
        id $pk,
        device_id $fk NULL,
        sender VARCHAR(60) NOT NULL,
        body $text NOT NULL,
        received_at $dt NOT NULL,
        fingerprint CHAR(64) NOT NULL UNIQUE,
        rule_name VARCHAR(100) NULL,
        kind VARCHAR(20) NULL,
        amount DECIMAL(14,2) NULL,
        phone VARCHAR(40) NULL,
        txn_id VARCHAR(100) NULL,
        balance DECIMAL(14,2) NULL,
        status VARCHAR(20) NOT NULL,
        note VARCHAR(255) NULL,
        payment_id $fk NULL,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE INDEX idx_sms_status ON sms_messages (status, amount)";
    $t[] = "CREATE INDEX idx_sms_txn ON sms_messages (txn_id)";
    $t[] = "CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id $pk,
        payment_id $fk NOT NULL,
        url VARCHAR(500) NOT NULL,
        event VARCHAR(50) NOT NULL,
        payload $text NOT NULL,
        attempts SMALLINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        response_code SMALLINT NULL,
        response_body VARCHAR(1000) NULL,
        next_attempt_at $dt NULL,
        created_at $dt NOT NULL,
        updated_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE INDEX idx_wh_next ON webhook_deliveries (status, next_attempt_at)";
    $t[] = "CREATE TABLE IF NOT EXISTS push_subscriptions (
        id $pk,
        user_id $fk NULL,
        endpoint VARCHAR(500) NOT NULL,
        endpoint_hash CHAR(64) NOT NULL UNIQUE,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(100) NOT NULL,
        user_agent VARCHAR(255) NULL,
        created_at $dt NOT NULL
    )$engine";
    return $t;
}

function db_install_schema(PDO $pdo, string $driver): void
{
    foreach (db_schema($driver) as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Les index existent peut-être déjà (réinstallation) : on ignore.
            if (stripos($sql, 'CREATE INDEX') !== 0) {
                throw $e;
            }
        }
    }
}

/** Version du schéma : les tables ajoutées après l'installation sont créées automatiquement. */
const SCHEMA_VERSION = 2;

function db_migrate(): void
{
    if ((int)setting('schema_version', '1') >= SCHEMA_VERSION) {
        return;
    }
    db_install_schema(db(), db_driver());
    setting_set('schema_version', (string)SCHEMA_VERSION);
}
