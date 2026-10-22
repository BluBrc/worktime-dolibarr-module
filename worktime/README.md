# Dolibarr-Modul „Arbeitszeiterfassung“ (worktime)

Version 1.2.0 · für **Dolibarr 23.0 (getestet)** · PHP 8.1+ · Modulnummer 500210

Mit diesem Modul erfassen Mitarbeitende ihre Arbeitszeit direkt in Dolibarr. Ein separates Tool ist dafür nicht nötig.

## Funktionen

- **Stempeluhr**: Kommen, Gehen, Pause beginnen und Pause beenden per Knopfdruck (AJAX, ohne Neuladen der Seite). Jede Buchung wird automatisch dem angemeldeten Benutzer zugeordnet. Doppelte oder unlogische Buchungen werden abgelehnt, z. B. zweimal „Kommen“ hintereinander oder „Gehen“ während einer laufenden Pause.
- **Arbeitszeitkonto**: Für jeden Tag werden Soll, Ist und Saldo berechnet und zu einem laufenden Überstunden-/Minusstunden-Konto aufsummiert.
- **Wochen- und Monatsauswertung**: Tagesübersicht mit Buchungen, Pausen, Soll/Ist, Feiertagen und Abwesenheiten. Fehlende „Gehen“-Buchungen werden markiert. Administratoren können Buchungen nachtragen und löschen.
- **Urlaub**: Mitarbeitende stellen Anträge, die der Administrator oder der direkte Vorgesetzte genehmigt bzw. ablehnt. Überschneidungen und Anträge über das Kontingent hinaus werden verhindert. Angezeigt werden Resturlaub, genommene, beantragte und übertragene Tage.
- **Krankheit**: Krankmeldungen gelten sofort als genehmigt. Ab einer einstellbaren Dauer (Standard: 3 Tage) wird ein Attest verlangt; der Eingang des Attests lässt sich abhaken.
- **Administration**: Standard-Wochenstunden und -Urlaubstage, individuelle Werte pro Benutzer (inkl. Urlaubsübertrag und Startdatum des Zeitkontos), Feiertage nach Bundesland plus eigene Feiertage, Stichtag für den Verfall von Resturlaub sowie Attestpflicht ab X Tagen.
- Oberfläche in Deutsch und Englisch.

## Installation

### Variante A – Upload über die Dolibarr-Oberfläche
1. Als Administrator in Dolibarr anmelden.
2. **Start → Einstellungen → Module/Anwendungen** öffnen und zum Reiter **„Externes Modul bereitstellen“** wechseln.
3. Die Datei `worktime_dolibarr_module.zip` hochladen.
   (Dafür muss in `conf/conf.php` der Eintrag `$dolibarr_main_document_root_alt` auf das Verzeichnis `htdocs/custom` gesetzt sein, was die Standardinstallation bereits tut.)
4. Anschließend das Modul aktivieren (siehe unten).

### Variante B – Manuell entpacken
1. Die ZIP-Datei so entpacken, dass der Ordner `worktime` hier liegt: `<dolibarr>/htdocs/custom/worktime/`
   ```bash
   cd /pfad/zu/dolibarr/htdocs/custom
   unzip worktime_dolibarr_module.zip
   chown -R www-data:www-data worktime   # Webserver-Benutzer anpassen
   ```
