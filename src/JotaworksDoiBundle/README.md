# JotaWorks Double-Opt-In (JotaworksDoiBundle)

Double-Opt-In für Mautic-Formulare und Kampagnen: Formular-/Kampagnenaktion „Double-Opt-In (DOI) mit
Email-Link“ verschickt eine Bestätigungsmail mit dem Platzhalter `{doi_url}`. Der Klick darauf führt die
konfigurierten Aktionen aus (Tags, Segmente, Kontaktfelder, DNC entfernen, Audit-Log, Seitenaufruf, Webhook)
und leitet auf die Danke-URL weiter.

## One-Click-Abmeldung (ab 2.4.0)

Ein Klick auf „Abbestellen“ im Postfach (Gmail, Apple Mail …) schickt nach RFC 8058 einen POST mit
`List-Unsubscribe=One-Click` an `/email/unsubscribe/{idHash}/{urlEmail}/{secretHash}`. Mautic sperrt dann den Kontakt für
alle E-Mails. Das Plugin fängt nur diesen POST ab (`EventListener/OneClickRequestSubscriber.php`) und führt die
eingestellte Wirkung aus:

| Wirkung | Was passiert | „bereits erledigt“, wenn … |
|---|---|---|
| Sperre (Standard) | E-Mail-DNC Grund 1 wie Mautic (`EmailModel::setDoNotContact`) | schon eine E-Mail-Sperre Grund 1 besteht |
| Aus Segmenten der Mail | nur Segment-Mails: Austragen aus den Segmenten der Mail (`manually_removed = 1`) | der Kontakt in keinem davon (mehr) steht |
| Aus Kampagne | Kontakt aus der Kampagne nehmen, die die Mail geschickt hat | er kein aktives Mitglied mehr ist |
| Aus festen Segmenten | nur je Mail: Austragen aus den gewählten Segmenten | er in keinem davon (mehr) steht |
| Automatisch | Segment-Mail → aus Segmenten, Kampagnen-Mail → aus Kampagne, sonst Sperre | wie die gewählte Wirkung |

Einstellung: global in der Integration (Reiter Funktionen, Standard „Sperre“, Ausweichregel „Sperre“), je Mail im Editor
unter „Erweitert“ → „Bei Abmeldung“. Die Einstellung der Mail geht vor.

- **Bereits erledigt** (Doppelklick, Kontakt nicht mehr im Segment …): keine Aktion, keine Ausweichregel, nur ein Eintrag
  im Kontaktverlauf (`result = already_applied`, höchstens einer je Statistik und 24 h).
- **Ausweichregel** nur, wenn die Wirkung zur Mail nicht passt (keine Segment-Mail, keine Kampagne gefunden, keine festen
  Segmente). Passt auch die Ausweichregel nicht, wird gesperrt.
- **Leadfeld setzen** (global und je Mail): Feld und Wert werden bei jeder ausgeführten Abmeldung gesetzt, vor der Sperre,
  nie bei „bereits erledigt“. An der Mail: „Wie global eingestellt“, „Kein Feld setzen“ oder ein Feld. Leerer Wert = kein
  Feld. Beispiel gemeinsam.fpoe.at: `optin_status` = `Widerrufen` nur an Mails mit Sperre.
- **Fehler:** Scheitert eine Aktion, wird gesperrt (ohne Leadfeld). Hat Doctrine den EntityManager geschlossen, setzt das
  Plugin ihn zurück und lädt die Statistik neu. Scheitert auch das, übernimmt Mautics eigener Controller.
- **Protokoll:** Kontaktverlauf (`lead_event_log`, Bundle `jwdoi`, Aktion `oneclick_unsubscribe`, mit Wirkung, Ergebnis,
  Ausweichregel, Segmenten bzw. Kampagne, Leadfeld) und Mautic-Log (Notice).

