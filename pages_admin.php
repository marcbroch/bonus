<?php
// Seiten für die Eltern
declare(strict_types=1);

function page_admin(): void {
    $me = require_parent();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $comment = trim((string)($_POST['comment'] ?? '')) ?: null;
        if ($action === 'approve_sub' || $action === 'reject_sub') {
            $approve = $action === 'approve_sub';
            $points = max(0, (int)($_POST['points'] ?? 0));
            if ($approve && $points < 1) { flash('Bitte eine Punktzahl eintragen.', 'err'); redirect('admin'); }
            $st = $pdo->prepare("UPDATE submissions SET status=?, points=?, comment=?, reviewed_by=?, reviewed_at=? WHERE id=? AND status='pending'");
            $st->execute([$approve ? 'approved' : 'rejected', $approve ? $points : 0, $comment, $me['id'], now(), $id]);
            flash($approve ? "Bestätigt: +$points Punkte." : 'Abgelehnt.');
        } elseif ($action === 'approve_red' || $action === 'reject_red') {
            $approve = $action === 'approve_red';
            $st = $pdo->prepare("UPDATE redemptions SET status=?, comment=?, reviewed_by=?, reviewed_at=? WHERE id=? AND status='pending'");
            $st->execute([$approve ? 'approved' : 'rejected', $comment, $me['id'], now(), $id]);
            flash($approve ? 'Als ausgezahlt eingetragen.' : 'Einlösung abgelehnt, Punkte sind zurück.');
        }
        redirect('admin');
    }
    $subs = $pdo->query("SELECT s.*, u.name AS child, t.points AS default_points FROM submissions s
                         JOIN users u ON u.id=s.user_id LEFT JOIN tasks t ON t.id=s.task_id
                         WHERE s.status='pending' ORDER BY s.id")->fetchAll();
    $reds = $pdo->query("SELECT r.*, u.name AS child FROM redemptions r JOIN users u ON u.id=r.user_id
                         WHERE r.status='pending' ORDER BY r.id")->fetchAll();
    page_header('Freigaben', $me);
    ?>
    <h1>Freigaben</h1>
    <?php if (!$subs && !$reds): ?><p class="card muted">Alles erledigt – nichts wartet auf Freigabe. 🎉</p><?php endif; ?>

    <?php if ($reds): ?><h2>Einlösungen</h2><?php endif; ?>
    <?php foreach ($reds as $r): ?>
      <section class="card review">
        <div class="rhead"><h2>🎁 <?= h($r['child']) ?>: <?= h($r['amount_text']) ?> <?= h($r['reward_name']) ?></h2><span class="rate"><?= (int)$r['points'] ?> P.</span></div>
        <p class="muted"><?= fmt_date($r['created_at']) ?> · Stand danach: <?= balance((int)$r['user_id']) ?> P.</p>
        <form method="post" class="decide">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input name="comment" list="payout-ways" placeholder="Wie ausgezahlt? (optional)" maxlength="200">
          <div class="row">
            <button class="btn primary" name="action" value="approve_red">✓ Ausgezahlt</button>
            <button class="btn ghost" name="action" value="reject_red">Ablehnen</button>
          </div>
        </form>
      </section>
    <?php endforeach; ?>

    <datalist id="payout-ways">
      <option value="Bar ausgezahlt"><option value="Aufs Sparbuch"><option value="Aufs Kinderkonto überwiesen">
      <option value="Bildschirmzeit am iPhone freigeschaltet"><option value="PlayStation-Zeit freigeschaltet">
    </datalist>

    <?php if ($subs): ?><h2>Erledigte Aufgaben</h2><?php endif; ?>
    <?php foreach ($subs as $s): $free = $s['task_id'] !== null && $s['default_points'] === null; ?>
      <section class="card review">
        <?php if ($s['photo']): ?><a href="<?= h(photo_url($s['photo'])) ?>" target="_blank"><img class="proof" src="<?= h(photo_url($s['photo'])) ?>" alt="Nachweisfoto"></a><?php endif; ?>
        <div class="rhead tline"><?= task_img($s['task_name']) ?><h2 class="grow"><?= h($s['child']) ?>: <?= h($s['task_name']) ?></h2></div>
        <p class="muted"><?= fmt_date($s['created_at']) ?></p>
        <?php if ($s['note']): ?><p class="comment">„<?= h($s['note']) ?>“</p><?php endif; ?>
        <form method="post" class="decide">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <label class="inline">Punkte <input name="points" type="number" min="0" max="1000" inputmode="numeric"
              value="<?= $free ? '' : (int)$s['points'] ?>" <?= $free ? 'placeholder="festlegen"' : '' ?>></label>
          <input name="comment" placeholder="Kommentar (optional)" maxlength="200">
          <div class="row">
            <button class="btn primary" name="action" value="approve_sub">✓ Bestätigen</button>
            <button class="btn ghost" name="action" value="reject_sub" formnovalidate>Ablehnen</button>
          </div>
        </form>
      </section>
    <?php endforeach; ?>
    <?php
    page_footer();
}

function page_admin_enter(): void {
    $me = require_parent();
    $kids = children();
    $tasks = db()->query('SELECT * FROM tasks WHERE active=1 ORDER BY sort, id')->fetchAll();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        try {
            $kid = (int)($_POST['user_id'] ?? 0);
            if (!in_array($kid, array_map(fn($k) => (int)$k['id'], $kids), true)) throw new RuntimeException('Bitte ein Kind auswählen.');
            $st = db()->prepare('SELECT * FROM tasks WHERE id=?');
            $st->execute([(int)($_POST['task_id'] ?? 0)]);
            $task = $st->fetch();
            if (!$task) throw new RuntimeException('Bitte eine Aufgabe auswählen.');
            $points = trim((string)($_POST['points'] ?? '')) === '' ? (int)($task['points'] ?? 0) : (int)$_POST['points'];
            if ($points < 1) throw new RuntimeException('Bitte eine Punktzahl eintragen.');
            $photo = save_photo($_FILES['photo'] ?? []);
            $note = trim((string)($_POST['note'] ?? '')) ?: null;
            $ins = db()->prepare("INSERT INTO submissions (user_id, task_id, task_name, note, photo, status, points, created_at, reviewed_by, reviewed_at)
                                  VALUES (?,?,?,?,?,'approved',?,?,?,?)");
            $ins->execute([$kid, $task['id'], $task['name'], $note, $photo, $points, now(), $me['id'], now()]);
            flash("Eingetragen: +$points Punkte.");
            redirect('admin_enter');
        } catch (RuntimeException $e) { $error = $e->getMessage(); }
    }
    page_header('Eintragen', $me);
    ?>
    <section class="card">
      <h1>Aufgabe für ein Kind eintragen</h1>
      <p class="muted">Für Heidi oder wenn ein Kind gerade kein Handy dabei hat. Wird sofort gutgeschrieben.</p>
      <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="js-photo-form">
        <?= csrf_field() ?>
        <div class="namepick">
          <?php foreach ($kids as $k): ?>
            <label class="chip"><input type="radio" name="user_id" value="<?= (int)$k['id'] ?>" required><span><?= h($k['name']) ?></span></label>
          <?php endforeach; ?>
        </div>
        <label>Aufgabe
          <select name="task_id" required class="js-task-select">
            <option value="">– bitte wählen –</option>
            <?php foreach ($tasks as $t): ?>
              <option value="<?= (int)$t['id'] ?>" data-points="<?= $t['points'] === null ? '' : (int)$t['points'] ?>"><?= h($t['name']) ?> (<?= $t['points'] === null ? 'frei' : (int)$t['points'] . ' P.' ?>)</option>
            <?php endforeach; ?>
          </select></label>
        <label>Punkte <span class="muted">(leer = Standard der Aufgabe)</span><input name="points" type="number" min="1" max="1000" inputmode="numeric" class="js-points"></label>
        <label class="photo-drop">
          <input type="file" name="photo" accept="image/*" capture="environment">
          <span class="photo-hint">📷 Foto (optional)</span>
          <img class="photo-preview" alt="" hidden>
        </label>
        <label>Notiz <input name="note" maxlength="300"></label>
        <button class="btn primary block">Eintragen</button>
      </form>
    </section>
    <?php
    page_footer();
}

function page_admin_overview(): void {
    $me = require_parent();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        // Bestätigte Aufgabe rückgängig machen
        if (($_POST['action'] ?? '') === 'undo') {
            $st = $pdo->prepare("UPDATE submissions SET status='rejected', points=0, comment=COALESCE(comment,'Zurückgenommen'), reviewed_by=?, reviewed_at=? WHERE id=? AND status='approved'");
            $st->execute([$me['id'], now(), (int)$_POST['id']]);
            flash('Gutschrift zurückgenommen.');
        }
        redirect('admin_overview', array_filter(['u' => $_POST['u'] ?? '']));
    }
    $kids = children();
    $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $wk = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM submissions WHERE user_id=? AND status='approved' AND created_at >= ?");
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id=? AND status='approved' AND created_at >= ?");
    $filter = (int)($_GET['u'] ?? 0);
    $sql = "SELECT s.*, u.name AS child FROM submissions s JOIN users u ON u.id=s.user_id" .
           ($filter ? ' WHERE s.user_id=' . $filter : '') . ' ORDER BY s.id DESC LIMIT 60';
    $rows = $pdo->query($sql)->fetchAll();
    page_header('Übersicht', $me);
    ?>
    <h1>Übersicht</h1>
    <section class="card">
      <p class="muted">Tippe auf einen Namen, um das Konto mit allen Auszahlungen zu sehen.</p>
      <table class="tbl">
        <thead><tr><th>Kind</th><th class="num">Punkte</th><th class="num">diese Woche</th><th class="num">Aufgaben</th></tr></thead>
        <tbody>
        <?php foreach ($kids as $k): $wk->execute([$k['id'], $weekStart]); $cnt->execute([$k['id'], $weekStart]); ?>
          <tr><td><a class="kid" href="index.php?p=account&u=<?= (int)$k['id'] ?>"><?= avatar($k) ?> <?= h($k['name']) ?></a></td>
              <td class="num"><b><?= balance((int)$k['id']) ?></b></td>
              <td class="num">+<?= (int)$wk->fetchColumn() ?></td>
              <td class="num"><?= (int)$cnt->fetchColumn() ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <section class="card">
      <div class="rhead"><h2>Verlauf<?= $filter ? '' : ' (alle)' ?></h2><?php if ($filter): ?><a href="index.php?p=admin_overview">alle zeigen</a><?php endif; ?></div>
      <ul class="list">
        <?php foreach ($rows as $s): ?>
          <li>
            <?php if ($s['photo']): ?><a href="<?= h(photo_url($s['photo'])) ?>" target="_blank"><img class="thumb" src="<?= h(photo_url($s['photo'])) ?>" alt="" loading="lazy"></a>
            <?php else: ?><?= task_img($s['task_name']) ?><?php endif; ?>
            <div class="grow"><b><?= h($s['child']) ?>:</b> <?= h($s['task_name']) ?><br><span class="muted"><?= fmt_date($s['created_at']) ?></span>
              <?php if ($s['comment']): ?><br><span class="comment">„<?= h($s['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill <?= h($s['status']) ?>"><?= $s['status'] === 'approved' ? '+' . (int)$s['points'] : status_label($s['status']) ?></span>
            <?php if ($s['status'] === 'approved'): ?>
              <form method="post" onsubmit="return confirm('Gutschrift wirklich zurücknehmen?')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="u" value="<?= $filter ?: '' ?>">
                <button class="btn tiny ghost" name="action" value="undo" title="Zurücknehmen">↺</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php
    page_footer();
}

function page_admin_settings(): void {
    $me = require_parent();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $a = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        try {
            switch ($a) {
                case 'save_person':
                    if ($name === '') throw new RuntimeException('Der Name darf nicht leer sein.');
                    $pdo->prepare('UPDATE users SET name=?, can_login=?, active=? WHERE id=?')
                        ->execute([$name, isset($_POST['can_login']) ? 1 : 0, isset($_POST['active']) ? 1 : 0, $id]);
                    if (in_array($_POST['avatar'] ?? '', AVATARS, true)) {
                        $pdo->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$_POST['avatar'], $id]);
                    }
                    if (($pw = (string)($_POST['password'] ?? '')) !== '') {
                        if (mb_strlen($pw) < 4) throw new RuntimeException('Das Passwort braucht mindestens 4 Zeichen.');
                        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                    }
                    flash("$name gespeichert.");
                    break;
                case 'add_person':
                    $role = ($_POST['role'] ?? '') === 'parent' ? 'parent' : 'child';
                    $pw = (string)($_POST['password'] ?? '');
                    if ($name === '' || mb_strlen($pw) < 4) throw new RuntimeException('Bitte Name und Passwort (mind. 4 Zeichen) angeben.');
                    $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+1 FROM users')->fetchColumn();
                    $pdo->prepare('INSERT INTO users (name, role, password_hash, sort) VALUES (?,?,?,?)')
                        ->execute([$name, $role, password_hash($pw, PASSWORD_DEFAULT), $sort]);
                    flash("$name angelegt.");
                    break;
                case 'save_task':
                    if ($name === '') throw new RuntimeException('Der Aufgabenname darf nicht leer sein.');
                    $pts = trim((string)($_POST['points'] ?? ''));
                    $pdo->prepare('UPDATE tasks SET name=?, points=?, active=?, sort=? WHERE id=?')
                        ->execute([$name, $pts === '' ? null : max(1, (int)$pts), isset($_POST['active']) ? 1 : 0, (int)($_POST['sort'] ?? 0), $id]);
                    flash('Aufgabe gespeichert.');
                    break;
                case 'add_task':
                    if ($name === '') throw new RuntimeException('Bitte einen Aufgabennamen eingeben.');
                    $pts = trim((string)($_POST['points'] ?? ''));
                    $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+1 FROM tasks')->fetchColumn();
                    $pdo->prepare('INSERT INTO tasks (name, points, sort) VALUES (?,?,?)')
                        ->execute([$name, $pts === '' ? null : max(1, (int)$pts), $sort]);
                    flash('Aufgabe hinzugefügt.');
                    break;
                case 'save_reward':
                case 'add_reward':
                    $cost = (int)($_POST['points_cost'] ?? 0);
                    $unit = trim((string)($_POST['unit_amount'] ?? ''));
                    if ($name === '' || $cost < 1 || $unit === '') throw new RuntimeException('Bitte Name, Punkte und Gegenwert ausfüllen.');
                    if ($a === 'add_reward') {
                        $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+1 FROM rewards')->fetchColumn();
                        $pdo->prepare('INSERT INTO rewards (name, points_cost, unit_amount, sort) VALUES (?,?,?,?)')->execute([$name, $cost, $unit, $sort]);
                    } else {
                        $pdo->prepare('UPDATE rewards SET name=?, points_cost=?, unit_amount=?, active=? WHERE id=?')
                            ->execute([$name, $cost, $unit, isset($_POST['active']) ? 1 : 0, $id]);
                    }
                    flash('Belohnung gespeichert.');
                    break;
                case 'save_matrix':
                    $pdo->beginTransaction();
                    $pdo->exec('DELETE FROM reward_user');
                    $ins = $pdo->prepare('INSERT INTO reward_user (reward_id, user_id, enabled, points_cost) VALUES (?,?,?,?)');
                    foreach ($pdo->query('SELECT id FROM rewards')->fetchAll() as $r) {
                        foreach (children(false) as $k) {
                            $key = $r['id'] . '_' . $k['id'];
                            $own = trim((string)($_POST['cost'][$key] ?? ''));
                            $ins->execute([$r['id'], $k['id'], isset($_POST['on'][$key]) ? 1 : 0, $own === '' ? null : max(1, (int)$own)]);
                        }
                    }
                    $pdo->commit();
                    flash('Belohnungen pro Kind gespeichert.');
                    break;
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash(str_contains($e->getMessage(), 'UNIQUE') ? 'Diesen Namen gibt es schon.' : 'Speichern fehlgeschlagen.', 'err');
        }
        redirect('admin_settings', ['s' => $_POST['s'] ?? 'people']);
    }

    $s = $_GET['s'] ?? 'people';
    page_header('Verwalten', $me);
    ?>
    <h1>Verwalten</h1>
    <nav class="subtabs">
      <a class="<?= $s === 'people' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=people">Personen</a>
      <a class="<?= $s === 'tasks' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=tasks">Aufgaben</a>
      <a class="<?= $s === 'rewards' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=rewards">Belohnungen</a>
    </nav>
    <?php
    if ($s === 'tasks') settings_tasks();
    elseif ($s === 'rewards') settings_rewards();
    else settings_people($me);
    page_footer();
}

