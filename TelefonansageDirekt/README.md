# Telefonansage Direkt – IP-Symcon 9 mit Queue

Eigenes IP-Symcon-Modul für ausgehende VoIP-Anrufe mit automatischer Sprachausgabe. Telefonnummer und Ansagetext werden bei jedem Aufruf als Parameter übergeben. Die Aufträge werden über eine FIFO-Warteschlange verarbeitet.

## Voraussetzungen

- IP-Symcon 9
- Funktionierende VoIP-Instanz
- AWS-Polly-Text-to-Speech-Instanz

## Aufruf

```php
TAD_StartCall(12345, '+491701234567', 'Achtung, es liegt eine Störung vor.');
```

Nach Annahme des Gesprächs wartet das Modul ungefähr zwei Sekunden vor der Sprachausgabe.
