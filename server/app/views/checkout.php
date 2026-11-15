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
    <?php $ops = $pub['operators']; ?>
    <?php if (!$ops): ?>
      <p class="muted">Aucun moyen de paiement n'est disponible pour le moment. Contactez le marchand.</p>
    <?php else: ?>
      <h2><?= count($ops) > 1 ? 'Choisissez votre opérateur' : 'Payer avec ' . h($ops[0]['name']) ?></h2>
      <?php if (count($ops) > 1): ?>
        <div class="op-pick" role="tablist">
          <?php foreach ($ops as $i => $o): ?>
            <button type="button" role="tab" class="op-tab<?= $i === 0 ? ' on' : '' ?>" data-op="<?= h($o['code']) ?>" style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
              <span class="op-dot sm"><?= h(mb_strtoupper(mb_substr($o['name'], 0, 1))) ?></span><?= h($o['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($ops as $i => $o): $uo = $o['ussd_options']; $main = $uo[0] ?? null; ?>
        <div class="op-panel" data-panel="<?= h($o['code']) ?>" <?= $i === 0 ? '' : 'hidden' ?> style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
          <ol class="steps">
            <li>Utilisez le téléphone avec le numéro <strong><?= h($masked) ?></strong> : le paiement est reconnu grâce à ce numéro.</li>
            <?php if ($main): ?>
              <li>Appuyez sur le bouton ci-dessous (ou composez le code) et validez avec votre code secret <?= h($o['name']) ?>.</li>
            <?php elseif ($o['pay_link']): ?>
              <li>Appuyez sur le bouton ci-dessous pour payer avec <?= h($o['name']) ?>.</li>
            <?php else: ?>
              <li><?php if ($o['fee_percent'] > 0): ?>Avec <?= h($o['name']) ?>, envoyez de l'argent au numéro <strong><?= h($o['merchant_number']) ?></strong> en faisant <strong>recevoir</strong> exactement <strong><?= h(fmt_amount($p['amount'], 'F')) ?></strong> (voir ci-dessous).
                <?php else: ?>Envoyez exactement <strong><?= h(fmt_amount($p['amount'], 'F')) ?></strong> au numéro <strong><?= h($o['merchant_number']) ?></strong> avec <?= h($o['name']) ?>.<?php endif; ?></li>
            <?php endif; ?>
            <li>Restez sur cette page : la confirmation est automatique, en général en moins d'une minute.</li>
          </ol>
          <?php if ($o['instructions']): ?><p class="op-note"><?= nl2br(h($o['instructions'])) ?></p><?php endif; ?>

          <?php if ($o['fee_percent'] > 0): ?>
            <div class="fee-box">
              <div class="fee-row"><span>Montant à faire <strong>recevoir</strong></span><strong class="fee-big"><?= h(fmt_amount($p['amount'], 'F')) ?></strong></div>
              <div class="fee-row muted"><span>Vous paierez environ (frais <?= h($o['name']) ?> <?= h(rtrim(rtrim(number_format($o['fee_percent'], 2, ',', ''), '0'), ',')) ?> % inclus)</span><span><?= h(fmt_amount($o['amount_to_send'], 'F')) ?></span></div>
              <p class="small" style="margin:8px 0 0">⚠️ Dans <?= h($o['name']) ?>, saisissez <strong><?= h(fmt_amount($p['amount'], 'F')) ?></strong> dans le champ <strong>« Montant reçu »</strong>, et non dans « Montant envoyé ». Sinon le montant reçu sera plus petit et le paiement ne sera pas reconnu.</p>
            </div>
            <?php if ($o['code'] === 'wave'): ?>
              <div class="wave-demo" aria-hidden="true">
                <div class="wd-title">Envoyer de l'argent</div>
                <div class="wd-label">À</div>
                <div class="wd-value"><?= h($o['merchant_number'] ?: 'Numéro marchand') ?></div>
                <div class="wd-label">Montant envoyé</div>
                <div class="wd-field wd-auto"><?= h(fmt_amount($o['amount_to_send'], 'F')) ?> <em>calculé par Wave</em></div>
                <div class="wd-label wd-hl-label">Montant reçu 👈 tapez ici</div>
                <div class="wd-field wd-hl"><?= h(number_format($p['amount'], 0, ',', ' ')) ?></div>
                <div class="wd-btn">Envoyer</div>
              </div>
              <p class="small muted" style="text-align:center;margin-top:6px">Illustration de l'écran « Envoyer de l'argent » de Wave</p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($main): ?>
            <a class="btn big op-btn" href="<?= h($main['link']) ?>">📞 Payer <?= h(fmt_amount($p['amount'])) ?> avec <?= h($o['name']) ?></a>
          <?php elseif ($o['pay_link']): ?>
            <a class="btn big op-btn" href="<?= h($o['pay_link']) ?>" target="_blank" rel="noopener">Payer <?= h(fmt_amount($p['amount'])) ?> avec <?= h($o['name']) ?></a>
          <?php endif; ?>
          <?php if ($main && $o['pay_link']): ?>
            <a class="btn big" style="margin-top:8px" href="<?= h($o['pay_link']) ?>" target="_blank" rel="noopener">Ou payer avec le lien <?= h($o['name']) ?></a>
          <?php endif; ?>

          <?php if ($main): ?>
            <p class="small muted" style="margin:12px 0 6px">Ou composez<?= count($uo) > 1 ? ' (' . h($main['label']) . ')' : '' ?> :</p>
            <div class="ussd"><?= h($main['code']) ?></div>
          <?php endif; ?>
          <div class="row" style="margin-top:10px">
            <?php if ($main): ?><button class="btn sm" type="button" onclick="copyText(<?= h(json_encode($main['code'])) ?>, this)">Copier le code</button><?php endif; ?>
            <?php if ($o['merchant_number']): ?>
              <span class="small muted">Numéro marchand : <strong><?= h($o['merchant_number']) ?></strong></span>
              <button class="btn sm" type="button" onclick="copyText(<?= h(json_encode((string)$o['merchant_number'])) ?>, this)">Copier le numéro</button>
            <?php endif; ?>
          </div>
          <?php if (count($uo) > 1): ?>
            <details class="alt-codes">
              <summary>Le code ne marche pas ? Autres façons de payer</summary>
              <?php foreach (array_slice($uo, 1) as $u): ?>
                <div class="alt-code">
                  <div><div class="small muted"><?= h($u['label']) ?></div><div class="mono"><?= h($u['code']) ?></div></div>
                  <a class="btn sm" href="<?= h($u['link']) ?>">📞 Composer</a>
                  <button class="btn sm" type="button" onclick="copyText(<?= h(json_encode($u['code'])) ?>, this)">Copier</button>
                </div>
              <?php endforeach; ?>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
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
(function(){
  var tabs = document.querySelectorAll('.op-tab'), panels = document.querySelectorAll('.op-panel');
  function pick(code){
    tabs.forEach(function(t){ t.classList.toggle('on', t.dataset.op === code); });
    panels.forEach(function(p){ p.hidden = p.dataset.panel !== code; });
    try { localStorage.setItem('fp_op', code); } catch (e) {}
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(){ pick(t.dataset.op); }); });
  try { var saved = localStorage.getItem('fp_op'); if (saved && document.querySelector('.op-tab[data-op="' + saved + '"]')) pick(saved); } catch (e) {}
})();
function copyText(t, btn){
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function(){
    btn.textContent = 'Copié ✓';
  }).catch(function(){ prompt('Copiez le code :', t); });
}
</script>
</body></html>
