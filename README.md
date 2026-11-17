# Telefonansage Direkt

IP-Symcon-Modul für ausgehende Telefonansagen über eine VoIP-Instanz. Ein Skript übergibt Telefonnummer und Text; AWS Polly erzeugt daraus zur Laufzeit eine WAV-Datei. Aufträge werden einzeln in einer persistenten FIFO-Warteschlange verarbeitet.

- **Version:** 1.1
- **Modulpräfix:** `TAD`
- **Kompatibilität laut Manifest:** IP-Symcon 9.0+
- **Modul-GUID:** `{A4D1D3B7-7B2D-4B0B-8E2D-9D7F5E2A6C41}`
- **Bibliotheks-GUID:** `{D1C78E5A-8A42-4C25-A4A4-6A1E96E0C931}`

## Funktionsweise

1. `TAD_StartCall` prüft, ob Rufnummer und Ansagetext nicht leer sind, und hängt den Auftrag hinten an die Queue.
2. Wenn kein Anruf aktiv ist, startet der Queue-Worker den nächsten Auftrag über die konfigurierte VoIP-Instanz.
3. Der Verbindungsstatus wird etwa sekündlich abgefragt. Bei Annahme wartet das Modul ungefähr zwei Sekunden.
4. Die ausgewählte AWS-Polly-Instanz erzeugt eine WAV-Datei aus dem Text. Die VoIP-Instanz spielt sie ab.
5. Nach erkannter WAV-Dauer plus zwei Sekunden (mindestens drei Sekunden) wird aufgelegt und der nächste Queue-Eintrag verarbeitet.

Pro Instanz läuft höchstens ein Anruf zugleich. Mehrere Modulinstanzen haben unabhängige Warteschlangen.

## Voraussetzungen und Kostenhinweis

- IP-Symcon 9.0 oder neuer.
- Eine eingerichtete VoIP-Instanz mit den Funktionen `VoIP_Connect`, `VoIP_GetConnection`, `VoIP_PlayWave` und `VoIP_Disconnect`.
- Eine eingerichtete AWS-Polly-TTS-Instanz, die `TTSAWSPOLLY_GenerateFile` bereitstellt und eine vorhandene WAV-Datei liefert.
- Beide Instanzen müssen für den Symcon-Dienst erreichbar sein.

**Kosten:** AWS Polly kann abhängig von AWS-Konto, Tarif und Nutzung Kosten verursachen. Vor dem produktiven Einsatz die aktuellen Konditionen und das eigene AWS-Nutzungslimit prüfen. Telefonie kann ebenfalls Kosten verursachen. Dieses Modul richtet keine Konten ein und enthält keine Zugangsdaten.

## Installation und Einrichtung

