<?php
/**
 * Séjours La Croze — application de réservation (PHP 8 + SQLite, sans dépendance).
 * bootstrap.php : configuration, base de données, session, authentification, helpers.
 *
 * Remplace l'ancien site WordPress. Aucune bibliothèque externe, aucun build :
 * il suffit d'uploader ce dossier sur un hébergement mutualisé PHP.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Emplacement de la base SQLite. Idéalement HORS de la racine web (voir README).
// Par défaut : sous-dossier data/ protégé par un .htaccess.
if (!defined('LC_DATA_DIR')) {
    define('LC_DATA_DIR', __DIR__ . '/data');
}
define('LC_DB_PATH', LC_DATA_DIR . '/lacroze.sqlite');

// Liste des chambres / couchages (reprise à l'identique de l'ancien site).
// Modifiable depuis la page d'administration (stockée en base une fois configurée).
const LC_ROOMS_DEFAULT = [
    'Papé',
    'Ouma',
    'Appenti',
    'Plan',
    'Aire à Blé',
    "Chambre d'amis",
    'Pigeons',
    'Salon',
    'Maison de François - Couple',
    'Maison de François - Terrasse',
    'Maison des garçons - Rdc',
    'Maison des garçons - 1er',
    'Maison des filles - Bas',
    'Maison des filles - Haut',
    'Dortoir',
];

// Moments de la journée pour arrivée / départ.
const LC_MOMENTS = [
    'matin'      => 'Matin',
    'apres-midi' => 'Après-midi',
    'soir'       => 'Soir',
];

// Repas suivis (clé interne => libellé affiché).
const LC_MEALS = [
    'midi' => 'Déjeuner',
    'soir' => 'Dîner',
];

// Catégories d'âge (pour le récapitulatif des repas, comme sur l'ancien site).
// La clé '' (vide) correspond à « Non renseigné ».
const LC_CATEGORIES = [
    'bebe'   => 'Bébés',
    'enfant' => 'Enfants',
    'ado'    => 'Ados',
    'adulte' => 'Adultes',
    ''       => 'Non renseigné',
];

const LC_MAX_PERSONNES = 10;

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('LACROZE');
    session_start();
}

// ---------------------------------------------------------------------------
// Base de données
// ---------------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(LC_DATA_DIR)) {
        @mkdir(LC_DATA_DIR, 0770, true);
    }
    $pdo = new PDO('sqlite:' . LC_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        );
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS reservations (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            room           TEXT    NOT NULL,
            date_arrivee   TEXT    NOT NULL,   -- YYYY-MM-DD
            moment_arrivee TEXT    NOT NULL,   -- matin|apres-midi|soir
            date_depart    TEXT    NOT NULL,
            moment_depart  TEXT    NOT NULL,
            email          TEXT    NOT NULL DEFAULT "",
            invite_par     TEXT    NOT NULL DEFAULT "",  -- rempli côté invité
            source         TEXT    NOT NULL DEFAULT "actionnaire", -- actionnaire|invite
            edit_token     TEXT    NOT NULL,
            created_at     TEXT    NOT NULL,
            updated_at     TEXT    NOT NULL
        );
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS participants (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_id INTEGER NOT NULL,
            position       INTEGER NOT NULL,
            nom            TEXT    NOT NULL,
            categorie      TEXT    NOT NULL DEFAULT "",  -- bebe|enfant|ado|adulte|""
            FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
        );
    ');

    // Migration douce : ajoute la colonne catégorie si une ancienne base ne l'a pas.
    $cols = $pdo->query('PRAGMA table_info(participants)')->fetchAll();
    $hasCat = false;
    foreach ($cols as $c) { if ($c['name'] === 'categorie') { $hasCat = true; break; } }
    if (!$hasCat) {
        $pdo->exec('ALTER TABLE participants ADD COLUMN categorie TEXT NOT NULL DEFAULT ""');
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS repas (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_id INTEGER NOT NULL,
            jour           TEXT    NOT NULL,   -- YYYY-MM-DD
            repas          TEXT    NOT NULL,   -- midi|soir
            present        INTEGER NOT NULL DEFAULT 1,
            UNIQUE (reservation_id, jour, repas),
            FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE
        );
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS personnes (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            type      TEXT NOT NULL DEFAULT "actionnaire", -- actionnaire|invite
            nom       TEXT NOT NULL DEFAULT "",
            prenom    TEXT NOT NULL DEFAULT "",
            naissance TEXT DEFAULT NULL,                   -- YYYY-MM-DD ou NULL
            email     TEXT NOT NULL DEFAULT ""
        );
    ');

    // Migrations douces : colonnes ajoutées au fil des versions.
    $pcols = array_column($pdo->query('PRAGMA table_info(personnes)')->fetchAll(), 'name');
    if (!in_array('email', $pcols, true)) {
        $pdo->exec('ALTER TABLE personnes ADD COLUMN email TEXT NOT NULL DEFAULT ""');
    }
    if (!in_array('parts', $pcols, true)) {
        // parts : nombre de parts d'un actionnaire (0 = sans part propre).
        $pdo->exec('ALTER TABLE personnes ADD COLUMN parts REAL NOT NULL DEFAULT 0');
    }
    if (!in_array('parent_id', $pcols, true)) {
        // filiation : identifiant du parent actionnaire (rattachement aux parts).
        $pdo->exec('ALTER TABLE personnes ADD COLUMN parent_id INTEGER DEFAULT NULL');
    }
    if (!in_array('marie', $pcols, true)) {
        // marié·e : les enfants mariés suivent la même règle que les 25 ans et plus.
        $pdo->exec('ALTER TABLE personnes ADD COLUMN marie INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('conjoint_id', $pcols, true)) {
        // conjoint·e : lien symétrique entre deux fiches de l'annuaire.
        $pdo->exec('ALTER TABLE personnes ADD COLUMN conjoint_id INTEGER DEFAULT NULL');
    }
    $ptcols = array_column($pdo->query('PRAGMA table_info(participants)')->fetchAll(), 'name');
    if (!in_array('personne_id', $ptcols, true)) {
        // lien participant -> fiche annuaire (permet quotas de parts, filiation…).
        $pdo->exec('ALTER TABLE participants ADD COLUMN personne_id INTEGER DEFAULT NULL');
    }
    $rcols = array_column($pdo->query('PRAGMA table_info(reservations)')->fetchAll(), 'name');
    if (!in_array('mdm', $rcols, true)) {
        // Maître / maîtresse de maison : période de service + indicateur.
        $pdo->exec('ALTER TABLE reservations ADD COLUMN mdm INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE reservations ADD COLUMN mdm_debut TEXT DEFAULT NULL');
        $pdo->exec('ALTER TABLE reservations ADD COLUMN mdm_fin TEXT DEFAULT NULL');
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resa_dates ON reservations(date_arrivee, date_depart);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_repas_jour ON repas(jour, repas);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_personnes_type ON personnes(type, nom, prenom);');
}

// ---------------------------------------------------------------------------
// Réglages (table settings)
// ---------------------------------------------------------------------------

function setting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string) $val;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('
        INSERT INTO settings (key, value) VALUES (:k, :v)
        ON CONFLICT(key) DO UPDATE SET value = :v
    ');
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function is_configured(): bool
{
    return setting('configured') === '1';
}

/**
 * Liste des chambres avec leur capacité et leur type.
 * type = 'exclusive' (une seule réservation à la fois — chambres de couple, etc.)
 *      | 'partagee'  (plusieurs réservations simultanées possibles, dans la limite des couchages — dortoirs)
 * @return array<int,array{nom:string, couchages:?int, type:string}>
 */
