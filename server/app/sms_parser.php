<?php
declare(strict_types=1);

/**
 * Règles de lecture des SMS. Chaque règle :
 *  - name     : nom affiché
 *  - kind     : "incoming" (argent reçu = paiement client) ou "outgoing" (argent sorti, sert au suivi du solde)
 *  - sender   : regex sur l'expéditeur du SMS (ex. "OrangeMoney")
 *  - require  : regex qui doit être présente dans le texte
 *  - exclude  : regex qui ne doit PAS être présente (optionnel)
 *  - amount, phone, txn, balance : regex dont le 1er groupe capturé donne la valeur
 * Les regex sont insensibles à la casse. Modifiables dans Admin > Réglages.
 */
function default_sms_rules(): array
{
    $amount = '(\d[\d\s.,]*)\s*(?:F\s?CFA|FCFA|XOF|F)\b';
    $txn = '(?:ID\s*(?:de\s*)?(?:la\s*)?trans(?:action)?|Trans(?:action)?\s*(?:ID|N°|No)?|R[ée]f(?:[ée]rence)?|Txn\s*ID|ID)\s*[:.#]?\s*([A-Z0-9][A-Z0-9.\-]{5,})';
    $balance = 'solde(?:\s+(?:actuel|disponible|est|de|du|compte|OM|Orange\s*Money|Moov\s*Money|Telecel\s*Money|Wave))*\s*(?:est\s*)?(?:de\s*)?:?\s*(\d[\d\s.,]*)\s*(?:F\s?CFA|FCFA|XOF|F)';
    // "du 70123456", "de 70 12 34 56", ou "Prénom Nom (70123456)" (format Wave)
    $phone = '(?:(?:du|de|from|par|exp[ée]diteur|num[ée]ro)\s*:?\s*(?:le\s*)?(?:num[ée]ro\s*)?|\()(\+?\d[\d ]{6,16}\d)(?![\d ]*(?:F\b|FCFA|F\s?CFA|XOF))';
    $incomingAmount = '(?:re[çc]u|recu|received|cr[ée]dit[ée]?|transfert|paiement|montant)\D{0,60}?' . $amount;

    $ops = [
        ['orange', 'Orange Money', 'orange|^OM$'],
        ['moov', 'Moov Money', 'moov|flooz'],
        ['telecel', 'Telecel Money', 'telecel'],
        ['wave', 'Wave', 'wave'],
    ];
    $rules = [];
    foreach ($ops as [$code, $name, $sender]) {
        // Wave termine ses messages par l'identifiant de transaction (ex. « TCT4QUYW5ZFEFASTN »).
        $opTxn = $code === 'wave' ? '(?:\\b|\\s)([A-Z0-9]{12,})\\s*$' : $txn;
        $rules[] = [
            'name' => "$name - argent reçu",
            'operator' => $code,
            'kind' => 'incoming',
            'sender' => $sender,
            'require' => 're[çc]u|recu|received|cr[ée]dit',
            'exclude' => 'vous avez (envoy|pay|retir)|a [ée]t[ée] d[ée]bit',
            'amount' => $incomingAmount,
            'phone' => $phone,
            'txn' => $opTxn,
            'balance' => $balance,
        ];
    }
    $rules[] = [
        'name' => 'Argent sorti (suivi du solde)',
        'kind' => 'outgoing',
        'sender' => implode('|', array_column($ops, 2)),
        'require' => 'envoy|transf[ée]r[ée]|retrait|pay[ée]|d[ée]bit',
        'exclude' => '',
        'amount' => $amount,
        'phone' => '',
        'txn' => $txn,
        'balance' => $balance,
    ];
    return $rules;
}

