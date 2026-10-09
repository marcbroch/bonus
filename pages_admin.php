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
    <?php render_week_board('admin'); ?>
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
        <div class="rhead tline"><?= task_img($s['task_name'], 'ticon', $s['task_id'] === null ? null : (int)$s['task_id']) ?><h2 class="grow"><?= h($s['child']) ?>: <?= h($s['task_name']) ?></h2></div>
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
            $photo = photo_from_request();
            if (!$photo) throw new RuntimeException('Bitte ein Foto als Nachweis machen oder auswählen.');
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
        <?php photo_picker(); ?>
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
            <?php else: ?><?= task_img($s['task_name'], 'ticon', $s['task_id'] === null ? null : (int)$s['task_id']) ?><?php endif; ?>
            <div class="grow"><b><?= h($s['child']) ?>:</b> <?= h($s['task_name']) ?><br><span class="muted"><?= fmt_date($s['created_at']) ?></span>
              <?php if ($s['comment']): ?><br><span class="comment">„<?= h($s['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill <?= h($s['status']) ?>"><?= $s['status'] === 'approved' ? '+' . (int)$s['points'] : status_label($s['status']) ?></span>
            <?php if ($s['status'] === 'approved'): ?>
              <a class="btn tiny ghost" href="index.php?p=admin_settings&s=entries&u=<?= (int)$s['user_id'] ?>#sub<?= (int)$s['id'] ?>" title="Ändern oder entfernen" aria-label="Ändern oder entfernen">✏️</a>
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
                    [$icon, $kw] = task_icon_keyword($name);
                    $pdo->prepare('UPDATE tasks SET name=?, keyword=?, icon=?, points=?, active=?, sort=? WHERE id=?')
                        ->execute([$name, $kw, $icon, $pts === '' ? null : max(1, (int)$pts), isset($_POST['active']) ? 1 : 0, (int)($_POST['sort'] ?? 0), $id]);
                    flash('Aufgabe gespeichert.');
                    break;
                case 'add_task':
                    if ($name === '') throw new RuntimeException('Bitte einen Aufgabennamen eingeben.');
                    $pts = trim((string)($_POST['points'] ?? ''));
                    $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+1 FROM tasks')->fetchColumn();
                    [$icon, $kw] = task_icon_keyword($name);
                    $pdo->prepare('INSERT INTO tasks (name, keyword, icon, points, sort) VALUES (?,?,?,?,?)')
                        ->execute([$name, $kw, $icon, $pts === '' ? null : max(1, (int)$pts), $sort]);
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
                case 'edit_sub':
                    $row = approved_row('submissions', $id);
                    $pts = (int)($_POST['points'] ?? -1);
                    if ($pts < 0 || $pts > 1000) throw new RuntimeException('Bitte eine Punktzahl zwischen 0 und 1000 eintragen.');
                    $taskId = (int)($_POST['task_id'] ?? 0);
                    $taskName = $row['task_name'];
                    if ($taskId && $taskId !== (int)$row['task_id']) {
                        $st = $pdo->prepare('SELECT name FROM tasks WHERE id=?');
                        $st->execute([$taskId]);
                        $taskName = $st->fetchColumn();
                        if ($taskName === false) throw new RuntimeException('Diese Aufgabe gibt es nicht.');
                    } else {
                        $taskId = $row['task_id'] === null ? null : (int)$row['task_id'];
                    }
                    $comment = trim((string)($_POST['comment'] ?? '')) ?: null;
                    $pdo->prepare('UPDATE submissions SET task_id=?, task_name=?, points=?, comment=? WHERE id=?')
                        ->execute([$taskId, $taskName, $pts, $comment, $id]);
                    flash('Eintrag geändert.');
                    break;
                case 'delete_sub':
                    $row = approved_row('submissions', $id);
                    $pdo->prepare('DELETE FROM submissions WHERE id=?')->execute([$id]);
                    // Foto mit entfernen (nur Dateien aus uploads/ im erwarteten Format)
                    if ($row['photo'] && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.(jpg|png|webp|gif)$#', $row['photo'])) {
                        @unlink(UPLOAD_DIR . '/' . $row['photo']);
                    }
                    flash('Eintrag entfernt: ' . $row['task_name'] . '.');
                    break;
                case 'edit_red':
                    $row = approved_row('redemptions', $id);
                    $qty = (int)($_POST['qty'] ?? 0);
                    if ($qty < 1 || $qty > 50) throw new RuntimeException('Bitte eine Anzahl zwischen 1 und 50 eintragen.');
                    $unitPts = intdiv((int)$row['points'], max(1, (int)$row['quantity']));
                    $unit = preg_replace('/^\d+\s*×\s*/u', '', (string)$row['amount_text']);
                    $newPts = $unitPts * $qty;
                    if (balance((int)$row['user_id']) + (int)$row['points'] - $newPts < 0) {
                        throw new RuntimeException('Dafür reichen die Punkte des Kindes nicht.');
                    }
                    $comment = trim((string)($_POST['comment'] ?? '')) ?: null;
                    $pdo->prepare('UPDATE redemptions SET quantity=?, amount_text=?, points=?, comment=? WHERE id=?')
                        ->execute([$qty, $qty . ' × ' . $unit, $newPts, $comment, $id]);
                    flash('Auszahlung geändert.');
                    break;
                case 'delete_red':
                    $row = approved_row('redemptions', $id);
                    $pdo->prepare('DELETE FROM redemptions WHERE id=?')->execute([$id]);
                    flash('Auszahlung entfernt, ' . (int)$row['points'] . ' Punkte sind zurück auf dem Konto.');
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
        redirect('admin_settings', array_filter(['s' => $_POST['s'] ?? 'people', 'u' => (int)($_POST['u'] ?? 0)]));
    }

    $s = $_GET['s'] ?? 'people';
    page_header('Verwalten', $me);
    ?>
    <h1>Verwalten</h1>
    <nav class="subtabs">
      <a class="<?= $s === 'people' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=people">Personen</a>
      <a class="<?= $s === 'tasks' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=tasks">Aufgaben</a>
      <a class="<?= $s === 'rewards' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=rewards">Belohnungen</a>
      <a class="<?= $s === 'entries' ? 'on' : '' ?>" href="index.php?p=admin_settings&s=entries">Bestätigt</a>
    </nav>
    <?php
    if ($s === 'tasks') settings_tasks();
    elseif ($s === 'rewards') settings_rewards();
    elseif ($s === 'entries') settings_entries();
    else settings_people($me);
    page_footer();
}