function rooms_full(): array
{
    $json = setting('rooms');
    $arr = $json ? json_decode($json, true) : null;
    if (!is_array($arr) || !$arr) {
        $arr = LC_ROOMS_DEFAULT;
    }
    $out = [];
    foreach ($arr as $item) {
        if (is_array($item)) {
            $nom = trim((string) ($item['nom'] ?? ''));
            $cap = $item['couchages'] ?? null;
            $cap = ($cap === null || $cap === '') ? null : (int) $cap;
            $type = ($item['type'] ?? '') === 'partagee' ? 'partagee' : 'exclusive';
            $coll = !empty($item['collective']);
        } else {
            // Ancien format : simple chaîne (rétrocompatibilité).
            $nom = trim((string) $item);
            $cap = null;
            $type = 'exclusive';
            $coll = false;
        }
        if ($nom !== '') { $out[] = ['nom' => $nom, 'couchages' => $cap, 'type' => $type, 'collective' => $coll]; }
    }
    return $out;
}

/** Noms des chambres collectives (dortoirs, maisons des enfants…). @return string[] */
function rooms_collectives(): array
{
    return array_map(fn($r) => $r['nom'], array_filter(rooms_full(), fn($r) => $r['collective']));
}

function room_est_collective(string $nom): bool
{
    return in_array($nom, rooms_collectives(), true);
}

/** Type d'une chambre ('exclusive' par défaut). */
function room_type(string $nom): string
{
    foreach (rooms_full() as $r) {
        if ($r['nom'] === $nom) { return $r['type']; }
    }
    return 'exclusive';
}

/** Liste des noms de chambres uniquement. @return string[] */
function rooms(): array
{
    return array_map(fn($r) => $r['nom'], rooms_full());
}

