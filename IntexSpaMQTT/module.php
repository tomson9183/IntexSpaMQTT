<?php

/**
 * IntexSpaMQTT – IP-Symcon Modul
 *
 * Steuert Intex PureSpa Whirlpools (Tuya, ab 2024) lokal über MQTT.
 * Datenquelle ist die tinytuya-Brücke (Docker), die per MQTT meldet.
 *
 * Kommuniziert als Kind des IP-Symcon MQTT-Servers (Broker).
 *
 * Funktionen: Heizung, Filter, Sprudler, Jets, Hygienisierung, Temperatur,
 * Kachel mit Steuerung + Zeitplan, Energie-Manager (PV-Überschuss).
 *
 * Autor: tomson9183
 * Version: 2.0.0
 */

declare(strict_types=1);

class IntexSpaMQTT extends IPSModuleStrict
{
    // MQTT-Server: Senden (Publish) an den Broker
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    // Zuordnung Schalt-Ident -> MQTT-Befehlsthema
    private const SET_TOPICS = [
        'Power'     => 'power',
        'Heater'    => 'heater',
        'Filter'    => 'filter',
        'Bubbles'   => 'bubbles',
        'Jets'      => 'jets',
        'Sanitizer' => 'sanitizer',
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('BaseTopic', 'intexspa');
        $this->RegisterPropertyBoolean('EnableEnergyManager', false);
        $this->RegisterPropertyInteger('PowerConsumptionHeating', 2200);
        $this->RegisterPropertyInteger('MinPVSurplus', 500);
        $this->RegisterPropertyInteger('PVSurplusVariableID', 0);

        $this->RegisterVariableBoolean('Power', 'Gerät Ein/Aus', '~Switch', 10);
        $this->EnableAction('Power');
        $this->RegisterVariableBoolean('Heater', 'Heizung', '~Switch', 20);
        $this->EnableAction('Heater');
        $this->RegisterVariableBoolean('Filter', 'Filterpumpe', '~Switch', 30);
        $this->EnableAction('Filter');
        $this->RegisterVariableBoolean('Bubbles', 'Luftsprudler', '~Switch', 40);
        $this->EnableAction('Bubbles');
        $this->RegisterVariableBoolean('Jets', 'Jets', '~Switch', 50);
        $this->EnableAction('Jets');
        $this->RegisterVariableBoolean('Sanitizer', 'Hygienisierung', '~Switch', 60);
        $this->EnableAction('Sanitizer');
        $this->RegisterVariableBoolean('Heating', 'Heizt gerade', '~Switch', 65);

        $this->RegisterVariableFloat('CurrentTemperature', 'Ist-Temperatur', 'ISPA.Temperature', 70);
        $this->RegisterVariableInteger('TargetTemperature', 'Soll-Temperatur', 'ISPA.TargetTemp', 80);
        $this->EnableAction('TargetTemperature');

        $this->RegisterVariableBoolean('Connected', 'Verbunden', '~Switch', 90);

        // Energie-Manager
        $this->RegisterVariableBoolean('EMSwitch', 'EM Schaltvariable (Heizung)', '~Switch', 100);
        $this->EnableAction('EMSwitch');
        $this->RegisterVariableInteger('EMPowerConsumption', 'EM Leistungsaufnahme (W)', '', 110);

        // PV-Vorrang: ob aktuell genug Überschuss zum Heizen da ist
        $this->RegisterVariableBoolean('PVHeatingAllowed', 'PV-Heizen erlaubt', '~Switch', 115);

        // Zeitplan (als JSON-String gespeichert)
        $this->RegisterVariableString('Schedule', 'Zeitplan (intern)', '', 120);

        // Zeitplan-Timer (jede Minute prüfen)
        $this->RegisterTimer('ScheduleTimer', 60000, 'ISPA_CheckSchedule($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Profile hier anlegen (NICHT in Create – dort ist der Kernel evtl. noch nicht bereit)
        $this->CreateProfiles();
        $this->ApplyProfile('CurrentTemperature', 'ISPA.Temperature');
        $this->ApplyProfile('TargetTemperature', 'ISPA.TargetTemp');

        // Interne Variablen verstecken
        IPS_SetHidden($this->GetIDForIdent('PVHeatingAllowed'), true);
        IPS_SetHidden($this->GetIDForIdent('Schedule'), true);

        $base = $this->ReadPropertyString('BaseTopic');
        // Nur Nachrichten unseres Spas vom MQTT-Server annehmen
        $this->SetReceiveDataFilter('.*' . preg_quote($base) . '.*');

        $this->SetValue('EMPowerConsumption', $this->ReadPropertyInteger('PowerConsumptionHeating'));
        IPS_SetHidden($this->GetIDForIdent('EMSwitch'), !$this->ReadPropertyBoolean('EnableEnergyManager'));
        IPS_SetHidden($this->GetIDForIdent('EMPowerConsumption'), !$this->ReadPropertyBoolean('EnableEnergyManager'));

        // Prüfen ob ein Parent (MQTT) verbunden ist
        if ($this->HasActiveParent()) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

        $this->UpdateVisualization();
    }

    private function ApplyProfile(string $ident, string $profile): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid && IPS_VariableProfileExists($profile)) {
            $var = IPS_GetVariable($vid);
            if (($var['VariableCustomProfile'] ?? '') === '' && ($var['VariableProfile'] ?? '') !== $profile) {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
        }
    }

