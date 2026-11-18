<?php
declare(strict_types=1);

/**
 * Opérateurs Mobile Money (Orange Money, Moov Money, Telecel Money, Wave…).
 * Chaque opérateur a son numéro marchand, ses codes USSD, un lien de paiement éventuel
 * et le motif d'expéditeur de ses SMS.
 */

/** Valeur spéciale : « le périmètre du contexte courant » (admin = plateforme, marchand = le sien). */
const SCOPE_AUTO = 'auto';

function scope_resolve($scope): ?int
{
    return $scope === SCOPE_AUTO ? current_scope() : ($scope === null ? null : (int)$scope);
}

function operators_all(): array
{
    if (!isset($GLOBALS['__operators'])) {
        try {
            $GLOBALS['__operators'] = q_all('SELECT * FROM operators ORDER BY sort_order, id');
        } catch (Throwable $e) {
            $GLOBALS['__operators'] = [];
        }
    }
    return $GLOBALS['__operators'];
}

/** Opérateurs d'un périmètre (null = plateforme, id = marchand autonome). */
function operators(bool $activeOnly = false, $scope = SCOPE_AUTO): array
{
    $scope = scope_resolve($scope);
    $list = array_filter(operators_all(), function ($o) use ($scope, $activeOnly) {
        $mid = isset($o['merchant_id']) && $o['merchant_id'] !== null ? (int)$o['merchant_id'] : null;
        return $mid === $scope && (!$activeOnly || (int)$o['active'] === 1);
    });
    return array_values($list);
}

function operators_reset_cache(): void
{
    unset($GLOBALS['__operators']);
}

function operator_by_code(?string $code, $scope = SCOPE_AUTO): ?array
{
    foreach (operators(false, $scope) as $o) {
        if ($code !== null && strcasecmp($o['code'], $code) === 0) {
            return $o;
        }
    }
    return null;
}

/** Par identifiant, tous périmètres confondus (vérifier l'appartenance avec operator_in_scope). */
function operator_by_id($id): ?array
{
    foreach (operators_all() as $o) {
        if ((int)$o['id'] === (int)$id) {
            return $o;
        }
    }
    return null;
}

function operator_in_scope(?array $op, ?int $scope): bool
{
    if (!$op) {
        return false;
    }
    $mid = $op['merchant_id'] !== null ? (int)$op['merchant_id'] : null;
    return $mid === $scope;
}

/** Opérateur par défaut : le premier actif du périmètre. */
function operator_default($scope = SCOPE_AUTO): ?array
{
    return operators(true, $scope)[0] ?? null;
}

/** Retrouve l'opérateur à partir de l'expéditeur d'un SMS. */
function operator_for_sender(string $sender, $scope = SCOPE_AUTO): ?array
{
    foreach (operators(false, $scope) as $o) {
        if ($o['sms_sender'] !== null && trim($o['sms_sender']) !== '' && rx_test($o['sms_sender'], $sender)) {
            return $o;
        }
    }
    return null;
}

/** Noms des opérateurs actifs, ex. "Orange Money, Moov Money et Wave". */
function operators_names($scope = SCOPE_AUTO): string
{
    $names = array_column(operators(true, $scope), 'name');
    if (!$names) {
        return 'Mobile Money';
    }
    $last = array_pop($names);
    return $names ? implode(', ', $names) . ' et ' . $last : $last;
}

function operator_fill(array $op, string $template, float $amount): string
{
    return strtr($template, [
        '{number}' => preg_replace('/\D+/', '', (string)$op['merchant_number']),
        '{amount}' => (string)(int)round($amount),
    ]);
}

/** @return array<int, array{label:string, template:string}> */
function operator_ussd_templates(array $op): array
{
    $out = [];
    foreach (preg_split('/\R/', (string)$op['ussd_codes']) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$label, $tpl] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : ['Code USSD', $line];
        if ($tpl !== '') {
            $out[] = ['label' => $label !== '' ? $label : 'Code USSD', 'template' => $tpl];
        }
    }
    return $out;
}

/** @return array<int, array{label:string, code:string, link:string}> */
function operator_ussd_options(array $op, float $amount, bool $all = false): array
{
    $list = array_map(function ($t) use ($op, $amount) {
        $code = operator_fill($op, $t['template'], $amount);
        return ['label' => $t['label'], 'code' => $code, 'link' => ussd_link($code)];
    }, operator_ussd_templates($op));
    if ($all || !$list) {
        return $list;
    }
    // Code choisi par l'administrateur en premier.
    $i = (int)($op['ussd_primary'] ?? 0);
    if (!isset($list[$i])) {
        $i = 0;
    }
    $chosen = $list[$i];
    if (empty($op['ussd_show_alt'])) {
        return [$chosen]; // un seul code affiché aux clients
    }
    unset($list[$i]);
    return array_merge([$chosen], array_values($list));
}

function operator_pay_link(array $op, float $amount): ?string
{
    $tpl = trim((string)$op['pay_link']);
    return $tpl === '' ? null : operator_fill($op, $tpl, $amount);
}

