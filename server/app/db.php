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
    $t[] = "CREATE TABLE IF NOT EXISTS operators (
        id $pk,
        code VARCHAR(30) NOT NULL,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(10) NULL,
        merchant_number VARCHAR(40) NULL,
        ussd_codes $text NULL,
        pay_link VARCHAR(500) NULL,
        sms_sender VARCHAR(255) NULL,
        instructions $text NULL,
        active SMALLINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS telegram_chats (
        id $pk,
        chat_id VARCHAR(30) NOT NULL UNIQUE,
        merchant_id $fk NULL,
        title VARCHAR(190) NULL,
        chat_type VARCHAR(20) NULL,
        notify SMALLINT NOT NULL DEFAULT 1,
        can_act SMALLINT NOT NULL DEFAULT 1,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS telegram_links (
        code VARCHAR(20) NOT NULL PRIMARY KEY,
        merchant_id $fk NULL,
        can_act SMALLINT NOT NULL DEFAULT 1,
        expires_at $dt NOT NULL,
        created_at $dt NOT NULL
    )$engine";
    $t[] = "CREATE TABLE IF NOT EXISTS payouts (
        id $pk,
        reference VARCHAR(40) NOT NULL UNIQUE,
        merchant_id $fk NULL,
        scope_id $fk NULL,
        payment_id $fk NULL,
        kind VARCHAR(20) NOT NULL DEFAULT 'payout',
        operator VARCHAR(30) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        external_ref VARCHAR(190) NULL,
        description VARCHAR(255) NULL,
        callback_url VARCHAR(500) NULL,
        metadata $text NULL,
        status VARCHAR(20) NOT NULL,
        device_id $fk NULL,
        attempts SMALLINT NOT NULL DEFAULT 0,
        result_text VARCHAR(500) NULL,
        operator_txn_id VARCHAR(100) NULL,
        created_by VARCHAR(60) NULL,
        approved_by VARCHAR(60) NULL,
        created_at $dt NOT NULL,
        approved_at $dt NULL,
        sent_at $dt NULL,
        completed_at $dt NULL
    )$engine";
    $t[] = "CREATE INDEX idx_payout_queue ON payouts (status, scope_id)";
    $t[] = "CREATE TABLE IF NOT EXISTS announcements (
        id $pk,
        title VARCHAR(190) NOT NULL,
        body $text NULL,
        link VARCHAR(500) NULL,
        audience VARCHAR(20) NOT NULL DEFAULT 'all',
        sent INT NOT NULL DEFAULT 0,
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

/** Version du schéma : les changements faits après l'installation sont appliqués automatiquement. */
const SCHEMA_VERSION = 16; // 11 : bot Telegram ; 12 : annonces ; 13 : envois d'argent (payouts) ; 14 : envois Wave par l'application

function db_add_column(string $table, string $column, string $mysqlType, string $sqliteType): void
{
    try {
        db()->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, db_driver() === 'sqlite' ? $sqliteType : $mysqlType));
    } catch (PDOException $e) {
        // La colonne existe déjà.
    }
}