/** Capacité (couchages) d'une chambre, ou null si non renseignée. */
function room_capacity(string $nom): ?int
{
    foreach (rooms_full() as $r) {
        if ($r['nom'] === $nom) { return $r['couchages']; }
    }
    return null;
}

function site_title(): string
{
    return setting('site_title', 'Séjours La Croze');
}

// ---------------------------------------------------------------------------
// Authentification (3 rôles : actionnaire, invite, admin)
// ---------------------------------------------------------------------------

/**
 * Vérifie un mot de passe saisi et renvoie le rôle correspondant, ou null.
 * L'espace invité n'est ouvert qu'à partir de la date paramétrée en admin.
 */
function authenticate(string $password): array
{
    // On teste du plus privilégié au moins privilégié.
    foreach (['admin', 'actionnaire', 'invite'] as $role) {
        $hash = setting('pwd_' . $role, '');
        if ($hash && password_verify($password, $hash)) {
            if ($role === 'invite') {
                $ouverture = setting('date_ouverture_invites', '');
                if ($ouverture !== '' && today() < $ouverture) {
                    return ['role' => null, 'error' => "L'espace invité ouvrira le " . fr_date($ouverture) . '.'];
                }
            }
            return ['role' => $role, 'error' => null];
        }
    }
    return ['role' => null, 'error' => 'Mot de passe incorrect.'];
}

