<?php
/**
 * Page de paiement en 3 étapes : ① moyen de paiement → ② numéro du payeur → ③ instructions.
 * ?embed=1 : version intégrée dans le site du marchand (widget), sans en-tête.
 */
$pub = payment_public($p, false);
$embed = !empty($_GET['embed']);
$merchant = $p['merchant_id'] ? q_one('SELECT name FROM merchants WHERE id = ?', [$p['merchant_id']]) : null;
$wa = preg_replace('/\D+/', '', (string)setting('support_whatsapp'));
$ops = $pub['operators'];
$single = count($ops) === 1;
$phoneSet = !empty($pub['payer_phone_set']);
view('_head', ['title' => 'Paiement ' . $p['reference'], 'noindex' => true]);
?>
<div class="checkout<?= $embed ? ' is-embed' : '' ?>" id="co"
     data-ref="<?= h($p['reference']) ?>" data-phone-set="<?= $phoneSet ? '1' : '0' ?>"
     data-single="<?= $single ? h($ops[0]['code']) : '' ?>">
  <?php if (!$embed): ?>
    <div class="row" style="margin-bottom:14px">
      <strong class="logo"><?= h(app_name()) ?><span>.</span></strong>
      <span class="spacer"></span>
      <span class="muted small mono"><?= h($p['reference']) ?></span>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="muted small"><?= h($merchant['name'] ?? app_name()) ?></div>
    <div class="amount"><?= h(fmt_amount($p['amount'], 'F CFA')) ?></div>
    <?php if ($p['description']): ?><p class="muted"><?= h($p['description']) ?></p><?php endif; ?>
    <div id="status" class="status-box" style="margin-top:12px">
      <div class="spinner"></div><div id="status-text">En attente de votre paiement…</div>
    </div>
  </div>

  <div class="card" id="howto">
    <?php if (!$ops): ?>
      <p class="muted">Aucun moyen de paiement n'est disponible pour le moment. Contactez le marchand.</p>
    <?php else: ?>
      <ol class="co-steps" aria-label="Étapes">
        <li data-step="1">Moyen de paiement</li><li data-step="2">Votre numéro</li><li data-step="3">Paiement</li>
      </ol>

      <!-- ① Choix du moyen de paiement -->
      <section class="co-step" data-step="1">
        <h2>Comment voulez-vous payer ?</h2>
        <div class="op-pick" role="list">
          <?php foreach ($ops as $o): ?>
            <button type="button" role="listitem" class="op-tab" data-op="<?= h($o['code']) ?>" data-name="<?= h($o['name']) ?>" style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
              <span class="op-dot sm"><?= h(mb_strtoupper(mb_substr($o['name'], 0, 1))) ?></span><?= h($o['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- ② Numéro du payeur -->
      <section class="co-step" data-step="2" hidden>
        <button type="button" class="co-back" data-go="1">← <span data-op-name></span> · changer</button>
        <h2>Votre numéro <span data-op-name></span></h2>
        <p class="muted small">Le numéro avec lequel vous allez payer. C'est grâce à lui que votre paiement est reconnu automatiquement.</p>
        <form id="phone-form" class="row" style="align-items:stretch">
          <input type="tel" id="payer-phone" inputmode="tel" autocomplete="tel" required placeholder="Ex. 70 00 00 00" style="flex:1;min-width:0;font-size:1.1rem">
          <button class="btn primary">Continuer</button>
        </form>
        <p class="small" id="phone-err" style="color:var(--err)" hidden></p>
        <?php if ($phoneSet): ?><p class="small muted">Numéro déjà indiqué : <strong><?= h($pub['payer_phone_masked']) ?></strong> — <a href="#" data-go="3">le garder</a></p><?php endif; ?>
      </section>

      <!-- ③ Instructions, par opérateur -->
      <?php foreach ($ops as $o): $uo = $o['ussd_options']; $main = $uo[0] ?? null; ?>
        <section class="co-step op-panel" data-step="3" data-panel="<?= h($o['code']) ?>" hidden style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
          <div class="row" style="gap:6px;margin-bottom:6px">
            <button type="button" class="co-back" data-go="1">← <?= h($o['name']) ?> · changer</button>
            <button type="button" class="co-back" data-go="2"><span data-phone-label></span> · changer de numéro</button>
          </div>
          <h2>Payer avec <?= h($o['name']) ?></h2>
          <ol class="steps">
            <li>Utilisez le téléphone avec le numéro <strong data-phone-label></strong>.</li>
            <?php if ($main): ?>
              <li>Appuyez sur le bouton ci-dessous (ou composez le code) et validez avec votre code secret <?= h($o['name']) ?>.</li>
            <?php elseif ($o['pay_link']): ?>
              <li>Appuyez sur le bouton ci-dessous pour payer avec <?= h($o['name']) ?>.</li>
            <?php elseif ($o['fee_percent'] > 0): ?>
              <li>Avec <?= h($o['name']) ?>, envoyez de l'argent au numéro <strong><?= h($o['merchant_number']) ?></strong> en faisant <strong>recevoir</strong> exactement <strong><?= h(fmt_amount($p['amount'], 'F')) ?></strong> (voir ci-dessous).</li>
            <?php else: ?>
              <li>Envoyez exactement <strong><?= h(fmt_amount($p['amount'], 'F')) ?></strong> au numéro <strong><?= h($o['merchant_number']) ?></strong> avec <?= h($o['name']) ?>.</li>
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
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <p class="small muted" style="text-align:center">
    Expire le <?= h(fmt_dt($p['expires_at'])) ?>.
    <?php if ($wa): ?><br>Un souci ? <a href="https://wa.me/<?= h($wa) ?>?text=<?= h(rawurlencode('Paiement ' . $p['reference'])) ?>" target="_blank">Support WhatsApp</a><?php endif; ?>
    <?php if ($embed): ?><br>Paiement sécurisé par <?= h(app_name()) ?><?php endif; ?>
  </p>
</div>
<script>
(function(){
  var co = document.getElementById('co'), ref = co.dataset.ref;
  var apiBase = <?= json_encode(url('api/public/payments/')) ?> + encodeURIComponent(ref);
  var embed = <?= $embed ? 'true' : 'false' ?>;
  var initialMasked = <?= json_encode($pub['payer_phone_masked']) ?>;
  var phoneSet = co.dataset.phoneSet === '1';
  var chosen = null, phoneLabel = initialMasked || '';
  var KEY = 'fp_op';

  function tell(msg){ if (embed && window.parent !== window) window.parent.postMessage(Object.assign({ source: 'flashpay', payment: ref }, msg), '*'); }
  function resize(){ tell({ type: 'resize', height: document.documentElement.scrollHeight }); }

  // --- navigation entre les étapes ---
  function show(step){
    co.querySelectorAll('.co-step').forEach(function(s){
      var match = s.dataset.step === String(step) && (step !== 3 || s.dataset.panel === chosen);
      s.hidden = !match;
    });
    co.querySelectorAll('.co-steps li').forEach(function(li){
      var n = +li.dataset.step; li.className = n < step ? 'done' : (n === step ? 'on' : '');
    });
    co.querySelectorAll('[data-op-name]').forEach(function(e){ e.textContent = chosenName(); });
    co.querySelectorAll('[data-phone-label]').forEach(function(e){ e.textContent = phoneLabel; });
    if (step === 2) setTimeout(function(){ var i = document.getElementById('payer-phone'); i && i.focus(); }, 50);
    resize();
  }
  function chosenName(){ var b = co.querySelector('.op-tab[data-op="' + chosen + '"]'); return b ? b.dataset.name : ''; }
  function pick(code){
    chosen = code;
    co.querySelectorAll('.op-tab').forEach(function(t){ t.classList.toggle('on', t.dataset.op === code); });
    try { localStorage.setItem(KEY, code); } catch (e) {}
    tell({ type: 'operator', operator: code });
    show(phoneSet ? 3 : 2);
  }
  co.querySelectorAll('.op-tab').forEach(function(t){ t.addEventListener('click', function(){ pick(t.dataset.op); }); });
  co.querySelectorAll('[data-go]').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var to = +a.dataset.go;
      show(to);
    });
  });

  // --- ② envoi du numéro ---
  var form = document.getElementById('phone-form');
  if (form) form.addEventListener('submit', function(e){
    e.preventDefault();
    var input = document.getElementById('payer-phone'), err = document.getElementById('phone-err'), btn = form.querySelector('button');
    var v = input.value.trim(); err.hidden = true; btn.disabled = true;
    fetch(apiBase + '/payer', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ payer_phone: v, operator: chosen }) })
      .then(function(r){ return r.json(); })
      .then(function(d){
        btn.disabled = false;
        if (d.error) { err.textContent = d.error.message; err.hidden = false; resize(); return; }
        phoneSet = true; phoneLabel = d.payer_phone_masked || v;
        if (!render(d)) show(3);
      })
      .catch(function(){ btn.disabled = false; err.textContent = 'Connexion impossible, réessayez.'; err.hidden = false; });
  });

  // --- statut du paiement ---
  var box = document.getElementById('status');
  var labels = {
    paid: '✅ Paiement reçu. Merci !',
    expired: 'Ce paiement a expiré. Si vous avez déjà payé, contactez le support avec la référence.',
    cancelled: 'Ce paiement a été annulé.'
  };
  function render(d){
    if (d.status === 'pending') return false;
    box.className = 'status-box ' + (d.status === 'paid' ? 'paid' : 'bad');
    box.innerHTML = '<div>' + labels[d.status] + '</div>';
    var how = document.getElementById('howto'); if (how) how.style.display = 'none';
    tell({ type: 'status', status: d.status });
    resize();
    if (d.status === 'paid' && d.return_url && !embed) setTimeout(function(){
      var u = new URL(d.return_url); u.searchParams.set('payment_id', ref); u.searchParams.set('status', 'paid');
      location.href = u.toString();
    }, 2500);
    return true;
  }
  if (render({ status: <?= json_encode($p['status']) ?>, return_url: <?= json_encode($pub['return_url']) ?> })) return;

  // Étape de départ
  var single = co.dataset.single, saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (single) pick(single);
  else if (saved && phoneSet && co.querySelector('.op-tab[data-op="' + saved + '"]')) pick(saved);
  else show(1);

  var delay = 3000, started = Date.now();
  function poll(){
    fetch(apiBase, { cache: 'no-store' }).then(function(r){ return r.json(); }).then(function(d){
      if (!render(d)) schedule();
    }).catch(schedule);
  }
  function schedule(){ if (Date.now() - started > 300000) delay = 10000; setTimeout(poll, delay); }
  schedule();
  window.addEventListener('load', resize);
})();
function copyText(t, btn){
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function(){
    btn.textContent = 'Copié ✓';
  }).catch(function(){ prompt('Copiez :', t); });
}
</script>
</body></html>
