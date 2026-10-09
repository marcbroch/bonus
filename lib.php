<?php
// Brochhaus-Bonus – gemeinsame Funktionen
declare(strict_types=1);

const APP_NAME = 'Brochhaus-Bonus';
define('APP_DIR', __DIR__);
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');

date_default_timezone_set('Europe/Berlin');

// Lange Sitzung, damit die Kinder eingeloggt bleiben (90 Tage)
$lifetime = 60 * 60 * 24 * 90;
ini_set('session.gc_maxlifetime', (string)$lifetime);
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('bbonus');
session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $pdo = new PDO('sqlite:' . DATA_DIR . '/bonus.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        role TEXT NOT NULL CHECK(role IN ('parent','child')),
        password_hash TEXT,
        can_login INTEGER NOT NULL DEFAULT 1,
        active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        points INTEGER,            -- NULL = Punkte legen die Eltern fest
        active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        task_id INTEGER REFERENCES tasks(id),
        task_name TEXT NOT NULL,
        note TEXT,
        photo TEXT,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
        points INTEGER NOT NULL DEFAULT 0,
        comment TEXT,
        created_at TEXT NOT NULL,
        reviewed_by INTEGER REFERENCES users(id),
        reviewed_at TEXT
    );
    CREATE TABLE IF NOT EXISTS rewards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        points_cost INTEGER NOT NULL,   -- Punkte pro Einheit
        unit_amount TEXT NOT NULL,      -- z.B. '30 Min.' oder '1 €'
        active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS reward_user (
        reward_id INTEGER NOT NULL REFERENCES rewards(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        enabled INTEGER NOT NULL DEFAULT 1,
        points_cost INTEGER,            -- eigener Kurs für dieses Kind (optional)
        PRIMARY KEY (reward_id, user_id)
    );
    CREATE TABLE IF NOT EXISTS redemptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        reward_id INTEGER REFERENCES rewards(id),
        reward_name TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        amount_text TEXT NOT NULL,
        points INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
        comment TEXT,
        created_at TEXT NOT NULL,
        reviewed_by INTEGER REFERENCES users(id),
        reviewed_at TEXT
    );
    ");
}

function is_installed(): bool {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role='parent'")->fetchColumn() > 0;
}

function seed_defaults(): void {
    $pdo = db();
    if ((int)$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === 0) {
        $tasks = [
            ['Müll rausbringen', 10], ['Wäsche in den Keller bringen', 10],
            ['Wäsche aus dem Keller hochholen', 10], ['Treppen freiräumen', 10],
            ['Geschirrspüler ausräumen', 15], ['Eigenes Zimmer aufräumen', 20],
            ['Auto von innen aussaugen (pro Auto)', 20], ['Garten aufräumen', 25],
            ['Carport aufräumen', 25], ['Schrank oder Regal aufräumen (z. B. Bücherregal)', 30],
            ['Schuppen aufräumen', 30], ['Gartenarbeit (z. B. Beete)', 30],
            ['Rasenmähen', 40], ['Hecke schneiden', 50], ['Keller aufräumen', 50],
            ['Sonstige Aufgabe', null],
        ];
        $st = $pdo->prepare('INSERT INTO tasks (name, points, sort) VALUES (?,?,?)');
        foreach ($tasks as $i => $t) $st->execute([$t[0], $t[1], $i]);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM rewards')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO rewards (name, points_cost, unit_amount, sort) VALUES (?,?,?,?)');
        $st->execute(['Bildschirmzeit iPhone', 100, '30 Min.', 0]);
        $st->execute(['PlayStation-Zeit', 100, '30 Min.', 1]);
        $st->execute(['Taschengeld', 100, '1 €', 2]);
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='child'")->fetchColumn() === 0) {
        // Kinder ohne Passwort – die Eltern vergeben die Passwörter im Elternbereich
        $kids = [['Bent', 1], ['Mieke', 1], ['Ole', 1], ['Jost', 1], ['Frieda', 1], ['Heidi', 0]];
        $st = $pdo->prepare("INSERT INTO users (name, role, can_login, sort) VALUES (?, 'child', ?, ?)");
        foreach ($kids as $i => $k) $st->execute([$k[0], $k[1], $i]);
    }
}

// ---------- Hilfsfunktionen ----------
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function fmt_date(?string $d): string { return $d ? date('d.m.Y, H:i', strtotime($d)) : ''; }
function redirect(string $page, array $q = []): never {
    $q = array_merge(['p' => $page], $q);
    header('Location: index.php?' . http_build_query($q));
    exit;
}
function flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function check_csrf(): void {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400); exit('Sitzung abgelaufen. Bitte Seite neu laden.');
    }
}

// ---------- Anmeldung ----------
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return $u = null;
    $st = db()->prepare('SELECT * FROM users WHERE id=? AND active=1');
    $st->execute([$id]);
    return $u = ($st->fetch() ?: null);
}
function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login');
    return $u;
}
function require_parent(): array {
    $u = require_login();
    if ($u['role'] !== 'parent') redirect('home');
    return $u;
}

