<h1>Simuler un SMS reçu</h1>
<p class="muted">Pour tester sans vrai paiement : le SMS est traité exactement comme s'il venait du téléphone (rapprochement, webhooks, etc.).</p>
<?php if ($result): ?>
  <div class="alert <?= $result['status'] === 'matched' ? 'ok' : 'warn' ?>">
    Résultat : <strong><?= h(status_label($result['status'])) ?></strong>
    <?php if ($result['payment']): ?> → paiement <a href="<?= h(url('admin/payments/' . $result['payment'])) ?>"><?= h($result['payment']) ?></a><?php endif; ?>
    · <a href="<?= h(url('admin/sms')) ?>">voir le SMS #<?= (int)$result['id'] ?></a>
  </div>
<?php endif; ?>
<div class="grid g2">
  <form class="card" method="post">
    <?= csrf_field() ?>
    <label>Téléphone (pour le contrôle du solde)</label>
    <select name="device_id"><option value="">Aucun (pas de contrôle du solde)</option>
      <?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?></option><?php endforeach; ?></select>
    <label>Expéditeur</label><input type="text" name="sender" id="sim-sender" value="OrangeMoney">
    <label>Texte du SMS</label><textarea name="body" id="sim-body" rows="5" required></textarea>
    <p></p><button class="btn primary">Envoyer le SMS simulé</button>
  </form>
  <div class="card">
    <h2>Paiements en attente</h2>
    <p class="small muted">Cliquez pour pré-remplir un SMS correspondant.</p>
    <?php foreach ($pending as $p): ?>
      <p><button type="button" class="btn sm" onclick="fill(<?= (int)$p['amount'] ?>, <?= json_encode((string)$p['payer_phone']) ?>)">
        <?= h(fmt_amount($p['amount'])) ?> · <?= h($p['payer_phone']) ?></button> <span class="small mono muted"><?= h($p['reference']) ?></span></p>
    <?php endforeach; ?>
    <?php if (!$pending): ?><p class="muted">Aucun. Créez-en un dans Paiements.</p><?php endif; ?>
  </div>
</div>
<script>
function fill(amount, phone){
  var id = 'PP' + Date.now().toString().slice(-10) + '.' + Math.floor(Math.random()*9000+1000);
  document.getElementById('sim-body').value =
    'Vous avez recu ' + amount + ' FCFA du ' + phone + '. ID Trans: ' + id;
}
</script>
