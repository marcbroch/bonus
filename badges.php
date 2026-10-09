<?php
// Sammelalbum (Sticker) und Konfetti-Moment für die Kinder
declare(strict_types=1);

// Welche Aufgabenbilder zu welchem Spezialisten-Sticker zählen
const BADGE_SPECIALISTS = [
    'muell'    => ['Müll-Meister', ['muell'], 'muell', '10× Müll rausgebracht'],
    'waesche'  => ['Wäsche-Profi', ['waesche-runter', 'waesche-hoch'], 'waesche-hoch', '10× Wäsche getragen'],
    'geschirr' => ['Spülmaschinen-Held', ['geschirr'], 'geschirr', '10× Spülmaschine ausgeräumt'],
    'rasen'    => ['Rasen-Champion', ['rasen'], 'rasen', '10× Rasen gemäht'],
    'garten'   => ['Garten-Zwerg', ['garten', 'beete', 'hecke'], 'beete', '10× im Garten geholfen'],
    'ordnung'  => ['Ordnungs-Ass', ['zimmer', 'regal', 'keller', 'schuppen', 'carport', 'treppe'], 'zimmer', '10× aufgeräumt'],
    'auto'     => ['Glanz-Macher', ['auto'], 'auto', '10× Auto gesaugt'],
];

// Alle Sticker: Schlüssel, Name, Stufe/Farbe, Symbol (Emoji oder Aufgabenbild), Messgröße, Ziel, Beschreibung
function badge_defs(): array {
    $d = [
        ['start', 'Erster Schritt', 'green', '🌱', 'count', 1, 'Erste Aufgabe erledigt'],
        ['reward1', 'Erste Belohnung', 'green', '🎁', 'rewards', 1, 'Zum ersten Mal eine Belohnung bekommen'],
        ['helfer1', 'Helfer-Held', 'bronze', '💪', 'count', 10, '10 Aufgaben erledigt'],
        ['helfer2', 'Helfer-Held', 'silver', '💪', 'count', 50, '50 Aufgaben erledigt'],
        ['helfer3', 'Helfer-Held', 'gold', '💪', 'count', 100, '100 Aufgaben erledigt'],
        ['punkte1', 'Punktesammler', 'bronze', '⭐', 'points', 250, '250 Punkte verdient'],
        ['punkte2', 'Punktesammler', 'silver', '⭐', 'points', 1000, '1.000 Punkte verdient'],
        ['punkte3', 'Punktesammler', 'gold', '⭐', 'points', 2500, '2.500 Punkte verdient'],
    ];
    foreach (BADGE_SPECIALISTS as $key => [$name, $icons, $img, $desc]) {
        $d[] = ['spez_' . $key, $name, 'blue', 'icon:' . $img, 'icon:' . $key, 10, $desc];
    }
    $d[] = ['streak4', 'Dranbleiber', 'orange', '🔥', 'streak', 4, '4 Wochen in Folge geholfen'];
    $d[] = ['streak10', 'Marathon', 'orange', '🏃', 'streak', 10, '10 Wochen in Folge geholfen'];
    $d[] = ['woche100', 'Starke Woche', 'orange', '⚡', 'bestweek', 100, '100 Punkte in einer Woche (Mo–So)'];
    $d[] = ['allround', 'Alleskönner', 'purple', '🌈', 'distinct', 8, '8 verschiedene Aufgaben erledigt'];
    $d[] = ['weekend', 'Wochenend-Held', 'purple', '🦸', 'weekend', 3, '3 Aufgaben an einem Wochenende'];
    $d[] = ['spar300', 'Sparfuchs', 'purple', '🦊', 'balance', 300, '300 Punkte angespart'];
    return array_map(fn($b) => array_combine(['key', 'name', 'color', 'symbol', 'metric', 'target', 'desc'], $b), $d);
}

// Wochennummer (fortlaufend) für ein Datum "Y-m-d ..." – Montag ist Wochenstart
function badge_week_index(string $date): int {
    $d = new DateTimeImmutable(substr($date, 0, 10), new DateTimeZone('UTC'));
    $monday = $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
    return intdiv($monday->getTimestamp(), 604800);
}
function badge_longest_run(array $weeks): int {
    $w = array_keys($weeks);
    sort($w);
    $best = $run = 0; $prev = null;
    foreach ($w as $x) { $run = ($prev !== null && $x === $prev + 1) ? $run + 1 : 1; $best = max($best, $run); $prev = $x; }
    return $best;
}