/** Un opérateur est utilisable s'il a un numéro marchand ou un lien de paiement. */
function operator_ready(array $op): bool
{
    return (int)$op['active'] === 1 && (trim((string)$op['merchant_number']) !== '' || trim((string)$op['pay_link']) !== '');
}

/** Représentation publique (API et page de paiement). */
function operator_public(array $op, float $amount): array
{
    $opts = operator_ussd_options($op, $amount);
    return [
        'code' => $op['code'],
        'name' => $op['name'],
        'color' => $op['color'],
        'merchant_number' => $op['merchant_number'],
        'ussd_code' => $opts[0]['code'] ?? null,
        'ussd_link' => $opts[0]['link'] ?? null,
        'ussd_options' => $opts,
        'pay_link' => operator_pay_link($op, $amount),
        // Frais à la charge du client (ex. Wave 0,5 %) : il doit faire RECEVOIR le montant exact.
        'fee_percent' => (float)($op['fee_percent'] ?? 0),
        'amount_to_send' => operator_amount_to_send($op, $amount),
        'instructions' => $op['instructions'] ?: null,
    ];
}

/** Montant que le client paie, frais de l'opérateur compris (arrondi au franc supérieur). */
function operator_amount_to_send(array $op, float $amount): float
{
    $fee = (float)($op['fee_percent'] ?? 0);
    return $fee > 0 ? (float)ceil($amount * (1 + $fee / 100)) : $amount;
}

/** Opérateurs à proposer au client pour ce paiement (ceux du marchand autonome, sinon ceux de la plateforme). */
function payment_operators(array $payment): array
{
    $chosen = !empty($payment['operator_id']) ? operator_by_id($payment['operator_id']) : null;
    if ($chosen) {
        return [$chosen];
    }
    return array_values(array_filter(operators(true, payment_scope($payment)), 'operator_ready'));
}

/* ---------- Soldes par téléphone ET par opérateur (téléphones double SIM) ---------- */

function device_balances(array $device): array
{
    $b = json_decode((string)($device['balances'] ?? ''), true);
    return is_array($b) ? $b : [];
}

function device_balance(array $device, ?string $opCode): ?float
{
    $b = device_balances($device);
    $key = $opCode ?: '_';
    return isset($b[$key]) && $b[$key] !== null ? (float)$b[$key] : null;
}

function device_set_balance(int $deviceId, ?string $opCode, ?float $balance): void
{
    $d = q_one('SELECT balances FROM devices WHERE id = ?', [$deviceId]);
    $b = device_balances($d ?: []);
    $key = $opCode ?: '_';
    if ($balance === null) {
        unset($b[$key]);
    } else {
        $b[$key] = $balance;
    }
    db_update('devices', ['balances' => json_encode($b)], 'id = :id', ['id' => $deviceId]);
}

/* ---------- Migration : création et remplissage de la table des opérateurs ---------- */

function operators_seed(?int $scope = null): void
{
    $params = [];
    if ((int)q_val('SELECT COUNT(*) FROM operators WHERE ' . scope_sql('merchant_id', $scope, $params), $params) > 0) {
        return;
    }
    $oldCodes = $scope === null ? trim((string)setting('ussd_codes', '')) : '';
    if ($oldCodes === '') {
        $oldCodes = 'Transfert | ' . (setting('ussd_template') ?: '*144*10*{number}*{amount}#') . "\nPaiement marchand | *144*2*1*{number}*{amount}#";
    }
    $rows = [
        ['orange', $scope === null ? (string)(setting('operator_name') ?: 'Orange Money') : 'Orange Money', '#ff7900', $scope === null ? (string)setting('merchant_number', '') : '', $oldCodes, '', 'orange|^OM$', '', $scope === null ? 1 : 0],
        ['moov', 'Moov Money', '#005baa', '', '', '', 'moov|flooz', '', 0],
        ['telecel', 'Telecel Money', '#e30613', '', '', '', 'telecel', '', 0],
        ['wave', 'Wave', '#1dc4ff', '', '', '', 'wave', "Ouvrez l'application Wave, touchez « Envoyer de l'argent » et saisissez le numéro indiqué.", 0],
    ];
    foreach ($rows as $i => [$code, $name, $color, $num, $ussd, $link, $sender, $instr, $active]) {
        db_insert('operators', [
            'code' => $code, 'name' => $name, 'color' => $color, 'merchant_number' => $num, 'ussd_codes' => $ussd,
            'pay_link' => $link, 'sms_sender' => $sender, 'instructions' => $instr, 'active' => $active,
            'sort_order' => $i + 1, 'created_at' => now(), 'merchant_id' => $scope,
            'app_package' => $code === 'wave' ? '^com\\.wave\\.' : null,
            'fee_percent' => $code === 'wave' ? 0.5 : null,
            'payout_ussd' => ['orange' => '*144*2*1*{number}*{amount}#', 'moov' => '*555*2*1*{number}*{amount}#'][$code] ?? null,
        ]);
    }
}
