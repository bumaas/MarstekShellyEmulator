# Umsetzungsgrundlage: Symcon Modul zur Emulation eines Shelly Pro 3EM

## 1. Ziel

Es soll ein neues, eigenständiges Symcon-Modul-Repository entstehen, das sich gegenüber einer Marstek Venus E als kompatibler Smart Meter ausgibt.

Das Modul soll:

- Messdaten aus Symcon-Variablen entgegennehmen
- daraus ein konsistentes Netzbezugs-/Einspeisebild berechnen
- über UDP eine hinreichend kompatible Shelly-Pro-3EM-Schnittstelle bereitstellen
- von einer Venus E als Smart Meter erkannt und genutzt werden können

`evccMQTT` dient dabei nur als Beispiel für Symcon-Modulaufbau, nicht als funktionale Grundlage.

Begriffsdefinition:

- `MVP` steht in diesem Dokument für `Minimum Viable Product`, also die kleinste lauffähige und testbare Ausbaustufe, die den technischen Zielnachweis für die Venus-E-Kopplung erbringen soll

## 2. Nicht-Ziele

- kein Fork und keine Code-Übernahme aus `b2500-meter`
- keine Abhängigkeit von `evcc`
- kein universeller Emulator für beliebige Geräte in der ersten Ausbaustufe
- keine Nachbildung der kompletten Shelly-Weboberfläche
- kein separates Provider-Modul im MVP

## 3. Technische Leitentscheidung

Zielgerät für die erste Version ist `Shelly Pro 3EM`.

Begründung:

- Die Venus E unterstützt nach aktueller öffentlicher Marstek-Angabe `Shelly Pro 3EM` und `CT002`.
- Die offizielle Shelly-Dokumentation für Gen2 und EM-RPC ist ausreichend, um eine eigene kompatible Implementierung abzuleiten.
- Für die Kopplung mit der Venus E ist für den MVP von `RPC over UDP` auf Port `1010` auszugehen. Port `2220` bleibt als spätere Kompatibilitätserweiterung für weitere Marstek-/Firmware-Varianten vorgesehen, ist für das Venus-E-MVP aber nicht primär. Discovery über UDP-Broadcasts bleibt davon unberührt relevant.

## 4. Zielarchitektur

Das neue Repository sollte genau ein fachliches Ziel haben: Messdaten in Symcon aufnehmen und als Shelly-Pro-3EM-kompatibles UDP-Gerät ausgeben.

Empfohlene Architektur für den MVP:

1. genau ein Modul: `ShellyEmulator`
2. gemeinsame Hilfsklassen in `libs/`
3. Symcon-Variablen als einzige Datenquelle

### 4.1 ShellyEmulator

Aufgabe:

- Konfiguration der Quellvariablen
- Erzeugung eines atomaren internen Mess-Snapshots
- Bearbeitung eingehender UDP-RPC-Requests
- Rückgabe Shelly-kompatibler JSON-Antworten
- Bereitstellung von Device-, System- und EM-Status
- Discovery-/Broadcast-Behandlung mit hoher Priorität nach dem ersten lauffähigen Venus-E-MVP

Das Modul enthält nur die für den MVP nötige Quellenanbindung. Ein separates Provider-Modul wird erst nach erfolgreichem Feldtest sinnvoll.

### 4.2 Gemeinsame Bibliothek

In `libs/`:

- `MeasurementSnapshot.php`
- `MeasurementCalculator.php`
- `ShellyResponseBuilder.php`
- `UdpRpcCodec.php`
- `DiscoveryHandler.php`
- `Validation.php`

## 5. Empfohlene Repo-Struktur

```text
marstekShellyEmulator/
  library.json
  README.md
  LICENSE
  docs/
    protocol-notes.md
    shelly-pro-3em-emulator-konzept.md
  libs/
    MeasurementSnapshot.php
    MeasurementCalculator.php
    ShellyResponseBuilder.php
    UdpRpcCodec.php
    DiscoveryHandler.php
    Validation.php
  ShellyEmulator/
    module.php
    module.json
    form.json
    locale.json
```

## 6. Symcon-Modulmodell

Empfehlung für das erste Repo:

