# Protocol Notes

Offene Punkte für die Implementierung:

- exakte UDP-RPC-Requests der Venus E mitschneiden
- Discovery- und Broadcast-Verhalten validieren
- benötigte RPC-Methoden im Pairing und Laufbetrieb bestimmen
- Vorzeichenlogik für Bezug und Einspeisung am echten Gerät prüfen
- tatsächliches Symcon-UDP-IO und dessen erwartetes `SendDataToParent()`-Envelope festlegen; aktuell sendet das Modul bewusst mehrere gängige Feldnamen parallel (`Buffer`, `Data`, `Payload`, `ClientIP`, `RemoteIP`, `ClientPort`, `RemotePort`)
