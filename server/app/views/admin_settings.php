<h1>Réglages</h1>
<form class="card" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h2>Général</h2>
  <div class="grid g2">
    <div><label>Nom de la plateforme</label><input type="text" name="app_name" value="<?= h(setting('app_name')) ?>"></div>
    <div style="grid-column:1/-1" class="callout" ><?= icon('wallet', 18) ?><div>Les numéros marchands et les codes USSD se règlent maintenant par opérateur, dans <a href="<?= h(aurl('operators')) ?>"><strong>Opérateurs</strong></a> (<?= h(operators_names()) ?>).</div></div>
    <div><label>Expiration d'un paiement (minutes)</label><input type="number" name="payment_expiry_minutes" value="<?= h(setting('payment_expiry_minutes')) ?>" min="5"></div>
    <div><label>Rapprocher un SMS arrivé avant la demande (minutes)</label><input type="number" name="late_match_minutes" value="<?= h(setting('late_match_minutes')) ?>" min="1"></div>
    <div><label>Montant minimum</label><input type="number" name="min_amount" value="<?= h(setting('min_amount')) ?>"></div>
    <div><label>Montant maximum</label><input type="number" name="max_amount" value="<?= h(setting('max_amount')) ?>"></div>
    <div><label>Devise</label><input type="text" name="currency" value="<?= h(setting('currency')) ?>"></div>
    <div><label>Fuseau horaire</label><input type="text" name="timezone" value="<?= h(setting('timezone')) ?>"></div>
    <div><label>WhatsApp du support (avec indicatif)</label><input type="tel" name="support_whatsapp" value="<?= h(setting('support_whatsapp')) ?>" placeholder="22670000000"></div>
  </div>
  <h2 style="margin-top:18px">Sécurité du rapprochement</h2>
  <label class="check"><input type="checkbox" name="balance_check" value="1" <?= setting('balance_check') === '1' ? 'checked' : '' ?>>
    <span><strong>Contrôle du solde (recommandé)</strong> : si le nouveau solde du SMS n'est pas égal à l'ancien solde + le montant, le SMS est mis « À vérifier » au lieu de valider le paiement. Cela bloque la plupart des faux SMS.</span></label>
  <label class="check"><input type="checkbox" name="match_amount_only" value="1" <?= setting('match_amount_only') === '1' ? 'checked' : '' ?>>
    <span>Si le SMS ne contient pas le numéro du payeur, valider sur le <strong>montant seul</strong> quand un seul paiement en attente a ce montant (moins sûr).</span></label>
  <p></p><button class="btn primary">Enregistrer</button>
</form>

<?php view('_app_section'); ?>

<form class="card" method="post" id="rules">
  <?= csrf_field() ?>
  <h2>Règles de lecture des SMS</h2>
  <p class="small muted">Chaque règle s'applique aux SMS dont l'expéditeur correspond à <code>sender</code>. Les champs <code>amount</code>, <code>phone</code>, <code>txn</code> et <code>balance</code>
    sont des expressions régulières dont le 1er groupe <code>( )</code> donne la valeur. <code>kind</code> : <code>incoming</code> (argent reçu) ou <code>outgoing</code> (argent sorti, suivi du solde).
    Collez un vrai SMS reçu dans la zone de test pour vérifier.</p>
  <textarea name="sms_rules" rows="22"><?= h($rulesJson) ?></textarea>
  <div class="grid g2" style="margin-top:12px">
    <div><label>Test : expéditeur</label><input type="text" name="test_sender" value="<?= h($testInput['sender']) ?>"></div>
    <div><label>Test : texte du SMS</label><textarea name="test_body" rows="3" style="min-height:70px"><?= h($testInput['body']) ?></textarea></div>
  </div>
  <?php if ($testResult === false): ?>
    <div class="alert err" style="margin-top:12px">Aucune règle ne correspond à ce SMS.</div>
  <?php elseif (is_array($testResult)): ?>
    <div class="alert ok" style="margin-top:12px">
      Règle : <strong><?= h($testResult['rule']) ?></strong> (<?= h($testResult['kind']) ?>)<br>
      Montant : <strong><?= h($testResult['amount'] ?? '—') ?></strong> · Numéro : <strong><?= h($testResult['phone'] ?? '—') ?></strong> ·
      Txn : <strong><?= h($testResult['txn'] ?? '—') ?></strong> · Solde : <strong><?= h($testResult['balance'] ?? '—') ?></strong>
    </div>
  <?php endif; ?>
  <div class="row" style="margin-top:12px">
    <button class="btn" name="action" value="test">Tester</button>
    <button class="btn primary" name="action" value="rules">Enregistrer les règles</button>
    <button class="btn danger" name="action" value="reset_rules" onclick="return confirm('Revenir aux règles par défaut ?')">Réinitialiser</button>
  </div>
</form>

<div class="card" id="cron">
  <h2>Tâche planifiée (cron)</h2>
  <p class="small muted">Elle expire les paiements et renvoie les webhooks échoués. Elle tourne déjà automatiquement à chaque contact du téléphone. Pour plus de régularité, ajoutez une tâche cron chaque minute dans votre hébergement :</p>
<pre><code>php <?= h(APP_ROOT) ?>/cron.php</code></pre>
  <?php if (setting('cron_key')): ?>
    <p class="small muted">Ou par URL (si votre hébergeur ne permet que des cron web) :</p>
    <pre><code><?= h(abs_url('cron?key=' . setting('cron_key'))) ?></code></pre>
  <?php endif; ?>
  <form method="post"><?= csrf_field() ?><button class="btn sm" name="action" value="cron_key">Générer une URL de cron</button></form>
</div>

<form class="card" method="post" action="<?= h(aurl('password')) ?>">
  <?= csrf_field() ?>
  <h2>Mot de passe admin</h2>
  <div class="grid g2">
    <div><label>Mot de passe actuel</label><input type="password" name="current_password" required autocomplete="current-password"></div>
    <div><label>Nouveau mot de passe</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
  </div>
  <p></p><button class="btn">Changer</button>
</form>
