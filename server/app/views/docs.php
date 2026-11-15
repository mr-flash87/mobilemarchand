<?php
$api = abs_url('api/v1');
$n = h(app_name());
view('_head', ['title' => 'Documentation API']);
view('_public_nav');
?>
<main class="wrap docs">
  <nav>
    <strong>Documentation</strong>
    <a href="#intro">Introduction</a>
    <a href="#flux">Déroulement d'un paiement</a>
    <a href="#auth">Authentification</a>
    <a href="#create">Créer un paiement</a>
    <a href="#get">Consulter un paiement</a>
    <a href="#list">Lister les paiements</a>
    <a href="#cancel">Annuler</a>
    <a href="#status">Statuts</a>
    <a href="#webhooks">Webhooks</a>
    <a href="#verify">Vérifier la signature</a>
    <a href="#ui">Intégrer l'interface</a>
    <a href="#errors">Erreurs</a>
    <a href="#test">Tester</a>
    <a href="#examples">Exemples complets</a>
  </nav>
  <div>
    <section id="intro">
      <h1>API <?= $n ?></h1>
      <p><?= $n ?> permet à votre site ou application d'encaisser des paiements <?= h(setting('operator_name')) ?> sur un numéro marchand,
        sans agrégateur. La confirmation est automatique : un téléphone Android lit le SMS de l'opérateur et valide le paiement.</p>
      <p>URL de base : <code><?= h($api) ?></code><br>Format : JSON (UTF-8). Montants en <?= h(setting('currency')) ?>, nombres entiers.</p>
      <p>Pour obtenir une clé API, contactez l'administrateur de la plateforme.</p>
    </section>

    <section id="flux">
      <h2>Déroulement d'un paiement</h2>
      <ol class="steps">
        <li>Votre serveur appelle <code>POST /payments</code> avec le montant et le numéro de téléphone du client.</li>
        <li>Vous redirigez le client vers <code>checkout_url</code>, ou vous affichez vous-même <code>ussd_code</code> / <code>ussd_link</code>.</li>
        <li>Le client paie <strong>depuis le numéro indiqué</strong>. C'est ce numéro qui permet de reconnaître son paiement.</li>
        <li>Dès réception du SMS, le paiement passe à <code>paid</code> et nous envoyons un webhook <code>payment.succeeded</code> à votre serveur.</li>
        <li>Votre serveur vérifie la signature du webhook puis livre la commande.</li>
      </ol>
      <div class="alert warn">Ne livrez jamais sur la base du retour navigateur (<code>return_url</code>) seul. Fiez-vous au webhook signé ou à un <code>GET /payments/{id}</code> fait depuis votre serveur.</div>
    </section>

    <section id="auth">
      <h2>Authentification</h2>
      <p>Envoyez votre clé secrète dans l'en-tête <code>Authorization</code>. Ne l'exposez jamais dans du code côté navigateur ou dans une application mobile.</p>
<pre><code>Authorization: Bearer fp_live_xxxxxxxxxxxxxxxxxxxxxxxx</code></pre>
      <p class="small muted">Si votre hébergeur supprime l'en-tête Authorization, utilisez <code>X-Api-Key: fp_live_...</code>.</p>
    </section>

    <section id="create">
      <h2>Créer un paiement</h2>
      <p><code>POST /payments</code></p>
      <div class="table-wrap"><table>
        <tr><th>Champ</th><th>Type</th><th>Description</th></tr>
        <tr><td><code>amount</code></td><td>entier</td><td><strong>Obligatoire.</strong> Montant en <?= h(setting('currency')) ?> (entre <?= h(setting('min_amount')) ?> et <?= h(setting('max_amount')) ?>).</td></tr>
        <tr><td><code>payer_phone</code></td><td>texte</td><td><strong>Obligatoire.</strong> Numéro qui va payer, ex. <code>70123456</code> ou <code>+22670123456</code>.</td></tr>
        <tr><td><code>reference</code></td><td>texte</td><td>Votre identifiant de commande (pour vous y retrouver).</td></tr>
        <tr><td><code>description</code></td><td>texte</td><td>Affiché au client sur la page de paiement.</td></tr>
        <tr><td><code>callback_url</code></td><td>URL</td><td>URL de webhook pour ce paiement (sinon celle de votre compte).</td></tr>
        <tr><td><code>return_url</code></td><td>URL</td><td>Où renvoyer le client après paiement (<code>?payment_id=…&amp;status=paid</code> est ajouté).</td></tr>
        <tr><td><code>metadata</code></td><td>objet</td><td>Données libres renvoyées telles quelles.</td></tr>
      </table></div>
<pre><code>curl -X POST <?= h($api) ?>/payments \
  -H "Authorization: Bearer fp_live_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 5000,
    "payer_phone": "70123456",
    "reference": "CMD-1042",
    "description": "Recharge compte",
    "return_url": "https://monsite.com/merci",
    "metadata": {"user_id": 42}
  }'</code></pre>
      <p>Réponse <code>201</code> :</p>