- `ShellyEmulator` als Instanzmodul vom Typ 3 auf Basis von `IPSModuleStrict`
- als kompatibler Parent ist ein UDP Socket in Symcon vorzusehen
- die Parent-Anbindung wird explizit über `RequireParent('{82347F20-F541-41E1-AC5B-A636FD3AE2D8}')` hergestellt
- für den Datenfluss zum UDP Socket ist `Erweitert (UDP)` zu verwenden
- `GetConfigurationForParent()` wird vorerst bewusst nicht verwendet, da dies in der Zielumgebung beim Parent-Verbinden instabil war
- kein Parent- oder Child-Zwang im MVP
- alle benötigten Variablen-IDs direkt als Properties im Modul

Das ist für ein erstes Release einfacher testbar als eine früh verallgemeinerte Zwei-Modul-Architektur.

## 7. Datenmodell

Interner Snapshot, den der Emulator immer atomar liest:

```php
final class MeasurementSnapshot
{
    public int $timestamp;
    public ?float $totalActivePowerW;
    public ?float $phaseAActivePowerW;
    public ?float $phaseBActivePowerW;
    public ?float $phaseCActivePowerW;
    public ?float $phaseAVoltageV;
    public ?float $phaseBVoltageV;
    public ?float $phaseCVoltageV;
    public ?float $phaseACurrentA;
    public ?float $phaseBCurrentA;
    public ?float $phaseCCurrentA;
    public ?float $frequencyHz;
    public ?float $totalImportedEnergyWh;
    public ?float $totalExportedEnergyWh;
    public bool $isValid;
}
```

Regeln:

- Werte immer in SI-nahen Einheiten intern halten
- fehlende Werte explizit `null`
- Antworten nie aus Einzelvariablen ad hoc zusammensetzen, sondern immer aus einem Snapshot
- Alter der Messung prüfen, damit die Venus E keine veralteten Werte als live interpretiert

## 8. Quellenmodell für das erste Release

Die erste Version sollte keine dynamische Plugin-Welt bauen.

Empfohlen für den MVP:

- Symcon-Variablen für:
    - Gesamtleistung
    - alternativ getrennt Netzbezug Leistung und Netzeinspeisung Leistung
    - optional Leistung L1/L2/L3
    - optional Spannung L1/L2/L3
    - optional Strom L1/L2/L3
    - optional Energieimport
    - optional Energieexport

Berechnungsregeln:

- Wenn nur Gesamtleistung vorhanden ist:
    - auf L1 abbilden oder gleichmäßig auf 3 Phasen verteilen, konfigurierbar
- Wenn Netzbezug und Netzeinspeisung separat vorhanden sind:
    - Gesamtleistung als `Netzbezug - Netzeinspeisung` berechnen
- Wenn Leistung je Phase vorhanden ist:
    - Gesamtleistung daraus berechnen
- Strom aus `P / U` nur dann ableiten, wenn Spannung vorhanden und plausibel
- Default-Spannung optional auf `230 V`, aber klar als Fallback markieren

## 9. Shelly-Pro-3EM-Schnittstelle

Für das Modul bedeutet das:

- ein UDP-basierter Listener in Symcon
- JSON-RPC Parsing aus UDP-Datagrammen
- Antworten an die jeweilige Absenderadresse
- Discovery-/Broadcast-Unterstützung zeitnah nach dem ersten lauffähigen Port-1010-MVP

Die offizielle Shelly-Dokumentation beschreibt für `EM` im Triphase-Profil unter anderem Werte wie `a_current`, `a_voltage`, `a_act_power`, `a_aprt_power` und `a_pf` sowie die entsprechenden Felder für Phase B und C.

### 9.1 MVP-Methoden

Diese Methoden sind für das Venus-E-MVP mit hoher Wahrscheinlichkeit zuerst nötig:

1. `Shelly.GetDeviceInfo`
2. `Sys.GetStatus`
3. `Sys.GetConfig`
4. `EM.GetStatus`

Optional früh:

1. `EMData.GetStatus`
2. `Shelly.GetStatus`
3. Discovery-/Annonce-Nachrichten für spätere breitere Marstek-Kompatibilität

Wichtig:

Noch nicht verifiziert ist, welche dieser Methoden die Venus E tatsächlich zwingend abfragt. Das muss mit Request-Mitschnitten gegenüber einem echten Shelly Pro 3EM oder gegenüber einer Testkopplung verifiziert werden.

