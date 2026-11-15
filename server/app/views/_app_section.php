<section class="card" id="app">
  <h2>Application & notifications</h2>
  <p class="muted small">Installez l'espace marchand comme une application sur votre téléphone ou ordinateur, et recevez une notification à chaque paiement reçu (et quand un SMS doit être vérifié).</p>
  <div class="grid g2">
    <div class="app-box">
      <h3><?= icon('phone', 16) ?> Installer l'application</h3>
      <p class="small"><strong>Android / ordinateur (Chrome, Edge)</strong> : bouton ci-dessous, ou menu ⋮ → « Installer l'application ».</p>
      <button class="btn primary sm" type="button" data-pwa-install hidden>Installer maintenant</button>
      <p class="small" style="margin-top:10px"><strong>iPhone (Safari)</strong> : bouton Partager <span aria-hidden="true">⬆️</span> → « Sur l'écran d'accueil », puis ouvrez l'application depuis son icône.</p>
      <p class="small alert warn" data-ios-hint hidden>Sur iPhone, les notifications ne fonctionnent qu'une fois l'application ajoutée à l'écran d'accueil (iOS 16.4 ou plus récent).</p>
    </div>
    <div class="app-box">
      <h3><?= icon('sms', 16) ?> Notifications sur cet appareil</h3>
      <p class="small" data-push-status>Chargement…</p>
      <div class="row">
        <button class="btn primary sm" type="button" data-push-enable hidden>Activer les notifications</button>
        <span data-push-on hidden class="row">
          <button class="btn sm" type="button" data-push-test>Envoyer une notification de test</button>
          <button class="btn sm danger" type="button" data-push-disable>Désactiver</button>
        </span>
      </div>
      <p class="small muted" style="margin-top:10px">À activer sur chaque appareil où vous voulez être alerté.</p>
    </div>
  </div>
</section>
