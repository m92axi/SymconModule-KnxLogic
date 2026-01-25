<?php

declare(strict_types=1);

// CLASS KnxLogicLight
class KnxLogicLight extends IPSModule
{

    /**
     * In contrast to Construct, this function is called only once when creating the instance and starting IP-Symcon.
     * Therefore, status variables and module properties which the module requires permanently should be created here.
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Sensors', '[]');
        $this->RegisterPropertyString('SceneInputs', '[]');
        $this->RegisterPropertyInteger('SceneVariableID', 0);
        $this->RegisterPropertyInteger('SceneSaveVariableID', 0);
        $this->RegisterPropertyInteger('ManualSwitchID', 0);
        $this->RegisterPropertyInteger('AutoSwitchID', 0);
        $this->RegisterPropertyInteger('DayNightSwitchID', 0);
        $this->RegisterPropertyInteger('DayNightLogic', 0);
        $this->RegisterPropertyInteger('ManualStateOutputID', 0);
        $this->RegisterPropertyInteger('SceneOn', 0);
        $this->RegisterPropertyInteger('SceneOnNight', 0);
        $this->RegisterPropertyInteger('SceneOff', 0);
        $this->RegisterPropertyInteger('MotionDuration', 60);
        $this->RegisterPropertyInteger('MotionDurationNight', 60);
        $this->RegisterPropertyInteger('UpdateInterval', 10);
        $this->RegisterPropertyInteger('ManualDuration', 3600);
        $this->RegisterPropertyString('BrightnessSensors', '[]');
        $this->RegisterPropertyInteger('BrightnessThresholdDay', 300);
        $this->RegisterPropertyInteger('BrightnessThresholdNight', 50);
        $this->RegisterPropertyBoolean('AutoOnOnBrightness', true);
        $this->RegisterPropertyBoolean('AutoOffOnBrightness', false);
        $this->RegisterPropertyString('ClientInstances', '[]');

        $this->RegisterTimer('MotionTimer', 0, 'KLL_MotionTimerExpired(' . $this->InstanceID . ');');
        $this->RegisterTimer('UpdateTimer', 0, 'KLL_UpdateRemainingTime(' . $this->InstanceID . ');');
        $this->RegisterTimer('ManualTimer', 0, 'KLL_ManualTimerExpired(' . $this->InstanceID . ');');
        $this->RegisterVariableBoolean('PresenceState', 'Presence State', '~Presence', 0);
        $this->RegisterVariableInteger('RemainingTime', 'Remaining Time', '', 0);
        $this->RegisterVariableBoolean('ManualActive', 'Manual Active', '~Switch', 0);
        $this->RegisterVariableBoolean('DayState', 'Day Mode', '~Switch', 0);
        $this->RegisterVariableFloat('CurrentBrightness', 'Current Brightness', '~Illumination', 0);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     */
    public function Destroy()
    {
        parent::Destroy();
    }

    /**
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     */
    public function GetConfigurationForm()
    {
        // Get Form
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Check Day/Night Switch
        $dayNightSwitchID = $this->ReadPropertyInteger('DayNightSwitchID');
        $this->SendDebug(__FUNCTION__, 'Day/Night Switch ID: ' . $dayNightSwitchID, 0);
        if ($dayNightSwitchID <= 1) {
            $this->RemoveNightOptions($form['elements']);
        }

        // Check for Scene Variable Profile
        $sceneVarID = $this->ReadPropertyInteger('SceneVariableID');
        $options = [];
        if ($sceneVarID > 0 && IPS_VariableExists($sceneVarID)) {
            $variable = IPS_GetVariable($sceneVarID);
            $profileName = $variable['VariableCustomProfile'];
            if ($profileName == '') {
                $profileName = $variable['VariableProfile'];
            }
            
            if ($profileName != '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);
                foreach ($profile['Associations'] as $association) {
                    $options[] = [
                        'caption' => $association['Name'],
                        'value'   => $association['Value']
                    ];
                }
            }
        }

        if (count($options) > 0) {
            $this->UpdateFormElements($form['elements'], $options);
        }

        // Debug output
        $this->SendDebug(__FUNCTION__, json_encode($form), 0);
        return json_encode($form);
    }

