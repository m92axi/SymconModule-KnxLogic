# KnxLogicLight
Dieses Modul stellt eine Logik zur Steuerung von KNX-Lichtszenen in einem Raum bereit. Es kombiniert verschiedene Eingänge wie Präsenzmelder, Bewegungsmelder, Taster und Tag/Nacht-Signale, um das Licht intelligent zu schalten.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Funktionsbeschreibung](#2-funktionsbeschreibung)
3. [Voraussetzungen](#3-voraussetzungen)
4. [Software-Installation](#4-software-installation)
5. [Einrichten der Instanzen in IP-Symcon](#5-einrichten-der-instanzen-in-ip-symcon)
6. [Statusvariablen und Profile](#6-statusvariablen-und-profile)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

*   **Automatische Lichtsteuerung:** Schaltet Lichtszenen basierend auf Präsenz- und Bewegungsmeldern.
*   **Tag/Nacht-Modus:** Verwendet unterschiedliche Szenen und Nachlaufzeiten für Tag- und Nachtbetrieb.
*   **Manuelle Steuerung:** Ermöglicht die manuelle Übersteuerung der Automatik durch Taster oder Szenenaufrufe. Der manuelle Modus hat eine einstellbare Dauer, nach der die Automatik wieder aktiv wird.
*   **Szenen-Durchschaltung (Cycling):** Ermöglicht das Durchschalten einer definierten Szenenabfolge durch erneutes Betätigen des Tasters.
*   **Master/Client-Funktionalität:**
    *   **Als Master:** Bleibt aktiv, solange verknüpfte Client-Instanzen (z.B. angrenzende Räume) Präsenz melden. Sendet Szenen an Clients weiter.
    *   **Helligkeitssteuerung:** Berücksichtigt die Umgebungshelligkeit, um unnötiges Einschalten zu vermeiden und das Licht bei ausreichender Helligkeit automatisch auszuschalten.
    *   **Als Client:** Empfängt Szenen vom Master.
*   **Dynamische Konfiguration:** Das Konfigurationsformular passt sich an (z.B. Ausblenden von Nacht-Optionen wenn nicht benötigt).

### 2. Funktionsbeschreibung

Das Modul arbeitet als Zustandsautomat, der zwischen **Automatik** und **Manuell** unterscheidet.

**Präsenzerkennung (Automatik)**
Der Raum gilt als "Präsent", wenn mindestens eine der folgenden Bedingungen erfüllt ist:
1.  Ein **Präsenzmelder** (Typ: Zustand) ist aktiv.
2.  Ein **Bewegungsmelder** (Typ: Event) hat ausgelöst und die **Nachlaufzeit** ist noch nicht abgelaufen.
3.  Eine verknüpfte **Client-Instanz** meldet Präsenz (z.B. Flur bleibt an, solange im Büro Licht ist).

**Schaltverhalten**
*   **Bei Präsenz:** Es wird die konfigurierte Szene für Tag (`SceneOn`) oder Nacht (`SceneOnNight`) an die KNX-Variable gesendet. Ist die Option "Automatisch einschalten bei Dunkelheit" aktiv, geschieht dies nur, wenn die **Helligkeitsschwelle** unterschritten wird.
*   **Bei Abwesenheit:** Es wird die "Aus"-Szene (`SceneOff`) gesendet, außer eine Master-Instanz ist noch aktiv. In diesem Fall wird auf die zuletzt vom Master gesendete Szene gewechselt.

**Manueller Modus**
Wird über den *Manuellen Schalter* oder einen *Szenen-Eingang* (konfiguriert als Manuell) eingegriffen, wechselt das Modul in den manuellen Modus.
*   Sensoren und Client-Instanzen werden in diesem Modus ignoriert (das Licht bleibt im gewählten Zustand).
*   Ein Timer (`ManualDuration`) läuft ab. Nach Ablauf fällt das Modul automatisch in den Automatik-Modus zurück und prüft erneut die Sensoren.
*   Der *Automatik Schalter* kann genutzt werden, um den manuellen Modus sofort zu beenden.

**Szenen-Durchschaltung (Cycling)**
Ist eine **Szenen Sequenz** konfiguriert, kann durch erneutes Senden eines "EIN"-Befehls zur nächsten Szene in der Liste geschaltet werden.
*   Im **Manuellen Modus**: Durch erneutes Betätigen des *Manuellen Schalters*.
*   Im **Automatik Modus**: Durch erneutes Betätigen des *Automatik Schalters* (sofern dieser als Taster für "Licht An" genutzt wird).
Eine integrierte Sperrzeit verhindert, dass Szenen bei Prellen oder zu schnellem Drücken übersprungen werden.

### 3. Voraussetzungen

- IP-Symcon ab Version 6.0

### 4. Software-Installation

* Über den Module Store das 'KnxLogic'-Modul installieren.
* Alternativ über das Module Control (`Kerninstanzen -> Modules -> Hinzufügen`) folgende URL hinzufügen: `https://github.com/m92axi/SymconModule-KnxLogic`

### 5. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'KnxLogicLight'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der Dokumentation der Instanzen

#### Konfigurationsseite

**KNX Eingänge**

Feld                 | Beschreibung
-------------------- | ------------------
Sensoren             | Liste von Präsenz- (Zustand) oder Bewegungsmeldern (Event).
Szenen Eingänge      | KNX-Szenen, die von extern empfangen werden (z.B. Taster). Können Automatik oder Manuell auslösen.
Manueller Schalter   | DPT 1 Schalter zum Erzwingen des manuellen Modus (Ein/Aus).
Automatik Schalter   | DPT 1 Schalter zum Erzwingen des Automatik-Modus.
Tag/Nacht Schalter   | DPT 1 Schalter für Tag/Nacht-Umschaltung.
Tag/Nacht Logik      | Invertierung des Tag/Nacht-Signals.
Helligkeitssensoren  | Liste von Helligkeitssensoren (Lux, Float oder Integer) und deren prozentuale Gewichtung für die Durchschnittsberechnung.

**KNX Ausgänge**

Feld                       | Beschreibung
-------------------------- | ------------------
KNX Szenen Variable        | Die zu steuernde KNX-Variable (DPT 17/18).
KNX Szenen Speicher Variable | Optional: DPT 1 Variable, die signalisiert, dass eine Szene gespeichert wird (verhindert Rückkopplung).
Manueller Status Ausgang   | DPT 1 Status, ob Manuell aktiv ist.

**Einstellungen**

Feld                               | Beschreibung
---------------------------------- | ------------------
Szene bei Präsenz (EIN) - Tag      | Szenennummer für Tag.
Szene bei Präsenz (EIN) - Nacht    | Szenennummer für Nacht.
Szene bei Abwesenheit (AUS)        | Szenennummer für Aus.
Szenen Sequenz (Durchschalten)     | Liste von Szenen, die durch wiederholtes Schalten durchlaufen werden.
Bewegungsmelder Nachlaufzeit       | Zeit in Sekunden, wie lange Bewegung nachwirkt (getrennt für Tag/Nacht).
Aktualisierungsintervall           | Intervall für die Restzeitanzeige.
Helligkeitsschwelle (Tag/Nacht)    | Lux-Wert, unter dem das Licht bei Präsenz eingeschaltet wird.
Automatisch einschalten bei Dunkelheit | Aktiviert das Einschalten bei Präsenz nur, wenn es dunkel genug ist.
Automatisch ausschalten bei Helligkeit | Schaltet das Licht aus, wenn es hell genug wird, auch wenn noch Präsenz besteht.
Manuelle Modus Dauer               | Zeit in Sekunden bis Rückfall in Automatik.
Client Instanzen                   | Untergeordnete Instanzen, deren Präsenz diese Instanz aktiv hält.

### 6. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name             | Typ       | Beschreibung
---------------- | --------- | ------------
Presence State   | Boolean   | Aktueller Präsenzstatus (True = Anwesend).
Remaining Time   | Integer   | Verbleibende Zeit (Nachlaufzeit oder Manuell-Timer).
Manual Active    | Boolean   | True, wenn manueller Modus aktiv ist.
Day Mode         | Boolean   | True, wenn Tag-Modus aktiv ist.
Current Brightness | Float     | Die aktuell berechnete, gewichtete durchschnittliche Helligkeit in Lux.

### 7. PHP-Befehlsreferenz

`KLL_SimulateMotion(integer $InstanzID);`
Simuliert eine Bewegungserkennung (startet Nachlaufzeit).

`KLL_ResetMotionTimer(integer $InstanzID);`
Setzt den Bewegungstimer zurück (führt meist zu Abwesenheit).

`KLL_SceneFromMaster(integer $InstanzID, integer $Scene, boolean $PresenceState);`
Interne Funktion für Master/Client-Kommunikation.