function login_as(string $role): void
{
    session_regenerate_id(true);
    $_SESSION['role'] = $role;
    $_SESSION['login_at'] = time();
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function is_logged_in(): bool
{
    return current_role() !== null;
}

function is_admin(): bool
{
    return current_role() === 'admin';
}

/** Rôles autorisés à créer/gérer des réservations et voir le planning. */
function can_manage(): bool
{
    return in_array(current_role(), ['admin', 'actionnaire', 'invite'], true);
}

/** Redirige vers la connexion si l'utilisateur n'a pas un rôle autorisé. */
function require_login(array $roles = ['admin', 'actionnaire', 'invite']): void
{
    if (!is_configured()) {
        redirect('setup.php');
    }
    if (!in_array(current_role(), $roles, true)) {
        $_SESSION['flash'] = "Veuillez vous connecter pour accéder à cette page.";
        redirect('login.php');
    }
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    $ok = isset($_POST['_csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string) $_POST['_csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Requête invalide (CSRF).');
    }
}

// ---------------------------------------------------------------------------
// Helpers généraux
// ---------------------------------------------------------------------------

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function today(): string
{
    return date('Y-m-d');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function flash_get(): ?string
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function flash_set(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

/** Date lisible en français : 2026-08-06 -> 6 août 2026. */
function fr_date(?string $ymd): string
{
    if (!$ymd) {
        return '';
    }
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int) date('j', $ts) . ' ' . $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Jour court : lun. 6, mar. 7 ... */
function fr_jour_court(string $ymd): string
{
    $ts = strtotime($ymd);
    $jours = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
    return $jours[(int) date('w', $ts)] . '. ' . (int) date('j', $ts);
}

function moment_label(string $key): string
{
    return LC_MOMENTS[$key] ?? $key;
}

/**
 * Liste les jours (YYYY-MM-DD) entre deux dates incluses.
 * @return string[]
 */
function jours_entre(string $debut, string $fin): array
{
    $out = [];
    $d = new DateTimeImmutable($debut);
    $f = new DateTimeImmutable($fin);
    if ($f < $d) {
        return [];
    }
    for ($cur = $d; $cur <= $f; $cur = $cur->modify('+1 day')) {
        $out[] = $cur->format('Y-m-d');
    }
    return $out;
}

/**
 * Détermine, pour un jour donné d'un séjour, quels repas sont présents PAR DÉFAUT.
 * L'utilisateur pourra ensuite décocher sur la page Repas.
 * @return array{midi:bool, soir:bool}
 */
function repas_par_defaut(string $jour, string $arr, string $momArr, string $dep, string $momDep): array
{
    $isArr = ($jour === $arr);
    $isDep = ($jour === $dep);

    // Midi : présent sauf si on arrive après le matin (ce jour-là) ou si on part le matin.
    $midiDebutOk = $isArr ? ($momArr === 'matin') : true;
    $midiFinOk   = $isDep ? ($momDep !== 'matin') : true;

    // Soir : présent quel que soit le moment d'arrivée ; à l'arrivée. Au départ, il faut partir le soir.
    $soirDebutOk = true;
    $soirFinOk   = $isDep ? ($momDep === 'soir') : true;

    return [
        'midi' => $midiDebutOk && $midiFinOk,
        'soir' => $soirDebutOk && $soirFinOk,
    ];
}

/**
 * (Re)génère les lignes de repas par défaut pour une réservation.
 * Utilisé à la création et lorsque les dates changent.
 */
function generer_repas(int $reservationId, array $resa): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM repas WHERE reservation_id = ?')->execute([$reservationId]);
    $ins = $pdo->prepare('
        INSERT INTO repas (reservation_id, jour, repas, present)
        VALUES (?, ?, ?, ?)
    ');
    foreach (jours_entre($resa['date_arrivee'], $resa['date_depart']) as $jour) {
        $def = repas_par_defaut(
            $jour,
            $resa['date_arrivee'], $resa['moment_arrivee'],
            $resa['date_depart'], $resa['moment_depart']
        );
        foreach (LC_MEALS as $meal => $_label) {
            $ins->execute([$reservationId, $jour, $meal, $def[$meal] ? 1 : 0]);
        }
    }
}

/** Charge une réservation + ses participants par id. */
function get_reservation(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    $r['participants'] = get_participants($id);
    return $r;
}

/** Charge une réservation par son token d'édition. */
function get_reservation_by_token(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM reservations WHERE edit_token = ?');
    $stmt->execute([$token]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }
    $r['participants'] = get_participants((int) $r['id']);
    return $r;
}

function get_participants(int $reservationId): array
{
    $stmt = db()->prepare('SELECT position, nom, categorie, personne_id FROM participants WHERE reservation_id = ? ORDER BY position');
    $stmt->execute([$reservationId]);
    return $stmt->fetchAll();
}

/** Libellé d'une catégorie d'âge. */
function categorie_label(string $key): string
{
    return LC_CATEGORIES[$key] ?? LC_CATEGORIES[''];
}

/** Âge en années à une date de référence (ou aujourd'hui). */
function age_a(?string $naissance, ?string $ref = null): ?int
{
    if (!$naissance || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $naissance)) { return null; }
    $ref = $ref ?: today();
    $n = new DateTimeImmutable($naissance);
    $r = new DateTimeImmutable($ref);
    if ($r < $n) { return 0; }
    return (int) $n->diff($r)->y;
}

/**
 * Seuils d'âge (modifiables en administration).
 * bebe = âge strictement inférieur à `bebe` ; enfant < `enfant` ; ado < `ado` ; adulte au-delà.
 * @return array{bebe:int, enfant:int, ado:int}
 */
function seuils_ages(): array
{
    $def = ['bebe' => 3, 'enfant' => 12, 'ado' => 18];
    $json = setting('age_seuils');
    if ($json) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            foreach ($def as $k => $v) {
                if (isset($arr[$k]) && (int) $arr[$k] > 0) { $def[$k] = (int) $arr[$k]; }
            }
        }
    }
    return $def;
}

/** Catégorie d'âge (bebe/enfant/ado/adulte) déduite d'un âge, ou '' si inconnu. */
function categorie_pour_age(?int $age): string
{
    if ($age === null) { return ''; }
    $s = seuils_ages();
    if ($age < $s['bebe'])   { return 'bebe'; }
    if ($age < $s['enfant']) { return 'enfant'; }
    if ($age < $s['ado'])    { return 'ado'; }
    return 'adulte';
}

/** Catégorie déduite d'une date de naissance à une date de référence. */
function categorie_naissance(?string $naissance, ?string $ref = null): string
{
    return categorie_pour_age(age_a($naissance, $ref));
}

// --- Annuaire des personnes -------------------------------------------------

/** Liste les personnes d'un type, triées. @return array<int,array> */
function personnes_list(string $type): array
{
    $stmt = db()->prepare('SELECT * FROM personnes WHERE type = ? ORDER BY nom, prenom');
    $stmt->execute([$type]);
    return $stmt->fetchAll();
}

function personne_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM personnes WHERE id = ?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    return $p ?: null;
}

/** Identifiants de tous les descendants d'une personne (enfants, petits-enfants…). */
function descendants_ids(int $id): array
{
    $out = [];
    $frontiere = [$id];
    $stmt = db()->prepare('SELECT id FROM personnes WHERE parent_id = ?');
    while ($frontiere) {
        $cur = array_pop($frontiere);
        $stmt->execute([$cur]);
        foreach ($stmt->fetchAll() as $row) {
            $cid = (int) $row['id'];
            if (!in_array($cid, $out, true)) { $out[] = $cid; $frontiere[] = $cid; }
        }
    }
    return $out;
}

