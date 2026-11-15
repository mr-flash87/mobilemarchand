<?php
$api = abs_url('api/v1');
$n = h(app_name());
$cur = h(setting('currency'));
$op = h(operators_names());
$exp = (int)setting('payment_expiry_minutes');
$opts = ussd_options(5000) ?: [['label' => 'Code USSD', 'code' => '*XXX*70000000*5000#', 'link' => 'tel:*XXX*70000000*5000%23']];
$readyOps = array_values(array_filter(operators(true), 'operator_ready'));
$sampleOps = array_map(fn($o) => operator_public($o, 5000), $readyOps ?: array_slice(operators(true), 0, 1));
view('_head', ['title' => 'Documentation API']);
view('_public_nav');

/** Bloc de code avec onglets de langages et bouton Copier. */
function doc_code(array $tabs, string $id): void
{
    echo '<div class="dc" data-dc>';
    echo '<div class="dc-bar"><div class="dc-tabs" role="tablist">';
    $i = 0;
    foreach ($tabs as $label => $code) {
        echo '<button type="button" role="tab" class="' . ($i === 0 ? 'on' : '') . '" data-tab="' . h($id . '-' . $i) . '">' . h($label) . '</button>';
        $i++;
    }
    echo '</div><button type="button" class="dc-copy" data-copy>Copier</button></div>';
    $i = 0;
    foreach ($tabs as $label => $code) {
        echo '<pre id="' . h($id . '-' . $i) . '"' . ($i === 0 ? '' : ' hidden') . '><code>' . h($code) . '</code></pre>';
        $i++;
    }
    echo '</div>';
}

function doc_params(array $rows): void
{
    echo '<div class="dp">';
    foreach ($rows as [$name, $type, $req, $desc]) {
        echo '<div class="dp-row"><div class="dp-name"><code>' . h($name) . '</code><span class="dp-type">' . h($type) . '</span>'
            . ($req ? '<span class="dp-req">obligatoire</span>' : '<span class="dp-opt">optionnel</span>') . '</div>'
            . '<div class="dp-desc">' . $desc . '</div></div>';
    }
    echo '</div>';
}

