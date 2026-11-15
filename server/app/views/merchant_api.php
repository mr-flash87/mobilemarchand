<div class="page-head">
  <div>
    <h1>Clés API & webhook</h1>
    <p class="muted">Tout ce qu'il faut pour connecter votre site. Suivez la <a href="<?= h(url('docs')) ?>" target="_blank">documentation API</a>.</p>
  </div>
</div>

<?php if ($newKey): ?>
  <section class="card" style="border-color:var(--brand)">
    <h2>🔑 Votre clé API (affichée une seule fois)</h2>
    <div class="key" id="api-key"><?= h($newKey) ?></div>
    <div class="row" style="margin-top:10px">
      <button class="btn sm" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('api-key').textContent);this.textContent='Copiée ✓'">Copier la clé</button>
      <span class="small muted">Gardez-la secrète : elle ne doit être utilisée que sur votre serveur.</span>
    </div>
  </section>
<?php endif; ?>

<div class="grid g2">
  <section class="card">
    <h2>Clé API</h2>
    <?php if ($m['status'] !== 'active'): ?>
      <div class="alert warn">Votre compte est en attente de validation. Vous pourrez générer votre clé API dès qu'il sera validé.</div>
    <?php else: ?>
      <p class="small muted">Clé actuelle : <span class="mono"><?= h($m['api_key_prefix']) ?><?= $m['api_key_prefix'] !== '—' ? '…' : '' ?></span></p>
      <form method="post" onsubmit="return <?= $m['api_key_prefix'] !== '—' ? "confirm('L\\'ancienne clé cessera de fonctionner. Continuer ?')" : 'true' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="rotate">
        <button class="btn primary"><?= $m['api_key_prefix'] === '—' ? 'Générer ma clé API' : 'Générer une nouvelle clé' ?></button>
      </form>
    <?php endif; ?>
    <h3 style="margin-top:20px">URL de l'API</h3>
    <div class="copy-line"><code><?= h(abs_url('api/v1')) ?></code></div>
  </section>

  <section class="card">
    <h2>Webhook</h2>
    <p class="small muted">Nous appelons cette adresse à chaque paiement reçu, pour que votre site livre la commande automatiquement.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="webhook">
      <label>URL de webhook</label>
      <input type="url" name="webhook_url" value="<?= h($m['webhook_url']) ?>" placeholder="https://monsite.com/webhook">
      <div class="row" style="margin-top:10px">
        <button class="btn primary">Enregistrer</button>
        <button class="btn" name="action" value="test" formnovalidate>Tester le webhook</button>
      </div>
    </form>
    <label style="margin-top:16px">Secret de signature</label>
    <div class="key"><?= h($m['webhook_secret']) ?></div>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('Il faudra mettre à jour le secret sur votre site. Continuer ?')">
      <?= csrf_field() ?><button class="btn sm" name="action" value="secret">Générer un nouveau secret</button>
    </form>
  </section>
</div>

<section class="card">
  <h2>Démarrage rapide</h2>
  <ol class="steps">
    <li>Configurez vos numéros dans <a href="<?= h(aurl('operators')) ?>">Mes opérateurs</a> et associez votre téléphone dans <a href="<?= h(aurl('devices')) ?>">Mes téléphones</a>.</li>
    <li>Depuis votre serveur, créez un paiement : <code>POST <?= h(abs_url('api/v1/payments')) ?></code> avec votre clé dans <code>Authorization: Bearer …</code>.</li>
    <li>Redirigez votre client vers <code>checkout_url</code> : il paie sur votre numéro avec le code USSD.</li>
    <li>Votre webhook reçoit <code>payment.succeeded</code> : livrez la commande.</li>
  </ol>
  <a class="btn" href="<?= h(url('docs')) ?>" target="_blank"><?= icon('book', 16) ?> Documentation complète</a>
</section>
