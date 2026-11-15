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
    $balance = 'solde(?:\s+(?:actuel|disponible|est|de|du|compte|OM|Orange\s*Money|Moov\s*Money))*\s*(?:est\s*)?(?:de\s*)?:?\s*(\d[\d\s.,]*)\s*(?:F\s?CFA|FCFA|XOF|F)';
    $phone = '(?:du|de|from|par|exp[ée]diteur|num[ée]ro)\s*:?\s*(?:le\s*)?(?:num[ée]ro\s*)?(\+?\d[\d ]{6,16}\d)(?![\d ]*(?:F\b|FCFA|F\s?CFA|XOF))';

    return [
        [
            'name' => 'Orange Money - argent reçu',
            'kind' => 'incoming',
            'sender' => 'orange|^OM$',
            'require' => 're[çc]u|recu|received|cr[ée]dit',
            'exclude' => 'vous avez (envoy|pay|retir)|a [ée]t[ée] d[ée]bit',
            'amount' => '(?:re[çc]u|recu|received|cr[ée]dit[ée]?|transfert|paiement|montant)\D{0,60}?' . $amount,
            'phone' => $phone,
            'txn' => $txn,
            'balance' => $balance,
        ],
        [
            'name' => 'Moov Money - argent reçu',
            'kind' => 'incoming',
            'sender' => 'moov|flooz',
            'require' => 're[çc]u|recu|received|cr[ée]dit',
            'exclude' => 'vous avez (envoy|pay|retir)|a [ée]t[ée] d[ée]bit',
            'amount' => '(?:re[çc]u|recu|received|cr[ée]dit[ée]?|transfert|paiement|montant)\D{0,60}?' . $amount,
            'phone' => $phone,
            'txn' => $txn,
            'balance' => $balance,
        ],
        [
            'name' => 'Argent sorti (suivi du solde)',
            'kind' => 'outgoing',
            'sender' => 'orange|^OM$|moov|flooz',
            'require' => 'envoy|transf[ée]r[ée]|retrait|pay[ée]|d[ée]bit',
            'exclude' => '',
            'amount' => $amount,
            'phone' => '',
            'txn' => $txn,
            'balance' => $balance,
        ],
    ];
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
        return [
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