function db_migrate(): void
{
    $v = (int)setting('schema_version', '1');
    if ($v >= SCHEMA_VERSION) {
        return;
    }
    db_install_schema(db(), db_driver()); // nouvelles tables (push, opérateurs)
    if ($v < 3) {
        db_add_column('payments', 'operator_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
        db_add_column('payments', 'paid_operator', 'VARCHAR(30) NULL', 'TEXT NULL');
        db_add_column('sms_messages', 'operator', 'VARCHAR(30) NULL', 'TEXT NULL');
        db_add_column('devices', 'balances', 'TEXT NULL', 'TEXT NULL');
        // Ancien solde unique -> solde de l'opérateur principal (les opérateurs sont créés plus bas).
        $first = 'orange';
        foreach (q_all('SELECT id, last_balance FROM devices WHERE last_balance IS NOT NULL') as $d) {
            device_set_balance((int)$d['id'], $first, (float)$d['last_balance']);
        }
        sms_rules_add_missing_defaults();
    }
    if ($v < 4) {
        db_add_column('operators', 'ussd_primary', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
        db_add_column('operators', 'ussd_show_alt', 'SMALLINT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
        operators_reset_cache();
    }
    if ($v < 5) {
        db_add_column('operators', 'app_package', 'VARCHAR(255) NULL', 'TEXT NULL');
        db_add_column('sms_messages', 'source', 'VARCHAR(20) NULL', 'TEXT NULL');
        // Wave prévient surtout par notification de son application.
        q("UPDATE operators SET app_package = ? WHERE code = 'wave' AND (app_package IS NULL OR app_package = '')", ['^com\\.wave\\.']);
        operators_reset_cache();
    }
    if ($v < 6) {
        db_add_column('devices', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'android'", "TEXT NOT NULL DEFAULT 'android'");
    }
    if ($v < 7) {
        // Espace marchand : comptes autonomes avec leurs propres opérateurs et téléphones.
        db_add_column('merchants', 'password_hash', 'VARCHAR(255) NULL', 'TEXT NULL');
        db_add_column('merchants', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'internal'", "TEXT NOT NULL DEFAULT 'internal'");
        db_add_column('merchants', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'", "TEXT NOT NULL DEFAULT 'active'");
        db_add_column('operators', 'merchant_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
        db_add_column('devices', 'merchant_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
        db_add_column('sms_messages', 'merchant_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
        db_add_column('push_subscriptions', 'merchant_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
        operators_drop_code_unique();
        try {
            db()->exec('CREATE INDEX idx_op_scope ON operators (merchant_id, code)');
        } catch (PDOException $e) {
        }
        operators_reset_cache();
    }
    if ($v < 8) {
        db_add_column('merchants', 'paid_until', 'DATETIME NULL', 'TEXT NULL');
    }
    if ($v < 10) {
        db_add_column('operators', 'fee_percent', 'DECIMAL(5,2) NULL', 'REAL NULL');
        q("UPDATE operators SET fee_percent = 0.5 WHERE code = 'wave' AND fee_percent IS NULL");
        q('UPDATE operators SET instructions = ? WHERE instructions = ?', ["Ouvrez l'application Wave, touchez « Envoyer de l'argent » et saisissez le numéro indiqué.", "Ouvrez l'application Wave et payez le montant exact au numéro indiqué."]);
        operators_reset_cache();
    }
    if ($v < 9) {
        sms_rules_upgrade_from_defaults(['Wave - argent reçu']);
    }
    if ($v < 13) {
        // Envois d'argent : code USSD d'envoi par opérateur (le code secret reste dans le téléphone).
        db_add_column('operators', 'payout_ussd', 'VARCHAR(255) NULL', 'TEXT NULL');
        db_add_column('operators', 'payout_success', 'VARCHAR(255) NULL', 'TEXT NULL');
        db_add_column('webhook_deliveries', 'merchant_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
        q("UPDATE operators SET payout_ussd = ? WHERE code = 'orange' AND (payout_ussd IS NULL OR payout_ussd = '')", ['*144*2*1*{number}*{amount}#']);
        q("UPDATE operators SET payout_ussd = ? WHERE code = 'moov' AND (payout_ussd IS NULL OR payout_ussd = '')", ['*555*2*1*{number}*{amount}#']);
        operators_reset_cache();
    }
    if ($v < 16) {
        // Demande du propriétaire : les envois de l'API partent automatiquement (plafonds conservés).
        if (setting('payout_api_auto') === null) {
            setting_set('payout_api_auto', '1');
        }
    }
    if ($v < 15) {
        // Opérateurs que chaque téléphone sait utiliser pour envoyer (diagnostic « personne ne prend l'envoi »).
        db_add_column('devices', 'payout_ops', 'VARCHAR(255) NULL', 'TEXT NULL');
        db_add_column('devices', 'payout_poll_at', 'DATETIME NULL', 'TEXT NULL');
    }
    if ($v < 14) {
        // Wave n'a pas de code USSD : le téléphone pilote l'application Wave (« app:wave »).
        q("UPDATE operators SET payout_ussd = 'app:wave' WHERE code = 'wave' AND (payout_ussd IS NULL OR payout_ussd = '')");
        operators_reset_cache();
    }
    // Opérateurs par défaut de la plateforme (une fois toutes les colonnes créées).
    operators_reset_cache();
    operators_seed(null);
    operators_reset_cache();
    setting_set('schema_version', (string)SCHEMA_VERSION);
}

/** Le code d'opérateur n'est plus unique globalement (chaque marchand peut avoir son « orange »). */
function operators_drop_code_unique(): void
{
    $pdo = db();
    if (db_driver() === 'sqlite') {
        $sql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'operators'")->fetchColumn();
        if (!preg_match('/code\s+VARCHAR\(30\)\s+NOT NULL\s+UNIQUE/i', $sql)) {
            return;
        }
        $new = preg_replace('/code\s+VARCHAR\(30\)\s+NOT NULL\s+UNIQUE/i', 'code VARCHAR(30) NOT NULL', $sql);
        $new = preg_replace('/CREATE TABLE\s+(IF NOT EXISTS\s+)?"?operators"?/i', 'CREATE TABLE operators_new', $new, 1);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        $pdo->exec($new);
        $pdo->exec('INSERT INTO operators_new SELECT * FROM operators');
        $pdo->exec('DROP TABLE operators');
        $pdo->exec('ALTER TABLE operators_new RENAME TO operators');
        $pdo->commit();
        return;
    }
    foreach ($pdo->query("SHOW INDEX FROM operators WHERE Column_name = 'code' AND Non_unique = 0")->fetchAll() as $ix) {
        try {
            $pdo->exec('ALTER TABLE operators DROP INDEX `' . str_replace('`', '', $ix['Key_name']) . '`');
        } catch (PDOException $e) {
        }
    }
}