function personne_save(?int $id, string $type, string $nom, string $prenom, ?string $naissance, string $email = '', array $extra = []): int
{
    $naissance = ($naissance && preg_match('/^\d{4}-\d{2}-\d{2}$/', $naissance)) ? $naissance : null;
    $parts    = isset($extra['parts']) ? max(0, (float) $extra['parts']) : 0.0;
    $parent   = !empty($extra['parent_id']) ? (int) $extra['parent_id'] : null;
    $marie    = !empty($extra['marie']) ? 1 : 0;
    $conjoint = !empty($extra['conjoint_id']) ? (int) $extra['conjoint_id'] : null;
    if ($parent === $id) { $parent = null; }     // pas de filiation sur soi-même
    if ($conjoint === $id) { $conjoint = null; } // ni de mariage avec soi-même
    // Anti-cycle : le parent ne peut pas être un descendant de la personne
    // (ex. impossible de déclarer son propre fils comme parent).
    if ($id && $parent && in_array($parent, descendants_ids($id), true)) { $parent = null; }

    if ($id) {
        $ancien = personne_get($id);
        $stmt = db()->prepare('UPDATE personnes SET type=?, nom=?, prenom=?, naissance=?, email=?, parts=?, parent_id=?, marie=?, conjoint_id=? WHERE id=?');
        $stmt->execute([$type, $nom, $prenom, $naissance, $email, $parts, $parent, $marie, $conjoint, $id]);
    } else {
        $ancien = null;
        $stmt = db()->prepare('INSERT INTO personnes (type, nom, prenom, naissance, email, parts, parent_id, marie, conjoint_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$type, $nom, $prenom, $naissance, $email, $parts, $parent, $marie, $conjoint]);
        $id = (int) db()->lastInsertId();
    }

    // Lien conjoint symétrique : l'autre fiche pointe vers celle-ci ; l'ancien conjoint est délié.
    $ancienConjoint = $ancien ? ((int) ($ancien['conjoint_id'] ?? 0) ?: null) : null;
    if ($ancienConjoint && $ancienConjoint !== $conjoint) {
        db()->prepare('UPDATE personnes SET conjoint_id = NULL WHERE id = ? AND conjoint_id = ?')
            ->execute([$ancienConjoint, $id]);
    }
    if ($conjoint) {
        db()->prepare('UPDATE personnes SET conjoint_id = ? WHERE id = ?')->execute([$id, $conjoint]);
    }
    return $id;
}

/**
 * Actionnaires « titulaires » (détenteurs de parts — pas leurs enfants).
 * Si aucune part n'est encore saisie, on retient les fiches sans rattachement parent.
 */
function actionnaires_titulaires(): array
{
    $tous = personnes_list('actionnaire');
    $avecParts = array_values(array_filter($tous, fn($p) => (float) $p['parts'] > 0));
    if ($avecParts) { return $avecParts; }
    return array_values(array_filter($tous, fn($p) => empty($p['parent_id'])));
}

// --- Disponibilité des chambres (règle STRICTE) -----------------------------

/**
 * Réservations chevauchant la période [arr, dep) pour une chambre.
 * Le jour de rotation (départ le matin, arrivée l'après-midi le même jour) est autorisé.
 */
function resas_chevauchantes(string $room, string $arr, string $dep, ?int $excludeId = null): array
{
    $sql = 'SELECT * FROM reservations WHERE room = :room AND date_arrivee < :dep AND date_depart > :arr';
    $params = [':room' => $room, ':dep' => $dep, ':arr' => $arr];
    if ($excludeId) { $sql .= ' AND id != :ex'; $params[':ex'] = $excludeId; }
    $stmt = db()->prepare($sql . ' ORDER BY date_arrivee');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Vérifie qu'une réservation est possible. Renvoie null si OK, sinon le message d'erreur.
 *  - Chambre EXCLUSIVE : refus dès qu'une autre réservation chevauche.
 *  - Chambre PARTAGÉE  : autorisé tant que, chaque nuit, le total de personnes ≤ couchages.
 */
function verifier_disponibilite(string $room, string $arr, string $dep, int $nb, ?int $excludeId = null): ?string
{
    $overlaps = resas_chevauchantes($room, $arr, $dep, $excludeId);
    if (!$overlaps) { return null; }

    if (room_type($room) !== 'partagee') {
        $o = $overlaps[0];
        $noms = noms_participants(get_participants((int) $o['id']));
        return 'La chambre « ' . $room . ' » est déjà réservée du ' . fr_date($o['date_arrivee'])
             . ' au ' . fr_date($o['date_depart']) . ($noms ? ' (' . $noms . ')' : '')
             . '. Cette chambre ne peut accueillir qu’une seule réservation à la fois : '
             . 'choisissez d’autres dates ou une autre chambre.';
    }

    // Partagée : contrôle nuit par nuit contre la capacité.
    $cap = room_capacity($room);
    if ($cap === null) { return null; } // pas de capacité définie → pas de limite

    $nbParResa = [];
    foreach ($overlaps as $o) { $nbParResa[(int) $o['id']] = nb_participants((int) $o['id']); }

    $nuits = ($dep > $arr) ? jours_entre($arr, (new DateTimeImmutable($dep))->modify('-1 day')->format('Y-m-d')) : [$arr];
    foreach ($nuits as $nuit) {
        $total = $nb;
        foreach ($overlaps as $o) {
            if ($o['date_arrivee'] <= $nuit && $nuit < $o['date_depart']) {
                $total += $nbParResa[(int) $o['id']];
            }
        }
        if ($total > $cap) {
            return 'La chambre partagée « ' . $room . ' » serait sur-occupée la nuit du ' . fr_date($nuit)
                 . ' : ' . $total . ' personnes pour ' . $cap . ' couchages. '
                 . 'Réduisez le nombre de personnes, changez de dates ou choisissez une autre chambre.';
        }
    }
    return null;
}

function personne_delete(int $id): void
{
    db()->prepare('DELETE FROM personnes WHERE id = ?')->execute([$id]);
    // On détache les éventuels enfants rattachés.
    db()->prepare('UPDATE personnes SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
}

/** Retrouve une fiche annuaire par nom complet (insensible accents/casse, 'Prénom Nom' ou 'Nom Prénom'). */
function personne_match(string $nomComplet): ?array
{
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (db()->query('SELECT * FROM personnes') as $p) {
            $a = norm_nom($p['prenom'] . ' ' . $p['nom']);
            $b = norm_nom($p['nom'] . ' ' . $p['prenom']);
            $index[$a] = $p;
            $index[$b] = $p;
        }
    }
    return $index[norm_nom($nomComplet)] ?? null;
}

/** Normalisation de nom : minuscules, sans accents, tirets/apostrophes = espaces. */
function norm_nom(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) { $s = $t; }
    $s = str_replace(['-', "'", '’'], ' ', $s);
    return preg_replace('/\s+/', ' ', $s) ?? $s;
}

// --- Parts, filiation & quotas ---------------------------------------------

/** Âge à partir duquel un enfant relève des parts surnuméraires (défaut 25 ans). */
function age_parts(): int
{
    return max(1, (int) (setting('age_parts', '25')));
}

/** Jours offerts par part surnuméraire et par an (défaut 11). */
function jours_par_part(): int
{
    return max(1, (int) (setting('jours_par_part', '11')));
}

/**
 * Si la personne est un « bénéficiaire de part surnuméraire » à la date donnée,
 * renvoie [parent, celibataire] ; sinon null.
 * Condition : rattachée à un parent ayant ≥ 2 parts, ET (âge ≥ seuil OU marié·e).
 */
function benef_part(array $personne, string $dateRef): ?array
{
    if (empty($personne['parent_id'])) { return null; }
    $parent = personne_get((int) $personne['parent_id']);
    if (!$parent || (float) $parent['parts'] < 2) { return null; }
    $age = age_a($personne['naissance'] ?? null, $dateRef);
    // Marié·e = drapeau explicite OU conjoint·e renseigné·e dans l'annuaire.
    $marie = !empty($personne['marie']) || !empty($personne['conjoint_id']);
    if (($age !== null && $age >= age_parts()) || $marie) {
        return ['parent' => $parent, 'celibataire' => !$marie];
    }
    return null;
}

/** Nombre de jours du séjour comptés au quota (au moins 1). */
function jours_sejour(string $arr, string $dep): int
{
    $d = (new DateTimeImmutable($arr))->diff(new DateTimeImmutable($dep))->days;
    return max(1, (int) $d);
}

/** Quota annuel total d'un parent : (parts - 1) × jours_par_part. */
function quota_total(array $parent): int
{
    return (int) floor(max(0, (float) $parent['parts'] - 1) * jours_par_part());
}

/**
 * Jours déjà consommés sur le quota d'un parent pour une année
 * (somme, sur les réservations de l'année, des jours × enfants bénéficiaires liés).
 */
function quota_utilise(int $parentId, string $annee, ?int $excludeResa = null): int
{
    $sql = 'SELECT r.id, r.date_arrivee, r.date_depart, p.personne_id
            FROM reservations r JOIN participants p ON p.reservation_id = r.id
            WHERE substr(r.date_arrivee, 1, 4) = :an AND p.personne_id IS NOT NULL';
    $params = [':an' => $annee];
    if ($excludeResa) { $sql .= ' AND r.id != :ex'; $params[':ex'] = $excludeResa; }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $total = 0;
    foreach ($stmt->fetchAll() as $row) {
        $pers = personne_get((int) $row['personne_id']);
        if (!$pers) { continue; }
        $b = benef_part($pers, $row['date_arrivee']);
        if ($b && (int) $b['parent']['id'] === $parentId) {
            $total += jours_sejour($row['date_arrivee'], $row['date_depart']);
        }
    }
    return $total;
}

// --- Fermeture des inscriptions & maître de maison --------------------------

/** Les inscriptions/modifications sont-elles fermées (associés ET invités) ? L'admin n'est jamais bloqué. */
function inscriptions_fermees(): bool
{
    if (is_admin()) { return false; }
    $f = setting('date_fermeture_resa', '');
    return $f !== '' && today() > $f;
}

/**
 * Vérifie une période de service « maître de maison ».
 * Interdit tout chevauchement de PLUS de 2 jours avec un autre maître de maison
 * (2 jours de tuilage pour la passation sont autorisés).
 */
function verifier_mdm(string $debut, string $fin, ?int $excludeId = null): ?string
{
    $sql = 'SELECT * FROM reservations WHERE mdm = 1 AND mdm_debut IS NOT NULL AND mdm_fin IS NOT NULL';
    $params = [];
    if ($excludeId) { $sql .= ' AND id != ?'; $params[] = $excludeId; }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $o) {
        $chevDebut = max($debut, $o['mdm_debut']);
        $chevFin   = min($fin, $o['mdm_fin']);
        if ($chevDebut <= $chevFin) {
            $jours = (new DateTimeImmutable($chevDebut))->diff(new DateTimeImmutable($chevFin))->days + 1;
            if ($jours > 2) {
                $noms = noms_participants(get_participants((int) $o['id']));
                return 'Période de maître de maison en conflit : ' . ($noms ?: 'un autre séjour')
                     . ' assure déjà le service du ' . fr_date($o['mdm_debut']) . ' au ' . fr_date($o['mdm_fin'])
                     . ' (chevauchement de ' . $jours . ' jours, maximum 2 jours de passation autorisés).';
            }
        }
    }
    return null;
}