## 10. Antwortstrategie

Nicht die komplette Shelly-Welt emulieren. Stattdessen:

- kleine, stabile Teilmenge liefern
- unbekannte Methoden mit sauberem JSON-RPC-Fehler beantworten
- Felder nur liefern, wenn sie konsistent berechnet werden können
- feste Device-Metadaten konfigurierbar halten:
    - Device Name
    - MAC
    - Seriennummer
    - Firmware-Version
    - IP-Adresse

Wichtiger Punkt:

Die Venus E könnte Plausibilitätsprüfungen auf Gerätetyp, Komponentenliste oder Firmwarestrings machen. Diese Felder sollten deshalb zentral konfigurierbar und nicht im Code verstreut sein.

Zusätzliche Anforderung:

- Zeichenketten in Konfiguration, JSON-Antworten, Debug-Ausgaben und sonstigen Textfeldern müssen UTF-8-korrekt verarbeitet und ausgegeben werden
- deutsche Umlaute und Sonderzeichen (`ae`, `oe`, `ue` als Ersatzschreibweise sind nicht ausreichend) müssen bei Eingabe, Speicherung und Ausgabe korrekt darstellbar sein, insbesondere `ae/oe/ue` im Sinne von `ä`, `ö`, `ü`, `Ä`, `Ö`, `Ü` sowie `ß`

## 11. Vorzeichen- und Leistungslogik

Hier liegt das größte fachliche Risiko.

Zu klären und dann strikt umzusetzen:

- Welche Vorzeichen erwartet die Venus E für Netzbezug und Einspeisung?
- Muss Gesamtleistung die Summe der Phasen exakt spiegeln?
- Dürfen negative Phasenleistungen auftreten?
- Welche Felder sind informativ, welche steuern wirklich das Regelverhalten?

Empfohlene interne Festlegung:

- positive Wirkleistung = Netzbezug
- negative Wirkleistung = Einspeisung

Diese Regel muss aber in der Testphase gegen das reale Verhalten validiert werden.

## 12. Symcon-seitige UDP-Umsetzung

Empfohlene technische Lösung:

- Nutzung des Symcon `UDP Socket` als Parent-IO
- Dekodierung der Datagramme direkt im Modul
- Antworten gezielt an den Request-Absender
- Request und Response mit Debug-Level protokollieren
- optional später zyklische Announce- oder Discovery-Antworten

Technische Festlegung für den aktuellen Stand:

- eingehende Daten vom UDP Socket werden als `Erweitert (UDP)`-Paket an `ReceiveData()` übergeben
- der `Buffer` des UDP Socket wird in der Zielumgebung hexkodiert geliefert und muss vor dem JSON-Parsing dekodiert werden
- Antworten an den UDP Socket werden als `Erweitert (UDP)`-Paket mit `ClientIP`, `ClientPort`, `Broadcast` und hexkodiertem `Buffer` über `SendDataToParent()` gesendet
- der UDP-Rückweg gegen einen lokalen PHP-Testclient ist erfolgreich verifiziert

Das ist gegenüber einem separaten externen Prozess der einfachere Weg für Installation und Betrieb innerhalb von Symcon.

## 13. Konfigurationsoberfläche

### 13.1 Emulator-Modul

Properties:

- Device Name
- Hostname
- simulierte Firmware-Version
- simulierte MAC-Adresse
- Variablen-ID Gesamtleistung
- alternativ Variablen-ID Netzbezug Leistung und Variablen-ID Netzeinspeisung Leistung
- Variablen-ID L1/L2/L3 Leistung
- Variablen-ID L1/L2/L3 Spannung
- Variablen-ID L1/L2/L3 Strom
- Variablen-ID Energieimport
- Variablen-ID Energieexport
- Umrechnungsfaktor für Energiezähler, Standard `1.0`
- maximal erlaubtes Messalter
- Phasenmodus:
    - `direct`
    - `split_total`
    - `total_on_l1`

Empfohlene Reihenfolge für die Erstkonfiguration:

1. zuerst `Leistung Gesamt` belegen, wenn eine signierte Summenleistung vorhanden ist
2. alternativ `Leistung Bezug` und `Leistung Einspeisung` belegen, wenn getrennte positive Kanäle vorliegen
3. danach optional `Zähler Bezug` und `Zähler Einspeisung` belegen
4. Phasenfelder `L1` bis `L3` nur belegen, wenn echte phasenspezifische Werte verfügbar sind

