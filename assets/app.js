// Brochhaus-Bonus: Foto verkleinern & Vorschau, kleine Komfortfunktionen
(function () {
  const MAX = 1600, QUALITY = 0.82;

  async function shrink(file) {
    if (!file || !file.type.startsWith('image/')) return file;
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
      if (scale === 1 && file.size < 1.5e6) return file;
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', QUALITY));
      return blob ? new File([blob], 'foto.jpg', { type: 'image/jpeg' }) : file;
    } catch (e) { return file; }
  }

  // Startbildschirm (Halloween, Neujahr): nur beim Öffnen der App (einmal pro Sitzung), verschwindet nach 3 Sekunden
  const splash = document.querySelector('.js-splash');
  if (splash) {
    let seen = false;
    const key = 'splash-' + (splash.dataset.key || 'hw');
    try { seen = sessionStorage.getItem(key) === '1'; sessionStorage.setItem(key, '1'); } catch (e) {}
    if (!seen) {
      splash.hidden = false;
      if (splash.dataset.fireworks) fireworks(splash.querySelector('canvas'));
      let gone = false;
      const hide = () => {
        if (gone) return; gone = true;
        splash.classList.add('out');
        setTimeout(() => { splash.hidden = true; }, 500);
      };
      splash.addEventListener('click', hide);
      setTimeout(hide, 3000);
    }
  }

  // Feuerwerk für den Neujahrs-Startbildschirm: Raketen steigen auf und zerplatzen in bunte Funken
  function fireworks(cv) {
    if (!cv || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
    const ctx = cv.getContext('2d'), dpr = window.devicePixelRatio || 1;
    const W = cv.width = innerWidth * dpr, H = cv.height = innerHeight * dpr;
    const colors = ['#f5c542', '#ff3ea5', '#29d3ff', '#9a6bff', '#7ed321', '#ffffff'];
    const rockets = [], sparks = [], start = performance.now();
    let next = 0;
    const tick = now => {
      const t = now - start;
      if (t > next && t < 2600) {
        rockets.push({ x: W * (.15 + Math.random() * .7), y: H, vy: -(H / 75) * (.8 + Math.random() * .3), top: H * (.15 + Math.random() * .3),
          c: colors[Math.floor(Math.random() * colors.length)] });
        next = t + 260 + Math.random() * 260;
      }
      ctx.globalCompositeOperation = 'destination-out'; ctx.fillStyle = 'rgba(0,0,0,.25)'; ctx.fillRect(0, 0, W, H);
      ctx.globalCompositeOperation = 'lighter';
      for (let i = rockets.length - 1; i >= 0; i--) {
        const r = rockets[i]; r.y += r.vy;
        ctx.fillStyle = r.c; ctx.beginPath(); ctx.arc(r.x, r.y, 2.5 * dpr, 0, 7); ctx.fill();
        if (r.y <= r.top) {
          rockets.splice(i, 1);
          for (let k = 0; k < 46; k++) {
            const a = Math.PI * 2 * k / 46, v = (2 + Math.random() * 2.5) * dpr;
            sparks.push({ x: r.x, y: r.y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, life: 1, c: Math.random() < .2 ? '#ffffff' : r.c });
          }
        }
      }
      for (let i = sparks.length - 1; i >= 0; i--) {
        const p = sparks[i]; p.x += p.vx; p.y += p.vy; p.vy += .05 * dpr; p.vx *= .985; p.life -= .016;
        if (p.life <= 0) { sparks.splice(i, 1); continue; }
        ctx.globalAlpha = p.life; ctx.fillStyle = p.c; ctx.beginPath(); ctx.arc(p.x, p.y, 2 * dpr, 0, 7); ctx.fill(); ctx.globalAlpha = 1;
      }
      if (t < 3600) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  // Konfetti-Moment: Karte zeigen, Konfetti regnen lassen, mit "Juhu!" schließen
  const cel = document.querySelector('.js-celebrate');
  if (cel) {
    const close = () => { cel.hidden = true; };
    cel.querySelector('.js-celebrate-close').addEventListener('click', close);
    cel.addEventListener('click', e => { if (e.target === cel) close(); });
    const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cv = cel.querySelector('.confetti');
    if (cv && !calm) {
      const ctx = cv.getContext('2d'), dpr = window.devicePixelRatio || 1;
      const W = cv.width = innerWidth * dpr, H = cv.height = innerHeight * dpr;
      const cl = document.body.classList;
      const colors = cl.contains('skin-halloween') ? ['#ff7a00', '#ff9a1f', '#8a3ffc', '#b06cff', '#7ed321', '#2b1a3d', '#ffd23f']
        : cl.contains('skin-silvester') ? ['#f5c542', '#ff3ea5', '#29d3ff', '#9a6bff', '#ffffff', '#cfd8e3', '#7ed321']
        : cl.contains('skin-weihnachten') ? ['#e3263a', '#2e9e4f', '#f2b705', '#ffffff', '#ffd84d', '#c41e3a', '#7fd1ff']
        : ['#ff4f6d', '#ff8a1f', '#ffd23f', '#3cbf5b', '#2f9be0', '#9b5de5', '#ff5fa2'];
      const bits = Array.from({ length: 140 }, () => ({
        x: Math.random() * W, y: -Math.random() * H * .6, w: (6 + Math.random() * 6) * dpr, h: (9 + Math.random() * 8) * dpr,
        vx: (Math.random() - .5) * 2.5 * dpr, vy: (2.5 + Math.random() * 3.5) * dpr, r: Math.random() * 6, vr: (Math.random() - .5) * .3,
        c: colors[Math.floor(Math.random() * colors.length)]
      }));
      const start = performance.now();
      const tick = now => {
        const t = now - start;
        ctx.clearRect(0, 0, W, H);
        ctx.globalAlpha = t > 2600 ? Math.max(0, 1 - (t - 2600) / 900) : 1;
        bits.forEach(b => {
          b.x += b.vx + Math.sin((t / 300) + b.r) * .6 * dpr; b.y += b.vy; b.r += b.vr;
          ctx.save(); ctx.translate(b.x, b.y); ctx.rotate(b.r); ctx.fillStyle = b.c;
          ctx.fillRect(-b.w / 2, -b.h / 2, b.w, b.h * Math.abs(Math.cos(b.r))); ctx.restore();
        });
        if (t < 3500) requestAnimationFrame(tick); else ctx.clearRect(0, 0, W, H);
      };
      requestAnimationFrame(tick);
    }
  }

  // Startseite: nach Wahl des Profils direkt ins Passwortfeld springen
  document.querySelectorAll('.js-login input[name=uid]').forEach(r => r.addEventListener('change', () => {
    const pw = document.querySelector('.js-login input[name=password]');
    if (pw) setTimeout(() => { pw.focus(); pw.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 50);
  }));

  // Verwalten > Aufgaben: Bildvorschau beim Wechseln des Bildes
  document.querySelectorAll('.js-icon-select').forEach(sel => sel.addEventListener('change', () => {
    const img = sel.closest('form').querySelector('.js-icon-preview');
    const src = (window.TASK_ICON_SRC || {})[sel.value || 'sonstige'];
    if (img && src) img.src = src;
  }));

  document.querySelectorAll('.js-photo-form').forEach(form => {
    // Foto: "Foto machen" (Kamera) oder "Aus Fotos wählen" (Mediathek) – eins von beiden ist Pflicht
    const box = form.querySelector('.js-photo-box');
    const inputs = box ? [...box.querySelectorAll('input[type=file]')] : [];
    const prev = box && box.querySelector('.photo-preview');
    const hint = box && box.querySelector('.photo-hint');
    inputs.forEach(input => input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      inputs.forEach(other => { if (other !== input) other.value = ''; });
      const small = await shrink(f);
      if (small !== f && window.DataTransfer) {
        const dt = new DataTransfer(); dt.items.add(small); input.files = dt.files;
      }
      prev.src = URL.createObjectURL(small); prev.hidden = false;
      box.classList.add('has-photo'); box.classList.remove('missing');
      if (hint) hint.textContent = '✅ Foto ist dabei. Du kannst es noch austauschen.';
    }));

    // Bei "Sonstige Aufgabe" ist die Notiz Pflicht
    const note = form.querySelector('textarea[name=note]');
    const noteHint = form.querySelector('.js-note-hint');
    const freeChanged = r => {
      const free = r.dataset.free === '1';
      if (note) { note.required = free; note.placeholder = free ? 'Was genau hast du gemacht?' : 'z. B. alle drei Mülltonnen'; }
      if (noteHint) noteHint.textContent = free ? '(bitte ausfüllen)' : '(optional)';
    };
    form.querySelectorAll('input[name=task_id]').forEach(r => {
      r.addEventListener('change', () => {
        freeChanged(r);
        if (box) setTimeout(() => box.scrollIntoView({ block: 'center', behavior: 'smooth' }), 150);
      });
      if (r.checked) freeChanged(r);
    });
    // Über eine Kachel auf der Startseite gekommen: gleich zum Foto springen
    if (box && form.querySelector('input[name=task_id]:checked')) {
      setTimeout(() => box.scrollIntoView({ block: 'center' }), 100);
    }

    // Eltern: Standardpunkte als Platzhalter zeigen
    const sel = form.querySelector('.js-task-select'), pts = form.querySelector('.js-points');
    if (sel && pts) sel.addEventListener('change', () => {
      const p = sel.selectedOptions[0]?.dataset.points;
      pts.placeholder = p ? p + ' (Standard)' : 'bitte festlegen';
      pts.required = !p;
    });

    form.addEventListener('submit', e => {
      // Ohne Foto nicht absenden
      if (box && !inputs.some(i => i.files && i.files.length)) {
        e.preventDefault();
        box.classList.add('missing');
        if (hint) hint.textContent = '📸 Bitte zuerst ein Foto machen oder auswählen.';
        box.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return;
      }
      // Doppeltes Absenden verhindern
      const b = form.querySelector('button.primary'); if (b) setTimeout(() => { b.disabled = true; b.textContent = 'Wird gesendet …'; }, 0);
    });
  });
})();