function settings_people(array $me): void {
    $people = db()->query("SELECT * FROM users ORDER BY role='child', sort, id")->fetchAll();
    ?>
    <p class="muted">Hier legt ihr Passwörter für andere fest, z. B. wenn ein Kind seins vergessen hat. Sein eigenes Passwort ändert jeder selbst über „🔑 Passwort“ oben.
      Passwort leer lassen, um es nicht zu ändern. „Darf sich anmelden“ ausschalten für Kinder ohne eigenes Gerät (z. B. Heidi) – für sie tragt ihr Aufgaben unter „Eintragen“ ein.</p>
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

// Stichwort und Bild aus dem Formular, leer = automatisch vorschlagen
function task_icon_keyword(string $name): array {
    $icon = (string)($_POST['icon'] ?? '');
    if (!isset(TASK_ICONS[$icon])) $icon = guess_icon($name);
    $kw = trim((string)($_POST['keyword'] ?? ''));
    return [$icon, $kw !== '' ? mb_substr($kw, 0, 30) : guess_keyword($name, $icon)];
}

function icon_select(string $current): string {
    $out = '<select name="icon" class="js-icon-select" aria-label="Bild">';
    foreach (TASK_ICONS as $key => $label) {
        $out .= '<option value="' . h($key) . '"' . ($key === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out . '</select>';
}

function settings_tasks(): void {
    $tasks = db()->query('SELECT * FROM tasks ORDER BY sort, id')->fetchAll();
    ?>
    <p class="muted">Jede Aufgabe hat ein <b>Stichwort</b> (groß auf der Kachel) und ein <b>Bild</b>. Punkte leer lassen = „Eltern entscheiden“.
      Ausgeblendete Aufgaben sehen die Kinder nicht.</p>
    <script>window.TASK_ICON_SRC = <?= json_encode(array_combine(array_keys(TASK_ICONS), array_map('icon_src', array_keys(TASK_ICONS)))) ?>;</script>
    <?php foreach ($tasks as $t): ?>
      <form method="post" class="card setting taskedit">
        <?= csrf_field() ?><input type="hidden" name="s" value="tasks"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <div class="te-top">
          <?= icon_img($t['icon'] ?? guess_icon($t['name']), 'ticon big js-icon-preview') ?>
          <div class="grow">
            <label>Stichwort<input name="keyword" value="<?= h($t['keyword'] ?? '') ?>" maxlength="30" required></label>
            <label>Bild<?= icon_select((string)($t['icon'] ?? guess_icon($t['name']))) ?></label>
          </div>
        </div>
        <label>Beschreibung<input name="name" value="<?= h($t['name']) ?>" required></label>
        <div class="grid3">
          <label>Punkte<input name="points" type="number" min="1" value="<?= $t['points'] === null ? '' : (int)$t['points'] ?>" placeholder="frei" inputmode="numeric"></label>
          <label>Reihenfolge<input name="sort" type="number" value="<?= (int)$t['sort'] ?>" inputmode="numeric"></label>
          <label class="check te-active"><input type="checkbox" name="active" <?= $t['active'] ? 'checked' : '' ?>> Aktiv</label>
        </div>
        <button class="btn primary" name="action" value="save_task">Speichern</button>
      </form>
    <?php endforeach; ?>
    <form method="post" class="card setting taskedit">
      <?= csrf_field() ?><input type="hidden" name="s" value="tasks">
      <h2>Neue Aufgabe</h2>
      <div class="te-top">
        <?= icon_img('sonstige', 'ticon big js-icon-preview') ?>
        <div class="grow">
          <label>Stichwort <span class="muted">(leer = Vorschlag)</span><input name="keyword" maxlength="30" placeholder="z. B. Fenster"></label>
          <label>Bild<select name="icon" class="js-icon-select" aria-label="Bild"><option value="">automatisch wählen</option>
            <?php foreach (TASK_ICONS as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
        </div>
      </div>
      <div class="grid2">
        <label>Beschreibung<input name="name" required placeholder="z. B. Fenster putzen"></label>
        <label>Punkte <span class="muted">(leer = frei)</span><input name="points" type="number" min="1" inputmode="numeric"></label>
      </div>
      <button class="btn primary" name="action" value="add_task">Hinzufügen</button>
    </form>
    <?php
}

// Bestätigten Eintrag laden (nur bestätigte dürfen hier geändert werden)
function approved_row(string $table, int $id): array {
    $st = db()->prepare("SELECT * FROM $table WHERE id=? AND status='approved'");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Diesen Eintrag gibt es nicht mehr.');
    return $row;
}

// Verwalten > Bestätigt: bestätigte Aufgaben und Auszahlungen ändern oder entfernen
function settings_entries(): void {
    $pdo = db();
    $kids = children(false);
    $u = (int)($_GET['u'] ?? 0);
    $limit = min(500, max(30, (int)($_GET['n'] ?? 30)));
    $where = $u ? ' AND x.user_id=' . $u : '';
    $subs = $pdo->query("SELECT x.*, k.name AS child, r.name AS reviewer FROM submissions x JOIN users k ON k.id=x.user_id
                         LEFT JOIN users r ON r.id=x.reviewed_by WHERE x.status='approved'$where")->fetchAll();
    $reds = $pdo->query("SELECT x.*, k.name AS child, r.name AS reviewer FROM redemptions x JOIN users k ON k.id=x.user_id
                         LEFT JOIN users r ON r.id=x.reviewed_by WHERE x.status='approved'$where")->fetchAll();
    $rows = [];
    foreach ($subs as $x) $rows[] = ['kind' => 'sub', 'row' => $x];
    foreach ($reds as $x) $rows[] = ['kind' => 'red', 'row' => $x];
    usort($rows, fn($a, $b) => strcmp($b['row']['created_at'], $a['row']['created_at']));
    $total = count($rows);
    $rows = array_slice($rows, 0, $limit);
    $tasks = $pdo->query('SELECT id, name FROM tasks ORDER BY active DESC, sort, id')->fetchAll();
    $bal = [];
    foreach ($kids as $k) $bal[(int)$k['id']] = balance((int)$k['id']);
    ?>
    <p class="muted">Hier könnt ihr bereits bestätigte Aufgaben und Auszahlungen nachträglich ändern oder entfernen.
      Punktestand, Wochenwertung und Sticker passen sich automatisch an.</p>
    <nav class="subtabs kidfilter">
      <a class="<?= $u ? '' : 'on' ?>" href="index.php?p=admin_settings&s=entries">Alle</a>
      <?php foreach ($kids as $k): ?>
        <a class="<?= $u === (int)$k['id'] ? 'on' : '' ?>" href="index.php?p=admin_settings&s=entries&u=<?= (int)$k['id'] ?>"><?= avatar($k) ?> <?= h($k['name']) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if (!$rows): ?><p class="card muted">Noch keine bestätigten Einträge.</p><?php endif; ?>
    <?php foreach ($rows as $e): $x = $e['row']; $id = (int)$x['id']; $kb = $bal[(int)$x['user_id']] ?? 0; ?>
      <?php if ($e['kind'] === 'sub'): ?>
        <section class="card entry" id="sub<?= $id ?>">
          <div class="entry-head">
            <?php if ($x['photo']): ?><a href="<?= h(photo_url($x['photo'])) ?>" target="_blank" class="tpic"><?= task_img((string)$x['task_name'], 'ticon', $x['task_id'] === null ? null : (int)$x['task_id']) ?><img class="thumb mini" src="<?= h(photo_url($x['photo'])) ?>" alt="Foto" loading="lazy"></a>
            <?php else: ?><?= task_img((string)$x['task_name'], 'ticon', $x['task_id'] === null ? null : (int)$x['task_id']) ?><?php endif; ?>
            <div class="grow"><b><?= h($x['child']) ?>:</b> <?= h($x['task_name']) ?><br>
              <span class="muted"><?= fmt_date($x['created_at']) ?><?= $x['reviewer'] ? ' · bestätigt von ' . h($x['reviewer']) : '' ?></span>
              <?php if ($x['comment']): ?><br><span class="comment">„<?= h($x['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill approved">+<?= (int)$x['points'] ?></span>
          </div>
          <details>
            <summary>✏️ Ändern oder entfernen</summary>
            <form method="post" class="entry-form">
              <?= csrf_field() ?><input type="hidden" name="s" value="entries"><input type="hidden" name="u" value="<?= $u ?: '' ?>"><input type="hidden" name="id" value="<?= $id ?>">
              <label>Aufgabe<select name="task_id">
                <?php if ($x['task_id'] === null): ?><option value="0" selected><?= h($x['task_name']) ?></option><?php endif; ?>
                <?php foreach ($tasks as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$t['id'] === (int)$x['task_id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
              </select></label>
              <div class="grid2">
                <label>Punkte<input name="points" type="number" min="0" max="1000" value="<?= (int)$x['points'] ?>" required inputmode="numeric"></label>
                <label>Kommentar<input name="comment" value="<?= h($x['comment']) ?>" maxlength="200" placeholder="optional"></label>
              </div>
              <div class="row">
                <button class="btn primary" name="action" value="edit_sub">Speichern</button>
                <button class="btn danger" name="action" value="delete_sub" formnovalidate
                  onclick="return confirm(<?= h(json_encode('„' . $x['task_name'] . '“ von ' . $x['child'] . ' wirklich entfernen? ' . (int)$x['points'] . ' Punkte werden abgezogen, das Foto wird gelöscht.' . ($kb - (int)$x['points'] < 0 ? ' Achtung: Der Punktestand wäre danach ' . ($kb - (int)$x['points']) . '.' : ''), JSON_UNESCAPED_UNICODE)) ?>)">🗑️ Entfernen</button>
              </div>
            </form>
          </details>
        </section>
      <?php else: ?>
        <section class="card entry" id="red<?= $id ?>">
          <div class="entry-head">
            <span class="gift" aria-hidden="true">🎁</span>
            <div class="grow"><b><?= h($x['child']) ?>:</b> <?= h($x['amount_text']) ?> <?= h($x['reward_name']) ?><br>
              <span class="muted"><?= fmt_date($x['created_at']) ?><?= $x['reviewer'] ? ' · ausgezahlt von ' . h($x['reviewer']) : '' ?></span>
              <?php if ($x['comment']): ?><br><span class="comment">„<?= h($x['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill">−<?= (int)$x['points'] ?></span>
          </div>
          <details>
            <summary>✏️ Ändern oder entfernen</summary>
            <form method="post" class="entry-form">
              <?= csrf_field() ?><input type="hidden" name="s" value="entries"><input type="hidden" name="u" value="<?= $u ?: '' ?>"><input type="hidden" name="id" value="<?= $id ?>">
              <div class="grid2">
                <label>Anzahl <span class="muted">(je <?= intdiv((int)$x['points'], max(1, (int)$x['quantity'])) ?> P.)</span>
                  <input name="qty" type="number" min="1" max="50" value="<?= (int)$x['quantity'] ?>" required inputmode="numeric"></label>
                <label>Wie ausgezahlt?<input name="comment" list="payout-ways" value="<?= h($x['comment']) ?>" maxlength="200" placeholder="optional"></label>
              </div>
              <div class="row">
                <button class="btn primary" name="action" value="edit_red">Speichern</button>
                <button class="btn danger" name="action" value="delete_red" formnovalidate
                  onclick="return confirm(<?= h(json_encode('Auszahlung „' . $x['amount_text'] . ' ' . $x['reward_name'] . '“ von ' . $x['child'] . ' wirklich entfernen? Die ' . (int)$x['points'] . ' Punkte kommen zurück aufs Konto.', JSON_UNESCAPED_UNICODE)) ?>)">🗑️ Entfernen</button>
              </div>
            </form>
          </details>
        </section>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($total > $limit): ?>
      <a class="btn block" href="index.php?p=admin_settings&s=entries<?= $u ? '&u=' . $u : '' ?>&n=<?= $limit + 50 ?>">Ältere Einträge zeigen (<?= $total - $limit ?> weitere)</a>
    <?php endif; ?>
    <datalist id="payout-ways">
      <option value="Bar ausgezahlt"><option value="Aufs Sparbuch"><option value="Aufs Kinderkonto überwiesen">
      <option value="Bildschirmzeit am iPhone freigeschaltet"><option value="PlayStation-Zeit freigeschaltet">
    </datalist>
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