Verhaltensregeln der Zuordnung:

- eine direkte signierte Gesamtleistung hat Vorrang vor der Ableitung aus Bezug und Einspeisung
- wenn keine direkte Gesamtleistung vorhanden ist, wird die Summenleistung als `Bezug - Einspeisung` berechnet
- Energiezähler können über einen Faktor skaliert werden, z. B. `1000` für Quellen in `kWh`
- phasenspezifische Leistungswerte werden bevorzugt verwendet, wenn sie vorhanden sind
- fehlen Phasenwerte vollständig, bildet der `Phasenmodus` die Summenleistung intern auf die Phasen ab
- wenn keine Phasenwerte konfiguriert sind, ist `direct` als Phasenmodus in der Regel nicht sinnvoll

Priorität für Feldtest und Regelungsverhalten:

1. Gesamtwirkleistung ist der wichtigste Eingangswert für die erste Kopplung
2. phasenspezifische Wirkleistungen sind ein fachlicher Vorteil, aber nicht erste Pflicht
3. Spannungen und Ströme sind nützliche Zusatzwerte für Plausibilisierung und vollständigere EM-Statusdaten
4. Frequenz ist für den ersten Regelungstest voraussichtlich nachrangig
5. für die Marstek-Regelung sind insbesondere `act_power` je Phase und `total_act_power` relevant

Ableitung für die Praxis:

- erster Funktionstest mit nur `Leistung Gesamt`
- zweiter Test mit echten Phasenleistungen `L1` bis `L3`
- danach optional Ergänzung von Spannung und Strom
- Frequenz nur ergänzen, wenn reale Gegenstellen sie sichtbar auswerten oder auf unvollständige Statusdaten empfindlich reagieren
- wenn keine echten Phasenwerte vorhanden sind, vor dem Test `Phasenmodus` bewusst auf `split_total` oder `total_on_l1` setzen

Bedeutung der Formularbereiche:

- `Gerät und Netzwerk`: Netzwerkparameter und Gerätekennung der Emulation
  Der UDP-Port selbst wird am Parent-Socket konfiguriert und nicht als Modul-Property geführt.
- `Gesamt`: globale Leistungs- und Energievariablen des Zählerpunkts; für die Marstek-Regelung sind hier insbesondere `total_act_power` und die daraus ableitbaren Phasenleistungen relevant
- `L1`, `L2`, `L3`: optionale phasenspezifische Leistungs-, Spannungs- und Stromwerte
- `Verarbeitung`: Regeln für Messalter und interne Ableitung fehlender Werte

Statusanzeigen:

- letzter Request
- letzter erfolgreicher Snapshot
- Alter des Snapshots
- zuletzt beantwortete Methode

## 14. Fehlermodell

Zwingend vorzusehen:

- keine gültigen Messdaten vorhanden
- Messdaten zu alt
- Pflichtwerte für angeforderten Endpunkt fehlen
- ungültige Request-Methode
- ungültige Parameter

Antwortverhalten:

- UDP technisch korrekt
- JSON-RPC semantisch korrekt
- Fehler für Clients nachvollziehbar
- parallel Symcon-Status und Debug-Meldung setzen

## 15. Teststrategie

### 15.1 Unit-nahe Tests

Auch wenn Symcon-Module oft ohne klassisches Testsetup starten, sollte die Logik in testbare Hilfsklassen ausgelagert werden:

- Snapshot-Berechnung
- Null-/Fallback-Logik
- JSON-Response-Builder
- Vorzeichenlogik

### 15.2 Integrationsnahe Tests

- Requests gegen den UDP-Listener mit Beispielpayloads
- Validierung der JSON-Struktur
- Verhalten bei fehlenden Werten
- Verhalten bei veralteten Werten

### 15.3 Feldtest

Entscheidend für Release:

- Venus E gegen Emulator verbinden
- alle Requests mitschneiden
- Minimalmenge der benötigten Methoden ableiten
- prüfen, welche Felder für die Regelung wirklich ausgewertet werden

### 15.4 Dokumentationsstandard