Aus Kampagne entfernen: Der Kontakt bekommt `manually_removed = 1` ohne Austrittsdatum. Der Segment-Neuaufbau
(`mautic:campaigns:rebuild`) nimmt ihn nicht wieder auf, auch wenn er noch im Quellsegment steht. Ein Formular mit der
Kampagne als Quelle oder die Kampagnenaktion „Kampagne ändern“ nimmt ihn wieder auf (bei Kampagnen ohne Neustart nur
dieser Weg). Ein neuer Segmenteintritt allein reicht dafür nicht.

Der Abmeldekopf (`EventListener/ListUnsubscribeHeaderSubscriber.php`) setzt die https-Adresse selbst, auch wenn der
Mailtext kein `{unsubscribe_url}` enthält.

**Update:** nach `mautic:plugins:reload` den Doctrine-Metadaten-Cache leeren (`bin/console doctrine:cache:clear-metadata`
als Site-User) und PHP-FPM neu starten, sonst fehlen neue Spalten in den Metadaten.

## Köder-Link in allen Mails (ab 2.3.0)

Ist der Schutz vor Link-Scannern eingeschaltet, bekommt jede echt versendete Mail (Newsletter, Kampagne,
Transaktion) direkt nach `<body>` einen unsichtbaren, nicht getrackten Link `/nothuman/<Statistik-Hash>`. Das Token
`{jw_nothuman}` liefert dieselbe URL für eigene Vorlagen; steht es in der Mail, fügt das Plugin nichts ein.
Test-Mails und die Vorschau bekommen keinen Köder.

Der Aufruf schreibt dieselbe Markierung wie der DOI-Köder (`doi_<Statistik-Hash>.log`, Verzeichnis
`JW_DOI_MARKER_DIR`). Das Plugin selbst wertet sie nur für die DOI-Bestätigung aus. Für Klicks wertet sie der Patch
`scanner-guard` des mautic_docker-Basis-Images aus: Ein Klick, vor dem der Köder desselben Empfängers aufgerufen wurde,
bleibt als Statistik erhalten, löst aber keine „klickt Link“-Entscheidung, keine Punkte und keine Öffnung aus.

## Schutz vor Link-Scannern (ab 2.2.0, Standard: aus)

### Problem

Mail-Scanner (z. B. Microsoft Defender „Safe Links“/ATP, Virenscanner in Firmen-Gateways) rufen beim
Zustellen alle Links einer Mail auf. Ohne Schutz bestätigt so der Scanner das Double-Opt-In, nicht der
Empfänger.

### Funktionsweise

1. Die DOI-Mail enthält zusätzlich einen **unsichtbaren Köder-Link** `{doi_nothuman}`
   (`/nothuman/<hash>`). Ein Mensch sieht und klickt ihn nicht; ein Scanner ruft ihn auf. Der Aufruf
   legt im Cache-Verzeichnis eine Markierung `doi_<hash>.log` an, mit dem Zeitpunkt jedes Aufrufs
   (ein Unix-Zeitstempel pro Zeile, höchstens 20).
2. Ein Klick auf `{doi_url}` leitet **sofort** auf die Danke-URL weiter. Die DOI-Aktionen werden aber
   nicht sofort ausgeführt, sondern als Messenger-Nachricht mit Verzögerung (Standard 60 s) eingereiht.
   Mitgegeben werden IP, User-Agent, Referer und Sprache des Klicks.