function settings_people(array $me): void {
    $people = db()->query("SELECT * FROM users ORDER BY role='child', sort, id")->fetchAll();
    ?>
    <p class="muted">Passwort leer lassen, um es nicht zu ändern. „Darf sich anmelden“ ausschalten für Kinder ohne eigenes Gerät (z. B. Heidi) – für sie tragt ihr Aufgaben unter „Eintragen“ ein.</p>
    <?php foreach ($people as $u): ?>
      <form method="post" class="card setting">
        <?= csrf_field() ?><input type="hidden" name="s" value="people"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <div class="rhead"><b><?= avatar($u) ?> <?= $u['role'] === 'parent' ? 'Elternteil' : 'Kind' ?></b>
          <?php if ($u['role'] === 'child' && !$u['password_hash'] && $u['can_login']): ?><span class="pill pending">noch kein Passwort</span><?php endif; ?></div>
        <div class="grid2">
          <label>Name<input name="name" value="<?= h($u['name']) ?>" required></label>
          <label>Neues Passwort<input name="password" type="text" autocomplete="off" placeholder="unverändert"></label>
          <label>Profilbild<select name="avatar"><?php foreach (AVATARS as $a): ?><option<?= avatar_of($u) === $a ? ' selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select></label>
        </div>
        <?php if ($u['role'] === 'child'): ?>
          <label class="check"><input type="checkbox" name="can_login" <?= $u['can_login'] ? 'checked' : '' ?>> Darf sich anmelden</label>
          <label class="check"><input type="checkbox" name="active" <?= $u['active'] ? 'checked' : '' ?>> Aktiv</label>
        <?php else: ?>
          <input type="hidden" name="can_login" value="1"><input type="hidden" name="active" value="1">
        <?php endif; ?>
        <button class="btn primary" name="action" value="save_person">Speichern</button>
      </form>
    <?php endforeach; ?>
    <form method="post" class="card setting">
      <?= csrf_field() ?><input type="hidden" name="s" value="people">
      <h2>Person hinzufügen</h2>
      <div class="grid2">
        <label>Name<input name="name" required></label>
        <label>Passwort<input name="password" type="text" required autocomplete="off"></label>
      </div>
      <label>Rolle<select name="role"><option value="child">Kind</option><option value="parent">Elternteil (Admin)</option></select></label>
      <button class="btn primary" name="action" value="add_person">Hinzufügen</button>
    </form>
    <?php
}

