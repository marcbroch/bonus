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

  document.querySelectorAll('.js-photo-form').forEach(form => {
    const input = form.querySelector('input[type=file]');
    const prev = form.querySelector('.photo-preview');
    const hint = form.querySelector('.photo-hint');
    if (input) input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      const small = await shrink(f);
      if (small !== f && window.DataTransfer) {
        const dt = new DataTransfer(); dt.items.add(small); input.files = dt.files;
      }
      prev.src = URL.createObjectURL(small); prev.hidden = false; if (hint) hint.hidden = true;
    });

    // Bei "Sonstige Aufgabe" ist die Notiz Pflicht
    const note = form.querySelector('textarea[name=note]');
    const noteHint = form.querySelector('.js-note-hint');
    form.querySelectorAll('input[name=task_id]').forEach(r => r.addEventListener('change', () => {
      const free = r.dataset.free === '1';
      if (note) { note.required = free; note.placeholder = free ? 'Was genau hast du gemacht?' : 'z. B. alle drei Mülltonnen'; }
      if (noteHint) noteHint.textContent = free ? '(bitte ausfüllen)' : '(optional)';
    }));

    // Eltern: Standardpunkte als Platzhalter zeigen
    const sel = form.querySelector('.js-task-select'), pts = form.querySelector('.js-points');
    if (sel && pts) sel.addEventListener('change', () => {
      const p = sel.selectedOptions[0]?.dataset.points;
      pts.placeholder = p ? p + ' (Standard)' : 'bitte festlegen';
      pts.required = !p;
    });

    // Doppeltes Absenden verhindern
    form.addEventListener('submit', () => {
      const b = form.querySelector('button.primary'); if (b) setTimeout(() => { b.disabled = true; b.textContent = 'Wird gesendet …'; }, 0);
    });
  });
})();