// Sticker eines Kindes berechnen: wann verdient (oder null) und aktueller Fortschritt.
// Alles wird aus den vorhandenen Daten berechnet, die bisherige Geschichte zählt mit.
function badges_for(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $pdo = db();
    $events = [];
    $st = $pdo->prepare("SELECT task_id, task_name, points, created_at, reviewed_at FROM submissions WHERE user_id=? AND status='approved'");
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $s) {
        $t = task_info($s['task_id'] === null ? null : (int)$s['task_id']);
        $events[] = ['t' => $s['reviewed_at'] ?: $s['created_at'], 'type' => 'task', 'points' => (int)$s['points'],
                     'created' => $s['created_at'], 'icon' => $t['icon'] ?? guess_icon((string)$s['task_name']),
                     'task' => $s['task_id'] !== null ? 'id' . (int)$s['task_id'] : 'n' . mb_strtolower((string)$s['task_name'])];
    }
    $st = $pdo->prepare("SELECT points, status, created_at, reviewed_at FROM redemptions WHERE user_id=? AND status IN ('pending','approved')");
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $r) {
        $events[] = ['t' => $r['created_at'], 'type' => 'spend', 'points' => (int)$r['points']];
        if ($r['status'] === 'approved') $events[] = ['t' => $r['reviewed_at'] ?: $r['created_at'], 'type' => 'reward'];
    }
    usort($events, fn($a, $b) => strcmp($a['t'], $b['t']));

    $iconGroup = [];
    foreach (BADGE_SPECIALISTS as $key => [, $icons]) foreach ($icons as $i) $iconGroup[$i] = $key;
    $m = ['count' => 0, 'rewards' => 0, 'points' => 0, 'streak' => 0, 'bestweek' => 0, 'distinct' => 0, 'weekend' => 0, 'balance' => 0];
    foreach (BADGE_SPECIALISTS as $key => $_) $m['icon:' . $key] = 0;
    $weeks = []; $weekPts = []; $weekend = []; $tasks = []; $balance = 0;
    $defs = badge_defs();
    $earned = [];
    foreach ($events as $e) {
        if ($e['type'] === 'task') {
            $m['count']++;
            $m['points'] += $e['points'];
            $balance += $e['points'];
            if (isset($iconGroup[$e['icon']])) $m['icon:' . $iconGroup[$e['icon']]]++;
            $w = badge_week_index($e['created']);
            $weeks[$w] = true;
            $weekPts[$w] = ($weekPts[$w] ?? 0) + $e['points'];
            $m['streak'] = badge_longest_run($weeks);
            $m['bestweek'] = max($weekPts);
            $tasks[$e['task']] = true;
            $m['distinct'] = count($tasks);
            $day = new DateTimeImmutable(substr($e['created'], 0, 10));
            $dow = (int)$day->format('N');
            if ($dow >= 6) {
                $sat = $day->modify($dow === 7 ? '-1 day' : '+0 days')->format('Y-m-d');
                $weekend[$sat] = ($weekend[$sat] ?? 0) + 1;
                $m['weekend'] = max($weekend);
            }
        } elseif ($e['type'] === 'spend') {
            $balance -= $e['points'];
        } else {
            $m['rewards']++;
        }
        $m['balance'] = max($m['balance'], $balance);
        foreach ($defs as $b) {
            if (!isset($earned[$b['key']]) && $m[$b['metric']] >= $b['target']) $earned[$b['key']] = $e['t'];
        }
    }
    foreach ($defs as &$b) {
        $b['earned_at'] = $earned[$b['key']] ?? null;
        $b['progress'] = min((int)$m[$b['metric']], (int)$b['target']);
    }
    unset($b);
    return $cache[$userId] = $defs;
}

function badge_sticker(array $b, string $size = ''): string {
    $sym = str_starts_with($b['symbol'], 'icon:')
        ? icon_img(substr($b['symbol'], 5), 'sticon')
        : '<span class="stemoji">' . $b['symbol'] . '</span>';
    $tier = ['bronze' => 'Bronze', 'silver' => 'Silber', 'gold' => 'Gold'][$b['color']] ?? '';
    return '<span class="sticker ' . h($b['color']) . ' ' . h($size) . ($b['earned_at'] ? '' : ' locked') . '" aria-hidden="true">'
         . $sym . ($tier ? '<span class="tier">' . $tier . '</span>' : '') . '</span>';
}