function settings_tasks(): void {
    $tasks = db()->query('SELECT * FROM tasks ORDER BY sort, id')->fetchAll();
    ?>
    <p class="muted">Punkte leer lassen = „Eltern entscheiden“ (wie bei „Sonstige Aufgabe“). Ausgeblendete Aufgaben sehen die Kinder nicht.
      Das Bild sucht die App anhand des Namens aus (z. B. „Müll“, „Rasen“, „Keller“) – sonst gibt es einen Stern.</p>
    <section class="card">
      <div class="tasktable">
        <div class="tt-head"><span>Reihenf.</span><span>Aufgabe</span><span>Punkte</span><span>Aktiv</span><span></span></div>
        <?php foreach ($tasks as $t): ?>
          <form method="post" class="tt-row">
            <?= csrf_field() ?><input type="hidden" name="s" value="tasks"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input name="sort" type="number" value="<?= (int)$t['sort'] ?>" class="sm" aria-label="Reihenfolge">
            <span class="tt-name"><?= task_img($t['name'], 'ticon mini') ?><input name="name" value="<?= h($t['name']) ?>" required aria-label="Aufgabe"></span>
            <input name="points" type="number" min="1" value="<?= $t['points'] === null ? '' : (int)$t['points'] ?>" placeholder="frei" class="sm" aria-label="Punkte">
            <input type="checkbox" name="active" <?= $t['active'] ? 'checked' : '' ?> aria-label="Aktiv">
            <button class="btn tiny primary" name="action" value="save_task">✓</button>
          </form>
        <?php endforeach; ?>
      </div>
    </section>
    <form method="post" class="card setting">
      <?= csrf_field() ?><input type="hidden" name="s" value="tasks">
      <h2>Neue Aufgabe</h2>
      <div class="grid2">
        <label>Aufgabe<input name="name" required></label>
        <label>Punkte <span class="muted">(leer = frei)</span><input name="points" type="number" min="1"></label>
      </div>
      <button class="btn primary" name="action" value="add_task">Hinzufügen</button>
    </form>
    <?php
}

