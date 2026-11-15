<?php view('_head', ['title' => 'Paiements Mobile Money par numéro marchand']); view('_public_nav'); ?>
<main>
  <section class="hero wrap">
    <h1>Encaissez par Mobile Money, confirmés automatiquement</h1>
    <p>Vos clients paient sur votre numéro marchand <?= h(setting('operator_name')) ?>. Notre téléphone passerelle lit le SMS de confirmation
      et valide le paiement en quelques secondes. Votre site est prévenu par webhook.</p>
    <div class="row">
      <a class="btn primary" href="<?= h(url('docs')) ?>">Lire la documentation</a>
      <?php $wa = preg_replace('/\D+/', '', (string)setting('support_whatsapp')); if ($wa): ?>
        <a class="btn" href="https://wa.me/<?= h($wa) ?>?text=<?= h(rawurlencode('Bonjour, je veux intégrer ' . app_name())) ?>">Demander une clé API</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="wrap grid g2">
    <div class="card">
      <h2>Comment ça marche</h2>
      <ol class="steps">
        <li>Votre site crée un paiement via l'API (montant + numéro du client).</li>
        <li>Le client compose le code USSD affiché et paie sur le numéro marchand.</li>
        <li>L'opérateur envoie un SMS de confirmation au téléphone passerelle.</li>
        <li>Le SMS est lu, vérifié (montant, numéro, ID de transaction, solde) et rapproché du paiement.</li>
        <li>Votre site reçoit un webhook signé <code>payment.succeeded</code> et livre la commande.</li>
      </ol>
    </div>
    <div class="card">
      <h2>Pourquoi c'est fiable</h2>
      <p><strong>Anti-doublon :</strong> chaque ID de transaction opérateur n'est accepté qu'une seule fois.</p>
      <p><strong>Contrôle du solde :</strong> le nouveau solde annoncé par le SMS doit correspondre à l'ancien solde plus le montant reçu. Un faux SMS est donc mis de côté pour vérification.</p>
      <p><strong>Expéditeur vérifié :</strong> seuls les SMS de l'opérateur sont pris en compte.</p>
      <p><strong>Webhooks signés</strong> (HMAC-SHA256), renvoyés automatiquement en cas d'échec.</p>
    </div>
  </section>
</main>
<footer class="footer"><div class="wrap">© <?= date('Y') ?> <?= h(app_name()) ?> · <a href="<?= h(url('docs')) ?>">API</a></div></footer>
</body></html>
