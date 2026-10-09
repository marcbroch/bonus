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
            <div><b><?= h($s['task_name']) ?></b><br><span class="muted"><?= fmt_date($s['created_at']) ?></span>
              <?php if ($s['comment']): ?><br><span class="comment">„<?= h($s['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill <?= h($s['status']) ?>"><?= $s['status'] === 'approved' ? '+' . (int)$s['points'] : status_label($s['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
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
        <div class="taskpick">
          <?php foreach ($tasks as $t): ?>
            <label class="task">
              <input type="radio" name="task_id" value="<?= (int)$t['id'] ?>" required data-free="<?= $t['points'] === null ? 1 : 0 ?>">
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

function page_history(): void {
    $u = child_user();
    $st = db()->prepare('SELECT * FROM submissions WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $st->execute([$u['id']]);
    $rows = $st->fetchAll();
    page_header('Verlauf', $u);
    ?>
    <section class="card">
      <h1>🏆 Deine Aufgaben</h1>
      <?php if (!$rows): ?><p class="muted">Noch keine Aufgaben gemeldet.</p><?php endif; ?>
      <ul class="list">
        <?php foreach ($rows as $s): ?>
          <li>
            <?php if ($s['photo']): ?><a href="<?= h(photo_url($s['photo'])) ?>" target="_blank"><img class="thumb" src="<?= h(photo_url($s['photo'])) ?>" alt="" loading="lazy"></a><?php endif; ?>
            <div class="grow"><b><?= h($s['task_name']) ?></b><br><span class="muted"><?= fmt_date($s['created_at']) ?></span>
              <?php if ($s['comment']): ?><br><span class="comment">„<?= h($s['comment']) ?>“</span><?php endif; ?></div>
            <span class="pill <?= h($s['status']) ?>"><?= $s['status'] === 'approved' ? '+' . (int)$s['points'] : status_label($s['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php
    page_footer();
}
