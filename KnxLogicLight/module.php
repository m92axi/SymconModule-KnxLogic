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
    { // Create: Creates the instance and registers properties and variables.
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
        $this->RegisterPropertyInteger('BrightnessHysteresis', 50);
        $this->RegisterPropertyBoolean('AutoOnOnBrightness', true);
        $this->RegisterPropertyBoolean('AutoOffOnBrightness', false);
        $this->RegisterPropertyString('ClientInstances', '[]');
        $this->RegisterPropertyString('SceneSequence', '[]');

        $this->RegisterTimer('MotionTimer', 0, 'KLL_MotionTimerExpired(' . $this->InstanceID . ');');
        $this->RegisterTimer('UpdateTimer', 0, 'KLL_UpdateRemainingTime(' . $this->InstanceID . ');');
        $this->RegisterTimer('ManualTimer', 0, 'KLL_ManualTimerExpired(' . $this->InstanceID . ');');
        $this->RegisterVariableBoolean('PresenceState', 'Presence State', '~Presence', 0);
        $this->RegisterVariableInteger('RemainingTime', 'Remaining Time', '', 0);
        $this->RegisterVariableBoolean('ManualActive', 'Manual Active', '~Switch', 0);
        $this->RegisterVariableBoolean('DayState', 'Day Mode', '~Switch', 0);
        $this->RegisterVariableFloat('CurrentBrightness', 'Current Brightness', '~Illumination', 0);
        $this->RegisterVariableBoolean('LightState', 'Light State', '~Switch', 0);
    }

    /**
     * This function is called when deleting the instance during operation and when updating via "Module Control".
     * The function is not called when exiting IP-Symcon.
     */
    public function Destroy()
    { // Destroy: Is called when the instance is deleted or updated.
        parent::Destroy();
    }

    /**
     * The content can be overwritten in order to transfer a self-created configuration page.
     * This way, content can be generated dynamically.
     * In this case, the "form.json" on the file system is completely ignored.
     */
    public function GetConfigurationForm()
    { // GetConfigurationForm: Returns the configuration form for the instance.
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
    { // RemoveNightOptions: Removes night-related options from the configuration form.
        foreach ($elements as &$element) {
            if (isset($element['items'])) {
                $newItems = [];
                foreach ($element['items'] as $item) {
                    if (isset($item['name']) && ($item['name'] == 'SceneOnNight' || $item['name'] == 'MotionDurationNight' || $item['name'] == 'BrightnessThresholdNight')) {
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
    { // UpdateFormElements: Updates the form elements with options retrieved from the scene variable profile.
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

            if (isset($element['name']) && $element['name'] == 'SceneSequence' && isset($element['columns'])) {
                foreach ($element['columns'] as &$column) {
                    if ($column['name'] == 'SceneID') {
                        $column['edit'] = [
                            'type'    => 'Select',
                            'options' => $options
                        ];
                    }
                }
            }
        }
    }

    /**
     * Is executed when "Apply" is pressed on the configuration page and immediately after the instance has been created.
     */
    public function ApplyChanges()
    { // ApplyChanges: Is executed when "Apply" is pressed on the configuration page.
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
    { // MessageSink: Handles messages received from registered objects.
        $this->SendDebug(__FUNCTION__, 'Sender: ' . IPS_GetName($sender) . ', Message: ' . $message . ', Data: ' . json_encode($data), 0);
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
                
                // If turning ON and already in Man Mode, treat as Motion (Reset Timer)
                if ($state && GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
                    $this->CycleScenes();
                } 
                $this->UpdateState($state, 1);
                return;
            }

            // Check Auto Switch
            if ($sender == $this->ReadPropertyInteger('AutoSwitchID')) {
                $state = (bool)$data[0];
                $this->SendDebug(__FUNCTION__, 'Auto Switch triggered: ' . ($state ? 'ON' : 'OFF'), 0);
                
                // If turning ON and already in Auto Mode, treat as Motion (Reset Timer)
                if ($state && !GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
                    $this->CycleScenes();
                    $this->UpdateMotionTime();
                    $seqScene = $this->GetBuffer('ActiveSequenceScene');
                    if ($seqScene !== '') {
                        $this->WriteKNXScene((int)$seqScene);
                    }
                    
                    $this->UpdateState($this->CheckPresence(), -1);
                    
                } else {
                    $this->UpdateState($state, 0);
                }
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
                    $this->SendDebug(__FUNCTION__, 'Scene Input triggered: ' . IPS_GetName($sender) . ', Mode: ' . ($mode == 1 ? 'Manual' : 'Auto'), 0);

                    if ($mode == 1) {
                        // Manual Mode
                        $this->UpdateState(null, 1, false);
                    } else {
                        // Auto Mode
                        $this->UpdateMotionTime();
                        $this->UpdateState(true,  0, false);
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
                        if (GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
                            $this->SendDebug(__FUNCTION__, 'Client Instance update ignored (Manual Active)', 0);
                            return;
                        }
                        $this->SendDebug(__FUNCTION__, 'Client Instance Presence update from: ' . $sender, 0);
                        $this->UpdateState($this->CheckPresence(), -1);
                        break;
                    }
                }
            }

            // Check Scene Output Feedback
            if ($sender == $this->ReadPropertyInteger('SceneVariableID')) {
                //$this->SendDebug(__FUNCTION__, 'Scene Output update ignored (not final implemented)', 0);
                //return;
                $saveVar = $this->ReadPropertyInteger('SceneSaveVariableID');
                if ($saveVar > 0 && IPS_VariableExists($saveVar) && GetValueBoolean($saveVar)) {
                    $this->SendDebug(__FUNCTION__, 'Scene Output update ignored (Save active)', 0);
                    return;
                }

                // Check if we should ignore this update (because we caused it)
                $ignoreTime = (float)$this->GetBuffer('IgnoreSceneUpdate');
                //$this->SendDebug(__FUNCTION__, 'Ignor Time for Scene Output update:' . $ignoreTime ."-" . microtime(true), 0);
                if ($ignoreTime > 0) {
                    //$this->SetBuffer('IgnoreSceneUpdate', ''); // Clear flag
                    if ((microtime(true) - $ignoreTime) < 5.0) { // 5 seconds timeout
                        $this->SendDebug(__FUNCTION__, 'Scene Output update ignored (Self-triggered):' . $ignoreTime ."-" . microtime(true), 0);
                        return;
                    }
                }
                
                // An external scene change should activate the manual mode
                $val = (int)$data[0];
                $sceneOff = $this->ReadPropertyInteger('SceneOff');
                $CurerentMode = GetValueBoolean($this->GetIDForIdent('ManualActive'));
                if ($val != $sceneOff ) {
                    $this->SendDebug(__FUNCTION__, 'External scene change to ON state (Scene ' . $val . ') detected. ', 0);
                    $this->UpdateState(true, -1, false);
                } else { // $val == $sceneOff
                    $this->SendDebug(__FUNCTION__, 'External scene change to OFF state (Scene ' . $val . ') detected. ', 0);
                    $this->UpdateState(NULL, -1, false);
                }
                return;
            }

            // Check Sensors
            foreach ($sensors as $sensor) {
                if ($sensor['VariableID'] == $sender) {
                    $isSensor = true;
                    $sensorType = $sensor['SensorType'];
                    $this->SendDebug(__FUNCTION__, 'Sensor detected: ' . IPS_GetName($sender) . ', Type: ' . $sensorType, 0);
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
                        $this->UpdateMotionTime();
                        $this->UpdateState($this->CheckPresence(), -1);
                        $this->SendDebug(__FUNCTION__, 'Motion timer reset', 0);
                    }
                } else { // Presence (State)
                    $this->UpdateState($this->CheckPresence(), -1);
                }
            }
        }
    }

    private function UpdateMotionTime()
    { // UpdateMotionTime: Updates the motion timer and related variables.
        $this->SetBuffer('MotionActive', '1');
        $duration = $this->GetMotionDuration();
        $this->SetTimerInterval('MotionTimer', $duration * 1000);
        
        SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
        $this->SetBuffer('LastUpdateTime', (string)time());
        
        $this->StartUpdateTimer();
        
    }

    public function MotionTimerExpired()
    { // MotionTimerExpired: Is called when the motion timer expires.
        $this->SendDebug(__FUNCTION__, 'Motion timer expired', 0);
        $this->SetTimerInterval('MotionTimer', 0);
        $this->SetBuffer('MotionActive', '0');

        // Only stop the update timer and reset the remaining time if the manual timer is not also running
        if ($this->GetTimerInterval('ManualTimer') == 0) {
            $this->SetTimerInterval('UpdateTimer', 0);
            SetValueInteger($this->GetIDForIdent('RemainingTime'), 0);
        }

        $this->UpdateState($this->CheckPresence(), -1);
    }

    public function UpdateRemainingTime()
    { // UpdateRemainingTime: Updates the remaining time variable.
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
    { // ManualTimerExpired: Is called when the manual timer expires.
        $this->SendDebug(__FUNCTION__, 'Manual timer expired', 0);
        $this->UpdateState($this->CheckPresence(), 0);
    }

    private function StartUpdateTimer()
    { // StartUpdateTimer: Starts the update timer.
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
    { // WriteKNXScene: Writes the scene value to the KNX variable.
        $sceneVar = $this->ReadPropertyInteger('SceneVariableID');
        if ($sceneVar > 0 && IPS_VariableExists($sceneVar)) {
            $this->SetBuffer('IgnoreSceneUpdate', (string)microtime(true));
            RequestAction($sceneVar, $Value);
        }
        $this->SendDebug(__FUNCTION__, 'Scene sent to KNX: ' . $Value, 0);

        $sceneOff = $this->ReadPropertyInteger('SceneOff');
        SetValueBoolean($this->GetIDForIdent('LightState'), $Value !== $sceneOff);
    }

    private function GetSceneForState(bool $State): int
    { // GetSceneForState: Returns the scene number for the given state.
        if ($State) {
            // Check for active sequence override
            $seqScene = $this->GetBuffer('ActiveSequenceScene');
            if ($seqScene !== '') {
                return (int)$seqScene;
            }

            $daySwitch = $this->ReadPropertyInteger('DayNightSwitchID');
            $isDay = true;
            if ($daySwitch > 0 && IPS_VariableExists($daySwitch)) {
                $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
            }
            return $isDay ? $this->ReadPropertyInteger('SceneOn') : $this->ReadPropertyInteger('SceneOnNight');
        } else {
            $this->SetBuffer('ActiveSequenceScene', '');

            $masterScene = $this->GetBuffer('MasterScene');
            if ($masterScene !== '') {
                $this->SendDebug(__FUNCTION__, 'Reverting to Master Scene: ' . $masterScene, 0);
                return (int)$masterScene;
            }
            return $this->ReadPropertyInteger('SceneOff');
        }
    }

    private function CycleScenes()
    { // CycleScenes: Cycles to the next scene in the configured sequence.
        $now = microtime(true);
        $lastCycle = (float)$this->GetBuffer('LastCycleTime');
        if (($now - $lastCycle) < 0.5) {
            $this->SendDebug(__FUNCTION__, 'Ignored: CycleScenes called too quickly', 0);
            return;
        } else {
            $this->SetBuffer('LastCycleTime', (string)$now);
        }
        

        $sequence = json_decode($this->ReadPropertyString('SceneSequence'), true);
        $this->SendDebug(__FUNCTION__, 'Cycling Scenes in Sequence: ' . json_encode($sequence), 0);
        if (empty($sequence)) return;

        $sceneVar = $this->ReadPropertyInteger('SceneVariableID');
        $currentScene = -1;
        if ($sceneVar > 0 && IPS_VariableExists($sceneVar)) {
            $currentScene = GetValueInteger($sceneVar);
        }
        
        $foundIndex = -1;
        foreach ($sequence as $index => $item) {
            if ($item['SceneID'] == $currentScene) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex != -1) {
            $nextIndex = ($foundIndex + 1) % count($sequence);
            $nextScene = (int)$sequence[$nextIndex]['SceneID'];
        } else {
            $nextScene = (int)$sequence[0]['SceneID'];
        }

        $this->SetBuffer('ActiveSequenceScene', (string)$nextScene);#
        $this->SendDebug(__FUNCTION__, 'Next Scene in Sequence: ' . $nextScene, 0);
    }

    private function SendScene(bool $State)
    { // SendScene: Sends the scene to the KNX bus and client instances.
        $scene = $this->GetSceneForState($State);
        $this->WriteKNXScene($scene);

        // Send to Client Instances
        $clientInstances = json_decode($this->ReadPropertyString('ClientInstances'), true);
        foreach ($clientInstances as $client) {
            $clientID = $client['InstanceID'];
            if ($clientID > 0 && IPS_InstanceExists($clientID)) {
                if (function_exists('KLL_SceneFromMaster')) {
                    $this->SendDebug(__FUNCTION__, 'Sending Scene to Client (' . $clientID . '): ' . $scene, 0);
                    KLL_SceneFromMaster($clientID, $scene, $State);
                }
            }
        }
    }

    private function UpdateState(?bool $State, int $Mode, bool $SendScene = true)
    { // UpdateState: Updates the state of the light and sends the scene to the KNX bus.
        // $Mode: 0 = Auto, 1 = Manual, -1 = Keep Current
        // $State: true = ON/Present, false = OFF/Absent, null = Keep Current
        // $SendScene: true = Send Scene to KNX, false = Only update internal state

        $currentMode = GetValueBoolean($this->GetIDForIdent('ManualActive')) ? 1 : 0;
        $targetMode = ($Mode === -1) ? $currentMode : $Mode;

        $currentPresence = GetValueBoolean($this->GetIDForIdent('PresenceState'));
        $targetPresence = ($State === null) ? $currentPresence : $State;
        
        $this->SendDebug(__FUNCTION__, 'UpdateState called. CurrentMode: ' . $currentMode . ', TargetMode: ' . $targetMode . ', CurrentPresence: ' . ($currentPresence ? 'Present' : 'Absent') . ', TargetPresence: ' . ($targetPresence ? 'Present' : 'Absent'), 0);
        

        // --- Mode Switching Logic ---
        if ($targetMode != $currentMode) {
            $this->SetBuffer('ActiveSequenceScene', '');
            if ($targetMode == 1) { // Switching to Manual
                $this->SendDebug(__FUNCTION__, 'Switching to Manual Mode', 0);
                SetValueBoolean($this->GetIDForIdent('ManualActive'), true);
                
                // Stop Motion Timer
                $this->SetTimerInterval('MotionTimer', 0);
                $this->SetBuffer('MotionActive', '0');
                
                // Start Manual Timer
                $duration = $this->ReadPropertyInteger('ManualDuration');
                $this->SetTimerInterval('ManualTimer', $duration * 1000);
                SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
                $this->SetBuffer('LastUpdateTime', (string)time());
                $this->StartUpdateTimer();
                
                // Update Manual Output
                $outID = $this->ReadPropertyInteger('ManualStateOutputID');
                if ($outID > 0 && IPS_VariableExists($outID)) RequestAction($outID, true);

            } else { // Switching to Auto
                $this->SendDebug(__FUNCTION__, 'Switching to Auto Mode', 0);
                SetValueBoolean($this->GetIDForIdent('ManualActive'), false);
                
                // Stop Manual Timer
                $this->SetTimerInterval('ManualTimer', 0);
                
                // Update Manual Output
                $outID = $this->ReadPropertyInteger('ManualStateOutputID');
                if ($outID > 0 && IPS_VariableExists($outID)) RequestAction($outID, false);
                
                // If switching to Auto with State=true (Auto Switch ON), treat as Motion
                if ($State === true) {
                     $this->UpdateMotionTime();
                     //$this->UpdateState($this->CheckPresence(), -1);
                } else {
                     // Reset Motion Timer/State if switching to Auto OFF
                     $this->SetTimerInterval('MotionTimer', 0);
                     $this->SetBuffer('MotionActive', '0');
                     SetValueInteger($this->GetIDForIdent('RemainingTime'), 0);
                     $this->SetTimerInterval('UpdateTimer', 0);
                }
            }
        } else {
            // Mode not changing
            if ($targetMode == 1 ) {
                 // Re-trigger Manual ON -> Reset Timer
                 $duration = $this->ReadPropertyInteger('ManualDuration');
                 $this->SetTimerInterval('ManualTimer', $duration * 1000);
                 SetValueInteger($this->GetIDForIdent('RemainingTime'), $duration);
                 $this->SetBuffer('LastUpdateTime', (string)time());
                $this->SendDebug(__FUNCTION__, 'Manual Mode: Timer reset on re-trigger', 0);


            }
            if ($targetMode == 0 ) {
                // Auto Mode
                if ($State === true) {
                    // Re-trigger Auto ON -> Reset Motion Timer
                    $this->UpdateMotionTime();
                    $this->SendDebug(__FUNCTION__, 'Auto Mode: Motion Timer reset on re-trigger', 0);
                }
            }
            
        }

        // --- Output Logic ---
        if ($SendScene) {
            if ($targetMode == 1) {
                // Manual Mode: Output follows PresenceState directly
                $this->SendScene($targetPresence);
            } else {
                // Auto Mode: Output follows PresenceState AND Brightness Logic

                $brightnessBlock = ($this->GetBuffer('BrightnessBlock') == '1');
                $oldBrightnessBlock = ($this->GetBuffer('OldBrightnessBlock') =='1');

                
                //Change in Presence
                if ($targetPresence != $currentPresence) {
                    
                    if ($targetPresence) {
                        if (!$brightnessBlock) {
                        $this->SendDebug(__FUNCTION__, 'Auto Mode: Presence change to ON detected and no Brightness Block active', 0);
                        $this->SendScene(true);
                        } else {
                            $this->SendDebug(__FUNCTION__, 'Auto Mode: Presence change to ON detected but Brightness Block active', 0);
                        }
                    } else {
                        $this->SendDebug(__FUNCTION__, 'Auto Mode: Presence change to OFF detected', 0);
                        $this->SendScene(false);
                    }
                }elseif ($brightnessBlock != $oldBrightnessBlock) {
                    // Change in Brightness Block
                    if (!$brightnessBlock) {
                        // Brightness Block turned OFF
                        $this->SendDebug(__FUNCTION__, 'Auto Mode: Brightness Block turned OFF', 0);
                        if ($targetPresence) {
                            $this->SendScene(true);
                        }
                    } else {
                        // Brightness Block turned ON
                        $this->SendDebug(__FUNCTION__, 'Auto Mode: Brightness Block turned ON', 0);
                        $this->SendScene(false);
                    $this->SendDebug(__FUNCTION__, 'Auto Mode: No Presence change detected', 0);
                    }

                    $this->SetBuffer('OldBrightnessBlock', $brightnessBlock );
                }

            }
        }

        if ($State !== null) {
            SetValueBoolean($this->GetIDForIdent('PresenceState'), $targetPresence);
        }
    }

    private function CheckPresence(): bool
    { // CheckPresence: Checks the presence status based on sensors, motion buffer, and client instances.
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

        return $isPresent;
    }

    public function SimulateMotion()
    { // SimulateMotion: Simulates motion detection.
        if (GetValueBoolean($this->GetIDForIdent('ManualActive'))) {
            $this->SendDebug(__FUNCTION__, 'Simulated Motion detected (Manual Active)', 0);
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Simulated Motion detected', 0);
        $this->UpdateMotionTime();
        $this->UpdateState($this->CheckPresence(), -1);
    }

    private function GetMotionDuration()
    { // GetMotionDuration: Returns the motion duration based on the day/night switch.
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
    { // CalculateBrightness: Calculates the average brightness based on the configured sensors.
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
    { // CheckBrightnessLogic: Checks the brightness logic and updates the light state if necessary.
        $currentBrightness = GetValueFloat($this->GetIDForIdent('CurrentBrightness'));
        $isDay = GetValueBoolean($this->GetIDForIdent('DayState'));
        $threshold = $isDay ? $this->ReadPropertyInteger('BrightnessThresholdDay') : $this->ReadPropertyInteger('BrightnessThresholdNight');
        $hysteresis = $this->ReadPropertyInteger('BrightnessHysteresis');
        
        $lightState = GetValueBoolean($this->GetIDForIdent('LightState'));
        $autoOn = $this->ReadPropertyBoolean('AutoOnOnBrightness');
        $autoOff = $this->ReadPropertyBoolean('AutoOffOnBrightness');

        $isTooBright = $this->GetBuffer('BrightnessBlock') == '1';
        
       // Check if if Brightness Block should be active
       $this->SendDebug(__FUNCTION__, 'Checking Brightness Logic. Current: ' . $currentBrightness . ' Lux, Threshold: ' . $threshold . ' Lux, Hysteresis: ' . $hysteresis . ' Lux, LightState: ' . ($lightState ? 'ON' : 'OFF') . ', IsTooBright: ' . ($isTooBright ? 'Yes' : 'No'), 0);
       if ( $currentBrightness >= ($threshold + $hysteresis)) {
            // light can be turned OFF or should be blocked from turning ON
            if ($autoOff) {

                $isTooBright = true;
            }
        } elseif ($currentBrightness < ($threshold )) {
            // light can be turned ON or should not blocked
            if ($autoOn) {
                $isTooBright = false;
            }
        }
       
        // if ($lightState) {
        //     // Light is ON: Check if we should turn OFF
        //     if ($autoOff && $currentBrightness > ($threshold + $hysteresis)) {
        //         $isTooBright = true;
        //     }
        // } else {
        //     // Light is OFF: Check if we should BLOCK turning ON
        //     if ($autoOn && $currentBrightness >= $threshold) {
        //         $isTooBright = true;
        //     }
        // }
        
        $oldBlock = $this->GetBuffer('BrightnessBlock');
        $this->SetBuffer('OldBrightnessBlock', $oldBlock);
        $newBlock = $isTooBright ? '1' : '0';
        $this->SetBuffer('BrightnessBlock', $newBlock);

        if ($oldBlock !== $newBlock) {
            $this->SendDebug(__FUNCTION__, 'Brightness Block changed to: ' . $newBlock, 0);
            // Trigger state update to apply brightness logic
            $this->UpdateState(null, -1);
        }
    }

    public function ResetMotionTimer()
    { // ResetMotionTimer: Resets the motion timer manually.
        $this->SendDebug(__FUNCTION__, 'Motion Timer reset manually', 0);
        $this->MotionTimerExpired();
    }

    public function SceneFromMaster(int $Scene, bool $PresenceState)
    { // SceneFromMaster: Sets the scene from the master instance.
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
    { // RequestAction: Is called when an action is requested for a variable.
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