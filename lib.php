<?php
// Brochhaus-Bonus – gemeinsame Funktionen
declare(strict_types=1);

const APP_NAME = 'Brochhaus-Bonus';
define('APP_DIR', __DIR__);
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');

date_default_timezone_set('Europe/Berlin');

// Ersatz für Funktionen, die es erst ab PHP 8 gibt (der Webspace läuft noch mit PHP 7.4)
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool { return strncmp($haystack, $needle, strlen($needle)) === 0; }
}

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
    // Migration für bestehende Datenbanken: Profilbild je Person
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('avatar', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN avatar TEXT');
    // Migration: wann das Kind zuletzt Neuigkeiten gesehen hat (für den Konfetti-Moment)
    if (!in_array('seen_at', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN seen_at TEXT');
    // Migration: Stichwort und Bild je Aufgabe, für vorhandene Aufgaben automatisch vorschlagen.
    // Darf die App nie lahmlegen: Bei einem Fehler wird er protokolliert und die App läuft weiter
    // (die Bilder werden dann über den Aufgabennamen gewählt).
    try {
        $cols = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
        if (!in_array('icon', $cols, true)) $pdo->exec('ALTER TABLE tasks ADD COLUMN icon TEXT');
        if (!in_array('keyword', $cols, true)) $pdo->exec('ALTER TABLE tasks ADD COLUMN keyword TEXT');
        $missing = $pdo->query('SELECT id, name, icon, keyword FROM tasks WHERE icon IS NULL OR keyword IS NULL')->fetchAll();
        $upd = $pdo->prepare('UPDATE tasks SET icon=?, keyword=? WHERE id=?');
        foreach ($missing as $t) {
            $icon = $t['icon'] ?: guess_icon((string)$t['name']);
            $upd->execute([$icon, $t['keyword'] ?: guess_keyword((string)$t['name'], $icon), $t['id']]);
        }
    } catch (Throwable $e) {
        app_log('Migration Aufgaben: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// Fehler in data/error.log schreiben (Ordner ist von außen gesperrt)
function app_log(string $msg): void {
    @file_put_contents(DATA_DIR . '/error.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
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
        $st = $pdo->prepare('INSERT INTO tasks (name, keyword, icon, points, sort) VALUES (?,?,?,?,?)');
        foreach ($tasks as $i => $t) {
            $icon = guess_icon($t[0]);
            $st->execute([$t[0], guess_keyword($t[0], $icon), $icon, $t[1], $i]);
        }
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

// ---------- Profilbilder ----------
const AVATARS = ['🦊', '🐼', '🐯', '🦁', '🐸', '🐵', '🐨', '🐰', '🦄', '🐶', '🐱', '🐻',
                 '🐧', '🐢', '🦖', '🐙', '🦉', '🐝', '🐞', '🦋', '🐬', '🦔', '🐷', '🐮'];

function avatar_of(array $u): string {
    $a = (string)($u['avatar'] ?? '');
    return in_array($a, AVATARS, true) ? $a : AVATARS[((int)$u['id'] - 1) % count(AVATARS)];
}
function avatar(array $u, string $size = ''): string {
    return '<span class="avatar ' . h($size) . ' av' . ((int)$u['id'] % 6) . '" aria-hidden="true">' . avatar_of($u) . '</span>';
}

// ---------- Comic-Bilder der Aufgaben ----------
// Bild-Schlüssel => vorgeschlagenes Stichwort. Die Bilder liegen in assets/tasks/.
const TASK_ICONS = [
    'muell' => 'Müll', 'waesche-runter' => 'Wäsche runter', 'waesche-hoch' => 'Wäsche hoch',
    'treppe' => 'Treppe', 'geschirr' => 'Geschirr', 'zimmer' => 'Zimmer', 'auto' => 'Auto saugen',
    'garten' => 'Garten', 'carport' => 'Carport', 'regal' => 'Regal', 'schuppen' => 'Schuppen',
    'beete' => 'Beete', 'rasen' => 'Rasen mähen', 'hecke' => 'Hecke', 'keller' => 'Keller',
    'sonstige' => 'Sonstiges',
];

// Passendes Bild anhand des Aufgabennamens vorschlagen
function guess_icon(string $name): string {
    $n = mb_strtolower($name);
    if (str_contains($n, 'wäsche') && (str_contains($n, 'hoch') || str_contains($n, 'aus dem'))) return 'waesche-hoch';
    $words = [
        'waesche-runter' => ['wäsche'],
        'muell'          => ['müll', 'tonne', 'altpapier'],
        'geschirr'       => ['geschirr', 'spülmaschine', 'spüler', 'tisch decken', 'tisch abräumen', 'küche', 'abwasch'],
        'zimmer'         => ['zimmer', 'bett'],
        'auto'           => ['auto', 'saug'],
        'rasen'          => ['rasen', 'mäh'],
        'hecke'          => ['hecke', 'busch', 'strauch'],
        'beete'          => ['beet', 'gartenarbeit', 'blume', 'gieß', 'unkraut', 'pflanz'],
        'garten'         => ['garten', 'laub', 'hof'],
        'carport'        => ['carport', 'garage', 'fahrrad'],
        'regal'          => ['regal', 'schrank', 'bücher'],
        'schuppen'       => ['schuppen', 'gartenhaus'],
        'treppe'         => ['treppe', 'flur', 'schuhe'],
        'keller'         => ['keller', 'dachboden', 'karton'],
    ];
    foreach ($words as $icon => $list) {
        foreach ($list as $w) if (str_contains($n, $w)) return $icon;
    }
    return 'sonstige';
}
function guess_keyword(string $name, string $icon): string {
    if ($icon !== 'sonstige') return TASK_ICONS[$icon];
    $first = preg_split('/[\s(,]+/u', trim($name))[0] ?? '';
    return mb_strtolower($first) === 'sonstige' || $first === '' ? 'Sonstiges' : mb_substr($first, 0, 20);
}

// Bild direkt in die Seite einbetten (kein eigener Abruf nötig, funktioniert auf jedem Server)
function icon_src(string $icon): string {
    static $cache = [];
    if (!isset(TASK_ICONS[$icon])) $icon = 'sonstige';
    if (!isset($cache[$icon])) {
        $svg = @file_get_contents(APP_DIR . "/assets/tasks/$icon.svg");
        $cache[$icon] = $svg ? 'data:image/svg+xml;base64,' . base64_encode($svg) : "assets/tasks/$icon.svg";
    }
    return $cache[$icon];
}
function icon_img(?string $icon, string $class = 'ticon'): string {
    return '<img class="' . h($class) . '" src="' . icon_src((string)$icon) . '" alt="">';
}

// Bild zu einer Aufgabe bzw. gemeldeten Aufgabe (über task_id, sonst über den Namen)
function task_info(?int $taskId): ?array {
    static $tasks = null;
    if ($tasks === null) {
        $tasks = [];
        foreach (db()->query('SELECT * FROM tasks')->fetchAll() as $t) $tasks[(int)$t['id']] = $t;
    }
    return $taskId !== null ? ($tasks[$taskId] ?? null) : null;
}
function task_img(string $name, string $class = 'ticon', ?int $taskId = null): string {
    $t = task_info($taskId);
    return icon_img($t['icon'] ?? guess_icon($name), $class);
}

// ---------- Wochenwertung (Mo–So). Die Punkte bleiben trotzdem auf dem Konto. ----------
function week_bounds(int $offset = 0): array {
    $monday = (new DateTimeImmutable('today'))->modify('-' . ((int)date('N') - 1) . ' days')->modify(($offset >= 0 ? '+' : '') . ($offset * 7) . ' days');
    return [$monday, $monday->modify('+7 days')];
}
function week_board(int $offset = 0): array {
    [$from, $to] = week_bounds($offset);
    $st = db()->prepare("SELECT COALESCE(SUM(points),0) AS pts, COUNT(*) AS cnt FROM submissions
                         WHERE user_id=? AND status='approved' AND created_at >= ? AND created_at < ?");
    $rows = [];
    foreach (children() as $k) {
        $st->execute([$k['id'], $from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]);
        $r = $st->fetch();
        $rows[] = ['user' => $k, 'points' => (int)$r['pts'], 'count' => (int)$r['cnt']];
    }
    usort($rows, fn($a, $b) => [$b['points'], $a['user']['sort']] <=> [$a['points'], $b['user']['sort']]);
    return $rows;
}
function render_week_board(string $page, ?array $me = null): void {
    $w = min(0, max(-52, (int)($_GET['w'] ?? 0)));
    [$from, $to] = week_bounds($w);
    $rows = week_board($w);
    $max = max(1, ...array_map(fn($r) => $r['points'], $rows));
    $medals = ['🥇', '🥈', '🥉'];
    $link = fn(int $x) => 'index.php?p=' . $page . ($x ? '&w=' . $x : '');
    ?>
    <section class="card week">
      <div class="week-head">
        <a class="wnav" href="<?= $link($w - 1) ?>" aria-label="Woche davor">‹</a>
        <div><h2>🏆 <?= $w === 0 ? 'Diese Woche' : ($w === -1 ? 'Letzte Woche' : 'KW ' . $from->format('W')) ?></h2>
          <span class="muted">Mo <?= $from->format('d.m.') ?> – So <?= $to->modify('-1 day')->format('d.m.Y') ?></span></div>
        <?php if ($w < 0): ?><a class="wnav" href="<?= $link($w + 1) ?>" aria-label="Woche danach">›</a><?php else: ?><span class="wnav off"></span><?php endif; ?>
      </div>
      <ol class="board">
        <?php foreach ($rows as $i => $r): $u = $r['user']; ?>
          <li class="<?= $me && (int)$me['id'] === (int)$u['id'] ? 'me' : '' ?>">
            <span class="rank"><?= $r['points'] > 0 && $i < 3 ? $medals[$i] : ($i + 1) . '.' ?></span>
            <?= avatar($u) ?>
            <div class="grow"><b><?= h($u['name']) ?></b>
              <div class="bar"><span style="width:<?= (int)round($r['points'] / $max * 100) ?>%"></span></div></div>
            <span class="wpts"><span><b><?= $r['points'] ?></b> P.</span><small><?= $r['count'] ?> Aufg.</small></span>
          </li>
        <?php endforeach; ?>
      </ol>
      <p class="muted note">Die Wochenwertung zeigt nur, wer wie fleißig war. Alle Punkte bleiben auf dem Konto – sie verfallen nie. 💰</p>
    </section>
    <?php
}

// ---------- Foto: Kamera oder Fotomediathek (Pflicht als Nachweis) ----------
function photo_picker(): void {
    ?>
    <div class="photo-box js-photo-box">
      <img class="photo-preview" alt="Vorschau" hidden>
      <p class="photo-hint">📸 Mach ein Foto als Nachweis – ohne Foto geht’s nicht.</p>
      <div class="photo-btns">
        <label class="btn primary photo-btn">📷 Foto machen
          <input type="file" name="photo" accept="image/*" capture="environment"></label>
        <label class="btn photo-btn">🖼️ Aus Fotos wählen
          <input type="file" name="photo_lib" accept="image/*"></label>
      </div>
    </div>
    <?php
}
// ---------- Foto aus dem Formular (Kamera oder Fotomediathek) ----------
function photo_from_request(): ?string {
    foreach (['photo', 'photo_lib'] as $field) {
        $name = save_photo($_FILES[$field] ?? []);
        if ($name) return $name;
    }
    return null;
}

// ---------- Konto: alle Buchungen eines Kindes ----------
function account_entries(int $userId): array {
    $pdo = db();
    $rows = [];
    $st = $pdo->prepare('SELECT s.*, r.name AS reviewer FROM submissions s LEFT JOIN users r ON r.id = s.reviewed_by WHERE s.user_id=?');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $s) {
        $rows[] = ['kind' => 'task', 'date' => $s['created_at'], 'id' => (int)$s['id'], 'title' => $s['task_name'],
                   'delta' => $s['status'] === 'approved' ? (int)$s['points'] : 0, 'row' => $s];
    }
    $st = $pdo->prepare('SELECT d.*, r.name AS reviewer FROM redemptions d LEFT JOIN users r ON r.id = d.reviewed_by WHERE d.user_id=?');
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $d) {
        $rows[] = ['kind' => 'reward', 'date' => $d['created_at'], 'id' => (int)$d['id'], 'title' => $d['amount_text'] . ' ' . $d['reward_name'],
                   'delta' => $d['status'] === 'rejected' ? 0 : -(int)$d['points'], 'row' => $d];
    }
    // chronologisch sortieren und den Kontostand nach jeder Buchung mitrechnen
    usort($rows, fn($a, $b) => [$a['date'], $a['kind'], $a['id']] <=> [$b['date'], $b['kind'], $b['id']]);
    $sum = 0;
    foreach ($rows as &$r) { $sum += $r['delta']; $r['balance'] = $sum; }
    unset($r);
    return array_reverse($rows);
}

// Summe der ausgezahlten Belohnungen, z. B. "3 Std. 30 Min." oder "12 €"
function payout_totals(int $userId): array {
    $st = db()->prepare("SELECT reward_name, quantity, amount_text FROM redemptions WHERE user_id=? AND status='approved'");
    $st->execute([$userId]);
    $tot = [];
    foreach ($st->fetchAll() as $r) {
        // amount_text hat die Form "2 × 30 Min."
        $unit = preg_replace('/^\d+\s*×\s*/u', '', $r['amount_text']);
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(.*)$/u', $unit, $m)) { $num = (float)str_replace(',', '.', $m[1]); $label = trim($m[2]); }
        else { $num = 1.0; $label = $unit; }
        $key = $r['reward_name'] . '|' . $label;
        $tot[$key] ??= ['name' => $r['reward_name'], 'label' => $label, 'sum' => 0.0, 'count' => 0];
        $tot[$key]['sum'] += $num * (int)$r['quantity'];
        $tot[$key]['count']++;
    }
    foreach ($tot as &$t) $t['text'] = fmt_amount($t['sum'], $t['label']);
    unset($t);
    return array_values($tot);
}
function fmt_amount(float $n, string $label): string {
    if (preg_match('/^min/i', $label) && $n >= 60) {
        $hrs = intdiv((int)$n, 60); $min = (int)$n % 60;
        return $hrs . ' Std.' . ($min ? " $min Min." : '');
    }
    $num = fmod($n, 1.0) == 0.0 ? (string)(int)$n : number_format($n, 2, ',', '.');
    return trim("$num $label");
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
<meta name="theme-color" content="#5ec4f2">
<link rel="preload" href="assets/fredoka.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=9">
</head>
<body>
<header class="top">
  <a class="brand" href="index.php" aria-label="<?= APP_NAME ?>"><img src="assets/icon-192.png" alt="">
    <span class="logo"><?= brand_logo() ?><span class="sub">Bonus</span></span></a>
  <?php if ($user): ?>
    <span class="who"><?= h($user['name']) ?> · <a href="index.php?p=password" title="Passwort ändern">🔑 Passwort</a> · <a href="index.php?p=logout">Abmelden</a></span>
  <?php endif; ?>
</header>
<?php $cur = (string)($_GET['p'] ?? ''); $on = fn(string $p) => $cur === $p ? ' class="on"' : ''; ?>
<?php if ($user && $user['role'] === 'parent'): $pend = pending_count(); ?>
<nav class="tabs">
  <a href="index.php?p=admin"<?= $on('admin') ?>>Freigaben<?= $pend ? ' <b class="badge">' . $pend . '</b>' : '' ?></a>
  <a href="index.php?p=admin_enter"<?= $on('admin_enter') ?>>Eintragen</a>
  <a href="index.php?p=admin_overview"<?= $on('admin_overview') ?>>Übersicht</a>
  <a href="index.php?p=admin_settings"<?= $on('admin_settings') ?>>Verwalten</a>
</nav>
<?php elseif ($user): ?>
<nav class="tabs">
  <a href="index.php?p=home"<?= $on('home') ?>>Start</a>
  <a href="index.php?p=submit"<?= $on('submit') ?>>Aufgabe melden</a>
  <a href="index.php?p=rewards"<?= $on('rewards') ?>>Einlösen</a>
  <a href="index.php?p=account"<?= $on('account') ?>>Mein Konto</a>
</nav>
<?php endif; ?>
<main>
<?php if ($f): ?><div class="flash <?= h($f[1]) ?>"><?= h($f[0]) ?></div><?php endif; ?>
<?php if ($user && $user['role'] === 'child') render_celebration($user); ?>
<?php
}

function page_footer(): void {
    ?>
</main>
<script src="assets/app.js?v=7"></script>
</body>
</html>
<?php
}

// Farbe aufhellen (+) oder abdunkeln (-), $f zwischen -1 und 1
function shade(string $hex, float $f): string {
    $rgb = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    $rgb = array_map(fn($c) => (int)round($f >= 0 ? $c + (255 - $c) * $f : $c * (1 + $f)), $rgb);
    return vsprintf('#%02x%02x%02x', $rgb);
}

// Schriftzug wie im Titelbild: dicke Comic-Buchstaben im Bogen, jeder in eigener Farbe,
// mit Glanzlicht, dunkler Kante, 3D-Unterkante und Sternchen drumherum
function brand_logo(): string {
    $letters = [['B', '#ff4fa0'], ['R', '#ff6a2b'], ['O', '#ffb21f'], ['C', '#7cc83a'], ['H', '#22b5e6'],
                ['H', '#a65fe8'], ['A', '#ff8a24'], ['U', '#1fc4b0'], ['S', '#2f8fe6']];
    $defs = $fill = $back = '';
    foreach ($letters as $i => [$ch, $c]) {
        $defs .= '<linearGradient id="lg' . $i . '" x1="0" y1="0" x2="0" y2="1">'
               . '<stop offset="0" stop-color="' . shade($c, .55) . '"/><stop offset=".45" stop-color="' . $c . '"/>'
               . '<stop offset="1" stop-color="' . shade($c, -.25) . '"/></linearGradient>';
        $fill .= '<tspan fill="url(#lg' . $i . ')" stroke="' . shade($c, -.45) . '">' . $ch . '</tspan>';
        $back .= '<tspan fill="' . shade($c, -.5) . '" stroke="' . shade($c, -.5) . '">' . $ch . '</tspan>';
    }
    $star = function (float $x, float $y, float $r, string $c): string {
        $pts = [];
        for ($k = 0; $k < 10; $k++) {
            $a = -M_PI / 2 + $k * M_PI / 5; $rr = $k % 2 ? $r * .45 : $r;
            $pts[] = round($x + $rr * cos($a), 1) . ',' . round($y + $rr * sin($a), 1);
        }
        return '<polygon points="' . implode(' ', $pts) . '" fill="' . $c . '" stroke="#2b2a3d" stroke-width="1.5" stroke-linejoin="round"/>';
    };
    $sparkle = fn(float $x, float $y, float $r) => '<path d="M' . $x . ' ' . ($y - $r) . ' Q' . ($x + $r * .18) . ' ' . ($y - $r * .18) . ' ' . ($x + $r) . ' ' . $y
        . ' Q' . ($x + $r * .18) . ' ' . ($y + $r * .18) . ' ' . $x . ' ' . ($y + $r) . ' Q' . ($x - $r * .18) . ' ' . ($y + $r * .18) . ' ' . ($x - $r) . ' ' . $y
        . ' Q' . ($x - $r * .18) . ' ' . ($y - $r * .18) . ' ' . $x . ' ' . ($y - $r) . 'Z" fill="#fff"/>';
    $path = 'M12 122 Q250 8 488 122';
    $txt = 'font-family="Fredoka, \'Baloo 2\', \'Comic Sans MS\', sans-serif" font-weight="700" font-stretch="78%" style="font-stretch:78%" font-size="92" letter-spacing="3" text-anchor="middle" stroke-linejoin="round"';
    return '<svg class="wordmark" viewBox="0 0 500 140" role="img" aria-label="Brochhaus">'
        . '<defs>' . $defs . '<path id="arc" d="' . $path . '"/>'
        . '<linearGradient id="gloss" x1="0" y1="0" x2="0" y2="1"><stop offset=".08" stop-color="#fff" stop-opacity=".95"/>'
        . '<stop offset=".34" stop-color="#fff" stop-opacity=".35"/><stop offset=".46" stop-color="#fff" stop-opacity="0"/></linearGradient></defs>'
        . $star(24, 40, 12, '#ffd23f') . $star(476, 44, 11, '#ff5fa2') . $star(120, 18, 9, '#7cc6f2') . $star(318, 134, 8, '#9b5de5') . $star(392, 128, 8, '#3cbf5b')
        . $sparkle(64, 14, 9) . $sparkle(392, 16, 9) . $sparkle(250, 134, 6)
        // 3D-Unterkante
        . '<text ' . $txt . ' stroke-width="8" transform="translate(0 6)"><textPath href="#arc" startOffset="50%">' . $back . '</textPath></text>'
        // Buchstaben mit dunkler Kante
        . '<text ' . $txt . ' stroke-width="6" paint-order="stroke"><textPath href="#arc" startOffset="50%">' . $fill . '</textPath></text>'
        // Glanzlicht oben
        . '<text ' . $txt . ' fill="url(#gloss)" stroke="none"><textPath href="#arc" startOffset="50%">BROCHHAUS</textPath></text>'
        . '</svg>';
}

function pending_count(): int {
    $a = (int)db()->query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn();
    $b = (int)db()->query("SELECT COUNT(*) FROM redemptions WHERE status='pending'")->fetchColumn();
    return $a + $b;
}

function status_label(string $s): string {
    return ['pending' => 'wartet', 'approved' => 'bestätigt', 'rejected' => 'abgelehnt'][$s] ?? $s;
}

require __DIR__ . '/badges.php';