/**
 * Prévient par e-mail les maîtres de maison dont la période de service chevauche
 * un séjour créé / modifié / supprimé (pour ajuster courses et ravitaillement).
 * Envoi silencieux : l'échec d'un mail ne bloque jamais l'action.
 */
function notifier_maitres_de_maison(array $resa, string $action, array $participants): void
{
    $stmt = db()->prepare('
        SELECT * FROM reservations
        WHERE mdm = 1 AND email != "" AND mdm_debut IS NOT NULL AND mdm_fin IS NOT NULL
          AND mdm_debut <= :fin AND mdm_fin >= :debut
    ');
    $stmt->execute([':debut' => $resa['date_arrivee'], ':fin' => $resa['date_depart']]);
    $cibles = $stmt->fetchAll();
    if (!$cibles) { return; }

    $noms = implode(', ', array_map(fn($p) => is_array($p) ? ($p['nom'] ?? '') : $p, $participants));
    $sujet = '[' . site_title() . '] Séjour ' . $action . ' — ' . $resa['room'];
    $corps = "Bonjour,\n\n"
        . "Un séjour chevauchant votre période de maître de maison vient d'être $action :\n\n"
        . '  Chambre   : ' . $resa['room'] . "\n"
        . '  Personnes : ' . ($noms ?: '—') . ' (' . count($participants) . ")\n"
        . '  Arrivée   : ' . fr_date($resa['date_arrivee']) . ' (' . moment_label($resa['moment_arrivee']) . ")\n"
        . '  Départ    : ' . fr_date($resa['date_depart']) . ' (' . moment_label($resa['moment_depart']) . ")\n\n"
        . 'Pensez à ajuster vos listes de courses et le ravitaillement.'
        . "\nRécapitulatif des repas : " . base_url() . "/repas.php\n\n"
        . site_title();
    $entetes = 'From: ' . site_title() . ' <no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'lacroze.local') . '>' . "\r\n"
             . 'Content-Type: text/plain; charset=UTF-8';
    foreach ($cibles as $c) {
        if ((int) $c['id'] === (int) ($resa['id'] ?? 0)) { continue; } // pas d'auto-notification
        @mail($c['email'], $sujet, $corps, $entetes);
    }
}

/**
 * Analyse les participants d'une réservation au regard des parts.
 * @param array $participants [pos => ['nom'=>, 'categorie'=>, 'personne_id'=>?int]]
 * @return array{erreurs: string[], infos: string[]}
 */
function analyse_participants(array $participants, string $room, string $arr, string $dep, ?int $excludeId = null): array
{
    $erreurs = []; $infos = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dep) || $dep < $arr) {
        return ['erreurs' => [], 'infos' => []];
    }
    $jours = jours_sejour($arr, $dep);
    $annee = substr($arr, 0, 4);
    $parParent = []; // parentId => ['parent'=>, 'noms'=>[], 'celibataires'=>[]]

    foreach ($participants as $p) {
        if (empty($p['personne_id'])) { continue; }
        $pers = personne_get((int) $p['personne_id']);
        if (!$pers) { continue; }
        $b = benef_part($pers, $arr);
        if (!$b) { continue; }
        $pid = (int) $b['parent']['id'];
        $parParent[$pid] ??= ['parent' => $b['parent'], 'noms' => [], 'celibataires' => []];
        $parParent[$pid]['noms'][] = $p['nom'];
        if ($b['celibataire']) { $parParent[$pid]['celibataires'][] = $p['nom']; }
    }

    $collectives = rooms_collectives();
    foreach ($parParent as $pid => $g) {
        // Restriction : les bénéficiaires célibataires ne peuvent réserver qu'en chambre collective.
        if ($g['celibataires'] && !room_est_collective($room)) {
            $liste = $collectives ? implode(', ', $collectives) : '(aucune chambre collective définie)';
            $erreurs[] = implode(', ', $g['celibataires'])
                . ' bénéficie(nt) d’une part surnuméraire en tant que célibataire(s) de '
                . age_parts() . ' ans ou plus : accès limité aux chambres collectives — ' . $liste . '.';
        }
        // Quota : information (le dépassement n'empêche pas de réserver, il devient payant).
        $total = quota_total($g['parent']);
        $deja  = quota_utilise($pid, $annee, $excludeId);
        $ce    = $jours * count($g['noms']);
        $reste = $total - $deja - $ce;
        $parentNom = trim($g['parent']['prenom'] . ' ' . $g['parent']['nom']);
        $msgBase = 'Part surnuméraire de ' . $parentNom . ' (' . rtrim(rtrim(number_format((float) $g['parent']['parts'], 1, ',', ''), '0'), ',') . ' parts, '
                 . $total . ' j/an) : ce séjour compte ' . $ce . ' j'
                 . ($deja ? ', ' . $deja . ' j déjà utilisés en ' . $annee : '');
        if ($reste >= 0) {
            $infos[] = $msgBase . ' — il restera ' . $reste . ' j.';
        } else {
            $infos[] = $msgBase . ' — dépassement de ' . abs($reste) . ' j : frais de nuitée applicables '
                     . 'pour ces jours (comme un invité non actionnaire).';
        }
    }
    return ['erreurs' => $erreurs, 'infos' => $infos];
}