3. Nach der Wartezeit prüft der Worker die Markierung. Es zählen nur Köder-Aufrufe im **Zeitfenster**
   von 300 s vor dem Klick bis zum Ende der Wartezeit (Klick − 300 s … Klick + Wartezeit;
   Konstante `JotaworksDoiIntegration::BAIT_LOOKBACK_SECONDS`):
   - **Köder-Link im Zeitfenster aufgerufen** → Bestätigung verworfen, die Aufrufe bis zum Fensterende
     werden aus der Markierung entfernt (Log: `JotaworksDoi: DOI confirmation discarded …`).
     Ein späterer echter Klick bestätigt normal.
   - **Köder-Aufrufe älter als das Fenster** (ein Scanner hat nur den Köder-Link aufgerufen) → werden
     ignoriert und entfernt (Log: `JotaworksDoi: stale bait link hits ignored and removed`). Der echte
     Klick wird übernommen.
   - **sonst** → DOI-Aktionen werden ausgeführt, mit IP/User-Agent des ursprünglichen Klicks
     (Audit-Log, `{doi_ip}`, Seitenaufruf). `{doi_timestamp}` ist der Zeitpunkt des Klicks, nicht der
     des Worker-Laufs (Format und Zeitzone wie ohne Option). Log: `JotaworksDoi: delayed DOI confirmation applied`.
     Das Datum des Audit-Log-Eintrags und des Seitenaufrufs setzt Mautic selbst; dort steht die Worker-Zeit.

   Markierungen aus 1.4.x/2.1.0 (Inhalt = Hash) werden mit ihrer Dateizeit gewertet.

Ist die Option aus, verhält sich das Plugin wie 2.1.0: Aktionen sofort beim Klick, der Köder-Link wird
nicht ausgewertet.

### Einrichtung

1. **Transport** in `config/local.php` (Mautic-Parameter, Standard `sync://`):

   ```php
   'messenger_dsn_jotaworks_doi' => 'doctrine://default?queue_name=jotaworks_doi',
   ```

   Danach Cache leeren (`bin/console cache:clear`) und einmalig
   `bin/console messenger:setup-transports jotaworks_doi` (legt die Tabelle `messenger_messages` an,
   falls es sie noch nicht gibt).

   Mit `sync://` wird die Verzögerung **ignoriert**: Die Prüfung läuft sofort im Klick. Das schützt nur,
   wenn der Scanner den Köder-Link vor dem Bestätigungslink aufruft.

2. **Worker** dauerhaft laufen lassen, als derselbe Benutzer wie PHP-FPM (die Markierungen liegen im
   Cache-Verzeichnis), z. B. per Cron jede Minute:

   ```
   * * * * * php /pfad/zu/mautic/bin/console messenger:consume jotaworks_doi --time-limit=55 --limit=100 >/dev/null 2>&1
   ```

   oder per Supervisor/systemd ohne `--time-limit`. **Ohne Worker werden bei eingeschalteter Option
   keine Bestätigungen übernommen** (die Nachrichten bleiben in der Warteschlange liegen und werden
   beim nächsten Worker-Lauf abgearbeitet).

3. **Einstellung**: Einstellungen › Plugins › „Double-Opt-In (DOI)“
   - Reiter „Aktiviert/Autorisiert“: Plugin veröffentlichen (ohne Veröffentlichung bleibt die Option aus).
   - Reiter „Funktionen“: „Schutz vor Link-Scannern (verzögerte Bestätigung)“ = Ja,
     „Wartezeit in Sekunden“ (1–3600, Standard 60).

4. **Köder-Link in jede DOI-Mail** einbauen, unsichtbar und ohne Mautic-Klicktracking
   (`data-mautic-disable-tracking="true"`, ab Mautic 7; in Mautic 5 `mautic:disable-tracking=""`),
   sonst geht der Aufruf über `/r/…` und zählt als Klick:

   ```html
   <a href="{doi_nothuman}" data-mautic-disable-tracking="true" aria-hidden="true" tabindex="-1"
      style="display:none;font-size:0;line-height:0;color:transparent;text-decoration:none;">&#8203;</a>
   ```

   Ohne diesen Link gibt es keine Markierung, und jede Bestätigung wird (nach der Wartezeit) übernommen.

### Prüfen

- Warteschlange: `SELECT id, queue_name, created_at, available_at, delivered_at FROM messenger_messages WHERE queue_name='jotaworks_doi';`
- Log (`var/logs/mautic_prod-*.php`): `JotaworksDoi: …`
- Audit-Log: `SELECT * FROM audit_log WHERE bundle='lead' AND object='doi' ORDER BY id DESC;`
  (Spalte `ip_address` = IP des Klicks, nicht 127.0.0.1)

