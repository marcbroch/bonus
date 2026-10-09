# Brochhaus-Bonus

Punkte- und Belohnungs-App für die Kinder der Familie Brochhaus.
Live unter **https://brochhaus.name/bonus** (IONOS Webhosting Premium).

## Wer hiermit arbeitet
Marc ist Marketing Manager, kein Developer. Erkläre Änderungen kurz und in
einfachem Deutsch, ohne Fachjargon. Die Texte in der App sind auf Deutsch.

## Technik
- Reines PHP 8.1+ ohne Framework und ohne Build-Schritt, Datenbank SQLite.
- `index.php` ist der Einstieg (Routing über `?p=...`). `lib.php` enthält
  gemeinsame Funktionen, Datenbankschema und Layout, `pages_child.php` die
  Kinder-Seiten, `pages_admin.php` die Eltern-Seiten, `photo.php` liefert
  Fotos nur nach Anmeldung aus.
- Alle Links müssen **relativ** bleiben (z. B. `index.php?p=home`), weil die
  App im Unterordner `/bonus` läuft.
- Bei Änderungen an CSS oder JS die Versionsnummer `?v=` in `lib.php`
  erhöhen, damit Handys die neue Datei laden.
- Die Live-Datenbank enthält echte Daten und wird nie neu angelegt.
  Schema-Änderungen brauchen deshalb eine Migration für bestehende
  Datenbanken (z. B. `ALTER TABLE` in `init_schema`, wenn eine Spalte fehlt).

## Veröffentlichen
- Jeder Push auf `main` lädt die App per GitHub Action
  (`.github/workflows/deploy.yml`) über SFTP zu IONOS hoch.
- `data/` (Datenbank) und `uploads/` (Fotos) gibt es nur auf dem Server. Sie
  stehen in `.gitignore` und werden nie hochgeladen, überschrieben oder
  gelöscht. Das muss so bleiben.
- Lokal testen: `php -S 127.0.0.1:8000` im Projektordner, dann
  `http://127.0.0.1:8000/index.php` öffnen. Vor dem Commit `php -l` auf alle
  geänderten PHP-Dateien ausführen.
