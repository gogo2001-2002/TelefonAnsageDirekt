# Telefonansage Direkt – Version 1.1 (IP-Symcon 9 mit Queue)

Eigenes IP-Symcon-Modul für ausgehende VoIP-Anrufe mit automatischer Sprachausgabe. Telefonnummer und Ansagetext werden bei jedem Aufruf als Parameter übergeben. Die Aufträge werden über eine FIFO-Warteschlange verarbeitet.

## Zusammenfassung

Das Modul führt ausgehende Telefonansagen über die konfigurierte VoIP-Instanz aus. AWS Polly erzeugt die Sprachausgabe. Nach Gesprächsannahme wartet das Modul etwa zwei Sekunden vor der Wiedergabe. Anrufaufträge werden in einer FIFO-Warteschlange gespeichert, die Neustarts übersteht. Wartezeit auf Annahme, Anzahl der Wiederholungen und Pause vor Wiederholungen sind konfigurierbar.

## Funktionen

- **`TAD_StartCall(Instanz-ID, Telefonnummer, Ansagetext)`** – Fügt einen Anrufauftrag zur Warteschlange hinzu.

  Beispiel:

  ```php
  TAD_StartCall(12345, '+491701234567', 'Achtung, eine Störung liegt vor.');
  ```

- **`TAD_ClearQueue(Instanz-ID)`** – Leert die Warteschlange. Ein bereits laufender Anruf wird nicht beendet.
- **`TAD_Hangup(Instanz-ID)`** – Beendet den aktiven Anruf.

## Voraussetzungen

- IP-Symcon 9
- Funktionierende VoIP-Instanz
- AWS-Polly-Text-to-Speech-Instanz

## Verhalten beim Anruf

Nach Annahme des Gesprächs wartet das Modul ungefähr zwei Sekunden vor der Sprachausgabe. Die Warteschlange bleibt über Neustarts hinweg erhalten. Bei entsprechenden Fehlern können Wiederholungen mit konfigurierbarer Anzahl und Pause ausgeführt werden.