$sections = [
    'intro' => 'Introduction',
    'flux' => 'Déroulement',
    'auth' => 'Authentification',
    'create' => 'Créer un paiement',
    'get' => 'Consulter',
    'list' => 'Lister',
    'cancel' => 'Annuler',
    'status' => 'Statuts',
    'operators' => 'Opérateurs',
    'ussd' => 'Codes USSD',
    'webhooks' => 'Webhooks',
    'verify' => 'Signature',
    'ui' => 'Interface',
    'errors' => 'Erreurs',
    'test' => 'Tester',
];
?>
<div class="docs2">
  <header class="dh">
    <div class="lp-wrap">
      <span class="lp-kicker">Développeurs</span>
      <h1>Documentation de l'API <?= $n ?></h1>
      <p>Encaissez des paiements <?= $op ?> sur votre site ou votre application, avec une confirmation automatique en quelques secondes.</p>
      <div class="dh-base">
        <span class="dh-label">URL de base</span>
        <code id="base-url"><?= h($api) ?></code>
        <button class="btn sm" type="button" data-copy-text="<?= h($api) ?>">Copier</button>
      </div>
    </div>
  </header>

  <nav class="dn-mobile" aria-label="Sommaire">
    <?php foreach ($sections as $id => $label): ?><a href="#<?= $id ?>"><?= h($label) ?></a><?php endforeach; ?>
  </nav>

  <div class="lp-wrap docs2-grid">
    <aside class="dn" aria-label="Sommaire">
      <div class="dn-title">Sur cette page</div>
      <?php foreach ($sections as $id => $label): ?><a href="#<?= $id ?>"><?= h($label) ?></a><?php endforeach; ?>
    </aside>

    <article class="dc-main">
      <section id="intro">
        <h2>Introduction</h2>
        <p>L'API <?= $n ?> permet d'encaisser des paiements <?= $op ?> sur un numéro marchand, <strong>sans agrégateur</strong>.
          Un téléphone Android lit le SMS de confirmation de l'opérateur et valide le paiement automatiquement.</p>
        <div class="dgrid">
          <div class="dcard"><strong>Format</strong><span>JSON (UTF-8)</span></div>
          <div class="dcard"><strong>Montants</strong><span><?= $cur ?>, nombres entiers</span></div>
          <div class="dcard"><strong>Expiration</strong><span><?= $exp ?> minutes</span></div>
          <div class="dcard"><strong>Clé API</strong><span>fournie par l'administrateur</span></div>
        </div>
      </section>

      <section id="flux">
        <h2>Déroulement d'un paiement</h2>
        <ol class="steps">
          <li>Votre serveur appelle <code>POST /payments</code> avec le montant et le numéro du client.</li>
          <li>Vous redirigez le client vers <code>checkout_url</code>, ou vous affichez vous-même le code USSD.</li>
          <li>Le client paie <strong>depuis le numéro indiqué</strong> : c'est lui qui permet de reconnaître son paiement.</li>
          <li>Dès réception du SMS, le paiement passe à <code>paid</code> et votre serveur reçoit le webhook <code>payment.succeeded</code>.</li>
          <li>Vous vérifiez la signature du webhook, puis vous livrez la commande.</li>
        </ol>
        <div class="callout warn"><?= icon('alert', 18) ?><div>Ne livrez jamais sur la seule base du retour navigateur (<code>return_url</code>). Fiez-vous au webhook signé ou à un <code>GET /payments/{id}</code> fait depuis votre serveur.</div></div>
      </section>

      <section id="auth">
        <h2>Authentification</h2>
        <p>Envoyez votre clé secrète dans l'en-tête <code>Authorization</code>. Ne la mettez jamais dans du code exécuté dans le navigateur ou dans une application mobile.</p>
        <?php doc_code(['En-tête' => "Authorization: Bearer fp_live_xxxxxxxxxxxxxxxxxxxxxxxx"], 'auth'); ?>
        <div class="callout"><?= icon('book', 18) ?><div>Si votre hébergeur supprime l'en-tête <code>Authorization</code>, utilisez <code>X-Api-Key: fp_live_...</code>.</div></div>
      </section>

      <section id="create">
        <div class="ep"><span class="m post">POST</span><code>/payments</code></div>
        <h2>Créer un paiement</h2>
        <?php doc_params([
            ['amount', 'entier', true, "Montant en $cur (entre " . h(setting('min_amount')) . ' et ' . h(setting('max_amount')) . ').'],
            ['payer_phone', 'texte', true, 'Numéro qui va payer, ex. <code>70123456</code> ou <code>+22670123456</code>.'],
            ['reference', 'texte', false, 'Votre identifiant de commande.'],
            ['operator', 'texte', false, 'Code de l\'opérateur à imposer (' . ($readyOps ? implode(', ', array_map(fn($o) => '<code>' . h($o['code']) . '</code>', $readyOps)) : '<code>orange</code>…') . '). Sans ce champ, le client choisit son opérateur sur la page de paiement.'],
            ['description', 'texte', false, 'Affiché au client sur la page de paiement.'],
            ['callback_url', 'URL', false, 'URL de webhook pour ce paiement (sinon celle de votre compte).'],
            ['return_url', 'URL', false, 'Où renvoyer le client après paiement (<code>?payment_id=…&amp;status=paid</code> est ajouté).'],
            ['metadata', 'objet', false, 'Données libres, renvoyées telles quelles.'],
        ]); ?>
        <?php doc_code([
            'cURL' => "curl -X POST $api/payments \\\n  -H \"Authorization: Bearer fp_live_xxx\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\n    \"amount\": 5000,\n    \"payer_phone\": \"70123456\",\n    \"reference\": \"CMD-1042\",\n    \"return_url\": \"https://monsite.com/merci\"\n  }'",
            'PHP' => "<?php\n\$ch = curl_init('$api/payments');\ncurl_setopt_array(\$ch, [\n  CURLOPT_POST => true,\n  CURLOPT_RETURNTRANSFER => true,\n  CURLOPT_HTTPHEADER => ['Authorization: Bearer fp_live_xxx', 'Content-Type: application/json'],\n  CURLOPT_POSTFIELDS => json_encode([\n    'amount' => 5000,\n    'payer_phone' => \$_POST['phone'],\n    'reference' => 'CMD-' . \$orderId,\n    'return_url' => 'https://monsite.com/merci',\n  ]),\n]);\n\$payment = json_decode(curl_exec(\$ch), true);\nif (isset(\$payment['error'])) { exit(\$payment['error']['message']); }\nheader('Location: ' . \$payment['checkout_url']); // page de paiement",
            'Node.js' => "const res = await fetch('$api/payments', {\n  method: 'POST',\n  headers: {\n    Authorization: 'Bearer ' + process.env.API_KEY,\n    'Content-Type': 'application/json',\n  },\n  body: JSON.stringify({ amount: 5000, payer_phone: '70123456', reference: 'CMD-1042' }),\n});\nconst payment = await res.json();\nconsole.log(payment.checkout_url, payment.ussd_code);",
            'Python' => "import requests\nr = requests.post(\n    \"$api/payments\",\n    headers={\"Authorization\": \"Bearer fp_live_xxx\"},\n    json={\"amount\": 5000, \"payer_phone\": \"70123456\", \"reference\": \"CMD-1042\"},\n)\npayment = r.json()\nprint(payment[\"checkout_url\"])",
        ], 'create'); ?>
        <p class="dlabel">Réponse <span class="badge b-paid">201</span></p>
        <?php
        $sample = [
            'id' => 'PAY-7KQ2M9XT4B', 'object' => 'payment', 'status' => 'pending', 'amount' => 5000, 'currency' => (string)setting('currency'),
            'description' => null, 'reference' => 'CMD-1042', 'payer_phone' => '70123456',
            'operator' => null, 'operator_name' => $sampleOps[0]['name'] ?? 'Orange Money',
            'merchant_number' => ($sampleOps[0]['merchant_number'] ?? '') ?: '7XXXXXXX',
            'ussd_code' => $opts[0]['code'], 'ussd_link' => $opts[0]['link'],
            'ussd_options' => $opts, 'pay_link' => $sampleOps[0]['pay_link'] ?? null,
            'operators' => $sampleOps, 'paid_operator' => null,
            'checkout_url' => abs_url('pay/PAY-7KQ2M9XT4B'), 'operator_txn_id' => null,
            'created_at' => '2026-11-15T10:00:00Z', 'expires_at' => '2026-11-15T10:' . str_pad((string)$exp, 2, '0', STR_PAD_LEFT) . ':00Z', 'paid_at' => null,
        ];
        doc_code(['JSON' => json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'create-res');
        ?>
        <div class="callout"><?= icon('clock', 18) ?><div>Le paiement expire après <?= $exp ?> minutes. Un même client ne doit pas avoir deux paiements en attente du même montant : annulez l'ancien avant d'en créer un nouveau.</div></div>
      </section>

      <section id="get">
        <div class="ep"><span class="m get">GET</span><code>/payments/{id}</code></div>
        <h2>Consulter un paiement</h2>
        <?php doc_code(['cURL' => "curl $api/payments/PAY-7KQ2M9XT4B \\\n  -H \"Authorization: Bearer fp_live_xxx\""], 'get'); ?>
      </section>

      <section id="list">
        <div class="ep"><span class="m get">GET</span><code>/payments</code></div>
        <h2>Lister les paiements</h2>
        <?php doc_params([
            ['status', 'texte', false, '<code>pending</code>, <code>paid</code>, <code>expired</code> ou <code>cancelled</code>.'],
            ['reference', 'texte', false, 'Filtre sur votre référence de commande.'],
            ['limit', 'entier', false, 'Nombre de résultats (100 au maximum, 20 par défaut).'],
        ]); ?>
      </section>

      <section id="cancel">
        <div class="ep"><span class="m post">POST</span><code>/payments/{id}/cancel</code></div>
        <h2>Annuler un paiement</h2>
        <p>Possible uniquement si le statut est <code>pending</code>.</p>
      </section>

      <section id="status">
        <h2>Statuts</h2>
        <div class="dp">
          <div class="dp-row"><div class="dp-name"><span class="badge b-pending">pending</span></div><div class="dp-desc">En attente du paiement du client.</div></div>
          <div class="dp-row"><div class="dp-name"><span class="badge b-paid">paid</span></div><div class="dp-desc">Paiement reçu et confirmé par SMS. Statut final.</div></div>
          <div class="dp-row"><div class="dp-name"><span class="badge b-expired">expired</span></div><div class="dp-desc">Délai dépassé. Un administrateur peut encore le valider si le client a payé en retard (vous recevez alors <code>payment.succeeded</code>).</div></div>
          <div class="dp-row"><div class="dp-name"><span class="badge b-cancelled">cancelled</span></div><div class="dp-desc">Annulé par vous.</div></div>
        </div>
      </section>

      <section id="operators">
        <h2>Opérateurs acceptés</h2>
        <p>Plusieurs opérateurs peuvent être acceptés. Si vous ne précisez pas <code>operator</code>, le client choisit le sien sur la page de paiement.
          La liste <code>operators</code> de la réponse donne, pour chaque opérateur, le numéro marchand, les codes USSD et l'éventuel lien de paiement (<code>pay_link</code>).
          Une fois le paiement reçu, <code>paid_operator</code> indique l'opérateur réellement utilisé.</p>
        <div class="dp">
          <?php foreach ($readyOps as $o): ?>
            <div class="dp-row"><div class="dp-name"><code><?= h($o['code']) ?></code></div><div class="dp-desc"><?= h($o['name']) ?></div></div>
          <?php endforeach; ?>
          <?php if (!$readyOps): ?><div class="dp-row"><div class="dp-desc">Aucun opérateur configuré pour le moment.</div></div><?php endif; ?>
        </div>
      </section>

      <section id="ussd">
        <h2>Codes USSD</h2>
        <p>Pour l'opérateur principal, chaque paiement indique le code principal (<code>ussd_code</code>, <code>ussd_link</code>) et, dans <code>ussd_options</code>, toutes les façons de payer acceptées. Exemple pour 5 000 F :</p>
        <div class="dp">
          <?php foreach ($opts as $i => $o): ?>
            <div class="dp-row"><div class="dp-name"><strong><?= h($o['label']) ?></strong><?php if ($i === 0): ?><span class="dp-req">principal</span><?php endif; ?></div>
              <div class="dp-desc"><code><?= h($o['code']) ?></code></div></div>
          <?php endforeach; ?>
        </div>
        <?php doc_code(['HTML' => "<a href=\"" . $opts[0]['link'] . "\">Payer 5 000 F</a>\n<!-- dans un lien tel:, le # doit être écrit %23 -->"], 'ussd'); ?>
      </section>

      <section id="webhooks">
        <h2>Webhooks</h2>
        <p>À chaque changement de statut, nous envoyons un <code>POST</code> JSON à votre URL de webhook. Événements : <code>payment.succeeded</code>, <code>payment.expired</code>, <code>payment.cancelled</code>.</p>
        <?php doc_code(['Requête reçue' => "POST /votre-webhook\nContent-Type: application/json\nX-Webhook-Event: payment.succeeded\nX-Webhook-Timestamp: 1763200000\nX-Webhook-Signature: sha256=5f1c…e9\n\n{\n  \"id\": \"evt_9a8b7c6d5e4f3a2b\",\n  \"type\": \"payment.succeeded\",\n  \"created_at\": \"2026-11-15T10:03:12Z\",\n  \"data\": { …objet paiement, status \"paid\"… }\n}"], 'wh'); ?>
        <div class="callout"><?= icon('clock', 18) ?><div>Répondez <code>2xx</code> en moins de 10 secondes. Sinon nous réessayons après 1 min, 5 min, 30 min, 2 h, 6 h puis 24 h. Un même événement peut arriver plusieurs fois : traitez-le de façon idempotente.</div></div>
      </section>

      <section id="verify">
        <h2>Vérifier la signature</h2>
        <p>Signature = <code>HMAC-SHA256(timestamp + "." + corps_brut, webhook_secret)</code> en hexadécimal. Votre secret (<code>fpwh_…</code>) est fourni avec votre clé API. Refusez les requêtes de plus de 5 minutes.</p>
        <?php doc_code([
            'PHP' => "<?php\n\$secret = 'fpwh_xxx';\n\$body = file_get_contents('php://input');\n\$ts   = \$_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';\n\$sig  = str_replace('sha256=', '', \$_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');\n\n\$expected = hash_hmac('sha256', \$ts . '.' . \$body, \$secret);\nif (!hash_equals(\$expected, \$sig) || abs(time() - (int)\$ts) > 300) {\n    http_response_code(401); exit('signature invalide');\n}\n\$event = json_decode(\$body, true);\nif (\$event['type'] === 'payment.succeeded') {\n    // livrer la commande \$event['data']['reference'] si pas déjà fait\n}\necho 'ok';",
            'Node.js' => "const crypto = require('crypto');\napp.post('/webhook', express.raw({ type: 'application/json' }), (req, res) => {\n  const ts = req.get('X-Webhook-Timestamp');\n  const sig = (req.get('X-Webhook-Signature') || '').replace('sha256=', '');\n  const expected = crypto.createHmac('sha256', process.env.WHSEC)\n    .update(ts + '.' + req.body.toString()).digest('hex');\n  if (sig.length !== expected.length ||\n      !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected)) ||\n      Math.abs(Date.now() / 1000 - Number(ts)) > 300) {\n    return res.status(401).send('signature invalide');\n  }\n  const event = JSON.parse(req.body);\n  if (event.type === 'payment.succeeded') { /* livrer event.data.reference */ }\n  res.send('ok');\n});",
            'Python' => "import hmac, hashlib, time\n\n@app.post(\"/webhook\")\ndef webhook():\n    ts = request.headers.get(\"X-Webhook-Timestamp\", \"\")\n    sig = request.headers.get(\"X-Webhook-Signature\", \"\").replace(\"sha256=\", \"\")\n    expected = hmac.new(WHSEC.encode(), (ts + \".\").encode() + request.get_data(), hashlib.sha256).hexdigest()\n    if not hmac.compare_digest(sig, expected) or abs(time.time() - int(ts or 0)) > 300:\n        return \"signature invalide\", 401\n    event = request.get_json()\n    if event[\"type\"] == \"payment.succeeded\":\n        pass  # livrer event[\"data\"][\"reference\"]\n    return \"ok\"",
        ], 'verify'); ?>
      </section>

      <section id="ui">
        <h2>Intégrer l'interface de paiement</h2>
        <div class="dgrid two">
          <div class="dcard"><strong>Option 1 : page hébergée</strong><span>Redirigez le client vers <code>checkout_url</code>. La page affiche le bouton d'appel USSD, suit le paiement en direct et renvoie vers votre <code>return_url</code>.</span></div>
          <div class="dcard"><strong>Option 2 : votre interface</strong><span>Affichez un bouton <code>ussd_link</code>, puis interrogez <em>votre</em> serveur, qui appelle <code>GET /payments/{id}</code> toutes les 3 à 5 secondes jusqu'à <code>paid</code>.</span></div>
        </div>
      </section>

      <section id="errors">
        <h2>Erreurs</h2>
        <?php doc_code(['JSON' => "HTTP 422\n{ \"error\": { \"code\": \"invalid_request\", \"message\": \"payer_phone: numéro du payeur obligatoire\" } }"], 'err'); ?>
        <div class="dp">
          <div class="dp-row"><div class="dp-name"><code>401</code><span class="dp-type">unauthorized</span></div><div class="dp-desc">Clé API absente, invalide ou désactivée.</div></div>
          <div class="dp-row"><div class="dp-name"><code>404</code><span class="dp-type">not_found</span></div><div class="dp-desc">Paiement inexistant ou appartenant à un autre compte.</div></div>
          <div class="dp-row"><div class="dp-name"><code>409</code><span class="dp-type">invalid_state</span></div><div class="dp-desc">Action impossible dans le statut actuel.</div></div>
          <div class="dp-row"><div class="dp-name"><code>422</code><span class="dp-type">invalid_request</span></div><div class="dp-desc">Paramètre manquant ou invalide.</div></div>
          <div class="dp-row"><div class="dp-name"><code>500</code><span class="dp-type">server_error</span></div><div class="dp-desc">Erreur interne : réessayez.</div></div>
        </div>
      </section>

      <section id="test">
        <h2>Tester votre intégration</h2>
        <p>Créez un paiement de petit montant avec votre propre numéro et payez-le réellement, ou demandez à l'administrateur de <strong>simuler</strong> le SMS de confirmation : votre webhook sera appelé comme en production.</p>
        <div class="ep"><span class="m get">GET</span><code>/account</code></div>
        <p>Renvoie votre nom de compte, le numéro marchand et les limites : pratique pour vérifier votre clé.</p>
      </section>
    </article>
  </div>
  <footer class="lp-foot"><div class="lp-wrap lp-foot-in">
    <a class="lp-logo" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 16) ?></span><?= $n ?></a>
    <nav><a href="<?= h(url('')) ?>">Accueil</a><a href="<?= h(url('espace/connexion')) ?>">Espace marchand</a></nav>
    <span class="muted small">© <?= date('Y') ?> <?= $n ?></span>
  </div></footer>
