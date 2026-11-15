<?php
$name = app_name();
$op = operators_names();
$wa = preg_replace('/\D+/', '', (string)setting('support_whatsapp'));
$contact = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Bonjour, je souhaite utiliser ' . $name . ' pour encaisser des paiements.') : url('docs');
view('_head', ['title' => 'Paiements Mobile Money automatiques']);
?>
<div class="lp">
  <header class="lp-nav">
    <div class="lp-wrap lp-nav-in">
      <a class="lp-logo" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 18) ?></span><?= h($name) ?></a>
      <nav class="lp-links">
        <a href="#fonctionnement">Fonctionnement</a>
        <a href="#securite">Sécurité</a>
        <a href="#developpeurs">Développeurs</a>
        <a href="#faq">FAQ</a>
      </nav>
      <div class="lp-actions">
        <a class="btn lp-ghost" href="<?= h(url('admin/login')) ?>">Se connecter</a>
        <a class="btn primary lp-hide-sm" href="<?= h($contact) ?>">Commencer</a>
      </div>
    </div>
  </header>

  <section class="lp-hero">
    <div class="lp-wrap lp-hero-in">
      <div class="lp-hero-text">
        <span class="lp-eyebrow"><span class="dot"></span> Paiements confirmés en temps réel, 24h/24</span>
        <h1>Encaissez par <span class="lp-grad">Mobile Money</span>, sans agrégateur.</h1>
        <p class="lp-lead">Vos clients paient sur votre numéro marchand <?= h($op) ?>. <?= h($name) ?> lit le SMS de confirmation,
          valide le paiement en quelques secondes et prévient votre site automatiquement.</p>
        <div class="lp-cta">
          <a class="btn primary lp-btn-lg" href="<?= h($contact) ?>">Commencer maintenant <?= icon('arrow', 16) ?></a>
          <a class="btn lp-btn-lg" href="<?= h(url('docs')) ?>"><?= icon('book', 16) ?> Documentation API</a>
        </div>
        <ul class="lp-proof">
          <li><?= icon('check', 16) ?> Aucune commission d'agrégateur</li>
          <li><?= icon('check', 16) ?> Intégration en 15 minutes</li>
          <li><?= icon('check', 16) ?> Anti-fraude intégré</li>
        </ul>
      </div>

      <div class="lp-visual" aria-hidden="true">
        <div class="lp-phone">
          <div class="lp-phone-notch"></div>
          <div class="lp-phone-screen">
            <div class="lp-sms-head">Messages</div>
            <div class="lp-sms lp-anim-1">
              <div class="lp-sms-from"><?= h($op ?: 'Mobile Money') ?></div>
              Vous avez reçu 25 000 FCFA du 70•••••56. ID Trans : PP251115.1042
            </div>
            <div class="lp-sms-badge lp-anim-2"><?= icon('check', 14) ?> Lu et transmis</div>
          </div>
        </div>
        <div class="lp-flow lp-anim-2"><span></span><span></span><span></span></div>
        <div class="lp-card-float lp-anim-3">
          <div class="row" style="gap:10px">
            <span class="lp-ok"><?= icon('check', 18) ?></span>
            <div><strong>Paiement confirmé</strong><div class="small muted">PAY-7KQ2M9 · il y a 3 s</div></div>
          </div>
          <div class="lp-amount">25 000 <small>F CFA</small></div>
          <div class="lp-mini">
            <span>Webhook envoyé</span><span class="badge b-paid">200 OK</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="lp-strip">
    <div class="lp-wrap lp-stats">
      <div><strong>&lt; 10 s</strong><span>pour confirmer un paiement</span></div>
      <div><strong>0 %</strong><span>de commission d'agrégateur</span></div>
      <div><strong>24h/24</strong><span>7j/7, même le week-end</span></div>
      <div><strong>100 %</strong><span>des SMS vérifiés (montant, ID, solde)</span></div>
    </div>
  </section>

  <section class="lp-section" id="fonctionnement">
    <div class="lp-wrap">
      <div class="lp-head">
        <span class="lp-kicker">Fonctionnement</span>
        <h2>Simple pour vos clients, automatique pour vous</h2>
        <p>Pas de carte bancaire, pas d'application à installer pour le client : il paie comme d'habitude avec son code USSD.</p>
      </div>
      <div class="lp-steps">
        <div class="lp-step">
          <span class="lp-step-n">1</span>
          <span class="lp-step-ic"><?= icon('card', 22) ?></span>
          <h3>Le client choisit de payer</h3>
          <p>Votre site crée le paiement via l'API. Le client voit le montant et un bouton qui compose le code automatiquement.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">2</span>
          <span class="lp-step-ic"><?= icon('phone', 22) ?></span>
          <h3>Il valide sur son téléphone</h3>
          <p>Il confirme avec son code secret <?= h($op) ?>. L'argent arrive directement sur votre numéro marchand.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">3</span>
          <span class="lp-step-ic"><?= icon('sms', 22) ?></span>
          <h3>Le SMS est lu et vérifié</h3>
          <p>Notre application passerelle lit le SMS de l'opérateur, contrôle le montant, le numéro et l'ID de transaction.</p>
        </div>
        <div class="lp-step">
          <span class="lp-step-n">4</span>
          <span class="lp-step-ic"><?= icon('check', 22) ?></span>
          <h3>Votre site est prévenu</h3>
          <p>Le paiement passe à « payé » et votre serveur reçoit un webhook signé : vous livrez la commande, sans rien faire.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="lp-section lp-alt" id="securite">
    <div class="lp-wrap lp-split">
      <div>
        <span class="lp-kicker">Sécurité</span>
        <h2>Les faux SMS ne passent pas</h2>
        <p class="lp-lead-sm">Chaque SMS reçu passe par plusieurs contrôles avant qu'un paiement soit validé. En cas de doute, il est mis de côté pour vérification au lieu d'être accepté.</p>
        <a class="btn" href="<?= h(url('docs')) ?>#webhooks">Voir comment c'est sécurisé <?= icon('arrow', 14) ?></a>
      </div>
      <div class="lp-feats">
        <div class="lp-feat"><span class="tile-ic blue"><?= icon('sms') ?></span><div><h3>Expéditeur vérifié</h3><p>Seuls les SMS officiels de l'opérateur sont pris en compte.</p></div></div>
        <div class="lp-feat"><span class="tile-ic green"><?= icon('wallet') ?></span><div><h3>Contrôle du solde</h3><p>Le nouveau solde doit correspondre à l'ancien solde + le montant reçu.</p></div></div>
        <div class="lp-feat"><span class="tile-ic amber"><?= icon('alert') ?></span><div><h3>Anti-doublon</h3><p>Un ID de transaction ne peut valider qu'un seul paiement.</p></div></div>
        <div class="lp-feat"><span class="tile-ic red"><?= icon('gear') ?></span><div><h3>Webhooks signés</h3><p>Signature HMAC-SHA256 et renvoi automatique en cas d'échec.</p></div></div>
      </div>
    </div>
  </section>

  <section class="lp-section" id="developpeurs">
    <div class="lp-wrap lp-split">
      <div>
        <span class="lp-kicker">Développeurs</span>
        <h2>Une API claire, intégrée en quelques lignes</h2>
        <p class="lp-lead-sm">Créez un paiement, redirigez votre client vers la page de paiement, recevez un webhook. Exemples prêts à l'emploi en PHP, Node.js et Python.</p>
        <ul class="lp-list">
          <li><?= icon('check', 16) ?> Page de paiement hébergée, adaptée au mobile</li>
          <li><?= icon('check', 16) ?> Ou votre propre interface avec le code USSD</li>
          <li><?= icon('check', 16) ?> Simulateur pour tester sans argent réel</li>
        </ul>
        <a class="btn primary" href="<?= h(url('docs')) ?>">Lire la documentation <?= icon('arrow', 14) ?></a>
      </div>
      <div class="lp-code">
        <div class="lp-code-bar"><span></span><span></span><span></span><em>créer un paiement</em></div>
