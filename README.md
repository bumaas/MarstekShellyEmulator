# MarstekShellyEmulator

IP-Symcon-Modul zur Emulation eines Shelly Pro 3EM für eine Marstek Venus E.

## Status

Funktionsfähiger Minimalemulator für Shelly Pro 3EM über den Symcon-UDP-Parent. Die Implementierung ist bewusst schlank und auf die Marstek-Kopplung fokussiert.

## Struktur

- `ShellyEmulator/`: Symcon-Instanzmodul
- `libs/`: gemeinsame Hilfsklassen
- `docs/`: Konzept und Protokollnotizen
- `tests/`: lokale Smoke-Tests für Codec und UTF-8-Verhalten

## MVP-Ziel

- Messwerte aus Symcon-Variablen lesen
- Shelly-Pro-3EM-kompatible UDP-RPC-Antworten liefern
- Minimalmenge für die Venus-E-Kopplung bereitstellen

## Konfiguration

- Der UDP-Port wird am Parent-Socket konfiguriert, nicht im Modul selbst.

Empfohlene Reihenfolge für die erste Zuordnung im Formular:

1. `Gesamt > Leistung Gesamt`
2. alternativ `Gesamt > Leistung Bezug` und `Gesamt > Leistung Einspeisung`
3. optional `Gesamt > Zähler Bezug` und `Gesamt > Zähler Einspeisung`
4. optional `L1` bis `L3`, wenn echte Phasenwerte vorhanden sind

Regeln:

- Eine direkte signierte Gesamtleistung hat Vorrang vor der Ableitung aus Bezug und Einspeisung.
- Wenn keine direkte Gesamtleistung vorhanden ist, berechnet das Modul die Gesamtleistung aus `Bezug - Einspeisung`.
- Zählerwerte können mit `Umrechnungsfaktor Zählerwerte` skaliert werden, z. B. `1000` für Quellen in `kWh`, wenn der Emulator `Wh` liefern soll.
- Phasenwerte sind optional. Fehlen sie, kann der `Phasenmodus` die Gesamtleistung intern auf Phasen abbilden.
- Wenn keine Phasenwerte zugeordnet sind, sollte `Phasenmodus` nicht auf `Direkt` stehen.
- Die Hinweise in den `ExpansionPanel`s des Formulars beschreiben jeweils, welche Variablen in dem Bereich erwartet werden.

Priorität für erste Praxistests:

1. Wirkleistung gesamt
2. phasenspezifische Wirkleistung
3. Spannung je Phase
4. Strom je Phase
5. Frequenz

Einschätzung:

- Für die Regelung ist die Wirkleistung voraussichtlich der wichtigste Wert.
- Für die Marstek-Regelung sind vor allem `act_power` je Phase und `total_act_power` relevant.
- Phasenspezifische Leistungswerte können für Plausibilisierung, Anzeige oder phasennähere Auswertung vorteilhaft sein.
- Spannung und Strom sind nachrangig, aber als Zusatzinformationen sinnvoll.
- Frequenz ist für den ersten Betrieb voraussichtlich am wenigsten relevant.

## Unterstützte RPC-Methoden

- `Shelly.GetDeviceInfo`
- `Shelly.GetStatus`
- `Shelly.ListMethods`
- `Sys.GetStatus`
- `Sys.GetConfig`
- `EM.GetStatus`

## Lokale Tests

Einfacher Smoke-Test ohne separates Framework:

```powershell
php .\tests\codec_smoke_test.php
php .\tests\measurement_calculator_smoke_test.php
php .\tests\udp_send_test.php 127.0.0.1 1010 Shelly.GetDeviceInfo
php .\tests\udp_send_test.php 127.0.0.1 1010 Shelly.GetStatus
php .\tests\udp_send_test.php 127.0.0.1 1010 Shelly.ListMethods
php .\tests\udp_send_test.php 127.0.0.1 1010 Sys.GetStatus
php .\tests\udp_send_test.php 127.0.0.1 1010 Sys.GetConfig
php .\tests\udp_send_test.php 127.0.0.1 1010 EM.GetStatus
```