/** Ajoute aux règles personnalisées les règles par défaut des nouveaux opérateurs (migration). */
function sms_rules_add_missing_defaults(): void
{
    $saved = json_decode((string)setting('sms_rules', ''), true);
    if (!is_array($saved) || !$saved) {
        return; // règles par défaut utilisées : rien à faire
    }
    $names = array_column($saved, 'name');
    $outgoing = array_values(array_filter($saved, fn($r) => ($r['kind'] ?? '') === 'outgoing'));
    $incoming = array_values(array_filter($saved, fn($r) => ($r['kind'] ?? '') !== 'outgoing'));
    foreach (default_sms_rules() as $r) {
        if ($r['kind'] === 'incoming' && !in_array($r['name'], $names, true)) {
            $incoming[] = $r;
        }
    }
    setting_set('sms_rules', json_encode(array_merge($incoming, $outgoing), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Remplace, dans les règles personnalisées, les règles nommées par leur version par défaut à jour. */
function sms_rules_upgrade_from_defaults(array $names): void
{
    $saved = json_decode((string)setting('sms_rules', ''), true);
    if (!is_array($saved) || !$saved) {
        return;
    }
    $defaults = [];
    foreach (default_sms_rules() as $r) {
        $defaults[$r['name']] = $r;
    }
    foreach ($saved as $i => $r) {
        if (in_array($r['name'] ?? '', $names, true) && isset($defaults[$r['name']])) {
            $saved[$i] = $defaults[$r['name']];
        }
    }
    setting_set('sms_rules', json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function sms_rules(): array
{
    $rules = json_decode((string)setting('sms_rules'), true);
    return is_array($rules) && $rules ? $rules : default_sms_rules();
}

function rx(string $pattern): string
{
    return '~' . str_replace('~', '\~', $pattern) . '~iu';
}

function rx_test(string $pattern, string $subject, ?array &$m = null): bool
{
    if (trim($pattern) === '') {
        return false;
    }
    $r = @preg_match(rx($pattern), $subject, $m);
    return $r === 1;
}

function rx_valid(string $pattern): bool
{
    return trim($pattern) === '' || @preg_match(rx($pattern), '') !== false;
}

/**
 * Analyse un SMS. Retourne null si aucune règle ne correspond.
 * @return array{rule:string,kind:string,amount:?float,phone:?string,txn:?string,balance:?float}|null
 */
function parse_sms(string $sender, string $body, ?array $rules = null): ?array
{
    $rules ??= sms_rules();
    $text = preg_replace('/\s+/u', ' ', $body);
    foreach ($rules as $rule) {
        if (!empty($rule['sender']) && !rx_test($rule['sender'], $sender)) {
            continue;
        }
        if (!empty($rule['require']) && !rx_test($rule['require'], $text)) {
            continue;
        }
        if (!empty($rule['exclude']) && rx_test($rule['exclude'], $text)) {
            continue;
        }
        $get = function (string $field) use ($rule, $text): ?string {
            if (empty($rule[$field])) {
                return null;
            }
            return rx_test($rule[$field], $text, $m) && isset($m[1]) ? trim($m[1]) : null;
        };
        $amount = parse_amount($get('amount'));
        if (($rule['kind'] ?? 'incoming') === 'incoming' && ($amount === null || $amount <= 0)) {
            continue; // un SMS "reçu" sans montant lisible n'est pas exploitable par cette règle
        }
        $phone = $get('phone');
        $txn = $get('txn');
        $opCode = $rule['operator'] ?? null;
        if (!$opCode && function_exists('operator_for_sender')) {
            $opCode = operator_for_sender($sender)['code'] ?? null;
        }
        return [
            'operator' => $opCode,
            'rule' => (string)($rule['name'] ?? 'règle'),
            'kind' => (string)($rule['kind'] ?? 'incoming'),
            'amount' => $amount,
            'phone' => $phone !== null ? preg_replace('/\D+/', '', $phone) : null,
            'txn' => $txn !== null ? rtrim($txn, '.-') : null,
            'balance' => parse_amount($get('balance')),
        ];
    }
    return null;
}