<pre><code><span class="c-k">curl</span> -X POST <?= h(abs_url('api/v1/payments')) ?> \
  -H <span class="c-s">"Authorization: Bearer fp_live_…"</span> \
  -d <span class="c-s">'{"amount": 5000, "payer_phone": "70123456"}'</span>

<span class="c-c"># Réponse</span>
{
  <span class="c-s">"id"</span>: <span class="c-s">"PAY-7KQ2M9XT4B"</span>,
  <span class="c-s">"status"</span>: <span class="c-s">"pending"</span>,
  <span class="c-s">"ussd_code"</span>: <span class="c-s">"<?= h(ussd_code(5000)) ?>"</span>,
  <span class="c-s">"checkout_url"</span>: <span class="c-s">"…/pay/PAY-7KQ2M9XT4B"</span>
}</code></pre>
      </div>
    </div>
  </section>

  <section class="lp-section lp-alt" id="faq">
    <div class="lp-wrap lp-narrow">
      <div class="lp-head">
        <span class="lp-kicker">FAQ</span>
        <h2>Questions fréquentes</h2>
      </div>
      <details class="lp-faq" open><summary>Ai-je besoin d'un agrégateur de paiement ?</summary>
        <p>Non. Les paiements arrivent directement sur votre numéro marchand. <?= h($name) ?> se charge uniquement de les reconnaître et de les confirmer automatiquement.</p></details>
      <details class="lp-faq"><summary>Comment le paiement est-il reconnu ?</summary>
        <p>Un téléphone Android avec votre SIM marchande reçoit le SMS de l'opérateur. Notre application le transmet au serveur, qui le rapproche du paiement grâce au montant et au numéro du client.</p></details>
      <details class="lp-faq"><summary>Et si le client paie avec un autre numéro ?</summary>
        <p>Le SMS apparaît dans l'espace d'administration comme « non rapproché » : vous pouvez le valider en un clic.</p></details>
      <details class="lp-faq"><summary>Quels opérateurs sont pris en charge ?</summary>
        <p><?= h($op) ?> est configuré par défaut. Les règles de lecture des SMS sont modifiables pour d'autres opérateurs (Moov Money, etc.).</p></details>
      <details class="lp-faq"><summary>Puis-je l'intégrer à mon site ou à mon application ?</summary>
        <p>Oui, via l'API REST documentée. Une page de paiement prête à l'emploi est aussi disponible si vous ne voulez rien développer.</p></details>
    </div>
  </section>

  <section class="lp-cta-band">
    <div class="lp-wrap lp-cta-in">
      <div>
        <h2>Prêt à encaisser automatiquement ?</h2>
        <p>Branchez votre numéro marchand et recevez vos premiers paiements aujourd'hui.</p>
      </div>
      <div class="lp-cta">
        <a class="btn lp-btn-lg lp-white" href="<?= h($contact) ?>">Commencer <?= icon('arrow', 16) ?></a>
        <a class="btn lp-btn-lg lp-outline" href="<?= h(url('admin/login')) ?>">Se connecter</a>
      </div>
    </div>
  </section>

  <footer class="lp-foot">
    <div class="lp-wrap lp-foot-in">
      <a class="lp-logo" href="<?= h(url('')) ?>"><span class="brand-mark"><?= icon('wallet', 16) ?></span><?= h($name) ?></a>
      <nav>
        <a href="<?= h(url('docs')) ?>">Documentation</a>
        <a href="<?= h(url('admin/login')) ?>">Espace marchand</a>
        <?php if ($wa): ?><a href="https://wa.me/<?= h($wa) ?>">Contact WhatsApp</a><?php endif; ?>
      </nav>
      <span class="muted small">© <?= date('Y') ?> <?= h($name) ?></span>
    </div>
  </footer>
</div>
</body></html>