// Sammelalbum im Konto
function render_album(array $kid, bool $own): void {
    $badges = badges_for((int)$kid['id']);
    $have = count(array_filter($badges, fn($b) => $b['earned_at'] !== null));
    // verdiente Sticker zuerst (neueste vorn), dann die fehlenden in fester Reihenfolge
    usort($badges, function ($a, $b) {
        if (($a['earned_at'] === null) !== ($b['earned_at'] === null)) return $a['earned_at'] === null ? 1 : -1;
        return strcmp((string)$b['earned_at'], (string)$a['earned_at']);
    });
    ?>
    <section class="card album">
      <h2>🏅 <?= $own ? 'Mein Sammelalbum' : 'Sammelalbum von ' . h($kid['name']) ?></h2>
      <p class="muted"><?= $have ?> von <?= count($badges) ?> Stickern gesammelt</p>
      <div class="stickers">
        <?php foreach ($badges as $b): ?>
          <div class="stcard <?= $b['earned_at'] ? 'got' : '' ?>">
            <?= badge_sticker($b) ?>
            <b><?= h($b['name']) ?></b>
            <span class="stdesc"><?= h($b['desc']) ?></span>
            <?php if ($b['earned_at']): ?>
              <span class="stdate">✓ <?= date('d.m.Y', strtotime($b['earned_at'])) ?></span>
            <?php else: ?>
              <span class="stprog"><span style="width:<?= (int)round($b['progress'] / $b['target'] * 100) ?>%"></span></span>
              <span class="stdate"><?= (int)$b['progress'] ?> / <?= (int)$b['target'] ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

// Konfetti-Moment: Was ist seit dem letzten Besuch passiert?
// Beim allerersten Besuch nach Einführung gibt es eine Begrüßungskarte statt vieler Einzelmeldungen.
function render_celebration(array $u): void {
    $uid = (int)$u['id'];
    $pdo = db();
    $seen = $u['seen_at'] ?? null;
    $welcome = $seen === null;
    $subs = $reds = $newBadges = [];
    if (!$welcome) {
        $st = $pdo->prepare("SELECT s.*, r.name AS reviewer FROM submissions s LEFT JOIN users r ON r.id = s.reviewed_by
                             WHERE s.user_id=? AND s.status='approved' AND s.reviewed_at > ? ORDER BY s.reviewed_at");
        $st->execute([$uid, $seen]);
        $subs = $st->fetchAll();
        $st = $pdo->prepare("SELECT d.*, r.name AS reviewer FROM redemptions d LEFT JOIN users r ON r.id = d.reviewed_by
                             WHERE d.user_id=? AND d.status='approved' AND d.reviewed_at > ? ORDER BY d.reviewed_at");
        $st->execute([$uid, $seen]);
        $reds = $st->fetchAll();
        $newBadges = array_values(array_filter(badges_for($uid), fn($b) => $b['earned_at'] !== null && $b['earned_at'] > $seen));
    }
    $allBadges = array_values(array_filter(badges_for($uid), fn($b) => $b['earned_at'] !== null));

    // "Gesehen" bis zur letzten Bestätigung merken (nicht bis "jetzt", damit nichts verloren geht)
    $st = $pdo->prepare("SELECT MAX(t) FROM (SELECT MAX(reviewed_at) AS t FROM submissions WHERE user_id=? AND status='approved'
                         UNION ALL SELECT MAX(reviewed_at) FROM redemptions WHERE user_id=? AND status='approved')");
    $st->execute([$uid, $uid]);
    $last = $st->fetchColumn() ?: null;
    $newSeen = $last !== null && ($seen === null || $last > $seen) ? $last : ($seen ?? now());
    if ($newSeen !== $seen) $pdo->prepare('UPDATE users SET seen_at=? WHERE id=?')->execute([$newSeen, $uid]);

    if ($welcome && !$allBadges) return;
    if (!$welcome && !$subs && !$reds && !$newBadges) return;
    $sum = array_sum(array_map(fn($s) => (int)$s['points'], $subs));
    ?>
    <div class="celebrate js-celebrate" role="dialog" aria-modal="true" aria-label="Neuigkeiten">
      <canvas class="confetti" aria-hidden="true"></canvas>
      <div class="cel-card">
        <?php if ($welcome): ?>
          <div class="cel-big">🎉</div>
          <h2>Neu: Dein Sammelalbum!</h2>
          <p>Du hast schon <b><?= count($allBadges) ?> Sticker</b> gesammelt.</p>
          <div class="cel-stickers"><?php foreach (array_slice($allBadges, 0, 8) as $b): ?><?= badge_sticker($b, 'small') ?><?php endforeach; ?></div>
          <p class="muted">Alle Sticker findest du unter „Mein Konto“.</p>
        <?php else: ?>
          <?php if ($subs): ?>
            <div class="cel-big">+<?= $sum ?> <small>Punkte!</small></div>
            <h2><?= count($subs) === 1 ? 'Aufgabe bestätigt 🎉' : count($subs) . ' Aufgaben bestätigt 🎉' ?></h2>
            <ul class="cel-list">
              <?php foreach ($subs as $s): ?>
                <li><?= task_img((string)$s['task_name'], 'ticon', $s['task_id'] === null ? null : (int)$s['task_id']) ?>
                  <div><b><?= h($s['task_name']) ?></b> <span class="plus">+<?= (int)$s['points'] ?></span>
                    <?php if ($s['reviewer']): ?><br><span class="muted">bestätigt von <?= h($s['reviewer']) ?></span><?php endif; ?>
                    <?php if ($s['comment']): ?><br><span class="comment">„<?= h($s['comment']) ?>“</span><?php endif; ?></div></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php foreach ($reds as $r): ?>
            <div class="cel-reward">🎁 <b>Deine Belohnung ist da!</b><br><?= h($r['amount_text']) ?> <?= h($r['reward_name']) ?>
              <?php if ($r['comment']): ?><br><span class="comment">„<?= h($r['comment']) ?>“</span><?php endif; ?></div>
          <?php endforeach; ?>
          <?php foreach ($newBadges as $b): ?>
            <div class="cel-badge"><?= badge_sticker($b, 'small') ?><div><b>Neuer Sticker!</b><br><?= h($b['name']) ?> – <?= h($b['desc']) ?></div></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <button type="button" class="btn primary block js-celebrate-close">Juhu! 👍</button>
      </div>
    </div>
    <?php
}