1. In IP-Symcon unter **Kerninstanzen → Module** dieses Repository hinzufügen: [gogo2001-2002/TelefonAnsageDirekt](https://github.com/gogo2001-2002/TelefonAnsageDirekt).
2. Die Bibliothek über die Modulverwaltung installieren oder aktualisieren.
3. Eine Instanz **Telefonansage Direkt** anlegen.
4. In der Instanzkonfiguration VoIP- und AWS-Polly-TTS-Instanz auswählen.
5. Timeout, Wiederholungen und Wiederholungspause einstellen und Konfiguration übernehmen.
6. Mit einem kurzen Text und einer eigenen Telefonnummer gezielt testen. Der Status sollte zunächst „Bereit“ bzw. „Bereit – Warteschlange leer.“ anzeigen.

### Konfiguration

| Einstellung | Standard | Bereich | Beschreibung |
| --- | ---: | ---: | --- |
| VoIP-Instanz | keine | vorhandene Instanz | Für Rufaufbau, Verbindungsstatus, WAV-Wiedergabe und Auflegen |
| AWS-Polly-TTS-Instanz | keine | vorhandene Instanz | Wandelt den Ansagetext in Sprache/WAV um |
| Wartezeit auf Annahme | 30 s | 5–300 s | Zeit bis eine nicht angenommene Verbindung als Timeout behandelt wird |
| Wiederholungen bei Fehler | 1 | 0–5 | Zusätzliche Versuche nach dem ersten Versuch; 0 deaktiviert Wiederholungen |
| Pause vor Wiederholung | 15 s | 1–600 s | Verzögerung vor dem nächsten Versuch |

## Aufruf aus Skripten

Alle öffentlichen Funktionen verwenden das Modulpräfix `TAD`; die Instanz-ID ist jeweils das erste Argument.

### Anruf einreihen

```php
$accepted = TAD_StartCall(12345, '+491701234567', 'Achtung, eine Störung liegt vor.');
```

Die Instanz-ID durch die Objekt-ID der Modulinstanz ersetzen. Das Telefonnummernformat wird nicht normalisiert; es muss zur VoIP-Integration passen. `true` bedeutet, dass der Auftrag in die Queue aufgenommen wurde. Es bestätigt weder den erfolgreichen Rufaufbau noch die spätere Wiedergabe. Bei leerer Rufnummer oder leerem Text liefert die Funktion `false` und setzt „Letzter Fehler“.

### Queue leeren

```php
TAD_ClearQueue(12345);
```

Löscht alle wartenden Aufträge, beendet aber keinen aktiven Anruf.

### Aktiven Anruf beenden

```php
TAD_Hangup(12345);
```

Beendet den aktuellen Anruf und stößt die Prüfung des nächsten Queue-Eintrags an. Beide Aktionen sind auch über Schaltflächen in der Instanzkonfiguration verfügbar.

## Statusvariablen

| Variable | Typ | Bedeutung |
| --- | --- | --- |
| **Status** | Zeichenkette | Laufzustand, z. B. „Bereit“, „Anruf wird aufgebaut“, „Gespräch angenommen“ oder Fehler-/Wiederholungsmeldung |
| **Aktiver Anruf** | Zeichenkette | Rufnummer des gerade bearbeiteten Auftrags; sonst leer |
| **Warteschlangenlänge** | Ganzzahl | Noch nicht gestartete Aufträge; der aktive Anruf zählt nicht mit |
| **Letzte Ansage** | Zeichenkette | Text des zuletzt aufgenommenen Auftrags, keine Wiedergabebestätigung |
| **Letzter Fehler** | Zeichenkette | Zuletzt gespeicherter Fehler; wird bei Erfolg nicht automatisch gelöscht |

Status- und Fehlermeldungen schreibt das Modul außerdem ins Symcon-Log (Sender „Telefonansage Direkt“ bzw. „Telefonansage Direkt Fehler“).

## Queue, Wiederholungen und Neustartverhalten

- Die FIFO-Queue liegt in einem Instanzattribut und bleibt bei normalen Neustarts gespeichert.
- Wiederholbare Fehler stellen den Auftrag an den Anfang der Queue zurück. Der konfigurierte Wert bezeichnet zusätzliche Versuche nach dem ersten.
- Fehler bei ungültigen Instanz-IDs oder fehlender TTS-Funktion werden nicht wiederholt. Verbindungsfehler, nicht angenommene Anrufe, TTS-/Wiedergabefehler und Laufzeitfehler können wiederholt werden.
- Nach Ausschöpfen der Wiederholungen wird der Auftrag verworfen; weitere Queue-Einträge werden trotzdem bearbeitet.
- Bei Symcon-Neustart während eines laufenden Anrufs kann der gespeicherte aktuelle Auftrag erneut eingereiht werden. Das Modul setzt seinen lokalen Verbindungsstatus zurück, trennt aber eine möglicherweise beim Provider bestehende Verbindung nicht blind. Telefonstatus nach Neustart prüfen; in ungünstigen Fällen kann ein Auftrag erneut angerufen werden.

## Fehlerbehebung

| Symptom/Meldung | Prüfen |
| --- | --- |
| VoIP-Instanz-ID ungültig | Existierende VoIP-Instanz in der Konfiguration auswählen |
| TTS-Instanz-ID ungültig | Existierende AWS-Polly-TTS-Instanz auswählen |
| `TTSAWSPOLLY_GenerateFile() ist nicht verfügbar` | AWS-Polly-Modul installiert und geladen? Stimmt die Integration? |
| Keine gültige Verbindungs-ID | VoIP-Registrierung, ausgehende Telefonie, Rufnummernformat und VoIP-Log prüfen |
| Anruf nicht angenommen/Timeout | Zielnummer, Erreichbarkeit und konfigurierten Annahme-Timeout prüfen |
| Keine vorhandene WAV-Datei | TTS-Ausgabe, Dateizugriff des Symcon-Dienstes und TTS-Log prüfen |
| WAV-Datei kann nicht abgespielt werden | Verbindung und WAV-Kompatibilität der VoIP-Integration prüfen |
| Auftrag bleibt warten | Status, Queue-Länge, Instanzauswahl und Symcon-Log prüfen; Wiederholungspause beachten |

## Technische Hinweise und Grenzen

- Timer: `QueueWorker` verarbeitet Queue-Einträge, `CheckCall` überwacht den Rufaufbau, `Hangup` beendet den Anruf nach der Wiedergabe.
- Die WAV-Dauer wird aus dem RIFF/WAVE-Header gelesen. Ist dieser nicht auswertbar, verwendet das Modul für die Auflegezeit einen Fallback von zehn Sekunden.
- Eine angenommene Verbindung ist keine Empfängerbestätigung. Das Modul erkennt weder, ob eine Person zugehört hat, noch eine DTMF-Bestätigung.
- Das Modul setzt keine Ruhezeiten, Sperrlisten, Rufnummernfreigaben oder Einwilligungsprüfungen um. Der Betreiber muss die rechtmäßige und angemessene Nutzung sicherstellen.
- VoIP- und AWS-Zugangsdaten bleiben in den jeweiligen IP-Symcon-Instanzen.

## Versionierung und Mitarbeit

`main` ist für stabile Versionen vorgesehen; `develop` enthält Entwicklung und Teststände. Für produktiven Betrieb `main` verwenden.

Bei Fehlerberichten Symcon- und Modulversion, Statusmeldung sowie passenden Logauszug angeben. Rufnummern, Ansagetexte, Zugangsdaten und andere personenbezogene Informationen vor dem Teilen entfernen. Lizenz- und Nutzungsbedingungen der eingebundenen Module sowie Telefonie- und AWS-Anbieter beachten.

## Dateien

- [Modulverzeichnis](TelefonansageDirekt/)
- [Modulcode](TelefonansageDirekt/module.php)
- [Konfigurationsformular](TelefonansageDirekt/form.json)
- [Modulinterne Doku](TelefonansageDirekt/README.md)