    // ── Empfang von MQTT (über MQTT-Server-Parent) ────────────────────────────

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString);
        if ($data === null) {
            return '';
        }

        // MQTT-Server liefert Topic/Payload direkt; MQTT-Client in ->Buffer
        $topic = null;
        $payload = null;
        if (isset($data->Topic)) {
            $topic = $data->Topic;
            $payload = $data->Payload ?? '';
        } elseif (isset($data->Buffer)) {
            $buf = json_decode($data->Buffer);
            if (isset($buf->Topic)) {
                $topic = $buf->Topic;
                $payload = $buf->Payload ?? '';
            }
        }
        if ($topic === null) {
            return '';
        }

        $this->HandleTopic((string)$topic, (string)$payload);
        return '';
    }

    private function HandleTopic(string $topic, string $payload): void
    {
        $base = $this->ReadPropertyString('BaseTopic');
        $prefix = $base . '/status/';
        if (strpos($topic, $prefix) !== 0) {
            return;
        }
        $key = substr($topic, strlen($prefix));
        $on = (strtoupper($payload) === 'ON' || strtoupper($payload) === 'TRUE' || $payload === '1');

        switch ($key) {
            case 'online':
                $this->SetValue('Connected', strtolower($payload) === 'online');
                break;
            case 'power':     $this->SetValue('Power', $on); break;
            case 'heater':    $this->SetValue('Heater', $on); $this->SetValue('EMSwitch', $on); break;
            case 'filter':    $this->SetValue('Filter', $on); break;
            case 'bubbles':   $this->SetValue('Bubbles', $on); break;
            case 'jets':      $this->SetValue('Jets', $on); break;
            case 'sanitizer': $this->SetValue('Sanitizer', $on); break;
            case 'heating':   $this->SetValue('Heating', $on); break;
            case 'current_temp':
                if (is_numeric($payload)) {
                    $this->SetValue('CurrentTemperature', (float)$payload);
                }
                break;
            case 'target_temp':
                if (is_numeric($payload)) {
                    $t = (int)round((float)$payload);
                    if ($t >= 20 && $t <= 40) {
                        $this->SetValue('TargetTemperature', $t);
                    }
                }
                break;
        }
        $this->UpdateVisualization();
    }

    // ── Aktionen ──────────────────────────────────────────────────────────────

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'Power':
            case 'Filter':
            case 'Bubbles':
            case 'Jets':
            case 'Sanitizer':
                $this->PublishSet(self::SET_TOPICS[$ident], ((bool)$value) ? 'ON' : 'OFF');
                $this->SetValue($ident, (bool)$value);
                break;
            case 'Heater':
            case 'EMSwitch':
                $on = (bool)$value;
                $this->PublishSet('heater', $on ? 'ON' : 'OFF');
                $this->SetValue('Heater', $on);
                $this->SetValue('EMSwitch', $on);
                break;
            case 'TargetTemperature':
                $t = max(20, min(40, (int)$value));
                $this->PublishSet('target_temp', (string)$t);
                $this->SetValue('TargetTemperature', $t);
                break;
            case 'Schedule':
                $this->SaveSchedule((string)$value);
                break;
        }
        $this->UpdateVisualization();
    }

    /**
     * Verbindet diese Instanz per Knopfdruck mit dem MQTT-Server
     * (umgeht den hängenden "Gateway ändern"-Dialog).
     */
    public function ConnectGateway(): void
    {
        $servers = IPS_GetInstanceListByModuleID('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        if (count($servers) === 0) {
            echo "Kein 'MQTT Server' gefunden. Bitte zuerst eine MQTT-Server-Instanz anlegen.";
            return;
        }

        // Den RICHTIGEN MQTT-Server wählen: der mit eigenem Parent (Server Socket)
        // und aktivem Status. Verwaiste Server (Parent 0) führen zu "inkompatibel".
        $best = 0;
        $list = '';
        foreach ($servers as $s) {
            $si = IPS_GetInstance($s);
            $hasSocket = ((int) $si['ConnectionID'] !== 0);
            $active = ((int) $si['InstanceStatus'] === 102);
            $list .= "\n  - ID $s: " . IPS_GetName($s)
                   . " (Status " . $si['InstanceStatus'] . ", Socket: " . ($hasSocket ? 'ja' : 'nein') . ")";
            if ($best === 0 && $hasSocket) {
                $best = $s; // erster Server mit Socket
            }
            if ($hasSocket && $active) {
                $best = $s; // bevorzugt aktiv + Socket
            }
        }
        if ($best === 0) {
            $best = $servers[0];
        }

        try {
            IPS_ConnectInstance($this->InstanceID, $best);
            IPS_ApplyChanges($this->InstanceID);
            $info = IPS_GetInstance($this->InstanceID);
            if ((int) $info['ConnectionID'] === (int) $best) {
                echo "ERFOLG: verbunden mit MQTT Server ID $best (" . IPS_GetName($best) . ").\n"
                   . "Bitte Konsole neu laden.\n\nGefundene MQTT-Server:" . $list;
            } else {
                echo "Verbindung nicht gesetzt (ConnectionID = " . $info['ConnectionID'] . ").\n\nGefundene MQTT-Server:" . $list;
            }
        } catch (Exception $e) {
            echo "Fehler beim Verbinden mit ID $best: " . $e->getMessage()
               . "\n\nGefundene MQTT-Server:" . $list;
        }
    }

    /**
     * Status von der Brücke anfordern (Brücke published ohnehin regelmäßig).
     */
    public function RequestStatus(): void
    {
        // Optionales Anstoßen – die Brücke published zyklisch von selbst.
        $this->PublishSet('refresh', '1');
    }

    // ── Energie-Manager ───────────────────────────────────────────────────────

    public function SetHeaterByPVSurplus(float $pvSurplusWatts): void
    {
        if (!$this->ReadPropertyBoolean('EnableEnergyManager')) {
            return;
        }
        $heaterPower = $this->ReadPropertyInteger('PowerConsumptionHeating');
        $minSurplus = $this->ReadPropertyInteger('MinPVSurplus');

        if ($pvSurplusWatts >= $heaterPower) {
            $this->SetValue('PVHeatingAllowed', true);
            if (!$this->GetValue('Heater')) {
                $this->RequestAction('Heater', true);
            }
        } elseif ($pvSurplusWatts < $minSurplus) {
            $this->SetValue('PVHeatingAllowed', false);
            if ($this->GetValue('Heater')) {
                $this->RequestAction('Heater', false);
            }
        }
        // Im Hysterese-Band: Zustand und PVHeatingAllowed unverändert lassen
    }

    /**
     * Liest die konfigurierte PV-Überschuss-Variable und steuert danach
     * die Heizung (PV-Vorrang). Wird jede Minute aufgerufen.
     */
    private function EvaluatePV(): void
    {
        if (!$this->ReadPropertyBoolean('EnableEnergyManager')) {
            return;
        }
        $varID = $this->ReadPropertyInteger('PVSurplusVariableID');
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return;
        }
        $watts = (float)GetValue($varID);
        $this->SetHeaterByPVSurplus($watts);
    }

    // ── Zeitplan ──────────────────────────────────────────────────────────────

    private function SaveSchedule(string $json): void
    {
        // Validieren
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            $arr = [];
        }
        $this->SetValue('Schedule', json_encode($arr));
    }

    /**
     * Wird jede Minute aufgerufen: wertet PV-Überschuss aus und führt fällige
     * Zeitplan-Einträge aus.
     */
    public function CheckSchedule(): void
    {
        $this->EvaluatePV();

        $json = $this->GetValue('Schedule');
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return;
        }

        $now = time();
        $hm = date('H:i', $now);
        $weekday = (int)date('N', $now) - 1; // 0=Montag .. 6=Sonntag

        foreach ($entries as $e) {
            if (empty($e['enabled'])) {
                continue;
            }
            if (($e['time'] ?? '') !== $hm) {
                continue;
            }
            $days = $e['days'] ?? [];
            if (!in_array($weekday, $days, true)) {
                continue;
            }
            // Doppel-Auslösung in derselben Minute verhindern
            $fireKey = 'fired_' . ($e['id'] ?? md5(json_encode($e)));
            $stamp = date('Y-m-d H:i', $now);
            if ($this->GetBuffer($fireKey) === $stamp) {
                continue;
            }
            $this->SetBuffer($fireKey, $stamp);

            $this->ExecuteScheduleAction($e);
        }
    }

    private function ExecuteScheduleAction(array $e): void
    {
        $action = $e['action'] ?? '';
        $value = $e['value'] ?? null;

        if ($action === 'target') {
            $t = max(20, min(40, (int)$value));
            $this->RequestAction('TargetTemperature', $t);
            $this->LogMessage("Zeitplan: Soll-Temperatur auf {$t} °C", KL_NOTIFY);
        } elseif (in_array($action, ['heater', 'filter', 'bubbles', 'jets', 'sanitizer'], true)) {
            $ident = ucfirst($action);
            $on = (bool)$value;

            // PV-Vorrang: Heizung per Zeitplan nur einschalten, wenn genug PV-Überschuss da ist
            if ($ident === 'Heater' && $on
                && $this->ReadPropertyBoolean('EnableEnergyManager')
                && $this->ReadPropertyInteger('PVSurplusVariableID') > 0
                && !$this->GetValue('PVHeatingAllowed')) {
                $this->LogMessage('Zeitplan: Heizung verschoben – kein ausreichender PV-Überschuss.', KL_NOTIFY);
                return;
            }

            $this->RequestAction($ident, $on);
            $this->LogMessage("Zeitplan: {$ident} -> " . ($on ? 'EIN' : 'AUS'), KL_NOTIFY);
        }
    }

    // ── MQTT senden ─────────────────────────────────────────────────────────

    private function PublishSet(string $suffix, string $payload): void
    {
        if (!$this->HasActiveParent()) {
            $this->LogMessage('Kein MQTT-Parent verbunden – Befehl nicht gesendet.', KL_WARNING);
            return;
        }
        $base = $this->ReadPropertyString('BaseTopic');
        // Wert ins TOPIC codieren – der MQTT-Server überträgt Topics zuverlässig,
        // den Payload verstümmelt er bei selbstgebauten Paketen. Format: base/set/<name>/<wert>
        $topic = $base . '/set/' . $suffix . '/' . $payload;

        $json = json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => $payload,
        ]);
        $this->SendDataToParent($json);
    }

    // ── Visualisierung ──────────────────────────────────────────────────────

    private function BuildStateJSON(): string
    {
        $schedule = json_decode($this->GetValue('Schedule'), true);
        if (!is_array($schedule)) {
            $schedule = [];
        }
        return json_encode([
            'connected' => (bool)$this->GetValue('Connected'),
            'power'     => (bool)$this->GetValue('Power'),
            'heater'    => (bool)$this->GetValue('Heater'),
            'filter'    => (bool)$this->GetValue('Filter'),
            'bubbles'   => (bool)$this->GetValue('Bubbles'),
            'jets'      => (bool)$this->GetValue('Jets'),
            'sanitizer' => (bool)$this->GetValue('Sanitizer'),
            'heating'   => (bool)$this->GetValue('Heating'),
            'current'   => (float)$this->GetValue('CurrentTemperature'),
            'target'    => (int)$this->GetValue('TargetTemperature'),
            'schedule'  => $schedule,
        ]);
    }

    private function UpdateVisualization(): void
    {
        $this->UpdateVisualizationValue($this->BuildStateJSON());
    }

    public function GetVisualizationTile(): string
    {
        $file = __DIR__ . '/tile.html';
        if (!is_file($file)) {
            return '<div style="padding:16px;font-family:sans-serif;">Kachel konnte nicht geladen werden (tile.html fehlt im Modulordner).</div>';
        }
        $html = @file_get_contents($file);
        if (!is_string($html)) {
            return '<div style="padding:16px;font-family:sans-serif;">Kachel konnte nicht gelesen werden.</div>';
        }
        return str_replace('/*INITIAL_STATE*/null', $this->BuildStateJSON(), $html);
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    private function CreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('ISPA.Temperature')) {
            IPS_CreateVariableProfile('ISPA.Temperature', 2);
            IPS_SetVariableProfileText('ISPA.Temperature', '', ' °C');
            IPS_SetVariableProfileValues('ISPA.Temperature', 0, 50, 0.5);
            IPS_SetVariableProfileIcon('ISPA.Temperature', 'Temperature');
        }
        if (!IPS_VariableProfileExists('ISPA.TargetTemp')) {
            IPS_CreateVariableProfile('ISPA.TargetTemp', 1);
            IPS_SetVariableProfileText('ISPA.TargetTemp', '', ' °C');
            IPS_SetVariableProfileValues('ISPA.TargetTemp', 20, 40, 1);
            IPS_SetVariableProfileIcon('ISPA.TargetTemp', 'Temperature');
        }
    }
}
