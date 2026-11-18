<?php $isAdmin = is_admin_ctx(); ?>
<div class="page-head">
  <div>
    <h1>Envois & remboursements</h1>
    <p class="muted">Le téléphone passerelle envoie l'argent tout seul : il compose le code USSD (Orange, Moov…) et saisit le code secret enregistré <strong>dans le téléphone</strong>. Remboursements depuis la fiche d'un paiement, envois libres ci-dessous ou par l'API.</p>
  </div>
</div>

<?php if (!payouts_enabled()): ?>
  <div class="alert err">Les envois d'argent sont désactivés<?= $isAdmin ? ' (réglage ci-dessous).' : ' par la plateforme.' ?></div>
<?php endif; ?>

<div class="grid g2">
  <form class="card" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>Nouvel envoi</h2>
    <?php if (!$ops): ?>
      <p class="small muted">Aucun opérateur ne peut envoyer : renseignez le « Code USSD pour envoyer » dans <a href="<?= h(aurl('operators')) ?>">Opérateurs</a>.</p>
    <?php else: ?>
      <label>Opérateur</label>
      <select name="operator"><?php foreach ($ops as $o): ?><option value="<?= h($o['code']) ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select>
      <label>Numéro du bénéficiaire</label><input type="tel" name="phone" required placeholder="70112233">
      <label>Montant</label><input type="number" name="amount" required min="1" placeholder="5000">
      <label>Description (optionnel)</label><input type="text" name="description" maxlength="255" placeholder="Ex. retrait 1xBet n°1234">
      <p></p><button class="btn primary" onclick="return confirm('Créer cet envoi d\'argent ?')"><?= icon('arrow', 16) ?> Envoyer</button>
    <?php endif; ?>
    <p class="small muted" style="margin-top:10px">Envoyé aujourd'hui : <strong><?= h(fmt_amount($today, 'F')) ?></strong>
      <?php if ((float)setting('payout_daily_limit', '500000') > 0): ?> / plafond <?= h(fmt_amount(setting('payout_daily_limit', '500000'), 'F')) ?><?php endif; ?></p>
  </form>

  <div class="card">
    <h2>Sécurité</h2>
    <?php if ($isAdmin): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings">
        <label class="check"><input type="checkbox" name="payouts_enabled" value="1" <?= payouts_enabled() ? 'checked' : '' ?>> <span>Autoriser les envois d'argent sur la plateforme</span></label>
        <label class="check"><input type="checkbox" name="refund_auto" value="1" <?= setting('refund_auto', '1') === '1' ? 'checked' : '' ?>> <span>Remboursements demandés par l'API (commande annulée…) : partir automatiquement, sans validation, si le paiement a été confirmé par l'opérateur</span></label>
        <p class="small muted" style="margin:4px 0 8px">Les envois et remboursements lancés ici ou sur Telegram partent toujours tout de suite : votre clic vaut validation.</p>
        <label>Autres envois par l'API : validation automatique jusqu'à (F) — <span class="muted">0 = tout valider à la main</span></label>
        <input type="number" name="payout_auto_max" min="0" value="<?= h(setting('payout_auto_max', '0')) ?>">
        <label>Maximum par envoi (F)</label><input type="number" name="payout_max" min="0" value="<?= h(setting('payout_max', '200000')) ?>">
        <label>Plafond par jour et par espace (F)</label><input type="number" name="payout_daily_limit" min="0" value="<?= h(setting('payout_daily_limit', '500000')) ?>">
        <p></p><button class="btn">Enregistrer</button>
      </form>
    <?php else: ?>
      <ul class="small" style="padding-left:18px;line-height:1.7">
        <li>Les envois et remboursements lancés ici ou sur Telegram partent tout de suite.</li>
        <li>Envois demandés par l'API au-dessus de <strong><?= (float)setting('payout_auto_max', '0') > 0 ? h(fmt_amount(setting('payout_auto_max'), 'F')) : '0 F' ?></strong> : à valider ici ou sur Telegram<?= setting('refund_auto', '1') === '1' ? ' (sauf remboursements de paiements confirmés par l\'opérateur : automatiques)' : '' ?>.</li>
        <li>Maximum <?= h(fmt_amount(setting('payout_max', '200000'), 'F')) ?> par envoi, <?= h(fmt_amount(setting('payout_daily_limit', '500000'), 'F')) ?> par jour.</li>
      </ul>
    <?php endif; ?>
    <div class="callout small" style="margin-top:12px"><?= icon('phone', 18) ?><div>Sur le téléphone passerelle (version 2.5 ou plus) : activez « Envois d'argent », l'accessibilité et enregistrez le code secret de chaque opérateur. Écran sans code de verrouillage, téléphone branché.</div></div>
  </div>
</div>

<div class="card">
  <div class="row" style="flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Historique</h2><span class="spacer"></span>
    <?php foreach (['' => 'Tous', 'approval' => 'À valider', 'queued' => 'En file', 'unknown' => 'À vérifier', 'success' => 'Envoyés', 'failed' => 'Échecs'] as $k => $l): ?>
      <a class="btn sm <?= $status === $k ? 'primary' : '' ?>" href="<?= h(aurl('payouts' . ($k !== '' ? '?status=' . $k : ''))) ?>"><?= h($l) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><p class="muted small">Aucun envoi.</p><?php endif; ?>
  <?php foreach ($rows as $r): $op = operator_by_code($r['operator'], $r['scope_id'] !== null ? (int)$r['scope_id'] : null); ?>
    <form method="post" class="row" style="flex-wrap:wrap;gap:8px 16px;padding:12px 0;border-top:1px solid var(--line)">
      <?= csrf_field() ?><input type="hidden" name="ref" value="<?= h($r['reference']) ?>">
      <div style="flex:1;min-width:220px">
        <strong><?= payout_emoji($r['status']) ?> <?= h(fmt_amount($r['amount'], 'F')) ?></strong> → <?= h($r['phone']) ?> · <?= h($op['name'] ?? $r['operator']) ?>
        <span class="badge <?= ['success' => 'b-paid', 'failed' => 'b-cancelled', 'cancelled' => 'b-cancelled', 'unknown' => 'b-review'][$r['status']] ?? 'b-pending' ?>"><?= h(payout_status_label($r['status'])) ?></span><br>
        <span class="small muted"><span class="mono"><?= h($r['reference']) ?></span> · <?= $r['kind'] === 'refund' ? 'Remboursement' . ($r['payment_ref'] ? ' de ' . h($r['payment_ref']) : '') : 'Envoi' ?>
          <?= $isAdmin && $r['merchant_name'] ? ' · ' . h($r['merchant_name']) : '' ?> · <?= h(fmt_dt($r['created_at'])) ?><?= $r['description'] ? ' · ' . h($r['description']) : '' ?></span>
        <?php if ($r['result_text']): ?><br><span class="small">« <?= h($r['result_text']) ?> »</span><?php endif; ?>
      </div>
      <div class="row" style="gap:6px">
        <?php if ($r['status'] === 'approval'): ?>
          <button class="btn sm ok" name="action" value="approve" onclick="return confirm('Valider : le téléphone va envoyer l\'argent.')">✅ Valider</button>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['approval', 'queued'], true)): ?>
          <button class="btn sm danger" name="action" value="cancel">Refuser</button>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['unknown', 'sending'], true)): ?>
          <button class="btn sm ok" name="action" value="success" onclick="return confirm('L\'argent est-il bien parti (vérifié sur le téléphone) ?')">L'argent est parti</button>
          <button class="btn sm" name="action" value="failed" onclick="return confirm('Confirmer que l\'argent n\'est PAS parti ?')">Rien n'est parti</button>
        <?php endif; ?>
        <?php if ($r['status'] === 'failed'): ?>
          <button class="btn sm" name="action" value="retry" onclick="return confirm('Renvoyer cet envoi ?')">Réessayer</button>
        <?php endif; ?>
      </div>
    </form>
  <?php endforeach; ?>
</div>
