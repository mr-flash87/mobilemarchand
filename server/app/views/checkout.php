<?php
/**
 * Page de paiement.
 *  Écran 1 : moyen de paiement + numéro du payeur, bouton « Payer ».
 *  Écran 2 : consignes de l'opérateur choisi (code USSD, lien, frais Wave…) et suivi en direct.
 *  Écran 3 : paiement reçu / expiré / annulé.
 * ?embed=1 : version intégrée dans le site du marchand (widget).
 */
$pub = payment_public($p, false);
$embed = !empty($_GET['embed']);
$merchant = $p['merchant_id'] ? q_one('SELECT name FROM merchants WHERE id = ?', [$p['merchant_id']]) : null;
$merchantName = $merchant['name'] ?? app_name();
$wa = preg_replace('/\D+/', '', (string)setting('support_whatsapp'));
$ops = $pub['operators'];
$phoneSet = !empty($pub['payer_phone_set']);
$amountTxt = number_format((float)$p['amount'], 0, ',', ' ');
$countries = [
    ['226', '🇧🇫', 'Burkina Faso'], ['225', '🇨🇮', "Côte d'Ivoire"], ['223', '🇲🇱', 'Mali'], ['221', '🇸🇳', 'Sénégal'],
    ['227', '🇳🇪', 'Niger'], ['228', '🇹🇬', 'Togo'], ['229', '🇧🇯', 'Bénin'], ['224', '🇬🇳', 'Guinée'],
];
view('_head', ['title' => 'Paiement ' . $p['reference'], 'noindex' => true]);
?>
<div class="ck-page<?= $embed ? ' is-embed' : '' ?>">
<div class="ck" id="co" data-ref="<?= h($p['reference']) ?>" data-phone-set="<?= $phoneSet ? '1' : '0' ?>"
     data-single="<?= count($ops) === 1 ? h($ops[0]['code']) : '' ?>">

  <header class="ck-head">
    <div>
      <div class="ck-k">Marchand</div>
      <div class="ck-merchant"><?= h($merchantName) ?></div>
      <?php if ($p['description']): ?><div class="ck-desc"><?= h($p['description']) ?></div><?php endif; ?>
    </div>
    <div class="ck-total">
      <div class="ck-k">Total à payer</div>
      <div class="ck-amount"><?= h($amountTxt) ?> <small>F CFA</small></div>
    </div>
  </header>

  <div class="ck-body">
    <?php if (!$ops): ?>
      <div class="ck-empty">Aucun moyen de paiement n'est disponible pour le moment. Contactez le marchand.</div>
    <?php else: ?>

    <!-- ===== Écran 1 : moyen de paiement + numéro ===== -->
    <section class="ck-view" data-view="choose">
      <div class="ck-label">Méthode de paiement</div>
      <div class="ck-methods" role="radiogroup" aria-label="Méthode de paiement">
        <?php foreach ($ops as $o): ?>
          <button type="button" role="radio" aria-checked="false" class="ck-method" data-op="<?= h($o['code']) ?>" data-name="<?= h($o['name']) ?>" style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
            <span class="ck-logo"><?= h(mb_strtoupper(mb_substr($o['name'], 0, 1))) ?></span>
            <span class="ck-mname"><?= h($o['name']) ?></span>
            <span class="ck-check" aria-hidden="true"><svg viewBox="0 0 24 24" width="14" height="14"><path d="m5 12.5 4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </button>
        <?php endforeach; ?>
      </div>

      <form id="phone-form" class="ck-phone-wrap" novalidate>
        <label class="ck-label" for="payer-phone">Numéro de téléphone</label>
        <div class="ck-phone">
          <select id="cc" aria-label="Indicatif du pays">
            <?php foreach ($countries as [$cc, $flag, $name]): ?><option value="<?= $cc ?>"><?= $flag ?> +<?= $cc ?></option><?php endforeach; ?>
          </select>
          <input type="tel" id="payer-phone" inputmode="tel" autocomplete="tel-national" placeholder="<?= $phoneSet ? h($pub['payer_phone_masked']) : '70 00 00 00' ?>">
        </div>
        <p class="ck-hint">Le numéro avec lequel vous allez payer : c'est grâce à lui que votre paiement est reconnu automatiquement.</p>
        <p class="ck-err" id="phone-err" hidden></p>
        <button class="ck-pay" id="pay-btn" disabled>
          <span data-pay-label>Choisissez une méthode de paiement</span>
        </button>
      </form>
    </section>

    <!-- ===== Écran 2 : consignes par opérateur ===== -->
    <?php foreach ($ops as $o): $uo = $o['ussd_options']; $main = $uo[0] ?? null; ?>
      <section class="ck-view" data-view="pay" data-panel="<?= h($o['code']) ?>" hidden style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
        <div class="ck-topline">
          <button type="button" class="ck-back" data-back>← Modifier</button>
          <span class="ck-chip"><span class="ck-logo xs"><?= h(mb_strtoupper(mb_substr($o['name'], 0, 1))) ?></span><?= h($o['name']) ?> · <span data-phone-label></span></span>
        </div>

        <div class="ck-wait"><span class="ck-spin"></span><span>En attente de votre paiement… la confirmation est automatique.</span></div>

        <?php if ($o['fee_percent'] > 0): ?>
          <div class="ck-fee">
            <div class="ck-fee-row"><span>Montant à faire <strong>recevoir</strong></span><strong class="ck-fee-big"><?= h($amountTxt) ?> F</strong></div>
            <div class="ck-fee-row ck-muted"><span>Vous paierez environ, frais <?= h($o['name']) ?> (<?= h(rtrim(rtrim(number_format($o['fee_percent'], 2, ',', ''), '0'), ',')) ?> %) inclus</span><span><?= h(fmt_amount($o['amount_to_send'], 'F')) ?></span></div>
            <p>⚠️ Dans <?= h($o['name']) ?>, saisissez <strong><?= h($amountTxt) ?> F</strong> dans <strong>« Montant reçu »</strong>, et non dans « Montant envoyé ».</p>
          </div>
        <?php endif; ?>

        <ol class="ck-steps">
          <?php if ($main): ?>
            <li>Appuyez sur <strong>Payer</strong> : votre téléphone compose le code <?= h($o['name']) ?>.</li>
            <li>Validez avec votre <strong>code secret</strong>.</li>
          <?php elseif ($o['pay_link']): ?>
            <li>Appuyez sur <strong>Payer</strong> pour ouvrir <?= h($o['name']) ?>.</li>
            <li>Confirmez le paiement dans l'application.</li>
          <?php else: ?>
            <li>Ouvrez <?= h($o['name']) ?> → <strong>Envoyer de l'argent</strong> au <strong><?= h($o['merchant_number']) ?></strong>.</li>
            <li><?= $o['fee_percent'] > 0 ? 'Faites <strong>recevoir</strong> exactement' : 'Envoyez exactement' ?> <strong><?= h($amountTxt) ?> F</strong>, puis validez.</li>
          <?php endif; ?>
          <li>Restez sur cette page : elle se met à jour toute seule.</li>
        </ol>
        <?php if ($o['instructions']): ?><p class="ck-note"><?= nl2br(h($o['instructions'])) ?></p><?php endif; ?>

        <?php if ($o['code'] === 'wave' && $o['fee_percent'] > 0): ?>
          <div class="wave-demo" aria-hidden="true">
            <div class="wd-title">Envoyer de l'argent</div>
            <div class="wd-label">À</div>
            <div class="wd-value"><?= h($o['merchant_number'] ?: 'Numéro marchand') ?></div>
            <div class="wd-label">Montant envoyé</div>
            <div class="wd-field wd-auto"><?= h(fmt_amount($o['amount_to_send'], 'F')) ?> <em>calculé par Wave</em></div>
            <div class="wd-label wd-hl-label">Montant reçu 👈 tapez ici</div>
            <div class="wd-field wd-hl"><?= h($amountTxt) ?></div>
            <div class="wd-btn">Envoyer</div>
          </div>
        <?php endif; ?>

        <?php if ($main): ?>
          <a class="ck-pay" href="<?= h($main['link']) ?>">Payer <?= h($amountTxt) ?> F</a>
          <div class="ck-code">
            <span class="ck-k">Ou composez</span>
            <button type="button" class="ck-copy" onclick="copyText(<?= h(json_encode($main['code'])) ?>, this)" title="Copier"><code><?= h($main['code']) ?></code><span>Copier</span></button>
          </div>
        <?php elseif ($o['pay_link']): ?>
          <a class="ck-pay" href="<?= h($o['pay_link']) ?>" target="_blank" rel="noopener">Payer <?= h($amountTxt) ?> F avec <?= h($o['name']) ?></a>
        <?php endif; ?>
        <?php if ($main && $o['pay_link']): ?>
          <a class="ck-alt-btn" href="<?= h($o['pay_link']) ?>" target="_blank" rel="noopener">Payer avec le lien <?= h($o['name']) ?></a>
        <?php endif; ?>

        <?php if ($o['merchant_number']): ?>
          <div class="ck-code">
            <span class="ck-k">Numéro marchand</span>
            <button type="button" class="ck-copy" onclick="copyText(<?= h(json_encode((string)$o['merchant_number'])) ?>, this)"><code><?= h($o['merchant_number']) ?></code><span>Copier</span></button>
          </div>
        <?php endif; ?>

        <?php if (count($uo) > 1): ?>
          <details class="ck-more">
            <summary>Le code ne marche pas ? Autres façons de payer</summary>
            <?php foreach (array_slice($uo, 1) as $u): ?>
              <div class="ck-code">
                <span class="ck-k"><?= h($u['label']) ?></span>
                <a class="ck-copy" href="<?= h($u['link']) ?>"><code><?= h($u['code']) ?></code><span>Composer</span></a>
              </div>
            <?php endforeach; ?>
          </details>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <!-- ===== Écran 3 : résultat ===== -->
    <section class="ck-view ck-result" data-view="result" hidden>
      <div class="ck-result-ic" data-result-ic></div>
      <h2 data-result-title></h2>
      <p class="ck-muted" data-result-text></p>
      <div class="ck-receipt">
        <div><span>Montant</span><strong><?= h($amountTxt) ?> F CFA</strong></div>
        <div><span>Marchand</span><strong><?= h($merchantName) ?></strong></div>
        <div><span>Référence</span><strong class="mono"><?= h($p['reference']) ?></strong></div>
      </div>
      <a class="ck-pay" data-return hidden href="#">Retourner sur le site du marchand</a>
    </section>
    <?php endif; ?>
  </div>

  <footer class="ck-foot">
    <span><span data-expiry>Expire le <?= h(fmt_dt($p['expires_at'])) ?></span><?php if ($wa): ?> · <a href="https://wa.me/<?= h($wa) ?>?text=<?= h(rawurlencode('Paiement ' . $p['reference'])) ?>" target="_blank" rel="noopener">Besoin d'aide ?</a><?php endif; ?></span>
    <span class="ck-secure"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.4 8 9 4.6-.6 8-4.5 8-9V6z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Paiement sécurisé par <strong><?= h(app_name()) ?></strong></span>
  </footer>