### Erprobt und ausgerollt (16.11.2026)

- **m7.dev.testserver.online** (Mautic 7.2.1): 2.2.0 installiert (Sicherung
  `/var/backups/pre-plugin-JotaworksDoiBundle-20261116-1351/` mit Plugin und `local.php`). Container kompiliert,
  `debug:messenger` zeigt den Handler einmal, Reiter „Features“ zeigt beide Felder und speichert.
  Test mit Doctrine-Transport, Wartezeit 20 s, Worker von Hand (`messenger:consume jotaworks_doi`), Nachweis über
  Tags, Feld `city={doi_ip}` und `audit_log.ip_address`:
  1. Option aus → sofort übernommen (wie 2.1.0).
  2. Option an, nur echter Klick → Nachricht mit `available_at` = Klick + 20 s, erst der Worker übernimmt;
     Audit-Log-IP = IP des Klicks, nicht die des Workers.
  3. Köder-Aufruf, dann Klick → verworfen (Log-Warnung), Markierung verbraucht.
  4. Köder-Aufruf 400 s vor dem Klick → übernommen, alte Markierung entfernt.
  Zusätzlich: Köder 200 s vor dem Klick → verworfen, zweiter Klick → übernommen; Markierung im 2.1.0-Format
  (Inhalt = Hash, Dateizeit −400 s) → übernommen; Option an mit `sync://` → Köder+Klick verworfen, Klick allein übernommen.
  Danach `local.php`, Integrationseinstellungen und Testdaten zurückgesetzt.
  Nachtrag `{doi_timestamp}` = Klickzeit (Sicherung `/var/backups/pre-plugin-JotaworksDoiBundle-20261116-1416/`):
  Option an, Klick 14:18:35, Worker 14:19:41 → Feld `16.11.2026 14:18:35` (Audit-Log und Seitenaufruf: Worker-Zeit);
  Option aus, Klick 14:17:48 → Feld `16.11.2026 14:17:49` (sofort, wie bisher).
- **m.jotaworks-demos.de**: 2.2.0 installiert, **Option aus** (keine Integrationszeile, also Verhalten wie 2.1.0).
  Köder-Link in den DOI-Mails 90 und 91. Details in `m.jotaworks-demos.de/CLAUDE.md`.
  Nachtrag `{doi_timestamp}` eingespielt (Sicherung `/root/pre-doi-220b-20261116-1420/`), Demo-Test T16: 15/15 PASS.
- Bekannt: Die Plugin-Übersetzungen liegen nur unter `de_DE`; Mautic 7 verwendet für Deutsch die Locale `de`.
  Die Einstellungen erscheinen deshalb auf Englisch („Features“, „Delay in seconds“). Das betrifft auch 2.1.0.

### Grenzen

- `cache:clear` zwischen Köder-Aufruf und Worker-Lauf löscht die Markierung.
- Ruft ein Scanner nur den Köder-Link auf und klickt die Empfängerin innerhalb von 300 s danach, wird ihr
  Klick verworfen (sie sieht trotzdem die Danke-Seite); ein erneuter Klick bestätigt. Liegt der Köder-Aufruf
  länger zurück, zählt er nicht mehr (bis 2.1.0/1.4.x verfiel die Markierung nie, und der erste echte
  Klick wurde immer verworfen).
- Ruft ein Scanner den Bestätigungslink mehrfach auf, verbraucht die erste verworfene Bestätigung die
  Köder-Aufrufe bis zu ihrem Fensterende. Weitere Scanner-Klicks werden nur verworfen, wenn der Köder-Link
  erneut in ihrem Fenster aufgerufen wurde.
- Schlägt die Ausführung im Worker fehl, wiederholt Mautics Retry-Strategie die Nachricht; Aktionen
  können dann teilweise doppelt laufen (z. B. zweiter Audit-Log-Eintrag).