// ---------- Punkte ----------
function balance(int $userId): int {
    $pdo = db();
    $earned = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM submissions WHERE user_id=? AND status='approved'");
    $earned->execute([$userId]);
    // offene und bestätigte Einlösungen sind schon reserviert
    $spent = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM redemptions WHERE user_id=? AND status IN ('pending','approved')");
    $spent->execute([$userId]);
    return (int)$earned->fetchColumn() - (int)$spent->fetchColumn();
}

function rewards_for(int $userId): array {
    $st = db()->prepare("
        SELECT r.*, COALESCE(ru.points_cost, r.points_cost) AS cost, COALESCE(ru.enabled, 1) AS enabled
        FROM rewards r LEFT JOIN reward_user ru ON ru.reward_id = r.id AND ru.user_id = ?
        WHERE r.active = 1 ORDER BY r.sort, r.id");
    $st->execute([$userId]);
    return array_values(array_filter($st->fetchAll(), fn($r) => (int)$r['enabled'] === 1));
}

function children(bool $onlyActive = true): array {
    $sql = "SELECT * FROM users WHERE role='child'" . ($onlyActive ? ' AND active=1' : '') . ' ORDER BY sort, id';
    return db()->query($sql)->fetchAll();
}

// ---------- Foto-Upload ----------
function save_photo(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Das Foto konnte nicht hochgeladen werden.');
    if ($file['size'] > 15 * 1024 * 1024) throw new RuntimeException('Das Foto ist zu groß (max. 15 MB).');
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Bitte ein Foto (JPG, PNG oder WebP) hochladen.');
    $sub = date('Y/m');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = $sub . '/' . bin2hex(random_bytes(12)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Das Foto konnte nicht gespeichert werden.');
    }
    return $name;
}
function photo_url(?string $photo): string { return $photo ? 'photo.php?f=' . rawurlencode($photo) : ''; }

// ---------- Layout ----------
function page_header(string $title, ?array $user = null): void {
    $f = flash();
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?> · <?= APP_NAME ?></title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= APP_NAME ?>">
<meta name="theme-color" content="#1f6f5c">
<link rel="stylesheet" href="assets/style.css?v=1">
<!-- SSL-Siegel (Sectigo/Instant SSL), Teil 1 -->
<script type="text/javascript">//<![CDATA[
var tlJsHost = ((window.location.protocol == "https:") ? "https://secure.trust-provider.com/" : "http://www.trustlogo.com/");
document.write(unescape("%3Cscript src='" + tlJsHost + "trustlogo/javascript/trustlogo.js' type='text/javascript'%3E%3C/script%3E"));
//]]>
</script>
</head>
<body>
<header class="top">
  <a class="brand" href="index.php"><img src="assets/icon-192.png" alt=""> <?= APP_NAME ?></a>
  <?php if ($user): ?>
    <span class="who"><?= h($user['name']) ?> · <a href="index.php?p=logout">Abmelden</a></span>
  <?php endif; ?>
</header>
<?php if ($user && $user['role'] === 'parent'): $pend = pending_count(); ?>
<nav class="tabs">
  <a href="index.php?p=admin">Freigaben<?= $pend ? ' <b class="badge">' . $pend . '</b>' : '' ?></a>
  <a href="index.php?p=admin_enter">Eintragen</a>
  <a href="index.php?p=admin_overview">Übersicht</a>
  <a href="index.php?p=admin_settings">Verwalten</a>
</nav>
<?php elseif ($user): ?>
<nav class="tabs">
  <a href="index.php?p=home">Start</a>
  <a href="index.php?p=submit">Aufgabe melden</a>
  <a href="index.php?p=rewards">Einlösen</a>
  <a href="index.php?p=history">Verlauf</a>
</nav>
<?php endif; ?>
<main>
<?php if ($f): ?><div class="flash <?= h($f[1]) ?>"><?= h($f[0]) ?></div><?php endif; ?>
<?php
}

function page_footer(): void {
    ?>
</main>
<script src="assets/app.js?v=1"></script>
<!-- SSL-Siegel (Sectigo/Instant SSL), Teil 2 -->
<div style="text-align:center;margin:24px 0">
<script language="JavaScript" type="text/javascript">
TrustLogo("https://www.trustlogo.com/images/install/instantssl_trust_seal_md_159x42.png", "SC7", "none");
</script>
<a href="https://www.instantssl.com/ssl.html" id="comodoTL">Instant SSL</a>
</div>
</body>
</html>
<?php
}

function pending_count(): int {
    $a = (int)db()->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();
    $b = (int)db()->query("SELECT COUNT(*) FROM redemptions WHERE status='pending'")->fetchColumn();
    return $a + $b;
}

function status_label(string $s): string {
    return ['pending' => 'wartet', 'approved' => 'bestätigt', 'rejected' => 'abgelehnt'][$s] ?? $s;
}
