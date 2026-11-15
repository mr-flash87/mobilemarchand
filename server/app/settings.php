<?php
declare(strict_types=1);

function setting_defaults(): array
{
    return [
        'app_name' => 'FlashPay',
        'timezone' => 'Africa/Ouagadougou',
        'currency' => 'XOF',
        'operator_name' => 'Orange Money',
        'merchant_number' => '',
        // {number} = numéro marchand, {amount} = montant entier
        'ussd_template' => '*144*10*{number}*{amount}#',
        'payment_expiry_minutes' => '30',
        'min_amount' => '100',
        'max_amount' => '1000000',
        // Si le SMS ne contient pas le numéro de l'expéditeur : accepter un match sur le montant seul
        // uniquement s'il n'y a qu'un seul paiement en attente avec ce montant.
        'match_amount_only' => '0',
        // Vérifie que le nouveau solde annoncé dans le SMS = ancien solde + montant reçu.
        'balance_check' => '1',
        'late_match_minutes' => '30',
        'support_whatsapp' => '',
        'sms_rules' => json_encode(default_sms_rules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'cron_key' => '',
    ];
}

function setting(string $name, $default = null)
{
    static $cache = null;
    if ($name === '__reset__') {
        $cache = null;
        return null;
    }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (q_all('SELECT name, value FROM settings') as $r) {
                $cache[$r['name']] = $r['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $defaults = setting_defaults();
    return $defaults[$name] ?? $default;
}

function setting_set(string $name, ?string $value): void
{
    $exists = q_val('SELECT COUNT(*) FROM settings WHERE name = ?', [$name]);
    if ((int)$exists > 0) {
        q('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    } else {
        q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    }
    setting('__reset__');
}

/**
 * Codes USSD proposés au client, un par ligne : "Libellé | modèle".
 * Le premier est le code principal. Par défaut : l'ancien modèle unique + le paiement marchand.
 * @return array<int, array{label:string, template:string}>
 */
function ussd_templates(): array
{
    $raw = setting('ussd_codes');
    if ($raw === null || trim((string)$raw) === '') {
        $raw = 'Transfert | ' . setting('ussd_template') . "\nPaiement marchand | *144*2*1*{number}*{amount}#";
    }
    $out = [];
    foreach (preg_split('/\R/', (string)$raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$label, $tpl] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : ['Code USSD', $line];
        if ($tpl !== '') {
            $out[] = ['label' => $label !== '' ? $label : 'Code USSD', 'template' => $tpl];
        }
    }
    return $out ?: [['label' => 'Code USSD', 'template' => (string)setting('ussd_template')]];
}

function ussd_fill(string $template, float $amount): string
{
    $number = preg_replace('/\D+/', '', (string)setting('merchant_number', ''));
    return strtr($template, [
        '{number}' => $number,
        '{amount}' => (string)(int)round($amount),
    ]);
}

/** @return array<int, array{label:string, code:string, link:string}> */
function ussd_options(float $amount): array
{
    return array_map(function ($t) use ($amount) {
        $code = ussd_fill($t['template'], $amount);
        return ['label' => $t['label'], 'code' => $code, 'link' => ussd_link($code)];
    }, ussd_templates());
}

/** Code USSD principal (le premier de la liste). */
function ussd_code(float $amount): string
{
    return ussd_options($amount)[0]['code'];
}

function ussd_link(string $code): string
{
    return 'tel:' . str_replace('#', '%23', $code);
}
