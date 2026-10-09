<?php
// Seiten für die Kinder
declare(strict_types=1);

function child_user(): array {
    $u = require_login();
    if ($u['role'] === 'parent') redirect('admin');
    return $u;
}

function page_home(): void {
    $u = child_user();
    $bal = balance((int)$u['id']);
    $st = db()->prepare("SELECT * FROM submissions WHERE user_id=? ORDER BY id DESC LIMIT 5");
    $st->execute([$u['id']]);
    $recent = $st->fetchAll();
    $rw = rewards_for((int)$u['id']);
    page_header('Start', $u);
    ?>
    <section class="hero">
      <?= avatar($u, 'big') ?>
      <div class="label">Hallo <?= h($u['name']) ?>, du hast</div>
      <div class="big"><?= $bal ?> <small>Punkte</small></div>
      <?php if ($rw && $bal >= min(array_map(fn($r) => (int)$r['cost'], $rw))): ?>
        <div class="worth">
          <?php foreach ($rw as $r): $n = intdiv(max($bal, 0), (int)$r['cost']); if ($n < 1) continue; ?>
            <span>reicht für <?= $n ?> × <?= h($r['unit_amount']) ?> <?= h($r['name']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <a class="btn primary block" href="index.php?p=submit">＋ Aufgabe erledigt</a>
    <section class="card">
      <h2>🧹 Zuletzt gemeldet</h2>
      <?php if (!$recent): ?><p class="muted">Noch nichts gemeldet. Los geht’s!</p><?php endif; ?>
      <ul class="list">
        <?php foreach ($recent as $s): ?>
          <li>
            <?= task_img($s['task_name']) ?>
            <div><b><?= h($s['task_name']) ?></b><br><span class="muted"><?= fmt_date($s['created_at']) ?></span>
              <?php if ($s['comment']): ?><br><span class="comment">„<?= h($s['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill <?= h($s['status']) ?>"><?= $s['status'] === 'approved' ? '+' . (int)$s['points'] : status_label($s['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($recent): ?><p><a href="index.php?p=account">Alles ansehen in „Mein Konto“ →</a></p><?php endif; ?>
    </section>
    <?php
    page_footer();
}

function page_submit(): void {
    $u = child_user();
    $tasks = db()->query('SELECT * FROM tasks WHERE active=1 ORDER BY sort, id')->fetchAll();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $st = db()->prepare('SELECT * FROM tasks WHERE id=? AND active=1');
        $st->execute([(int)($_POST['task_id'] ?? 0)]);
        $task = $st->fetch();
        $note = trim((string)($_POST['note'] ?? ''));
        try {
            if (!$task) throw new RuntimeException('Bitte eine Aufgabe auswählen.');
            if ($task['points'] === null && $note === '') throw new RuntimeException('Bitte kurz beschreiben, was du gemacht hast.');
            $photo = save_photo($_FILES['photo'] ?? []);
            if (!$photo) throw new RuntimeException('Bitte ein Foto als Nachweis machen.');
            $ins = db()->prepare('INSERT INTO submissions (user_id, task_id, task_name, note, photo, points, created_at) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$u['id'], $task['id'], $task['name'], $note ?: null, $photo, (int)($task['points'] ?? 0), now()]);
            flash('Super! Deine Aufgabe wartet jetzt auf die Freigabe.');
            redirect('home');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
    page_header('Aufgabe melden', $u);
    ?>
    <section class="card">
      <h1>Was hast du erledigt? 💪</h1>
      <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="js-photo-form">
        <?= csrf_field() ?>
        <div class="taskpick tiles">
          <?php foreach ($tasks as $t): ?>
            <label class="task">
              <input type="radio" name="task_id" value="<?= (int)$t['id'] ?>" required data-free="<?= $t['points'] === null ? 1 : 0 ?>">
              <?= task_img($t['name'], 'ticon big') ?>
              <span class="tname"><?= h($t['name']) ?></span>
              <span class="tpts"><?= $t['points'] === null ? 'Eltern entscheiden' : (int)$t['points'] . ' P.' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <label class="photo-drop">
          <input type="file" name="photo" accept="image/*" capture="environment" required>
          <span class="photo-hint">📷 Foto machen</span>
          <img class="photo-preview" alt="" hidden>
        </label>
        <label>Kurze Notiz <span class="muted js-note-hint">(optional)</span>
          <textarea name="note" rows="2" maxlength="300" placeholder="z. B. alle drei Mülltonnen"></textarea></label>
        <button class="btn primary block">Einreichen</button>
      </form>
    </section>
    <?php
    page_footer();
}

function page_rewards(): void {
    $u = child_user();
    $uid = (int)$u['id'];
    $rewards = rewards_for($uid);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $rid = (int)($_POST['reward_id'] ?? 0);
        $qty = max(1, min(20, (int)($_POST['qty'] ?? 1)));
        $r = null;
        foreach ($rewards as $x) if ((int)$x['id'] === $rid) $r = $x;
        if (!$r) { flash('Diese Belohnung gibt es nicht.', 'err'); redirect('rewards'); }
        $cost = (int)$r['cost'] * $qty;
        db()->beginTransaction();
        if (balance($uid) < $cost) {
            db()->rollBack();
            flash('Dafür reichen deine Punkte noch nicht.', 'err');
            redirect('rewards');
        }
        $amount = $qty . ' × ' . $r['unit_amount'];
        $ins = db()->prepare('INSERT INTO redemptions (user_id, reward_id, reward_name, quantity, amount_text, points, created_at) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([$uid, $r['id'], $r['name'], $qty, $amount, $cost, now()]);
        db()->commit();
        flash('Eingelöst! Mama oder Papa bestätigen das gleich.');
        redirect('rewards');
    }
    $bal = balance($uid);
    $st = db()->prepare('SELECT * FROM redemptions WHERE user_id=? ORDER BY id DESC LIMIT 10');
    $st->execute([$uid]);
    $done = $st->fetchAll();
    page_header('Einlösen', $u);
    ?>
    <section class="hero small"><div class="label">Dein Punktestand</div><div class="big"><?= $bal ?> <small>Punkte</small></div></section>
    <?php if (!$rewards): ?><p class="card muted">Für dich sind gerade keine Belohnungen freigeschaltet.</p><?php endif; ?>
    <?php foreach ($rewards as $r): $max = intdiv(max($bal, 0), (int)$r['cost']); ?>
      <section class="card reward">
        <div class="rhead"><h2><?= h($r['name']) ?></h2><span class="rate"><?= (int)$r['cost'] ?> P. = <?= h($r['unit_amount']) ?></span></div>
        <?php if ($max < 1): ?>
          <p class="muted">Noch <?= (int)$r['cost'] - max($bal, 0) ?> Punkte bis zur ersten Einheit.</p>
          <div class="progress"><span style="width:<?= min(100, (int)round(max($bal, 0) / (int)$r['cost'] * 100)) ?>%"></span></div>
        <?php else: ?>
          <form method="post" class="redeem">
            <?= csrf_field() ?>
            <input type="hidden" name="reward_id" value="<?= (int)$r['id'] ?>">
            <label>Anzahl <select name="qty"><?php for ($i = 1; $i <= min($max, 20); $i++): ?><option value="<?= $i ?>"><?= $i ?> × <?= h($r['unit_amount']) ?> (<?= $i * (int)$r['cost'] ?> P.)</option><?php endfor; ?></select></label>
            <button class="btn primary">Einlösen</button>
          </form>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
    <?php if ($done): ?>
    <section class="card">
      <h2>🎁 Deine Einlösungen</h2>
      <ul class="list">
        <?php foreach ($done as $d): ?>
          <li><div><b><?= h($d['amount_text']) ?> <?= h($d['reward_name']) ?></b><br><span class="muted"><?= fmt_date($d['created_at']) ?> · <?= (int)$d['points'] ?> P.</span>
            <?php if ($d['comment']): ?><br><span class="comment">„<?= h($d['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill <?= h($d['status']) ?>"><?= status_label($d['status']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <?php
    page_footer();
}

// Konto eines Kindes: Kontoauszug mit allen Buchungen und ausgezahlten Belohnungen.
// Kinder sehen ihr eigenes Konto, Eltern das Konto jedes Kindes (?u=ID).
function page_account(): void {
    $me = require_login();
    if ($me['role'] === 'parent') {
        $st = db()->prepare("SELECT * FROM users WHERE id=? AND role='child'");
        $st->execute([(int)($_GET['u'] ?? $_POST['u'] ?? 0)]);
        $kid = $st->fetch();
        if (!$kid) redirect('admin_overview');
    } else {
        $kid = $me;
    }
    $uid = (int)$kid['id'];
    $own = $me['role'] === 'child';
    $back = $own ? [] : ['u' => $uid];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $av = (string)($_POST['avatar'] ?? '');
        if (in_array($av, AVATARS, true)) {
            db()->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$av, $uid]);
            flash($own ? "Dein neues Profilbild: $av" : "Neues Profilbild für {$kid['name']}: $av");
        }
        redirect('account', $back);
    }
    $entries = account_entries($uid);
    $earned = array_sum(array_map(fn($e) => $e['kind'] === 'task' ? $e['delta'] : 0, $entries));
    $spent = -array_sum(array_map(fn($e) => $e['kind'] === 'reward' ? $e['delta'] : 0, $entries));
    $tasksDone = count(array_filter($entries, fn($e) => $e['kind'] === 'task' && $e['row']['status'] === 'approved'));
    $payouts = payout_totals($uid);
    page_header($own ? 'Mein Konto' : 'Konto ' . $kid['name'], $me);
    ?>
    <?php if (!$own): ?><p><a href="index.php?p=admin_overview">← zur Übersicht</a></p><?php endif; ?>
    <section class="hero small">
      <?= avatar($kid, 'big') ?>
      <div class="label"><?= $own ? 'Dein Konto' : 'Konto von ' . h($kid['name']) ?></div>
      <div class="big"><?= balance($uid) ?> <small>Punkte</small></div>
    </section>

    <div class="stats">
      <div class="stat"><b>+<?= $earned ?></b><span>gesammelt</span></div>
      <div class="stat"><b>−<?= $spent ?></b><span>eingelöst</span></div>
      <div class="stat"><b><?= $tasksDone ?></b><span>Aufgaben</span></div>
    </div>

    <section class="card">
      <h2>🎁 Das wurde schon ausgezahlt</h2>
      <?php if (!$payouts): ?><p class="muted">Noch keine Belohnung ausgezahlt.</p><?php endif; ?>
      <ul class="list">
        <?php foreach ($payouts as $p): ?>
          <li><span class="gift" aria-hidden="true">🎁</span>
            <div><b><?= h($p['text']) ?></b> <?= h($p['name']) ?><br><span class="muted"><?= $p['count'] ?> × eingelöst</span></div></li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="card">
      <h2>📒 Kontoauszug</h2>
      <?php if (!$entries): ?><p class="muted">Noch keine Buchungen. Melde deine erste Aufgabe!</p><?php endif; ?>
      <ul class="list ledger">
        <?php foreach ($entries as $e): $r = $e['row']; ?>
          <li class="<?= $e['delta'] === 0 ? 'zero' : '' ?>">
            <?php if ($e['kind'] === 'task'): ?>
              <?php if ($r['photo']): ?><a href="<?= h(photo_url($r['photo'])) ?>" target="_blank" class="tpic"><?= task_img($e['title']) ?><img class="thumb mini" src="<?= h(photo_url($r['photo'])) ?>" alt="Foto" loading="lazy"></a>
              <?php else: ?><?= task_img($e['title']) ?><?php endif; ?>
            <?php else: ?>
              <span class="gift" aria-hidden="true">🎁</span>
            <?php endif; ?>
            <div class="grow"><b><?= h($e['title']) ?></b><br>
              <span class="muted"><?= fmt_date($e['date']) ?><br><?= ledger_status($e) ?></span>
              <?php if ($r['comment']): ?><br><span class="comment">„<?= h($r['comment']) ?>“</span><?php endif; ?></div>
            <div class="amt">
              <?php if ($e['kind'] === 'task' && $r['status'] === 'pending'): ?>
                <span class="delta wait"><?= $r['points'] ? '+' . (int)$r['points'] : '+?' ?></span><span class="muted">offen</span>
              <?php else: ?>
                <span class="delta <?= $e['delta'] > 0 ? 'plus' : ($e['delta'] < 0 ? 'minus' : '') ?>"><?= $e['delta'] > 0 ? '+' . $e['delta'] : ($e['delta'] < 0 ? '−' . -$e['delta'] : '±0') ?></span>
                <span class="muted">= <?= $e['balance'] ?></span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <details class="card avatars">
      <summary><h2><?= avatar($kid) ?> <?= $own ? 'Profilbild ändern' : 'Profilbild für ' . h($kid['name']) ?></h2></summary>
      <form method="post" class="avatar-pick">
        <?= csrf_field() ?><?php if (!$own): ?><input type="hidden" name="u" value="<?= $uid ?>"><?php endif; ?>
        <?php foreach (AVATARS as $a): ?>
          <button name="avatar" value="<?= $a ?>" class="<?= avatar_of($kid) === $a ? 'on' : '' ?>" aria-label="Profilbild <?= $a ?>"><?= $a ?></button>
        <?php endforeach; ?>
      </form>
    </details>
    <?php
    page_footer();
}

function ledger_status(array $e): string {
    $r = $e['row'];
    $who = $r['reviewer'] ? ' von ' . h($r['reviewer']) : '';
    if ($e['kind'] === 'task') {
        return ['pending' => 'wartet auf Freigabe', 'approved' => 'gutgeschrieben' . $who,
                'rejected' => 'nicht gutgeschrieben'][$r['status']] ?? '';
    }
    return ['pending' => 'Punkte reserviert, wartet auf Auszahlung',
            'approved' => 'ausgezahlt' . ($r['reviewed_at'] ? ' am ' . date('d.m.Y', strtotime($r['reviewed_at'])) : '') . $who,
            'rejected' => 'abgelehnt, Punkte zurück'][$r['status']] ?? '';
}