2. In `conf/conf.php` prüfen, ob das Custom-Verzeichnis aktiv ist:
   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/pfad/zu/dolibarr/htdocs/custom';
   ```

### Modul aktivieren
**Start → Einstellungen → Module/Anwendungen**, im Bereich **Personalwesen (HR)** das Modul **„Arbeitszeiterfassung“** einschalten. Dabei werden die Datenbanktabellen automatisch angelegt:
`llx_worktime_entries`, `llx_worktime_absences`, `llx_worktime_settings`.

Danach erscheint oben das Menü **„Arbeitszeit“** mit den Einträgen Stempeluhr, Wochenübersicht, Monatsübersicht, Abwesenheiten und Einstellungen.

## Rechte vergeben

Unter **Benutzer & Gruppen → Benutzer → Reiter „Berechtigungen“** (oder für eine Gruppe):

| Recht | Bedeutung |
|---|---|
| Eigene Arbeitszeiten ansehen (read) | Stempeluhr und eigene Auswertungen ansehen |
| Arbeitszeit stempeln / Abwesenheiten beantragen (write) | Kommen/Gehen/Pause buchen, Urlaub und Krankheit melden |
| Arbeitszeiterfassung verwalten (admin) | Alle Mitarbeitenden einsehen, Buchungen korrigieren, Anträge genehmigen, Einstellungen |

Neue Benutzer erhalten *read* und *write* standardmäßig. Das Recht *admin* sollten nur Personalverantwortliche bekommen.

**Genehmigung durch Vorgesetzte:** Ist beim Benutzer im Dolibarr-Feld **„Vorgesetzter“** jemand eingetragen, kann diese Person die Urlaubsanträge des Mitarbeiters auch ohne Admin-Recht genehmigen.

## Einstellungen

**Arbeitszeit → Einstellungen** (oder Modulliste → Zahnrad):

- **Allgemein**: Standard-Wochenstunden (z. B. 40), Standard-Urlaubstage (z. B. 30), Bundesland für Feiertage, Stichtag für den Resturlaub (Format `MM-TT`, z. B. `03-31`), Attestpflicht ab X Krankheitstagen.
- **Mitarbeiter**: Abweichende Wochenstunden und Urlaubstage, Urlaubsübertrag aus dem Vorjahr und das Startdatum des Arbeitszeitkontos für jeden Benutzer.
- **Feiertage**: Eigene Feiertage, eine Angabe pro Zeile. `JJJJ-MM-TT` gilt einmalig, `MM-TT` jedes Jahr (z. B. `12-24` für Heiligabend). Darunter steht eine Vorschau der Feiertage des laufenden Jahres.

## Berechnungsregeln

- **Tagessoll** = Wochenstunden ÷ 5 (Montag bis Freitag). Samstag, Sonntag und Feiertage haben ein Soll von 0.
- **Ist** = Summe aller Zeiten von Kommen bis Gehen abzüglich der Pausen. Für einen noch offenen Tag wird bis zum aktuellen Zeitpunkt gerechnet.
- Genehmigter **Urlaub** und **Krankheit** zählen als erfülltes Soll (Saldo 0). Urlaubstage werden nur an Arbeitstagen gezählt, Feiertage und Wochenenden also nicht.
- Das **Arbeitszeitkonto** summiert die Tagessalden ab dem Startdatum des Kontos (Standard: erste Buchung des Benutzers).
- **Resturlaub** = Jahresanspruch + Übertrag − genommene Tage. Ein nicht genommener Übertrag verfällt nach dem eingestellten Stichtag.

## Hinweise

- Stempelzeiten werden als UTC in der Datenbank gespeichert und jeweils in der Zeitzone des angemeldeten Benutzers angezeigt. Die Zeitzone kommt vom Browser des Benutzers (Dolibarr-Mechanik, `$_SESSION['dol_tz_string']`); die PHP-Einstellung `date.timezone` ist dafür nicht relevant. Gibt ein Verwalter eine Buchung für jemanden nach, wird die eingegebene Zeit in seiner eigenen Zeitzone interpretiert. Bereits gespeicherte Einträge werden als UTC gelesen – sie bleiben ab Version 1.2.0 gültig.
- Deinstallation: Beim Deaktivieren des Moduls bleiben die Daten erhalten. Erst wenn die Tabellen manuell gelöscht werden, sind die Daten entfernt.
- **Kompatibilität:** Dolibarr 23.0 (getestet mit Dolibarr 23.0.0, PHP 8.2 und MariaDB 10.11). Mindestanforderungen laut Modul: Dolibarr 23.0 und PHP 8.1. Ältere Dolibarr-Versionen (14–22) werden von dieser Version nicht mehr unterstützt; Dolibarr verweigert dort die Aktivierung.

## Änderungen in Version 1.1.0 (Anpassung an Dolibarr 23)

- Mindestversionen auf Dolibarr 23.0 und PHP 8.1 angehoben, `module_position` als Ganzzahl.
- Menüs: Bedingungen über `isModEnabled('worktime')` und `$user->hasRight(...)`, kein `prefix` mehr beim Hauptmenü.
- Konfiguration wird überall über `getDolGlobalString()` / `getDolGlobalInt()` gelesen statt direkt über `$conf->global`.
- Rechteprüfung über `$user->hasRight()` statt `$user->rights->...`, Modulprüfung über `isModEnabled()`.
- Ganzzahl-Parameter werden über `GETPOSTINT()` gelesen.
- Die Datenbankspalte `llx_user.statut` bleibt in den SQL-Abfragen unverändert, weil sie auch in Dolibarr 23 noch so heißt (nur das PHP-Attribut heißt `$user->status`).

## Änderungen in Version 1.2.0 (Zeitzonen)

- Fehler behoben: Kommen/Gehen/Pause wurden in der PHP-Serverzeitzone statt in der Benutzerzeitzone gespeichert und angezeigt (auf Servern mit `date.timezone=UTC` liefen alle Zeiten um die Zeitdifferenz falsch).
- `entry_time` wird explizit als UTC gespeichert; die Anzeige und die Tagesabgrenzung (Heute, Wochen-/Monatsgrenzen) erfolgen in der Zeitzone des angemeldeten Benutzers über Dolibarrs `tzuserrel`-Mechanik.

## Verzeichnisstruktur

```
worktime/
├── core/modules/modWorktime.class.php   Moduldefinition (Rechte, Menüs, Konstanten)
├── sql/                                 Tabellen und Indizes
├── class/                               Geschäftslogik (Zeiten, Abwesenheiten)
├── lib/worktime.lib.php                 Feiertage, Formatierung, Rechte-Helfer
├── ajax/timeclock.php                   JSON-Endpunkt der Stempeluhr
├── index.php                            Stempeluhr
├── report_weekly.php / report_monthly.php
├── absence.php                          Urlaub & Krankheit
├── admin/setup.php                      Einstellungen
├── css/, js/, img/, langs/
```