Die Implementierung soll gezielt, aber nicht überladen dokumentiert werden. Ziel ist Wartbarkeit ohne Kommentarballast.

Festlegung:

- selbsterklärender Code hat Vorrang vor Kommentaren
- öffentliche Methoden, Schnittstellen und zentrale Hilfsklassen erhalten eine kurze fachliche Dokumentation, wenn Zweck, Eingaben oder Rückgabewerte nicht unmittelbar klar sind
- Kommentare sollen das Warum und fachliche Randbedingungen erklären, nicht den offensichtlichen Ablauf jeder einzelnen Codezeile
- komplexe Protokollannahmen, Vorzeichenregeln, Fallback-Logik und bekannte Inkompatibilitäten müssen direkt am relevanten Code dokumentiert werden
- JSON-RPC-, Shelly- und Venus-E-spezifische Annahmen müssen zusätzlich in `docs/` festgehalten werden, wenn sie für mehrere Klassen relevant sind
- Debug-Ausgaben sollen aussagekräftig, aber knapp sein und keine Redundanz zu bereits klar lesbarem Code erzeugen
- für nicht offensichtliche Konstanten, Magic Values, Ports, Feldnamen und Sonderfälle ist eine kurze Erklärung am Code vorzusehen
- bei neuen Klassen oder größeren Methoden ist zu prüfen, ob ein kurzer Kopfkommentar zur Verantwortung der Einheit nötig ist
- keine reinen Wiederholungskommentare wie "weist Wert zu", "liest Variable" oder "sendet Antwort"
- README dokumentiert nur Projektaufbau, Nutzung und Testausführung; fachliche Regeln und Protokollentscheidungen gehören ins Konzept oder in `docs/`

## 16. MVP-Scope

Die erste veröffentlichbare Version sollte bewusst klein bleiben:

1. ein Emulator-Modul
2. nur `Shelly Pro 3EM`
3. nur UDP/RPC
4. nur lesende Methoden
5. keine vollständige Discovery-Autokonfiguration im ersten Venus-E-MVP
6. keine Web-UI-Emulation

## 17. Offene technische Fragen

Diese Punkte müssen vor oder während der Implementierung verifiziert werden:

1. Reicht für die Venus E zunächst `RPC over UDP` auf Port `1010` oder erwartet sie zusätzlich zwingende Discovery-Mechanismen?
2. Welche exakten RPC-Methoden werden beim Pairing und im Laufbetrieb aufgerufen?
3. Ist `EM.GetStatus` ausreichend oder wird auch Energiedatenhistorie benötigt?
4. Welche Firmware- und Device-Strings akzeptiert die Venus E?
5. Welche Reaktionszeit ist nötig, damit das Pairing stabil bleibt?
6. Reicht ein monostatischer Snapshot oder werden Sequenz- oder Zeitwerte geprüft?

## 18. Umsetzungsreihenfolge

1. neues Repo anlegen
2. Grundgerüst für `library.json`, `module.json`, `README.md`
3. Snapshot-Modell und Berechnungslogik bauen
4. `ShellyEmulator` mit UDP-Listener und statischem `Shelly.GetDeviceInfo`
5. `EM.GetStatus` aus echtem Snapshot liefern
6. Logging und Fehlerbehandlung härten
7. Venus-E-Kompatibilität im Feldtest validieren
8. fehlende RPC-Methoden gezielt nachziehen

## 19. Lizenz- und Projektrahmen

Da das Repo eigenständig veröffentlicht werden soll und bewusst keine GPL-Ableitung entstehen soll:

- keine Codeübernahme aus `b2500-meter`
- nur eigene Implementierung
- Referenzprojekte nur als Verhaltens- und Testvergleich nutzen

Sinnvolle Lizenzkandidaten für das neue Repo:

- `MIT`
- `Apache-2.0`

## 20. Konkrete Empfehlung

Für das neue Projekt sollte die erste Entwicklungsiteration auf genau diesen Satz begrenzt werden:

- ein einzelnes Symcon-Modul mit Variablen-Mapping für Gesamtleistung und drei Phasen auf Port `1010` für Venus E
- alternativ direkter Ableitung der Gesamtleistung aus separatem Netzbezug und separater Netzeinspeisung
- UDP-basierter `Shelly Pro 3EM` Emulator, zunächst auf Port `1010` fokussiert
- funktionierende Antworten für `Shelly.GetDeviceInfo`, `Sys.GetStatus`, `Sys.GetConfig`, `EM.GetStatus`
- Test gegen reale Venus E

