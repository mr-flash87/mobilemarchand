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

function ussd_code(float $amount): string
{
    $number = preg_replace('/\D+/', '', (string)setting('merchant_number', ''));
    return strtr((string)setting('ussd_template'), [
        '{number}' => $number,
        '{amount}' => (string)(int)round($amount),
    ]);
}

function ussd_link(string $code): string
{
    return 'tel:' . str_replace('#', '%23', $code);
}
