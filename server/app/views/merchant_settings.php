<h1>Mon compte</h1>
<div class="grid g2">
  <form class="card" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <h2>Profil</h2>
    <label>Nom de l'entreprise ou du site</label><input type="text" name="name" value="<?= h($m['name']) ?>" required>
    <label>Téléphone</label><input type="tel" name="phone" value="<?= h($m['phone']) ?>" required>
    <label>Email (identifiant de connexion)</label><input type="email" value="<?= h($m['email']) ?>" disabled>
    <p class="small muted">Statut du compte : <?= $m['status'] === 'active' ? '<span class="badge b-paid">Validé</span>' : '<span class="badge b-pending">En attente de validation</span>' ?></p>
    <button class="btn primary">Enregistrer</button>
  </form>
  <form class="card" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <h2>Mot de passe</h2>
    <label>Mot de passe actuel</label><input type="password" name="current_password" required autocomplete="current-password">
    <label>Nouveau mot de passe</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password">
    <p></p><button class="btn">Changer le mot de passe</button>
  </form>
</div>
<?php view('_app_section'); ?>