Alles andere ist Erweiterung nach dem ersten lauffähigen Nachweis.

## 21. Konkretes Repo-Skelett für den MVP

### 21.1 `library.json`

```json
{
  "id": "{D4D2C55E-3D9F-4B7D-A0E8-8AB0A4A4F001}",
  "author": "bumaas",
  "name": "MarstekShellyEmulator",
  "url": "https://github.com/<user>/MarstekShellyEmulator",
  "compatibility": {
    "version": "8.1"
  },
  "version": "0.1",
  "build": 1,
  "date": 1776981600
}
```

Hinweise:

- neue GUIDs für Repo und Modul verwenden
- `name` bewusst generisch halten
- `url` direkt auf das neue Repo zeigen lassen

### 21.2 `ShellyEmulator/module.json`

```json
{
  "id": "{6D7A8E57-0C5E-43AA-B6A2-1A2F55A02001}",
  "name": "ShellyEmulator",
  "type": 3,
  "vendor": "MarstekShellyEmulator",
  "aliases": ["Marstek Shelly Pro 3EM Emulator"],
  "parentRequirements": [
    "{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}"
  ],
  "childRequirements": [],
  "implemented": [
    "{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}"
  ],
  "prefix": "MSE",
  "url": "https://github.com/<user>/MarstekShellyEmulator"
}
```

### 21.3 `ShellyEmulator/form.json`

```json
{
  "elements": [
    {
      "type": "ValidationTextBox",
      "name": "deviceName",
      "caption": "Device Name"
    },
    {
      "type": "ValidationTextBox",
      "name": "hostname",
      "caption": "Hostname"
    },
    {
      "type": "ValidationTextBox",
      "name": "macAddress",
      "caption": "MAC Adresse"
    },
    {
      "type": "ValidationTextBox",
      "name": "firmwareVersion",
      "caption": "Firmware Version"
    },
    {
      "type": "SelectVariable",
      "name": "powerTotalVarId",
      "caption": "Variable Gesamtleistung"
    },
    {
      "type": "SelectVariable",
      "name": "gridImportPowerVarId",
      "caption": "Variable Netzbezug Leistung"
    },
    {
      "type": "SelectVariable",
      "name": "gridExportPowerVarId",
      "caption": "Variable Netzeinspeisung Leistung"
    },
    {
      "type": "SelectVariable",
      "name": "powerL1VarId",
      "caption": "Variable Leistung L1"
    },
    {
      "type": "SelectVariable",
      "name": "powerL2VarId",
      "caption": "Variable Leistung L2"
    },
    {
      "type": "SelectVariable",
      "name": "powerL3VarId",
      "caption": "Variable Leistung L3"
    },
    {
      "type": "SelectVariable",
      "name": "voltageL1VarId",
      "caption": "Variable Spannung L1"
    },
    {
      "type": "SelectVariable",
      "name": "voltageL2VarId",
      "caption": "Variable Spannung L2"
    },
    {
      "type": "SelectVariable",
      "name": "voltageL3VarId",
      "caption": "Variable Spannung L3"
    },
    {
      "type": "SelectVariable",
      "name": "currentL1VarId",
      "caption": "Variable Strom L1"
    },
    {
      "type": "SelectVariable",
      "name": "currentL2VarId",
      "caption": "Variable Strom L2"
    },
    {
      "type": "SelectVariable",
      "name": "currentL3VarId",
      "caption": "Variable Strom L3"
    },
    {
      "type": "SelectVariable",
      "name": "importEnergyVarId",
      "caption": "Variable Energieimport"
    },
    {
      "type": "SelectVariable",
      "name": "exportEnergyVarId",
      "caption": "Variable Energieexport"
    },
    {
      "type": "NumberSpinner",
      "name": "maxMeasurementAge",
      "caption": "Maximales Messalter in Sekunden"
    },
    {
      "type": "Select",
      "name": "phaseMode",
      "caption": "Phasenmodus",
      "options": [
        { "label": "Direkt", "value": "direct" },
        { "label": "Gesamtwert verteilen", "value": "split_total" },
        { "label": "Gesamtwert auf L1", "value": "total_on_l1" }
      ]
    }
  ],
  "actions": [
    {
      "type": "TestCenter"
    }
  ]
}
```

