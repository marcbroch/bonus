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