</div>
</div>
<script>
(function(){
  var co = document.getElementById('co'); if (!co) return;
  var ref = co.dataset.ref;
  var apiBase = <?= json_encode(url('api/public/payments/')) ?> + encodeURIComponent(ref);
  var embed = <?= $embed ? 'true' : 'false' ?>;
  var amountTxt = <?= json_encode($amountTxt) ?>;
  var phoneSet = co.dataset.phoneSet === '1';
  var phoneLabel = <?= json_encode($pub['payer_phone_masked']) ?> || '';
  var chosen = null, KEY = 'fp_op', CCKEY = 'fp_cc';
  var form = document.getElementById('phone-form'), input = document.getElementById('payer-phone');
  var payBtn = document.getElementById('pay-btn'), err = document.getElementById('phone-err'), cc = document.getElementById('cc');

  function tell(msg){ if (embed && window.parent !== window) window.parent.postMessage(Object.assign({ source: 'flashpay', payment: ref }, msg), '*'); }
  function resize(){ tell({ type: 'resize', height: document.documentElement.scrollHeight }); }
  function view(name){
    co.querySelectorAll('.ck-view').forEach(function(v){
      v.hidden = !(v.dataset.view === name && (name !== 'pay' || v.dataset.panel === chosen));
    });
    co.querySelectorAll('[data-phone-label]').forEach(function(e){ e.textContent = phoneLabel; });
    window.scrollTo(0, 0); resize();
  }
  function opName(){ var b = co.querySelector('.ck-method[data-op="' + chosen + '"]'); return b ? b.dataset.name : ''; }
  function updateButton(){
    if (!payBtn) return;
    var label = payBtn.querySelector('[data-pay-label]');
    if (!chosen) { payBtn.disabled = true; label.textContent = 'Choisissez une méthode de paiement'; return; }
    payBtn.disabled = false;
    payBtn.style.setProperty('--op', getComputedStyle(co.querySelector('.ck-method[data-op="' + chosen + '"]')).getPropertyValue('--op'));
    label.textContent = 'Payer ' + amountTxt + ' F avec ' + opName();
  }
  function pick(code){
    chosen = code;
    co.querySelectorAll('.ck-method').forEach(function(m){
      var on = m.dataset.op === code; m.classList.toggle('on', on); m.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    try { localStorage.setItem(KEY, code); } catch (e) {}
    tell({ type: 'operator', operator: code });
    updateButton();
    if (input && !phoneSet) input.focus();
  }
  co.querySelectorAll('.ck-method').forEach(function(m){ m.addEventListener('click', function(){ pick(m.dataset.op); }); });
  co.querySelectorAll('[data-back]').forEach(function(b){ b.addEventListener('click', function(){ view('choose'); }); });
  try { var savedCc = localStorage.getItem(CCKEY); if (savedCc && cc) cc.value = savedCc; } catch (e) {}

  if (form) form.addEventListener('submit', function(e){
    e.preventDefault();
    if (!chosen) return;
    err.hidden = true;
    var v = input.value.replace(/[^\d]/g, '');
    if (!v && phoneSet) { view('pay'); return; } // numéro déjà connu
    if (v.length < 8) { err.textContent = 'Saisissez votre numéro (8 chiffres).'; err.hidden = false; resize(); return; }
    var full = v.length > 8 ? v : cc.value + v;
    try { localStorage.setItem(CCKEY, cc.value); } catch (e) {}
    payBtn.disabled = true; payBtn.classList.add('is-loading');
    fetch(apiBase + '/payer', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ payer_phone: full, operator: chosen }) })
      .then(function(r){ return r.json(); })
      .then(function(d){
        payBtn.disabled = false; payBtn.classList.remove('is-loading');
        if (d.error) { err.textContent = d.error.message; err.hidden = false; resize(); return; }
        phoneSet = true; phoneLabel = d.payer_phone_masked || v;
        if (!render(d)) view('pay');
      })
      .catch(function(){ payBtn.disabled = false; payBtn.classList.remove('is-loading'); err.textContent = 'Connexion impossible, réessayez.'; err.hidden = false; });
  });

  // --- Résultat ---
  var texts = {
    paid: ['Paiement réussi', 'Merci ! Votre paiement a bien été reçu.', 'ok'],
    expired: ['Paiement expiré', 'Le délai est dépassé. Si vous avez déjà payé, contactez le support avec la référence ci-dessous.', 'bad'],
    cancelled: ['Paiement annulé', 'Ce paiement a été annulé par le marchand.', 'bad']
  };
  function render(d){
    if (!d || d.status === 'pending') return false;
    var t = texts[d.status] || texts.cancelled;
    var res = co.querySelector('[data-view="result"]'); if (!res) return true;
    res.querySelector('[data-result-title]').textContent = t[0];
    res.querySelector('[data-result-text]').textContent = t[1];
    res.querySelector('[data-result-ic]').className = 'ck-result-ic ' + t[2];
    res.querySelector('[data-result-ic]').innerHTML = t[2] === 'ok'
      ? '<svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="m15 27 7 7 15-15"/></svg>'
      : '<svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M18 18l16 16M34 18 18 34"/></svg>';
    var back = res.querySelector('[data-return]');
    if (d.status === 'paid' && d.return_url && !embed) {
      var u = new URL(d.return_url); u.searchParams.set('payment_id', ref); u.searchParams.set('status', 'paid');
      back.href = u.toString(); back.hidden = false;
      setTimeout(function(){ location.href = back.href; }, 4000);
    }
    var ex = co.querySelector('[data-expiry]'); if (ex) ex.hidden = true;
    view('result');
    tell({ type: 'status', status: d.status });
    return true;
  }
  if (render({ status: <?= json_encode($p['status']) ?>, return_url: <?= json_encode($pub['return_url']) ?> })) return;

  // Départ : opérateur unique ou dernier choisi
  var single = co.dataset.single, saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (single) pick(single);
  else if (saved && co.querySelector('.ck-method[data-op="' + saved + '"]')) pick(saved);
  view('choose');

  var delay = 3000, started = Date.now();
  function poll(){ fetch(apiBase, { cache: 'no-store' }).then(function(r){ return r.json(); }).then(function(d){ if (!render(d)) schedule(); }).catch(schedule); }
  function schedule(){ if (Date.now() - started > 300000) delay = 10000; setTimeout(poll, delay); }
  schedule();
  window.addEventListener('load', resize);
})();
function copyText(t, btn){
  var label = btn.querySelector('span');
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function(){
    if (label) { label.textContent = 'Copié ✓'; setTimeout(function(){ label.textContent = 'Copier'; }, 1500); }
  }).catch(function(){ prompt('Copiez :', t); });
}
</script>
</body></html>
