<h1>Marchands & clés API</h1>
<p class="muted">Un marchand = un site ou une personne qui intègre votre API. Créez-en un pour vous-même pour vos propres sites.</p>

<?php if ($newKey): ?>
  <div class="card" style="border-color:var(--brand)">
    <h2>🔑 Clé API (affichée une seule fois)</h2>
    <div class="key"><?= h($newKey) ?></div>
    <p class="small muted" style="margin-top:8px">Transmettez-la au marchand avec son secret de webhook et le lien vers la documentation : <a href="<?= h(abs_url('docs')) ?>"><?= h(abs_url('docs')) ?></a></p>
  </div>
<?php endif; ?>

<form class="card" method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2>Nouveau marchand</h2>
  <div class="grid g2">
    <div><label>Nom</label><input type="text" name="name" required placeholder="Ex. Ma boutique"></div>
    <div><label>URL de webhook (optionnel)</label><input type="url" name="webhook_url" placeholder="https://site.com/webhook"></div>
    <div><label>Email</label><input type="email" name="email"></div>
    <div><label>Téléphone</label><input type="tel" name="phone"></div>
  </div>
  <p></p><button class="btn primary">Créer et générer la clé</button>
</form>

<?php foreach ($rows as $m): ?>
  <div class="card">
    <div class="row">
      <h2 style="margin:0"><?= h($m['name']) ?></h2>
      <?= $m['active'] ? badge('online') : badge('cancelled') ?>
      <span class="spacer"></span>
      <span class="small muted"><?= (int)$m['count_paid'] ?> paiements · <?= h(fmt_amount($m['total_paid'])) ?></span>
    </div>
    <p class="small muted" style="margin-top:6px">Clé : <span class="mono"><?= h($m['api_key_prefix']) ?>…</span> · Secret webhook : <span class="mono key" style="display:inline-block;padding:2px 6px"><?= h($m['webhook_secret']) ?></span></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <div class="grid g2">
        <div><label>Nom</label><input type="text" name="name" value="<?= h($m['name']) ?>"></div>
        <div><label>URL de webhook</label><input type="url" name="webhook_url" value="<?= h($m['webhook_url']) ?>"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= h($m['email']) ?>"></div>
        <div><label>Téléphone</label><input type="tel" name="phone" value="<?= h($m['phone']) ?>"></div>
      </div>
      <div class="row" style="margin-top:12px">
        <button class="btn primary" name="action" value="update">Enregistrer</button>
        <button class="btn" name="action" value="test">Tester le webhook</button>
        <button class="btn" name="action" value="rotate" onclick="return confirm('L\'ancienne clé cessera de fonctionner. Continuer ?')">Nouvelle clé API</button>
        <button class="btn danger" name="action" value="toggle"><?= $m['active'] ? 'Désactiver' : 'Activer' ?></button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
