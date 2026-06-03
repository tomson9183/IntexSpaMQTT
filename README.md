# 🎛️ Stufe 2: IP-Symcon-Modul (MQTT) mit Steuerung + Zeitplan

Voraussetzung: Stufe 1 (Brücke) läuft auf dem NAS.

> **Architektur-Hinweis:** Für die zuverlässigste Anbindung ist **IP-Symcon selbst der MQTT-Broker** (MQTT-Server-Modul). Die Brücke auf dem NAS verbindet sich dann zu IP-Symcon. Das ist eine kleine Anpassung an Stufe 1 (siehe Schritt 1).

---

## Schritt 1 – Brücke auf IP-Symcon zeigen lassen

In `bridge.py` auf dem NAS oben die MQTT-Adresse ändern:

```python
MQTT_HOST = "192.168.178.XX"   # IP des IP-Symcon-Rechners (Pi)
MQTT_PORT = 1883
```

(Den `mosquitto`-Container aus Stufe 1 kannst du dann stoppen – wir nutzen ab jetzt den MQTT-Server in IP-Symcon. Alternativ kann Mosquitto bleiben; dann müsste IP-Symcon als MQTT-**Client** dorthin verbinden – sag mir Bescheid, falls du das lieber möchtest.)

Bridge-Container in Portainer neu starten (Recreate).

---

## Schritt 2 – MQTT-Server in IP-Symcon anlegen

1. `Kern Instanzen` → **„Instanz hinzufügen"** → nach **„MQTT Server"** suchen → anlegen.
2. In der Konfiguration: **Port 1883** aktiv, „Anonyme Anmeldung erlauben" anhaken (lokales Netz).
3. Übernehmen. Damit ist IP-Symcon der Broker.

---

## Schritt 3 – Modul installieren

1. ZIP `IPSymcon-IntexSpaMQTT` entpacken, Inhalt (mit `library.json` ganz oben) ins GitHub-Repo `IPSymcon-IntexSpaMQTT` laden.
2. In IP-Symcon: `Kern Instanzen → Modules → +` → URL des Repos → installieren (muss grün sein).

---

## Schritt 4 – Instanz anlegen und verbinden

1. **„Instanz hinzufügen"** → **„IntexSpaMQTT"** wählen.
2. Beim Anlegen nach dem **Parent (MQTT Server)** fragen lassen → den eben erstellten **MQTT Server** auswählen.
3. In der Konfiguration: **MQTT-Basis-Thema** = `intexspa` (Standard).
4. Optional Energie-Manager aktivieren (Heizleistung 2200 W, Mindest-Überschuss 500 W).
5. Übernehmen.

Nach kurzer Zeit sollten sich die Variablen füllen (Ist-Temperatur, Status usw.) und „Verbunden" auf an gehen.

---

## Schritt 5 – Kachel einbinden

1. In der **Visualisierung (Kacheln)** auf **„Kachel hinzufügen"**.
2. Die **IntexSpaMQTT-Instanz** auswählen → die Kachel mit **Steuerung** und **Zeitplan** erscheint.
3. Über die zwei Tabs oben wechselst du zwischen Bedienung und Zeitplan-Editor.

---

## Schritt 6 – Energie-Manager (optional)

Wie gehabt: Im Energie-Manager ein „Allgemeiner Verbraucher" anlegen, als Schaltvariable **„EM Schaltvariable (Heizung)"** der Instanz wählen, Leistung = **„EM Leistungsaufnahme (W)"**, Modus „Automatisch".

---

## Zeitplan benutzen

Im Tab **„Zeitplan"**:
- Uhrzeit wählen, Aktion (Heizung/Filter/Sprudler/Jets/Hygienisierung oder Soll-Temperatur), Ein/Aus bzw. °C, Wochentage antippen → **„+ Eintrag hinzufügen"**.
- Einträge lassen sich per Häkchen aktivieren/deaktivieren und per ✖ löschen.
- Das Modul prüft jede Minute und führt fällige Einträge automatisch aus.

---

## Wenn etwas nicht läuft

| Problem | Lösung |
|---|---|
| Variablen bleiben leer | Brücke published auf IP-Symcon? MQTT-Server Port 1883? Basis-Thema = intexspa? |
| „Verbunden" bleibt aus | Brücke-Logs prüfen; zeigt sie „MQTT verbunden" mit der Pi-IP? |
| Schalten ohne Wirkung | In den Brücke-Logs erscheint „Befehl empfangen"? Falls nein: Topic/Parent prüfen. |
| Kachel leer | Instanz hat MQTT-Server als Parent? Status „Aktiv"? |

Sag mir, ob sich die Variablen füllen und ob Schalten funktioniert – dann optimieren wir Details (z. B. DP 114, falls es ein Timer/Fehlercode ist).
