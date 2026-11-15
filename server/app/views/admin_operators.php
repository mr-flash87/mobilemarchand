<div class="page-head">
  <div>
    <h1>Opérateurs</h1>
    <p class="muted">Ajoutez autant d'opérateurs que vous voulez (Orange Money, Moov Money, Telecel Money, Wave…). Le client choisit le sien sur la page de paiement.</p>
  </div>
  <a class="btn primary" href="#new"><?= icon('plus', 16) ?> Ajouter un opérateur</a>
</div>

<div class="callout"><?= icon('book', 18) ?><div>
  Dans les codes USSD et le lien de paiement, <code>{number}</code> est remplacé par le numéro marchand et <code>{amount}</code> par le montant.
  Mettez un code par ligne au format <code>Libellé | code</code> : le premier est le bouton principal.
  <strong>Pour qu'un paiement soit confirmé automatiquement, le téléphone passerelle doit avoir la SIM de cet opérateur</strong> (un téléphone double SIM peut en gérer deux).
</div></div>

<?php foreach ($rows as $i => $o): $st = $stats[$o['code']] ?? null; $ready = operator_ready($o); ?>
  <form class="card op-card" method="post" style="--op:<?= h($o['color'] ?: '#0b5fff') ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
    <div class="op-head">
      <span class="op-dot"><?= h(mb_strtoupper(mb_substr($o['name'], 0, 1))) ?></span>
      <div class="op-title">
        <strong><?= h($o['name']) ?></strong>
        <span class="small muted">code : <code><?= h($o['code']) ?></code>
          <?php if ($st): ?> · <?= (int)$st['n'] ?> paiement<?= $st['n'] > 1 ? 's' : '' ?> · <?= h(fmt_amount($st['total'])) ?><?php endif; ?></span>
      </div>
      <?php if (!(int)$o['active']): ?><?= badge('cancelled') ?>
      <?php elseif ($ready): ?><span class="badge b-paid">Actif</span>
      <?php else: ?><span class="badge b-pending">Numéro manquant</span><?php endif; ?>
      <div class="op-move">
        <button class="icon-btn" name="action" value="up" title="Monter" <?= $i === 0 ? 'disabled' : '' ?>>▲</button>
        <button class="icon-btn" name="action" value="down" title="Descendre" <?= $i === count($rows) - 1 ? 'disabled' : '' ?>>▼</button>
      </div>
    </div>
    <div class="grid g2">
      <div><label>Nom affiché</label><input type="text" name="name" value="<?= h($o['name']) ?>" required></div>
      <div><label>Numéro marchand (qui reçoit l'argent)</label><input type="tel" name="merchant_number" value="<?= h($o['merchant_number']) ?>" placeholder="Ex. 70000000"></div>
      <div style="grid-column:1/-1"><label>Codes USSD (un par ligne : <code>Libellé | code</code>)</label>
        <textarea name="ussd_codes" rows="2" style="min-height:64px" placeholder="Ex. Transfert | *XXX*{number}*{amount}#"><?= h($o['ussd_codes']) ?></textarea>
        <?php $prev = operator_ussd_options($o, 5000); if ($prev): ?>
          <span class="small muted">Aperçu 5 000 F : <?php foreach ($prev as $p): ?><code><?= h($p['label'] . ' : ' . $p['code']) ?></code> <?php endforeach; ?></span>
        <?php endif; ?></div>
      <div><label>Lien de paiement (optionnel, ex. Wave)</label><input type="url" name="pay_link" value="<?= h($o['pay_link']) ?>" placeholder="https://pay.wave.com/m/…/?amount={amount}"></div>
      <div><label>Expéditeur des SMS (motif)</label><input type="text" name="sms_sender" value="<?= h($o['sms_sender']) ?>" placeholder="Ex. orange|^OM$"></div>
      <div><label>Couleur</label><input type="color" name="color" value="<?= h($o['color'] ?: '#0b5fff') ?>" class="op-color"></div>
      <div style="grid-column:1/-1"><label>Instructions affichées au client (optionnel)</label>
        <textarea name="instructions" rows="2" style="min-height:56px;font-family:inherit"><?= h($o['instructions']) ?></textarea></div>
    </div>
    <div class="row" style="margin-top:14px">
      <label class="check" style="margin:0"><input type="checkbox" name="active" value="1" <?= (int)$o['active'] ? 'checked' : '' ?>> <span>Proposer cet opérateur aux clients</span></label>
      <span class="spacer"></span>
      <button class="btn danger sm" name="action" value="delete" onclick="return confirm('Supprimer cet opérateur ?')">Supprimer</button>
      <button class="btn primary" name="action" value="update">Enregistrer</button>
    </div>
  </form>
<?php endforeach; ?>

<form class="card" method="post" id="new">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2>Ajouter un opérateur</h2>
  <div class="grid g2">
    <div><label>Nom</label><input type="text" name="name" required placeholder="Ex. Sank Money"></div>
    <div><label>Code court (lettres, sans espace)</label><input type="text" name="code" placeholder="Ex. sank"></div>
    <div><label>Numéro marchand</label><input type="tel" name="merchant_number"></div>
    <div><label>Expéditeur des SMS (motif)</label><input type="text" name="sms_sender" placeholder="Ex. sank"></div>
    <div style="grid-column:1/-1"><label>Codes USSD (un par ligne : <code>Libellé | code</code>)</label>
      <textarea name="ussd_codes" rows="2" style="min-height:64px"></textarea></div>
    <div><label>Lien de paiement (optionnel)</label><input type="url" name="pay_link"></div>
    <div><label>Couleur</label><input type="color" name="color" value="#0b5fff" class="op-color"></div>
  </div>
  <label class="check" style="margin-top:12px"><input type="checkbox" name="active" value="1" checked> <span>Proposer cet opérateur aux clients</span></label>
  <p class="small muted">Pensez à ajouter une règle de lecture des SMS pour ce nouvel opérateur dans Réglages (champ <code>"operator"</code> = son code).</p>
  <button class="btn primary">Ajouter</button>
</form>
