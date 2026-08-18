# Séjours La Croze — application de réservation

Réécriture **sans WordPress** du site `sejourslacroze.fr`, à héberger sur **aid-s.fr**.
Application **PHP 8 + SQLite**, sans dépendance, sans étape de build : on téléverse le
dossier et ça fonctionne.

---

## 1. Ce que fait l'application

Reprise fidèle du fonctionnement d'origine, avec des améliorations :

| Page | Rôle |
|------|------|
| **Connexion** (`login.php`) | 3 mots de passe : *actionnaires*, *invités*, *admin*. Saisie dans un formulaire, session serveur. **Plus de mot de passe dans l'URL.** |
| **Nouvelle inscription** (`inscription.php`) | 1 à 10 personnes (avec **catégorie d'âge** : Bébé / Enfant / Ado / Adulte), chambre, dates + moments (Matin / Après-midi / Soir), e-mail. Les invités renseignent « Invité par ». Une **grille de repas** (midi/soir) pré-cochée et **décochable** apparaît sous les dates. |
| **Modification** (`modification.php?token=…`) | Édition / suppression d'une réservation via un lien privé (token). La grille de repas est pré-remplie avec les choix enregistrés. |
| **Calendrier** (`calendrier.php`) | Planning mensuel **en barres (style Gantt)**, une ligne par chambre, avec **détection des chevauchements** (barres rouges) + liste des séjours à venir. Clic sur une barre = modifier. |
| **Repas** (`repas.php`) | Récapitulatif des présences **par catégorie d'âge** (Déjeuner / Dîner), consultable **par semaine, par mois ou sur une plage de dates libre**, avec **export Excel/CSV**. |
| **Impression / PDF** (`pdf.php`) | Vue imprimable → « Enregistrer au format PDF » du navigateur. |
| **Administration** (`admin.php`) | Mots de passe, **date d'ouverture de l'espace invité**, **chambres + nombre de couchages**, nom du site, accès à l'annuaire. |
| **Annuaire actionnaires** (`admin-personnes.php`) | Ajouter / modifier / supprimer les actionnaires et leurs **dates de naissance**. Import depuis l'ancien Excel. |

### Les 3 accès
- **Actionnaires** : accès complet.
- **Invités** : **même espace**, mais le mot de passe n'est accepté qu'**à partir d'une date** que vous fixez dans l'admin (avant, message « L'espace invité ouvrira le … »).
- **Admin** : réglages.

---

## 2. Améliorations par rapport à l'ancien site

- 🔐 **Sécurité** : fini le hash bcrypt exposé dans l'URL. Connexion par formulaire + session, mots de passe hachés (`password_hash`), protection CSRF sur tous les formulaires, requêtes préparées (anti-injection SQL), en-têtes de session `HttpOnly`/`SameSite`.
- 🗓️ **Calendrier** en barres (style Gantt), une ligne par chambre, avec **alerte visuelle en cas de chevauchement** et clic direct pour modifier.
- 🍽️ **Repas** : cases midi/soir pré-cochées selon les moments d'arrivée/départ, **directement dans le formulaire** ; et une consultation **par catégorie d'âge** avec vues **semaine / mois / plage libre** et **export Excel (CSV)**.
- 👶 **Catégories d'âge** par personne (Bébé / Enfant / Ado / Adulte) pour des comptages de repas utiles (comme l'ancien tableau).
- ⚙️ **Page d'administration** : plus besoin de toucher au code pour changer un mot de passe, la date d'ouverture invités ou la liste des chambres.
- 📄 **Zéro dépendance** : pas de WordPress, pas de Composer, pas de base MySQL à configurer. Sauvegarde = **un seul fichier** (`data/lacroze.sqlite`).
- 💻 **Test local en un double-clic** (`start-local.command`) + données de démonstration.

> ⚠️ Le mot de passe présent dans le lien que vous m'avez transmis a circulé en clair.
> **Changez-le** (nouvel `pwd_actionnaire`) dès l'installation.

---

## 3. Prérequis hébergement (mutualisé aid-s.fr)

- **PHP 8.0+** (testé PHP 8.5) avec l'extension **PDO SQLite** (activée par défaut chez OVH, IONOS, o2switch, Gandi, Hostinger…).
- Un serveur **Apache** (les fichiers `.htaccess` fournis protègent la base). Si votre hébergement est sous **Nginx**, voir §6.

Vérifier PHP : dans l'espace client de l'hébergeur, réglez la version PHP du domaine/sous-domaine sur 8.x.

---

## 4. Installation (5 minutes)

1. **Choisir l'adresse.** Deux options :
   - un **sous-domaine** dédié : `reservations.aid-s.fr` (recommandé) ;
   - ou un **sous-dossier** : `aid-s.fr/lacroze/`.

2. **Téléverser** tout le contenu du dossier `sejourslacroze/` dans le répertoire web
   correspondant (via FTP/SFTP – FileZilla – ou le gestionnaire de fichiers de l'hébergeur).
   Conservez la structure (`assets/`, `partials/`, `data/`).

3. **Droits d'écriture** : le dossier `data/` doit être accessible en écriture par PHP
   (permissions `755`, voire `775`). L'application y crée `lacroze.sqlite` toute seule.

4. **Ouvrir le site** dans le navigateur → vous arrivez automatiquement sur
   `setup.php`. Renseignez :
   - le nom du site,
   - les **3 mots de passe** (actionnaires / invités / admin, tous différents),
   - la **date d'ouverture de l'espace invité** (laisser vide = ouvert tout de suite).

5. **Verrouiller l'installation** : une fois `setup.php` validé, il se désactive tout seul.
   Par prudence, **supprimez ou renommez `setup.php`** via FTP.

6. C'est prêt : communiquez le mot de passe *actionnaires* au groupe, gardez l'*admin* pour vous.

---

## 5. Sauvegarde & maintenance

- **Sauvegarde** : copiez régulièrement le fichier `data/lacroze.sqlite` (c'est toute la base :
  réservations, repas, réglages). Un simple téléchargement FTP suffit.
- **Restauration** : reposez ce fichier au même endroit.
- **Réinitialiser** : supprimez `data/lacroze.sqlite*` → l'appli repropose l'installation.
- **Mise à jour du contenu** (chambres, mots de passe, date invités) : page **Administration**, sans toucher au code.

---

## 6. Cas particulier : hébergement Nginx

Les `.htaccess` ne sont pas lus par Nginx. Dans ce cas, la protection de la base doit se faire
dans la config du serveur. Le plus simple : **placer le dossier `data/` HORS de la racine web**.
Dans `bootstrap.php`, en haut, définissez le chemin absolu, par exemple :

```php
define('LC_DATA_DIR', '/home/VOTRE_COMPTE/data_lacroze');
```

(dossier situé au-dessus de `www/` ou `public_html/`, donc inaccessible par le web).
Ajoutez aussi, dans le bloc `server` Nginx :

```nginx
location ~* \.(sqlite|sqlite-wal|sqlite-shm)$ { deny all; }
```

Sur mutualisé Apache (le cas courant pour un `.fr`), **rien à faire** : les `.htaccess` fournis suffisent.

---

## 7. Tester en local (sur votre Mac)

**Le plus simple :** double-cliquez le fichier **`start-local.command`**. Il lance PHP et ouvre
`http://127.0.0.1:8000` dans votre navigateur. Fermez la fenêtre du Terminal pour arrêter.

> Si macOS refuse de l'ouvrir (« développeur non identifié »), faites **clic droit → Ouvrir** la
> première fois. PHP doit être installé (`brew install php` si besoin).

Deux façons de remplir l'écran :
- **Découvrir avec des données d'exemple** : une fois le serveur lancé, ouvrez
  `http://127.0.0.1:8000/demo-seed.php?confirm=1`. Cela crée des réservations de démonstration
  (dont un chevauchement pour voir les conflits) et les mots de passe **demo** (actionnaire) /
  **invite** (invité) / **admin** (admin).
- **Partir de zéro** : ouvrez `http://127.0.0.1:8000/` et suivez la page d'installation.

En ligne de commande, l'équivalent manuel :
```bash
cd sejourslacroze
php -S 127.0.0.1:8000        # puis ouvrir http://127.0.0.1:8000
php demo-seed.php            # (optionnel) charge les données de démonstration
```

> ⚠️ **`demo-seed.php` réinitialise la base.** Supprimez ce fichier **avant** la mise en ligne
> (comme `setup.php` une fois l'installation faite).

---

## 8. Structure des fichiers

```
sejourslacroze/
├── .htaccess              Sécurité Apache (bloque l'accès direct à la base)
├── bootstrap.php          Config, base SQLite, auth, helpers (cœur de l'appli)
├── setup.php              Installation initiale (à supprimer après usage)
├── login.php / logout.php Connexion / déconnexion
├── index.php              Menu d'accueil
├── inscription.php        Nouvelle réservation
├── modification.php       Édition / suppression (via token)
├── calendrier.php         Planning des chambres
├── repas.php              Présences aux repas
├── recapitulatif.php      Totaux par jour
├── pdf.php                Version imprimable
├── admin.php              Réglages (mots de passe, date invités, chambres+couchages)
├── admin-personnes.php    Annuaire des actionnaires (CRUD)
├── import-annuaire.php    Import de data/annuaire.json vers la table personnes
├── assets/style.css       Feuille de style unique
├── partials/              Gabarits communs (header, footer) + logique partagée
├── data/                  Base SQLite (créée automatiquement) — protégée
├── start-local.command    Lancement local en un double-clic (macOS) — inutile en ligne
└── demo-seed.php          Données de démonstration (à SUPPRIMER avant mise en ligne)
```

---

## 9. Récupérer les données de l'ancien site (migration)

Les réservations de `sejourslacroze.fr` vivent dans des tables personnalisées de la base
WordPress — elles ne sont pas exposées publiquement. Pour les reprendre :

1. Dans l'hébergement de l'ancien site (IONOS) → **phpMyAdmin** → exportez les tables de
   réservations (**CSV** ou **SQL**).
2. Transmettez-moi le fichier : j'écris un petit script d'import qui charge ces données dans
   `data/lacroze.sqlite` (mapping des chambres, dates, participants, catégories).

À défaut, l'export **Excel** de la page « Repas » et la page **PDF** donnent une reprise partielle
(comptages agrégés).

---

## 10. Annuaire des actionnaires & catégories d'âge

L'annuaire (nom, prénom, **date de naissance**) a été repris de votre fichier Excel
(`2019.08.19_La croze_Participation…xlsm`, onglet *Listes*) → **83 actionnaires**.

- **Import** : le fichier `data/annuaire.json` est chargé au premier `demo-seed.php`, ou à la demande
  via **Administration → Annuaire → « Réimporter depuis l'Excel »** (ou `php import-annuaire.php`).
- **Gestion** : page **Annuaire des actionnaires** (admin) — ajout, modification, suppression.
- **Saisie fluide** : à l'inscription, un actionnaire **choisit les personnes dans une liste** ;
  le nom se remplit et la **catégorie d'âge** (Bébé <3 / Enfant 3–11 / Ado 12–17 / Adulte 18+) est
  **calculée automatiquement** depuis la date de naissance (au jour d'arrivée). La saisie libre reste possible.
- **Invités** : saisie manuelle pour l'instant (l'annuaire invités est extrait mais non exposé — on verra plus tard).

> 🔒 `data/annuaire.json` contient des dates de naissance : il est dans `data/` (protégé par `.htaccess`),
> jamais servi par le web. Les seuils d'âge sont ajustables dans `bootstrap.php` (`categorie_pour_age`).

### Couchages & types de chambre — règle stricte anti-conflit
Dans **Administration → Chambres & couchages**, chaque chambre a un **nombre de couchages** et un **type** :

- **Exclusive** (chambres de couple…) : **une seule réservation à la fois**. Toute tentative de réserver
  sur des dates déjà prises est **refusée** (message clair, contrôle en direct dans le formulaire
  ET au moment de l'enregistrement côté serveur — impossible à contourner).
- **Partagée** (dortoirs, chambres d'enfants) : plusieurs réservations simultanées possibles, tant que
  le **total de personnes chaque nuit ne dépasse pas les couchages**. Au-delà : refus.

Le jour de « rotation » (départ le matin, arrivée le même jour) reste autorisé.

### Suggestions familiales & e-mail
- À l'inscription, dès qu'un membre d'une famille est choisi, les **autres membres du même nom —
  les enfants d'abord — apparaissent en boutons cliquables** : un clic les ajoute.
- Pour une seconde chambre (ex. enfants au dortoir), le bouton **« Autre chambre, mêmes dates »**
  après l'enregistrement pré-remplit les dates.
- L'annuaire enregistre : **nom, prénom, e-mail, date de naissance**. L'e-mail de la première personne
  choisie pré-remplit l'e-mail de la réservation.

### Seuils d'âge réglables
**Administration → Catégories d'âge** : les seuils Bébé / Enfant / Ado sont modifiables
(par défaut <3, <12, <18 ans ; adulte au-delà). Ils s'appliquent partout, y compris au calcul
automatique dans le formulaire.

### Réservations 2026 importées ✔
Les **exports hebdomadaires de l'ancien site** (`repas_YYYYMMDD_to_YYYYMMDD.xlsx`, du 29 juin au
6 septembre 2026) ont été convertis en réservations complètes :

- `extract_sejours.py` (dossier Résa/) reconstitue les séjours — dates et moments d'arrivée/départ
  déduits des repas, **repas exacts conservés** (y compris décochés), catégorie d'âge du statut,
  **actionnaire / invité** détecté via l'annuaire.
- `import-sejours.php` charge `data/sejours2026.json` dans la base (**remplace les réservations de
  l'année couverte**). Relançable : `php import-sejours.php` ou depuis le navigateur en admin.
- Résultat : **43 réservations, 72 participants** (28 actionnaires / 15 invités), 6 juillet → 21 août 2026.
  Totaux repas vérifiés à l'identique contre les fichiers source.

### Ouverture des réservations (admin)
**Administration → Ouverture des réservations** :
- **Année ouverte** : seule cette année est réservable (ex. 2026) ; toute date hors année est refusée
  avec un message clair. Vide = toutes années. L'administrateur n'est pas limité (corrections).
- **Non-actionnaires à partir du…** : avant cette date, le mot de passe « invités / non-actionnaires »
  est refusé à la connexion (message annonçant la date d'ouverture). Vide = ouvert.

### Parts, filiation & quotas 👨‍👩‍👧‍👦
Dans l'**annuaire** (admin), chaque personne peut avoir : un **nombre de parts** (actionnaire),
un rattachement **« Enfant de »** (filiation), et un statut **marié·e**.

Règle appliquée (seuils réglables dans *Administration → Catégories d'âge*) :
- Chaque part **au-delà de la première** offre **11 jours par an** (pool commun) aux **enfants de
  25 ans et +** de l'actionnaire — ou mariés avant 25 ans, même règle. 1 part = 0 jour.
- Au-delà du quota : le séjour reste possible mais **frais de nuitée** annoncés (comme un invité).
- Les bénéficiaires **célibataires** ne peuvent réserver que les **chambres collectives**
  (case « Collective » par chambre dans l'admin : Dortoir, Maisons des filles/garçons,
  Maison de François) — refus bloquant sinon.

Le formulaire d'inscription affiche tout **en direct** (quota restant, dépassement, restriction)
dès qu'une personne de l'annuaire est choisie, et un tableau **Quotas** dans l'admin annuaire
récapitule l'année : parts, jours offerts, utilisés, restants.

À configurer : saisissez les **parts** de chaque actionnaire et rattachez les **enfants** dans
l'annuaire (rien n'est pré-rempli — la filiation n'était pas dans l'Excel, qui ne contient que le
regroupement par branche familiale pour ses menus en cascade).

**Édition familiale (fiche personne)** :
- **Anti-cycle** : impossible de déclarer un descendant comme parent (le menu « Enfant de » exclut
  les descendants, et toute tentative directe est neutralisée côté serveur).
- **Conjoint·e** : lien réciproque automatique entre deux fiches (changer de conjoint délie
  l'ancien). Un conjoint renseigné vaut « marié·e » pour la règle des parts.
- **Enfants** : depuis la fiche d'un parent, section « Enfants de … » — rattacher via un menu,
  détacher d'un clic.
- **Saisie rapide des parts** : une grille unique liste tout l'annuaire avec un champ Parts par
  personne et un seul bouton d'enregistrement.

**« Invité par » (page invité)** : menu déroulant des **actionnaires titulaires** (détenteurs de
parts — pas leurs enfants ; tant qu'aucune part n'est saisie, la liste retombe sur les fiches sans
parent), avec « Autre… » pour un nom libre. Champ désormais **facultatif**.

### Retirer une personne d'une inscription
Chaque ligne du formulaire a un bouton **« ✕ Retirer »** : la personne est enlevée et les
suivantes remontent (plus besoin de tout re-saisir en cas d'erreur).

### Maître / maîtresse de maison 🏠
- À l'inscription, un **associé** peut cocher « Maître / maîtresse de maison » et saisir sa
  **période de service** (début / fin — pré-remplie avec les dates du séjour). Son **e-mail devient
  obligatoire**.
- Sur le **calendrier**, un **carton vert « MdM »** apparaît à droite du nom (barre du planning et
  liste des séjours) ; l'infobulle donne les dates du service.
- Deux services ne peuvent pas se **chevaucher de plus de 2 jours** (tuilage de passation autorisé) —
  refus bloquant sinon.
- **Notifications** : à chaque **création, modification ou annulation** d'un séjour chevauchant sa
  période, le maître de maison reçoit un **e-mail** (chambre, personnes, dates + lien vers la page
  Repas) pour adapter courses et ravitaillement. (Envoi via la fonction mail de PHP : opérationnel
  chez l'hébergeur ; en local, les mails ne partent généralement pas.)

### Fermeture des inscriptions 🔒
**Administration → Ouverture des réservations → « Fermeture des inscriptions après le… »** :
passée cette date, plus aucune inscription **ni modification ni suppression** (associés ET invités),
avec un message clair. L'administrateur reste libre. Vide = jamais fermé.

### Chambres : Maison des filles Bas / Haut
« Maison des filles » est désormais scindée : **Bas (5 couchages)** et **Haut (6 couchages)**, toutes
deux partagées et collectives. Les réservations 2026 existantes ont été rattachées au **Bas** —
déplaçables vers le Haut via « Modifier ». Le calendrier affiche aussi toute chambre présente dans
d'anciennes réservations même si elle a disparu des réglages (rien ne devient invisible).

### Visites guidées animées ❓
Chaque page principale embarque une **visite guidée animée** : un curseur se déplace sur la vraie
interface, montre les boutons, tape dans les champs, avec des bulles d'explication — pensé pour les
membres peu à l'aise avec l'informatique.

- Lancement **automatique à la première visite** de chaque page, puis rejouable à volonté via le
  bouton **« ❓ Visite guidée »** en haut à droite.
- Aucune vidéo à maintenir : la visite joue sur la page réelle, elle reste donc toujours à jour.
- Des blocs **« Comment ça marche ? »** dépliables complètent chaque page.
