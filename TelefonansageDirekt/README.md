# Telefonansage Direkt – Moduldokumentation

Die ausführliche deutsche Dokumentation zu Installation, Einrichtung, Konfiguration, Skriptfunktionen, Statusvariablen, Queue-Verhalten, Wiederholungen, Neustarts und Fehlerbehebung steht in der [Repository-Dokumentation](../README.md).

## Kurzüberblick

Das Modul führt ausgehende VoIP-Anrufe mit individuellen Ansagetexten aus. AWS Polly erzeugt aus dem Text eine WAV-Datei; nach Annahme des Gesprächs wird diese über die konfigurierte VoIP-Instanz abgespielt. Aufträge laufen einzeln über eine FIFO-Warteschlange, die Neustarts übersteht.

Voraussetzungen sind IP-Symcon 9.0+, eine VoIP-Instanz sowie eine AWS-Polly-TTS-Instanz. AWS Polly und Telefonie können nutzungsabhängige Kosten verursachen.

## Öffentliche Funktionen

```php
TAD_StartCall(12345, '+491701234567', 'Ihre Ansage.');
TAD_ClearQueue(12345); // löscht wartende Aufträge, beendet keinen aktiven Anruf
TAD_Hangup(12345);     // beendet den aktiven Anruf
```

Die Instanz-ID ist jeweils das erste Argument. `TAD_StartCall` bestätigt mit `true` nur die Aufnahme in die Warteschlange, nicht die erfolgreiche Zustellung.