    private function RemoveNightOptions(&$elements)
    {
        foreach ($elements as &$element) {
            if (isset($element['items'])) {
                $newItems = [];
                foreach ($element['items'] as $item) {
                    if (isset($item['name']) && ($item['name'] == 'SceneOnNight' || $item['name'] == 'MotionDurationNight')) {
                        continue;
                    }
                    $newItems[] = $item;
                }
                $element['items'] = $newItems;
                $this->RemoveNightOptions($element['items']);
            }
        }
    }

    private function UpdateFormElements(&$elements, $options)
    {
        foreach ($elements as &$element) {
            if (isset($element['items'])) {
                $this->UpdateFormElements($element['items'], $options);
            }
            
            if (isset($element['name']) && ($element['name'] == 'SceneOn' || $element['name'] == 'SceneOnNight' || $element['name'] == 'SceneOff')) {
                $element['type'] = 'Select';
                $element['options'] = $options;
                unset($element['minimum']);
                unset($element['maximum']);
                unset($element['suffix']);
            }
        }
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     */
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister all messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        // Register sensors
        $sensors = json_decode($this->ReadPropertyString('Sensors'), true);
        foreach ($sensors as $sensor) {
            $id = $sensor['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Register scene inputs
        $sceneInputs = json_decode($this->ReadPropertyString('SceneInputs'), true);
        foreach ($sceneInputs as $sceneInput) {
            $id = $sceneInput['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
            $saveId = isset($sceneInput['SaveVariableID']) ? (int)$sceneInput['SaveVariableID'] : 0;
            if ($saveId > 0 && IPS_VariableExists($saveId)) {
                $this->RegisterMessage($saveId, VM_UPDATE);
            }
        }

        // Register Scene Output Variables for feedback
        $sceneVar = $this->ReadPropertyInteger('SceneVariableID');
        if ($sceneVar > 0 && IPS_VariableExists($sceneVar)) {
            $this->RegisterMessage($sceneVar, VM_UPDATE);
        }
        $sceneSaveVar = $this->ReadPropertyInteger('SceneSaveVariableID');
        if ($sceneSaveVar > 0 && IPS_VariableExists($sceneSaveVar)) {
            $this->RegisterMessage($sceneSaveVar, VM_UPDATE);
        }

        // Register Manual Switch
        $manualSwitch = $this->ReadPropertyInteger('ManualSwitchID');
        if ($manualSwitch > 0 && IPS_VariableExists($manualSwitch)) {
            $this->RegisterMessage($manualSwitch, VM_UPDATE);
        }

        // Register Auto Switch
        $autoSwitch = $this->ReadPropertyInteger('AutoSwitchID');
        if ($autoSwitch > 0 && IPS_VariableExists($autoSwitch)) {
            $this->RegisterMessage($autoSwitch, VM_UPDATE);
        }

        // Register Day/Night Switch
        $dayNightSwitch = $this->ReadPropertyInteger('DayNightSwitchID');
        if ($dayNightSwitch > 0 && IPS_VariableExists($dayNightSwitch)) {
            $this->RegisterMessage($dayNightSwitch, VM_UPDATE);
            // Initial update
            $val = GetValueBoolean($dayNightSwitch);
            $logic = $this->ReadPropertyInteger('DayNightLogic');
            $isDay = ($logic == 0) ? $val : !$val;
            SetValueBoolean($this->GetIDForIdent('DayState'), $isDay);
        } else {
            SetValueBoolean($this->GetIDForIdent('DayState'), true);
        }

        // Register Client Instance Presence
        $clientInstances = json_decode($this->ReadPropertyString('ClientInstances'), true);
        foreach ($clientInstances as $client) {
            $clientID = $client['InstanceID'];
            if ($clientID > 0 && IPS_InstanceExists($clientID)) {
                $presenceVarID = @IPS_GetObjectIDByIdent('PresenceState', $clientID);
                if ($presenceVarID && IPS_VariableExists($presenceVarID)) {
                    $this->RegisterMessage($presenceVarID, VM_UPDATE);
                }
            }
        }

        // Register Brightness Sensors
        $brightnessSensors = json_decode($this->ReadPropertyString('BrightnessSensors'), true);
        foreach ($brightnessSensors as $sensor) {
            $id = $sensor['VariableID'];
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Initial calculation of brightness
        $this->CalculateBrightness();
    }

    /**
     * The content of the function can be overwritten in order to carry out own reactions to certain messages.
     * The function is only called for registered MessageIDs/SenderIDs combinations.
     *
     * data[0] = new value
     * data[1] = value changed?
     * data[2] = old value
     * data[3] = timestamp.
     *
     * @param int   $timestamp Continuous counter timestamp
     * @param int   $sender    Sender ID
     * @param int   $message   ID of the message
     * @param array{0:mixed,1:bool,2:mixed,3:int} $data Data of the message
     */
    public function MessageSink($timestamp, $sender, $message, $data)
    {
        $this->SendDebug(__FUNCTION__, 'Sender: ' . $sender . ', Message: ' . $message . ', Data: ' . json_encode($data), 0);
        if ($message === VM_UPDATE) {
            // Check Brightness Sensors
            $brightnessSensors = json_decode($this->ReadPropertyString('BrightnessSensors'), true);
            $isBrightnessSensor = false;
            foreach ($brightnessSensors as $sensor) {
                if ($sensor['VariableID'] == $sender) {
                    $isBrightnessSensor = true;
                    break;
                }
            }
            if ($isBrightnessSensor) {
                $this->SendDebug(__FUNCTION__, 'Brightness sensor update from: ' . $sender, 0);
                $this->CalculateBrightness();
                return;
            }
            $sensors = json_decode($this->ReadPropertyString('Sensors'), true);
            $isSensor = false;
            $sensorType = 0; // 0 = Presence, 1 = Motion

            // Check Manual Switch
            if ($sender == $this->ReadPropertyInteger('ManualSwitchID')) {
                $state = (bool)$data[0];
                $this->SendDebug(__FUNCTION__, 'Manual Switch changed to: ' . ($state ? 'ON' : 'OFF'), 0);
                $this->SetState($state, 1);
                return;
            }

            // Check Auto Switch
            if ($sender == $this->ReadPropertyInteger('AutoSwitchID')) {
                $state = (bool)$data[0];
                $this->SendDebug(__FUNCTION__, 'Auto Switch triggered: ' . ($state ? 'ON' : 'OFF'), 0);
                $this->SetState($state, 0);
                return;
            }

            // Check Day/Night Switch
            if ($sender == $this->ReadPropertyInteger('DayNightSwitchID')) {
                $value = (bool)$data[0];
                $logic = $this->ReadPropertyInteger('DayNightLogic');
                $isDay = ($logic == 0) ? $value : !$value;
                
                $this->SendDebug(__FUNCTION__, 'Day/Night Switch changed: ' . ($isDay ? 'Day' : 'Night'), 0);
                SetValueBoolean($this->GetIDForIdent('DayState'), $isDay);
                return;
            }

            // Check Scene Inputs
            $sceneInputs = json_decode($this->ReadPropertyString('SceneInputs'), true);
            foreach ($sceneInputs as $sceneInput) {
                if ($sceneInput['VariableID'] == $sender) {
                    $mode = isset($sceneInput['Mode']) ? (int)$sceneInput['Mode'] : 1;
                    $this->SendDebug(__FUNCTION__, 'Scene Input triggered: ' . $sender . ', Mode: ' . ($mode == 1 ? 'Manual' : 'Auto'), 0);

                    if ($mode == 1) {
                        // Manual Mode
                        $this->SetState(null, 1);
                    } else {
                        // Auto Mode
                        $this->SetState(true, 0);
                    }
                    return;
                }
            }

            // Check Client Instance Presence Update
            $clientInstances = json_decode($this->ReadPropertyString('ClientInstances'), true);
            foreach ($clientInstances as $client) {
                $clientID = $client['InstanceID'];
                if ($clientID > 0 && IPS_InstanceExists($clientID)) {
                    $presenceVarID = @IPS_GetObjectIDByIdent('PresenceState', $clientID);
                    if ($sender == $presenceVarID) {
                        $this->CheckPresence();
                        break;
                    }
                }
            }

            // Check Scene Output Feedback
            if ($sender == $this->ReadPropertyInteger('SceneVariableID')) {
                $this->SendDebug(__FUNCTION__, 'Scene Output update ignored (not final implemented)', 0);
                return;
                $saveVar = $this->ReadPropertyInteger('SceneSaveVariableID');
                if ($saveVar > 0 && IPS_VariableExists($saveVar) && GetValueBoolean($saveVar)) {
                    $this->SendDebug(__FUNCTION__, 'Scene Output update ignored (Save active)', 0);
                    return;
                }

                // Check if we should ignore this update (because we caused it)
                $ignoreTime = (float)$this->GetBuffer('IgnoreSceneUpdate');
                if ($ignoreTime > 0) {
                    $this->SetBuffer('IgnoreSceneUpdate', ''); // Clear flag
                    if ((microtime(true) - $ignoreTime) < 5.0) { // 5 seconds timeout
                        $this->SendDebug(__FUNCTION__, 'Scene Output update ignored (Self-triggered)', 0);
                        return;
                    }
                }
                
                // An external scene change should activate the manual mode
                $val = (int)$data[0];
                $sceneOff = $this->ReadPropertyInteger('SceneOff');
                if ($val != $sceneOff) {
                    $this->SendDebug(__FUNCTION__, 'External scene change to ON state (Scene ' . $val . ') detected. Activating manual mode.', 0);
                    $this->SetState(true, 1, false);
                } else { // $val == $sceneOff
                    // When turned off externally, we can go back to auto mode immediately
                    $this->SendDebug(__FUNCTION__, 'External scene change to OFF state (Scene ' . $val . ') detected. Switching to auto mode.', 0);
                    $this->SetState(false, 0, false);
                }
                return;
            }

            // Check Sensors
            foreach ($sensors as $sensor) {
                if ($sensor['VariableID'] == $sender) {
                    $isSensor = true;
                    $sensorType = $sensor['SensorType'];
                    $this->SendDebug(__FUNCTION__, 'Sensor detected: ' . $sender . ', Type: ' . $sensorType, 0);
                    break;
                }
            }

            if ($isSensor) {
                if (GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
                    $this->SendDebug(__FUNCTION__, 'Sensor ignored (Manual Active)', 0);
                    return;
                }

                $value = (bool)$data[0];
                
                if ($sensorType == 1) { // Motion (Event)
                    // Only react to ON (Motion detected)
                    if ($value) {
                        $this->SendDebug(__FUNCTION__, 'Motion detected', 0);
                        $this->SetBuffer('MotionActive', '1');
                        $duration = $this->GetMotionDuration();
                        $this->SetTimerInterval('MotionTimer', $duration * 1000);
                        
                        SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
                        $this->SetBuffer('LastUpdateTime', (string)time());
                        
                        $this->StartUpdateTimer();
                        $this->CheckPresence();
                        $this->SendDebug(__FUNCTION__, 'Motion timer set to ' . $duration . ' seconds', 0);
                    }
                } else { // Presence (State)
                    $this->CheckPresence();
                }
            }
        }
    }

    public function MotionTimerExpired()
    {
        $this->SendDebug(__FUNCTION__, 'Motion timer expired', 0);
        $this->SetTimerInterval('MotionTimer', 0);
        $this->SetBuffer('MotionActive', '0');

        // Only stop the update timer and reset the remaining time if the manual timer is not also running
        if ($this->GetTimerInterval('ManualTimer') == 0) {
            $this->SetTimerInterval('UpdateTimer', 0);
            SetValueInteger($this->GetIDForIdent('RemainingTime'), 0);
        }

        $this->CheckPresence();
    }

    public function UpdateRemainingTime()
    {
        $lastUpdate = (int)$this->GetBuffer('LastUpdateTime');
        $now = time();
        $diff = $now - $lastUpdate;
        $this->SetBuffer('LastUpdateTime', (string)$now);

        $remaining = GetValueInteger($this->GetIDForIdent('RemainingTime'));
        $newRemaining = max(0, $remaining - $diff);
        
        SetValueInteger($this->GetIDForIdent('RemainingTime'), $newRemaining);
        $this->SendDebug(__FUNCTION__, 'Remaining time updated: ' . $newRemaining . ' seconds', 0);
    }

    public function ManualTimerExpired()
    {
        $this->SendDebug(__FUNCTION__, 'Manual timer expired', 0);
        $this->SetState(true, 0);
    }

    private function StartUpdateTimer()
    {
        $this->SendDebug(__FUNCTION__, 'Starting update timer', 0);
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 1000);
            $this->UpdateRemainingTime();
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
            SetValueInteger($this->GetIDForIdent('RemainingTime'), 0);
        }
    }

    private function WriteKNXScene(int $Value)
    {
        $sceneVar = $this->ReadPropertyInteger('SceneVariableID');
        if ($sceneVar > 0 && IPS_VariableExists($sceneVar)) {
            $this->SetBuffer('IgnoreSceneUpdate', (string)microtime(true));
            RequestAction($sceneVar, $Value);
        }
    }

    private function SendKNXScene(bool $State)
    {
        if ($State) {
            $daySwitch = $this->ReadPropertyInteger('DayNightSwitchID');
            $isDay = true;
            if ($daySwitch > 0 && IPS_VariableExists($daySwitch)) {
                $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
            }
            $sceneOn = $isDay ? $this->ReadPropertyInteger('SceneOn') : $this->ReadPropertyInteger('SceneOnNight');
            $this->WriteKNXScene($sceneOn);
        } else {
            $this->WriteKNXScene($this->ReadPropertyInteger('SceneOff'));
        }
    }

    private function SetState(?bool $State, int $Mode, bool $SendKNX = true)
    {
        // $Mode: 0 = Auto, 1 = Manual
        // $State: true = ON/Active, false = OFF/Inactive, null = Keep current
        
        $timerActive = false;

        if ($Mode == 1) { // Manual
            $this->SendDebug(__FUNCTION__, 'Switching to Manual Mode. State: ' . ($State === null ? 'Keep' : ($State ? 'ON' : 'OFF')), 0);
            
            SetValueBoolean($this->GetIDForIdent('ManualActive'), true);
            
            $outID = $this->ReadPropertyInteger('ManualStateOutputID');
            if ($outID > 0 && IPS_VariableExists($outID)) {
                RequestAction($outID, true);
            }

            // Start Manual Timer
            $duration = $this->ReadPropertyInteger('ManualDuration');
            $this->SetTimerInterval('ManualTimer', $duration * 1000);
            
            // Update Remaining Time
            SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
            $this->SetBuffer('LastUpdateTime', (string)time());
            $timerActive = true;

            // Stop Motion Timer
            $this->SetTimerInterval('MotionTimer', 0);
            $this->SetBuffer('MotionActive', '0');

            if ($State !== null) {
                SetValueBoolean($this->GetIDForIdent('PresenceState'), $State);
                
                if ($SendKNX) {
                    $this->SendKNXScene($State);
                }
            }

        } else { // Auto
            $this->SendDebug(__FUNCTION__, 'Switching to Auto Mode. Active: ' . ($State ? 'Yes' : 'No'), 0);
            
            SetValueBoolean($this->GetIDForIdent('ManualActive'), false);
            $this->SetTimerInterval('ManualTimer', 0);

            $outID = $this->ReadPropertyInteger('ManualStateOutputID');
            if ($outID > 0 && IPS_VariableExists($outID)) {
                RequestAction($outID, false);
            }

            if ($State) {
                // Auto Mode Active / Started / Fallback
                // Start Motion Timer (Nachlaufzeit)
                $duration = $this->GetMotionDuration();
                $this->SetTimerInterval('MotionTimer', $duration * 1000);
                $this->SetBuffer('MotionActive', '1');
                
                SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
                $this->SetBuffer('LastUpdateTime', (string)time());
                $timerActive = true;
            } else {
                // Auto Mode Stopped / Reset
                $this->SetTimerInterval('MotionTimer', 0);
                $this->SetBuffer('MotionActive', '0');
                
                SetValueInteger($this->GetIDForIdent('RemainingTime'), 0);
            }
            
            $this->CheckPresence();
        }

        // Manage Update Timer
        if ($timerActive) {
            $this->StartUpdateTimer();
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }
    }

    private function CheckPresence()
    {
        if (GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
            return;
        }

        $sensors = json_decode($this->ReadPropertyString('Sensors'), true);
        $isPresent = false;

        // Check Presence Sensors
        foreach ($sensors as $sensor) {
            if ($sensor['SensorType'] == 0) { // Presence
                if (IPS_VariableExists($sensor['VariableID']) && GetValueBoolean($sensor['VariableID'])) {
                    $isPresent = true;
                    $this->SendDebug(__FUNCTION__, 'Presence detected by sensor: ' . $sensor['VariableID'], 0);
                    break;
                }
            }
        }

        // Check Motion Buffer
        if (!$isPresent) {
            if ($this->GetBuffer('MotionActive') == '1') {
                $isPresent = true;
            }
        }

        // Check Client Instance Presence
        $clientInstances = json_decode($this->ReadPropertyString('ClientInstances'), true);
        foreach ($clientInstances as $client) {
            $clientID = $client['InstanceID'];
            if ($clientID > 0 && IPS_InstanceExists($clientID)) {
                $presenceVarID = @IPS_GetObjectIDByIdent('PresenceState', $clientID);
                if ($presenceVarID && GetValueBoolean($presenceVarID)) {
                    $isPresent = true;
                }
            }
        }

        // Check current state
        $stateVarID = $this->GetIDForIdent('PresenceState');
        $currentState = GetValueBoolean($stateVarID);
        
        if ($isPresent != $currentState) {
            $this->SendDebug(__FUNCTION__, 'Presence state changed to: ' . ($isPresent ? 'Present' : 'Not Present'), 0);
            
            // Determine Scene Value
            $sceneVal = 0;
            if ($isPresent) {
                // BECAME present. Check if we should turn on.
                $autoOn = $this->ReadPropertyBoolean('AutoOnOnBrightness');
                $isBrightEnough = false;
                if ($autoOn) {
                    $currentBrightness = GetValueFloat($this->GetIDForIdent('CurrentBrightness'));
                    $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
                    $threshold = $isDay ? $this->ReadPropertyInteger('BrightnessThresholdDay') : $this->ReadPropertyInteger('BrightnessThresholdNight');
                    if ($currentBrightness >= $threshold) {
                        $isBrightEnough = true;
                    }
                }

                if ($isBrightEnough) {
                    $this->SendDebug(__FUNCTION__, 'Presence detected, but it is bright enough. Light remains off.', 0);
                    $sceneVal = $this->ReadPropertyInteger('SceneOff');
                } else {
                    $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
                    $sceneVal = $isDay ? $this->ReadPropertyInteger('SceneOn') : $this->ReadPropertyInteger('SceneOnNight');
                }
            } else {
                // BECAME absent. Always turn off.
                $sceneVal = $this->ReadPropertyInteger('SceneOff');
            }

            // Send to KNX
            $this->WriteKNXScene($sceneVal);

            // Update internal state AFTER sending command to prevent race conditions with feedback
            SetValueBoolean($stateVarID, $isPresent);

            // Send to Client Instance
            foreach ($clientInstances as $client) {
                $clientID = $client['InstanceID'];
                if ($clientID > 0 && IPS_InstanceExists($clientID)) {
                    if (function_exists('KLL_SceneFromMaster')) {
                        $this->SendDebug(__FUNCTION__, 'Sending Scene to Client (' . $clientID . '): ' . $sceneVal, 0);
                        KLL_SceneFromMaster($clientID, $sceneVal, $isPresent);
                    }
                }
            }
        }
    }

    public function SimulateMotion()
    {
        $this->SendDebug(__FUNCTION__, 'Simulated Motion detected', 0);
        $this->SetBuffer('MotionActive', '1');
        $duration = $this->GetMotionDuration();
        $this->SetTimerInterval('MotionTimer', $duration * 1000);

        SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
        $this->SetBuffer('LastUpdateTime', (string)time());

        $this->StartUpdateTimer();
        $this->CheckPresence();
    }

    private function GetMotionDuration()
    {
        $daySwitch = $this->ReadPropertyInteger('DayNightSwitchID');
        if ($daySwitch > 0 && IPS_VariableExists($daySwitch)) {
            $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
            if (!$isDay) {
                return $this->ReadPropertyInteger('MotionDurationNight');
            }
        }
        return $this->ReadPropertyInteger('MotionDuration');
    }

    private function CalculateBrightness()
    {
        $brightnessSensors = json_decode($this->ReadPropertyString('BrightnessSensors'), true);
        if (count($brightnessSensors) == 0) {
            // Set to a low value if no sensor is configured, so brightness logic doesn't interfere
            if (GetValueFloat($this->GetIDForIdent('CurrentBrightness')) != -99999) {
                SetValueFloat($this->GetIDForIdent('CurrentBrightness'), -99999);
            }
            return;
        }

        $totalBrightness = 0;
        $totalWeight = 0;

        foreach ($brightnessSensors as $sensor) {
            $id = $sensor['VariableID'];
            $weight = $sensor['Weight'];
            if ($id > 0 && IPS_VariableExists($id) && $weight > 0) {
                $totalBrightness += (float)GetValue($id) * $weight;
                $totalWeight += $weight;
            }
        }

        $avgBrightness = 0;
        if ($totalWeight > 0) {
            $avgBrightness = $totalBrightness / $totalWeight;
        }

        SetValueFloat($this->GetIDForIdent('CurrentBrightness'), $avgBrightness);
        $this->SendDebug(__FUNCTION__, 'Calculated average brightness: ' . $avgBrightness . ' Lux', 0);

        // After calculating, check if the light state needs to change
        $this->CheckBrightnessLogic();
    }

    private function CheckBrightnessLogic()
    {
        if (GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
            return;
        }
        // This logic is for changes while presence is already active
        if (!GetValueBoolean($this->GetIDForIdent('PresenceState'))) {
            return;
        }
        $currentBrightness = GetValueFloat($this->GetIDForIdent('CurrentBrightness'));
        $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
        $threshold = $isDay ? $this->ReadPropertyInteger('BrightnessThresholdDay') : $this->ReadPropertyInteger('BrightnessThresholdNight');
        $sceneVar = $this->ReadPropertyInteger('SceneVariableID');
        if ($sceneVar <= 0 || !IPS_VariableExists($sceneVar)) {
            return;
        }
        $currentScene = GetValueInteger($sceneVar);
        $sceneOn = $isDay ? $this->ReadPropertyInteger('SceneOn') : $this->ReadPropertyInteger('SceneOnNight');
        $sceneOff = $this->ReadPropertyInteger('SceneOff');
        // Check if we should turn OFF because it got too bright
        $autoOff = $this->ReadPropertyBoolean('AutoOffOnBrightness');
        if ($autoOff && $currentBrightness > $threshold && $currentScene != $sceneOff) {
            $this->SendDebug(__FUNCTION__, 'Turning OFF due to high brightness (' . $currentBrightness . ' > ' . $threshold . ')', 0);
            $this->WriteKNXScene($sceneOff);
            return;
        }
    }

    public function ResetMotionTimer()
    {
        $this->SendDebug(__FUNCTION__, 'Motion Timer reset manually', 0);
        $this->MotionTimerExpired();
    }

    public function SceneFromMaster(int $Scene, bool $PresenceState)
    {
        $this->SendDebug(__FUNCTION__, 'Scene: ' . $Scene . ', Presence: ' . ($PresenceState ? 'true' : 'false'), 0);

        if ($PresenceState) {
            $this->SetBuffer('MasterScene', (string)$Scene);
        } else {
            $this->SetBuffer('MasterScene', '');
        }

        // Check if Manual Mode is active
        if (GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
            $this->SendDebug(__FUNCTION__, 'Ignored: Manual Mode is active', 0);
            return;
        }

        // Check if locally switched on (PresenceState is true)
        if (GetValueBoolean($this->GetIDForIdent('PresenceState'))) {
            $this->SendDebug(__FUNCTION__, 'Ignored: Light is already ON (locally)', 0);
            return;
        }

        // Forward Scene
        $this->WriteKNXScene($Scene);
        $this->SendDebug(__FUNCTION__, 'Scene forwarded to KNX', 0);
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     * @param string $ident Ident of the variable
     * @param mixed $value The value to be set
     */
    public function RequestAction($ident, $value)
    {
        // Debug output
        $this->SendDebug(__FUNCTION__, $ident . ' => ' . $value, 0);
        // TODO: Replace identifier
        switch ($ident) {
            case 'OnXxxxxYyyyy':
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'There was no reaction to the action.', 0);
        }
        return;
    }

}