<?php
$pub = payment_public($p, false);
$merchant = $p['merchant_id'] ? q_one('SELECT name FROM merchants WHERE id = ?', [$p['merchant_id']]) : null;
$masked = $p['payer_phone'] ? substr($p['payer_phone'], 0, -6) . '••••' . substr($p['payer_phone'], -2) : '';
$wa = preg_replace('/\D+/', '', (string)setting('support_whatsapp'));
view('_head', ['title' => 'Paiement ' . $p['reference'], 'noindex' => true]);
?>
<div class="checkout">
  <div class="row" style="margin-bottom:14px">
    <strong class="logo"><?= h(app_name()) ?><span>.</span></strong>
    <span class="spacer"></span>
    <span class="muted small mono"><?= h($p['reference']) ?></span>
  </div>

  <div class="card">
    <div class="muted small"><?= h($merchant['name'] ?? app_name()) ?></div>
    <div class="amount"><?= h(fmt_amount($p['amount'], 'F CFA')) ?></div>
    <?php if ($p['description']): ?><p class="muted"><?= h($p['description']) ?></p><?php endif; ?>

    <div id="status" class="status-box" style="margin-top:12px">
      <div class="spinner"></div><div id="status-text">En attente de votre paiement…</div>
    </div>
  </div>

  <div class="card" id="howto">
    <h2>Payer avec <?= h(setting('operator_name')) ?></h2>
    <ol class="steps">
      <li>Utilisez le téléphone avec le numéro <strong><?= h($masked) ?></strong>. Le paiement est reconnu grâce à ce numéro.</li>
      <li>Appuyez sur le bouton ci-dessous (ou composez le code) et validez avec votre code secret.</li>
      <li>Restez sur cette page : la confirmation est automatique, en général en moins d'une minute.</li>
    </ol>
    <a class="btn primary big" href="<?= h($pub['ussd_link']) ?>">📞 Payer <?= h(fmt_amount($p['amount'])) ?></a>
    <p class="small muted" style="margin:12px 0 6px">Ou composez :</p>
    <div class="ussd" id="ussd"><?= h($pub['ussd_code']) ?></div>
    <div class="row" style="margin-top:10px">
      <button class="btn sm" type="button" onclick="copyText('<?= h($pub['ussd_code']) ?>', this)">Copier le code</button>
      <span class="small muted">Numéro marchand : <strong><?= h(setting('merchant_number')) ?></strong></span>
    </div>
  </div>

  <p class="small muted" style="text-align:center">
    Expire le <?= h(fmt_dt($p['expires_at'])) ?>.
    <?php if ($wa): ?><br>Un souci ? <a href="https://wa.me/<?= h($wa) ?>?text=<?= h(rawurlencode('Paiement ' . $p['reference'])) ?>">Support WhatsApp</a><?php endif; ?>
  </p>
</div>
<script>
(function(){
  var ref = <?= json_encode($p['reference']) ?>;
  var api = <?= json_encode(url('api/public/payments/')) ?> + encodeURIComponent(ref);
  var box = document.getElementById('status'), txt = document.getElementById('status-text');
  var labels = {
    paid: '✅ Paiement reçu. Merci !',
    expired: 'Ce paiement a expiré. Si vous avez déjà payé, contactez le support avec la référence.',
    cancelled: 'Ce paiement a été annulé.'
  };
  function render(d){
    if (d.status === 'pending') return false;
    box.className = 'status-box ' + (d.status === 'paid' ? 'paid' : 'bad');
    box.innerHTML = '<div>' + labels[d.status] + '</div>';
    if (d.status === 'paid') {
      document.getElementById('howto').style.display = 'none';
      if (d.return_url) setTimeout(function(){
        var u = new URL(d.return_url); u.searchParams.set('payment_id', ref); u.searchParams.set('status', 'paid');
        location.href = u.toString();
      }, 2500);
    }
    return true;
  }
  var initial = <?= json_encode($p['status']) ?>;
  if (render({status: initial, return_url: <?= json_encode($pub['return_url']) ?>})) return;
  var delay = 3000, started = Date.now();
  function poll(){
    fetch(api, {cache: 'no-store'}).then(function(r){ return r.json(); }).then(function(d){
      if (!render(d)) schedule();
    }).catch(schedule);
  }
  function schedule(){
    // ralentit après 5 minutes pour économiser le serveur
    if (Date.now() - started > 300000) delay = 10000;
    setTimeout(poll, delay);
  }
  schedule();
})();
function copyText(t, btn){
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function(){
    btn.textContent = 'Copié ✓';
  }).catch(function(){ prompt('Copiez le code :', t); });
}
</script>
</body></html>