<pre><code>{
  "id": "PAY-7KQ2M9XT4B",
  "object": "payment",
  "status": "pending",
  "amount": 5000,
  "currency": "<?= h(setting('currency')) ?>",
  "description": "Recharge compte",
  "reference": "CMD-1042",
  "payer_phone": "70123456",
  "operator": "<?= h(setting('operator_name')) ?>",
  "merchant_number": "<?= h(setting('merchant_number') ?: '7XXXXXXX') ?>",
  "ussd_code": "<?= h(ussd_code(5000)) ?>",
  "ussd_link": "<?= h(ussd_link(ussd_code(5000))) ?>",
  "checkout_url": "<?= h(abs_url('pay/PAY-7KQ2M9XT4B')) ?>",
  "operator_txn_id": null,
  "created_at": "2026-11-15T10:00:00Z",
  "expires_at": "2026-11-15T10:<?= str_pad((string)(int)setting('payment_expiry_minutes'), 2, '0', STR_PAD_LEFT) ?>:00Z",
  "paid_at": null,
  "metadata": {"user_id": 42},
  "callback_url": null,
  "return_url": "https://monsite.com/merci"
}</code></pre>
      <p class="small muted">Le paiement expire après <?= (int)setting('payment_expiry_minutes') ?> minutes. Un client ne doit pas avoir deux paiements en attente du même montant : annulez l'ancien avant d'en créer un nouveau.</p>
    </section>

    <section id="get">
      <h2>Consulter un paiement</h2>
      <p><code>GET /payments/{id}</code></p>
<pre><code>curl <?= h($api) ?>/payments/PAY-7KQ2M9XT4B -H "Authorization: Bearer fp_live_xxx"</code></pre>
    </section>

    <section id="list">
      <h2>Lister les paiements</h2>
      <p><code>GET /payments?status=paid&amp;limit=20&amp;reference=CMD-1042</code>. Les paramètres sont optionnels, avec 100 résultats au maximum.</p>
    </section>

    <section id="cancel">
      <h2>Annuler un paiement</h2>
      <p><code>POST /payments/{id}/cancel</code> : uniquement si le statut est <code>pending</code>.</p>
    </section>

    <section id="status">
      <h2>Statuts</h2>
      <div class="table-wrap"><table>
        <tr><th>Statut</th><th>Signification</th></tr>
        <tr><td><span class="badge b-pending">pending</span></td><td>En attente du paiement du client.</td></tr>
        <tr><td><span class="badge b-paid">paid</span></td><td>Paiement reçu et confirmé par SMS. Statut final.</td></tr>
        <tr><td><span class="badge b-expired">expired</span></td><td>Délai dépassé sans paiement. Un admin peut encore le valider manuellement si le client a payé en retard (vous recevrez alors <code>payment.succeeded</code>).</td></tr>
        <tr><td><span class="badge b-cancelled">cancelled</span></td><td>Annulé par vous.</td></tr>
      </table></div>
    </section>

    <section id="webhooks">
      <h2>Webhooks</h2>
      <p>À chaque changement de statut, nous envoyons un <code>POST</code> JSON à votre URL de webhook. Événements : <code>payment.succeeded</code>, <code>payment.expired</code>, <code>payment.cancelled</code>.</p>
<pre><code>POST /votre-webhook
Content-Type: application/json
X-Webhook-Event: payment.succeeded
X-Webhook-Timestamp: 1763200000
X-Webhook-Signature: sha256=5f1c...e9

{
  "id": "evt_9a8b7c6d5e4f3a2b",
  "type": "payment.succeeded",
  "created_at": "2026-11-15T10:03:12Z",
  "data": { ...objet paiement, status "paid", operator_txn_id renseigné... }
}</code></pre>
      <p>Répondez avec un code <code>2xx</code> en moins de 10 secondes. Sinon nous réessayons après 1 min, 5 min, 30 min, 2 h, 6 h puis 24 h.
        Un même événement peut arriver plusieurs fois : traitez-le de façon idempotente (vérifiez que la commande n'est pas déjà livrée).</p>
    </section>

    <section id="verify">
      <h2>Vérifier la signature</h2>
      <p>La signature est <code>HMAC-SHA256(timestamp + "." + corps_brut, webhook_secret)</code> en hexadécimal. Votre <code>webhook_secret</code> (<code>fpwh_…</code>) vous est fourni avec votre clé API.
        Refusez les requêtes dont le timestamp a plus de 5 minutes.</p>
      <h3>PHP</h3>
<pre><code>&lt;?php
$secret = 'fpwh_xxx';
$body = file_get_contents('php://input');
$ts   = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$sig  = str_replace('sha256=', '', $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');

$expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
if (!hash_equals($expected, $sig) || abs(time() - (int)$ts) > 300) {
    http_response_code(401); exit('signature invalide');
}
$event = json_decode($body, true);
if ($event['type'] === 'payment.succeeded') {
    $p = $event['data'];
    // ex. : livrer la commande $p['reference'] si pas déjà fait, montant $p['amount']
}
http_response_code(200);
echo 'ok';</code></pre>
      <h3>Node.js (Express)</h3>
<pre><code>const crypto = require('crypto');
app.post('/webhook', express.raw({ type: 'application/json' }), (req, res) => {
  const ts = req.get('X-Webhook-Timestamp');
  const sig = (req.get('X-Webhook-Signature') || '').replace('sha256=', '');
  const expected = crypto.createHmac('sha256', process.env.WHSEC)
    .update(ts + '.' + req.body.toString()).digest('hex');
  if (sig.length !== expected.length ||
      !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected)) ||
      Math.abs(Date.now() / 1000 - Number(ts)) > 300) {
    return res.status(401).send('signature invalide');
  }
  const event = JSON.parse(req.body);
  if (event.type === 'payment.succeeded') { /* livrer event.data.reference */ }
  res.send('ok');
});</code></pre>
      <h3>Python (Flask)</h3>