</div>
<script>
(function () {
  function copy(text, btn) {
    var done = function () { var t = btn.textContent; btn.textContent = 'Copié ✓'; setTimeout(function () { btn.textContent = t; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { prompt('Copiez :', text); });
    else prompt('Copiez :', text);
  }
  document.querySelectorAll('[data-dc]').forEach(function (box) {
    var tabs = box.querySelectorAll('[data-tab]');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (x) { x.classList.toggle('on', x === t); document.getElementById(x.dataset.tab).hidden = x !== t; });
      });
    });
    box.querySelector('[data-copy]').addEventListener('click', function () {
      var pre = box.querySelector('pre:not([hidden])'); copy(pre.innerText, this);
    });
  });
  document.querySelectorAll('[data-copy-text]').forEach(function (b) {
    b.addEventListener('click', function () { copy(b.getAttribute('data-copy-text'), b); });
  });
  // Section active dans le sommaire
  var links = document.querySelectorAll('.dn a, .dn-mobile a');
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      links.forEach(function (a) {
        var on = a.getAttribute('href') === '#' + e.target.id;
        a.classList.toggle('on', on);
        if (on && a.parentElement.classList.contains('dn-mobile')) a.parentElement.scrollTo({ left: a.offsetLeft - 16, behavior: 'smooth' });
      });
    });
  }, { rootMargin: '-30% 0px -65% 0px' });
  document.querySelectorAll('.dc-main section').forEach(function (s) { io.observe(s); });
})();
</script>
</body></html>