### 21.4 `ShellyEmulator/module.php`

Das Modul sollte in `Create()` mindestens diese Properties registrieren:

- `deviceName`
- `hostname`
- `macAddress`
- `firmwareVersion`
- `powerTotalVarId`
- `gridImportPowerVarId`
- `gridExportPowerVarId`
- `powerL1VarId`
- `powerL2VarId`
- `powerL3VarId`
- `voltageL1VarId`
- `voltageL2VarId`
- `voltageL3VarId`
- `currentL1VarId`
- `currentL2VarId`
- `currentL3VarId`
- `importEnergyVarId`
- `exportEnergyVarId`
- `maxMeasurementAge`
- `phaseMode`

Öffentliche Methoden für den ersten Wurf:

- `ApplyChanges(): void`
- `ReceiveData(string $JSONString): string`
- `GetConfigurationForm(): string`

Private Methoden:

- `buildSnapshot(): MeasurementSnapshot`
- `handleRpcRequest(string $payload, string $remoteIp, int $remotePort): ?array`
- `handleMethod(string $method, array $params): array`
- `sendUdpReply(array $message, string $remoteIp, int $remotePort): void`
- `extractTransportContext(string $jsonString): array`
- `buildUdpResponseEnvelope(string $payload, string $remoteIp, int $remotePort): array`

Wichtige aktuelle Festlegung:

- `MeasurementCalculator` und `ShellyResponseBuilder` greifen nicht direkt auf geschützte Methoden von `IPSModuleStrict` zu
- stattdessen werden benötigte Integer- und String-Properties im Modul gelesen und als Konfigurationsarrays an die Hilfsklassen übergeben

### 21.5 `libs/MeasurementSnapshot.php`

Enthält nur den unveränderlichen Messzustand.

Pflichtfelder für den MVP:

- `timestamp`
- `isValid`
- `totalActivePowerW`
- `phaseAActivePowerW`
- `phaseBActivePowerW`
- `phaseCActivePowerW`
- `phaseAVoltageV`
- `phaseBVoltageV`
- `phaseCVoltageV`
- `phaseACurrentA`
- `phaseBCurrentA`
- `phaseCCurrentA`
- `totalImportedEnergyWh`
- `totalExportedEnergyWh`

### 21.6 `libs/MeasurementCalculator.php`

Verantwortlich für:

- Lesen der Symcon-Variablenwerte
- Plausibilisierung
- Ermittlung fehlender Gesamt- oder Phasenwerte
- Berechnung von Strom aus Leistung und Spannung
- Kennzeichnung veralteter Messungen

### 21.7 `libs/UdpRpcCodec.php`

Verantwortlich für:

- JSON aus Datagramm lesen
- JSON-RPC-Struktur validieren
- ID, Methode und Parameter extrahieren
- Antwortobjekt aufbauen
- standardisierte Fehlerantworten erzeugen

### 21.8 `libs/ShellyResponseBuilder.php`

Verantwortlich für:

- `Shelly.GetDeviceInfo`
- `Sys.GetStatus`
- `Sys.GetConfig`
- `EM.GetStatus`

Die Klasse darf keine Variablen direkt lesen. Sie arbeitet nur auf Basis eines `MeasurementSnapshot`.

Die Klasse liest außerdem keine Modul-Properties direkt, sondern erhält dafür vorbereitete Konfigurationswerte aus dem Modul.

### 21.9 `libs/DiscoveryHandler.php`

Im MVP optional, aber als Datei bereits sinnvoll vorgesehen für:

- Erkennung von Broadcast-Anfragen
- spätere Discovery-Antworten
- mögliche Announce-Telegramme

### 21.10 Erste Implementierungsreihenfolge

1. `library.json`
2. `ShellyEmulator/module.json`
3. `ShellyEmulator/form.json`
4. `MeasurementSnapshot`
5. `MeasurementCalculator`
6. `UdpRpcCodec`
7. `ShellyResponseBuilder`
8. `module.php` mit statischer Antwort auf `Shelly.GetDeviceInfo`
9. `EM.GetStatus` aus Live-Snapshot
