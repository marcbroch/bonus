<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/pages_child.php';
require __DIR__ . '/pages_admin.php';

$p = $_GET['p'] ?? '';

// Ersteinrichtung, solange es noch keinen Eltern-Zugang gibt
if (!is_installed()) { page_setup(); exit; }

switch ($p) {
    case 'login':          page_login(); break;
    case 'logout':         session_destroy(); redirect('login');
    case 'home':           page_home(); break;
    case 'submit':         page_submit(); break;
    case 'rewards':        page_rewards(); break;
    case 'history':        redirect('account');
    case 'account':        page_account(); break;
    case 'admin':          page_admin(); break;
    case 'admin_enter':    page_admin_enter(); break;
    case 'admin_overview': page_admin_overview(); break;
    case 'admin_settings': page_admin_settings(); break;
    default:
        $u = current_user();
        if (!$u) redirect('login');
        redirect($u['role'] === 'parent' ? 'admin' : 'home');
}

function page_setup(): void {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $name = trim((string)($_POST['name'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        if ($name === '' || mb_strlen($pw) < 6) {
            $error = 'Bitte einen Namen und ein Passwort mit mindestens 6 Zeichen eingeben.';
        } else {
            seed_defaults();
            $st = db()->prepare("INSERT INTO users (name, role, password_hash) VALUES (?, 'parent', ?)");
            $st->execute([$name, password_hash($pw, PASSWORD_DEFAULT)]);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            session_regenerate_id(true);
            flash('Willkommen! Lege jetzt unter „Einstellungen“ die Passwörter der Kinder fest.');
            redirect('admin_settings');
        }
    }
    page_header('Einrichtung');
    ?>
    <section class="card narrow">
      <img class="welcome" src="assets/welcome.jpg" alt="Die Familie Brochhaus hilft gemeinsam im Haus und Garten">
      <h1>Willkommen bei <?= APP_NAME ?></h1>
      <p>Lege zuerst deinen Eltern-Zugang an. Die Kinder, Aufgaben und Belohnungen sind schon vorbereitet.</p>
      <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <label>Dein Name<input name="name" required autocomplete="username" placeholder="z. B. Marc"></label>
        <label>Passwort (mind. 6 Zeichen)<input name="password" type="password" required autocomplete="new-password"></label>
        <button class="btn primary">Einrichten</button>
      </form>
    </section>
    <?php
    page_footer();
}

function page_login(): void {
    if (current_user()) redirect('');
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $st = db()->prepare('SELECT * FROM users WHERE id=? AND active=1 AND can_login=1');
        $st->execute([(int)($_POST['uid'] ?? 0)]);
        $u = $st->fetch();
        // kleine Bremse gegen Durchprobieren
        usleep(300000);
        if ($u && $u['password_hash'] && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            redirect($u['role'] === 'parent' ? 'admin' : 'home');
        }
        $error = 'Das Passwort stimmt leider nicht.';
    }
    $users = db()->query("SELECT id, name, role, avatar FROM users WHERE active=1 AND can_login=1 AND password_hash IS NOT NULL
                          ORDER BY role='parent', sort, id")->fetchAll();
    $sel = (int)($_POST['uid'] ?? 0);
    page_header('Anmelden');
    ?>
    <section class="card narrow login">
      <img class="welcome" src="assets/welcome.jpg" alt="Die Familie Brochhaus hilft gemeinsam im Haus und Garten">
      <h1>Wer bist du? 👋</h1>
      <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
      <?php if (!$users): ?><p class="muted">Noch hat niemand ein Passwort. Die Eltern legen es unter „Verwalten“ fest.</p><?php endif; ?>
      <form method="post" class="js-login">
        <?= csrf_field() ?>
        <div class="profiles">
          <?php foreach ($users as $u): ?>
            <label class="profile <?= $u['role'] === 'parent' ? 'parent' : '' ?>">
              <input type="radio" name="uid" value="<?= (int)$u['id'] ?>" required <?= $sel === (int)$u['id'] ? 'checked' : '' ?>>
              <span class="ptile"><?= avatar($u, 'big') ?><span class="pname"><?= h($u['name']) ?></span>
                <?php if ($u['role'] === 'parent'): ?><span class="prole">Eltern</span><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="pw-box">
          <label>Dein Passwort<input name="password" type="password" required autocomplete="current-password"></label>
          <button class="btn primary block">Los geht’s! 🚀</button>
        </div>
      </form>
    </section>
    <?php
    page_footer();
}