/**
 * Enregistre les présences aux repas depuis les cases du formulaire.
 * $postPresent = $_POST['present'] (present[jour][repas] = 1). Si vide (JS désactivé),
 * on retombe sur les valeurs par défaut calculées depuis les moments.
 */
function enregistrer_repas(int $reservationId, array $resa, array $postPresent): void
{
    if (!$postPresent) {
        generer_repas($reservationId, $resa);
        return;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM repas WHERE reservation_id = ?')->execute([$reservationId]);
    $ins = $pdo->prepare('INSERT INTO repas (reservation_id, jour, repas, present) VALUES (?, ?, ?, ?)');
    foreach (jours_entre($resa['date_arrivee'], $resa['date_depart']) as $jour) {
        foreach (LC_MEALS as $meal => $_l) {
            $present = isset($postPresent[$jour][$meal]) ? 1 : 0;
            $ins->execute([$reservationId, $jour, $meal, $present]);
        }
    }
}

/** Bornes de la semaine (lundi→dimanche) contenant $date. @return array{0:string,1:string} */
function semaine_bornes(string $date): array
{
    $d = new DateTimeImmutable($date);
    $dow = (int) $d->format('N'); // 1 = lundi … 7 = dimanche
    $lundi = $d->modify('-' . ($dow - 1) . ' days');
    return [$lundi->format('Y-m-d'), $lundi->modify('+6 days')->format('Y-m-d')];
}

/** Bornes du mois contenant $date. @return array{0:string,1:string} */
function mois_bornes(string $date): array
{
    $d = new DateTimeImmutable($date);
    return [$d->format('Y-m-01'), $d->format('Y-m-t')];
}

function nb_participants(int $reservationId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM participants WHERE reservation_id = ?');
    $stmt->execute([$reservationId]);
    return (int) $stmt->fetchColumn();
}

/** Renvoie la liste des noms d'une réservation, jointe. */
function noms_participants(array $participants): string
{
    return implode(', ', array_map(fn($p) => $p['nom'], $participants));
}

/** URL absolue de base de l'application (pour construire les liens d'édition). */
function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}
