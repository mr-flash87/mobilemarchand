# FlashPay : paiements Mobile Money confirmés par SMS

Plateforme pour encaisser des paiements sur un **numéro marchand** (Orange Money, Moov Money…) **sans agrégateur** :
un téléphone Android lit les SMS de confirmation de l'opérateur et valide les paiements automatiquement.

```
Client ──(USSD *144*…#)──▶ Numéro marchand ──SMS──▶ Téléphone Android (app Passerelle)
                                                        │ HTTPS
Votre site / vos clients API ◀──webhook signé── Serveur PHP (ce dossier server/)
```

## Contenu

| Dossier | Rôle |
|---|---|
| `server/` | Site web PHP + MySQL : API, page de paiement, administration, documentation développeur (`/docs`) |
| `android/` | Application Android « Passerelle SMS » (Kotlin). L'APK est compilé automatiquement par GitHub Actions |

## 1. Mettre le serveur en ligne (hébergement cPanel ou autre hébergement PHP)

Prérequis : PHP 8.0+, MySQL/MariaDB, extensions `pdo_mysql`, `curl`, `mbstring`, Apache avec `mod_rewrite` (cas standard). **HTTPS obligatoire** (certificat SSL gratuit dans cPanel).

1. Dans cPanel → **Bases de données MySQL** : créez une base, un utilisateur, et donnez tous les droits à cet utilisateur sur la base.
2. Envoyez le **contenu** du dossier `server/` (ou le fichier `smspay-server.zip`, puis « Extraire ») dans `public_html/` ou dans un sous-domaine (ex. `pay.votresite.com`).
3. Ouvrez `https://votre-domaine/install.php`, remplissez les accès MySQL et créez votre compte admin.
4. **Supprimez `install.php`** après l'installation.
5. Connectez-vous à `https://votre-domaine/admin` puis :
   - **Réglages** : numéro marchand, opérateur, modèle USSD (par défaut `*144*10*{number}*{amount}#`) ;
   - **Téléphones** → Ajouter (saisissez le solde actuel du compte) → notez le code à 6 chiffres ;
   - **Marchands** → créez-vous un marchand pour obtenir une clé API.
6. (Recommandé) cPanel → **Tâches cron**, toutes les minutes : `php /home/VOTRE_USER/public_html/cron.php`

## 2. Installer l'application sur le téléphone Android

1. Téléchargez l'APK : GitHub → onglet **Actions** → « Application Android (APK) » → dernier build → **Artifacts** → `smspay-gateway-apk`.
   Pour que vos téléphones puissent le télécharger directement, envoyez-le aussi dans `server/downloads/smspay-gateway.apk` sur votre hébergement.
2. Sur le téléphone qui a la **SIM marchande** : installez l'APK (autorisez « sources inconnues »).
3. Ouvrez l'application, entrez l'adresse du site et le code à 6 chiffres, puis **Associer**.
4. Acceptez les permissions SMS et notifications, puis **désactivez l'optimisation de batterie**.
   Sur Xiaomi, Tecno, Infinix, itel et Huawei, activez aussi « Démarrage automatique » et mettez « Aucune restriction » pour la batterie.
5. Laissez le téléphone **branché** et **connecté à Internet**.

Seuls les SMS des expéditeurs opérateurs (Orange Money, Moov…) sont envoyés au serveur. Les SMS personnels restent sur le téléphone.

## 3. Tester

- **Sans téléphone** : Admin → Paiements → créer un lien, puis Admin → **Simuler** → cliquer sur le paiement → Envoyer.
- **En réel** : créez un paiement de 100 F avec un autre numéro, payez-le, et regardez-le passer en « Payé ».
- **Important** : collez un **vrai SMS** de confirmation dans Admin → Réglages → *Règles de lecture des SMS* → **Tester**.
  Si le montant, le numéro ou l'ID de transaction ne sont pas reconnus, ajustez les règles (ou envoyez-moi un exemple de SMS).

## Sécurité anti-fraude intégrée

- **Expéditeur** : seuls les SMS de l'opérateur sont pris en compte.
- **ID de transaction unique** : un même ID n'est accepté qu'une seule fois (anti-rejeu).
- **Contrôle du solde** : le nouveau solde annoncé par le SMS doit valoir l'ancien solde plus le montant. Sinon le SMS est mis « À vérifier » et le paiement n'est pas validé automatiquement. Cela bloque les faux SMS envoyés par des fraudeurs.
- **Numéro du payeur** : le paiement est rapproché par montant **et** numéro du payeur.
- **Webhooks** signés en HMAC-SHA256, avec renvois automatiques.

## Pour les intégrateurs

Documentation complète en ligne sur `https://votre-domaine/docs` (création de paiement, webhooks, vérification de signature, exemples PHP / Node / Python).