function settings_rewards(): void {
    $rewards = db()->query('SELECT * FROM rewards ORDER BY sort, id')->fetchAll();
    $kids = children(false);
    $mx = [];
    foreach (db()->query('SELECT * FROM reward_user')->fetchAll() as $r) $mx[$r['reward_id'] . '_' . $r['user_id']] = $r;
    ?>
    <p class="muted">Kurs = wie viele Punkte eine Einheit kostet. Die Bildschirmzeit selbst stellt ihr weiter am iPhone bzw. an der PlayStation ein.</p>
    <?php foreach ($rewards as $r): ?>
      <form method="post" class="card setting">
        <?= csrf_field() ?><input type="hidden" name="s" value="rewards"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="grid3">
          <label>Belohnung<input name="name" value="<?= h($r['name']) ?>" required></label>
          <label>Punkte<input name="points_cost" type="number" min="1" value="<?= (int)$r['points_cost'] ?>" required></label>
          <label>Gegenwert<input name="unit_amount" value="<?= h($r['unit_amount']) ?>" required></label>
        </div>
        <label class="check"><input type="checkbox" name="active" <?= $r['active'] ? 'checked' : '' ?>> Aktiv</label>
        <button class="btn primary" name="action" value="save_reward">Speichern</button>
      </form>
    <?php endforeach; ?>
    <form method="post" class="card setting">
      <?= csrf_field() ?><input type="hidden" name="s" value="rewards">
      <h2>Neue Belohnung</h2>
      <div class="grid3">
        <label>Belohnung<input name="name" required placeholder="z. B. Kinoabend"></label>
        <label>Punkte<input name="points_cost" type="number" min="1" required></label>
        <label>Gegenwert<input name="unit_amount" required placeholder="z. B. 1 Ticket"></label>
      </div>
      <button class="btn primary" name="action" value="add_reward">Hinzufügen</button>
    </form>

    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="s" value="rewards">
      <h2>Belohnungen pro Kind</h2>
      <p class="muted">Haken = Kind sieht die Belohnung. Eigener Kurs leer = Standardkurs.</p>
      <div class="scroll">
      <table class="tbl matrix">
        <thead><tr><th></th><?php foreach ($rewards as $r): ?><th><?= h($r['name']) ?><br><span class="muted"><?= (int)$r['points_cost'] ?> P. = <?= h($r['unit_amount']) ?></span></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($kids as $k): ?>
          <tr><td><b><?= h($k['name']) ?></b></td>
          <?php foreach ($rewards as $r): $key = $r['id'] . '_' . $k['id']; $m = $mx[$key] ?? null; ?>
            <td><label class="cell"><input type="checkbox" name="on[<?= $key ?>]" <?= !$m || $m['enabled'] ? 'checked' : '' ?>>
              <input type="number" min="1" name="cost[<?= $key ?>]" value="<?= $m && $m['points_cost'] ? (int)$m['points_cost'] : '' ?>" placeholder="<?= (int)$r['points_cost'] ?>" class="sm" aria-label="Eigener Kurs"></label></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <button class="btn primary" name="action" value="save_matrix">Speichern</button>
    </form>
    <?php
}
