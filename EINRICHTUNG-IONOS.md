# Einrichtung: automatisch auf brochhaus.name/bonus veröffentlichen

Das ist nur einmal nötig. Danach wird jede Änderung, die auf `main` landet,
automatisch hochgeladen.

## 1. SFTP-Zugang bei IONOS anlegen
1. Im IONOS-Kundenbereich **Hosting → SFTP & SSH** öffnen.
2. **Benutzer anlegen** und ein sicheres Passwort vergeben.
3. Notieren: **Server** (sieht aus wie `access-123456789.webspace-host.com`),
   **Benutzername** und **Passwort**.

## 2. Zielordner herausfinden
1. Im IONOS-Kundenbereich **Domains & SSL → brochhaus.name** öffnen.
2. Dort steht, auf welchen Ordner die Domain zeigt (z. B. `/` oder
   `/brochhaus`).
3. Der Zielordner für die App ist dieser Ordner plus `/bonus`, also z. B.
   `/bonus` oder `/brochhaus/bonus`.

## 3. Zugangsdaten bei GitHub hinterlegen
Auf github.com/marcbroch/bonus: **Settings → Secrets and variables → Actions
→ New repository secret**. Dort vier Einträge anlegen:

| Name              | Inhalt                                    |
|-------------------|-------------------------------------------|
| `SFTP_HOST`       | Server aus Schritt 1 (ohne `sftp://`)     |
| `SFTP_USER`       | Benutzername aus Schritt 1                |
| `SFTP_PASSWORD`   | Passwort aus Schritt 1                    |
| `SFTP_REMOTE_DIR` | Zielordner aus Schritt 2, z. B. `/bonus`  |

## 4. Ersten Upload starten
**Actions → „Auf IONOS veröffentlichen“ → Run workflow**. Nach etwa einer
Minute erscheint ein grüner Haken.

## 5. App einrichten
https://brochhaus.name/bonus öffnen und **sofort** den Eltern-Zugang anlegen.
Solange das nicht passiert ist, könnte das jeder tun, der den Link kennt.

## Gut zu wissen
- **SSL:** Unter „Domains & SSL“ muss für brochhaus.name ein Zertifikat aktiv
  sein, sonst gibt es eine Endlos-Weiterleitung.
- **PHP-Version:** Unter **Hosting → PHP-Einstellungen** mindestens PHP 8.1
  wählen (besser 8.3).
- **Datensicherung:** Datenbank und Fotos liegen nur bei IONOS (Ordner `data`
  und `uploads`), nicht bei GitHub. Ab und zu per SFTP (z. B. mit FileZilla)
  herunterladen.