<pre><code>import hmac, hashlib, time
@app.post("/webhook")
def webhook():
    ts = request.headers.get("X-Webhook-Timestamp", "")
    sig = request.headers.get("X-Webhook-Signature", "").replace("sha256=", "")
    expected = hmac.new(WHSEC.encode(), (ts + "." ).encode() + request.get_data(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(sig, expected) or abs(time.time() - int(ts or 0)) > 300:
        return "signature invalide", 401
    event = request.get_json()
    if event["type"] == "payment.succeeded":
        pass  # livrer event["data"]["reference"]
    return "ok"</code></pre>
    </section>

    <section id="ui">
      <h2>Intégrer l'interface de paiement</h2>
      <p><strong>Option 1 : page hébergée (la plus simple).</strong> Redirigez le client vers <code>checkout_url</code>. La page affiche le bouton d'appel USSD, suit le paiement en direct et renvoie le client vers votre <code>return_url</code>.</p>
      <p><strong>Option 2 : votre propre interface.</strong> Affichez un bouton <code>&lt;a href="ussd_link"&gt;Payer&lt;/a&gt;</code> (il ouvre le composeur du téléphone avec le code), puis interrogez <strong>votre</strong> serveur, qui appelle <code>GET /payments/{id}</code> toutes les 3 à 5 secondes, jusqu'à <code>paid</code>.</p>
<pre><code>&lt;a href="tel:*144*10*70000000*5000%23"&gt;Payer 5 000 F&lt;/a&gt;
&lt;!-- le # doit être encodé en %23 dans un lien tel: --&gt;</code></pre>
    </section>

    <section id="errors">
      <h2>Erreurs</h2>
<pre><code>HTTP 422
{ "error": { "code": "invalid_request", "message": "payer_phone: numéro du payeur obligatoire" } }</code></pre>
      <div class="table-wrap"><table>
        <tr><th>HTTP</th><th>code</th><th>Cause</th></tr>
        <tr><td>401</td><td><code>unauthorized</code></td><td>Clé API absente, invalide ou désactivée.</td></tr>
        <tr><td>404</td><td><code>not_found</code></td><td>Paiement inexistant ou appartenant à un autre compte.</td></tr>
        <tr><td>409</td><td><code>invalid_state</code></td><td>Action impossible dans le statut actuel.</td></tr>
        <tr><td>422</td><td><code>invalid_request</code></td><td>Paramètre manquant ou invalide.</td></tr>
        <tr><td>500</td><td><code>server_error</code></td><td>Erreur interne : réessayez.</td></tr>
      </table></div>
    </section>

    <section id="test">
      <h2>Tester votre intégration</h2>
      <p>Créez un paiement de petit montant avec votre propre numéro, puis payez-le réellement. Ou demandez à l'administrateur de simuler le SMS de confirmation depuis son espace : votre webhook sera appelé comme en production.</p>
      <p><code>GET /account</code> renvoie votre nom de compte, le numéro marchand et les limites : c'est pratique pour vérifier votre clé.</p>
    </section>

    <section id="examples">
      <h2>Exemple complet en PHP</h2>
<pre><code>&lt;?php
// payer.php : crée le paiement puis redirige le client
$ch = curl_init('<?= h($api) ?>/payments');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Authorization: Bearer fp_live_xxx', 'Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode([
    'amount' => 2500,
    'payer_phone' => $_POST['phone'],
    'reference' => 'CMD-' . $orderId,
    'return_url' => 'https://monsite.com/commande/' . $orderId,
  ]),
]);
$payment = json_decode(curl_exec($ch), true);
if (isset($payment['error'])) { exit($payment['error']['message']); }
// enregistrez $payment['id'] avec la commande, puis :
header('Location: ' . $payment['checkout_url']);</code></pre>
      <h2>Exemple en JavaScript (serveur Node 18+)</h2>
<pre><code>const res = await fetch('<?= h($api) ?>/payments', {
  method: 'POST',
  headers: { Authorization: 'Bearer ' + process.env.API_KEY, 'Content-Type': 'application/json' },
  body: JSON.stringify({ amount: 2500, payer_phone: '70123456', reference: 'CMD-1042' }),
});
const payment = await res.json();
console.log(payment.checkout_url, payment.ussd_code);</code></pre>
    </section>
  </div>
</main>
<footer class="footer"><div class="wrap">© <?= date('Y') ?> <?= $n ?></div></footer>
</body></html>
