<?php

class TelefonansageDirekt extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('VoIPInstanceID', 0);
        $this->RegisterPropertyInteger('TTSInstanceID', 0);
        $this->RegisterPropertyInteger('AnswerTimeout', 30);
        $this->RegisterPropertyInteger('MaxRetries', 1);
        $this->RegisterPropertyInteger('RetryDelay', 15);

        $this->RegisterAttributeInteger('ConnectionID', 0);
        $this->RegisterAttributeInteger('CallStartedAt', 0);
        $this->RegisterAttributeInteger('ConnectedAt', 0);
        $this->RegisterAttributeString('WaveFile', '');
        $this->RegisterAttributeString('CurrentMessage', '');
        $this->RegisterAttributeString('CurrentPhone', '');
        $this->RegisterAttributeString('CurrentJob', '');
        $this->RegisterAttributeString('QueueJSON', '[]');
        $this->RegisterAttributeInteger('NextProcessAt', 0);
        $this->RegisterAttributeBoolean('WaveStarted', false);

        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableString('ActiveCall', 'Aktiver Anruf', '', 15);
        $this->RegisterVariableInteger('QueueLength', 'Warteschlangenlänge', '', 20);
        $this->RegisterVariableString('LastMessage', 'Letzte Ansage', '', 30);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 40);

        $this->RegisterTimer('CheckCall', 0, 'TAD_CheckCall($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Hangup', 0, 'TAD_Hangup($_IPS[\'TARGET\']);');
        $this->RegisterTimer('QueueWorker', 0, 'TAD_ProcessQueue($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // The queue is stored in an instance attribute and survives restarts.
        $this->SetTimerInterval('CheckCall', 0);
        $this->SetTimerInterval('Hangup', 0);
        $this->UpdateQueueLength();

        if ($this->ReadAttributeInteger('ConnectionID') !== 0) {
            $this->SetStatusText('Ein Anrufstatus war nach Neustart noch gespeichert. Der Auftrag wird erneut eingereiht; Telefonstatus bitte prüfen.');
            // Preserve the interrupted job so a restart does not silently lose it.
            $job = json_decode($this->ReadAttributeString('CurrentJob'), true);
            if (is_array($job) && !empty($job['Phone']) && !empty($job['Text'])) {
                $queue = $this->GetQueue();
                array_unshift($queue, $job);
                $this->SaveQueue($queue);
            }
            // A connection can potentially still exist in the VoIP provider after a Symcon restart.
            // We clear our stale local state but do not blindly disconnect an unknown connection.
            $this->ClearCallState();
        } else {
            $this->SetValue('ActiveCall', '');
            $this->SetStatusText('Bereit');
        }
        $this->ScheduleQueueWorker(1000);
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'SelectInstance', 'name' => 'VoIPInstanceID', 'caption' => 'VoIP-Instanz'],
                ['type' => 'SelectInstance', 'name' => 'TTSInstanceID', 'caption' => 'AWS-Polly-TTS-Instanz'],
                ['type' => 'NumberSpinner', 'name' => 'AnswerTimeout', 'caption' => 'Wartezeit auf Annahme (Sekunden)', 'minimum' => 5, 'maximum' => 300],
                ['type' => 'NumberSpinner', 'name' => 'MaxRetries', 'caption' => 'Wiederholungen bei Fehler', 'minimum' => 0, 'maximum' => 5],
                ['type' => 'NumberSpinner', 'name' => 'RetryDelay', 'caption' => 'Pause vor Wiederholung (Sekunden)', 'minimum' => 1, 'maximum' => 600],
                ['type' => 'Label', 'caption' => 'Telefonansage Direkt – Version 1.1'],
                ['type' => 'Label', 'caption' => "Funktionsübersicht: TAD_StartCall(Instanz-ID, Telefonnummer, Ansagetext) stellt einen Anrufauftrag in die FIFO-Warteschlange. Beispiel: TAD_StartCall(12345, '+491701234567', 'Achtung, eine Störung liegt vor.'); Die konfigurierte VoIP-Instanz baut den Anruf auf, AWS Polly erzeugt die Sprachausgabe. Nach Gesprächsannahme wartet das Modul etwa zwei Sekunden vor der Wiedergabe. Die Warteschlange bleibt über Neustarts hinweg erhalten. Wartezeit auf Annahme, Anzahl der Wiederholungen und Pause vor Wiederholungen sind konfigurierbar. TAD_ClearQueue(Instanz-ID) leert die Warteschlange, beendet aber keinen laufenden Anruf. TAD_Hangup(Instanz-ID) beendet den aktiven Anruf. Status, aktiver Anruf, Warteschlangenlänge und letzter Fehler werden als Variablen angezeigt."]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Warteschlange leeren', 'onClick' => 'TAD_ClearQueue($id);'],
                ['type' => 'Button', 'caption' => 'Aktiven Anruf beenden', 'onClick' => 'TAD_Hangup($id);']
            ],
        ]);
    }

    /** Enqueue an outgoing call. Returns true when the job was accepted into the queue. */
    public function StartCall(string $phone, string $text)
    {
        $phone = trim($phone);
        $text = trim($text);
        if ($phone === '' || $text === '') {
            $this->SetLastError('Telefonnummer und Ansagetext müssen ausgefüllt sein.');
            return false;
        }

        $queue = $this->GetQueue();
        $queue[] = [
            'Phone' => $phone,
            'Text' => $text,
            'Attempts' => 0,
            'CreatedAt' => time(),
            'JobID' => uniqid('tad_', true)
        ];
        $this->SaveQueue($queue);
        SetValueString($this->GetIDForIdent('LastMessage'), $text);
        $this->SetStatusText('Anruf in Warteschlange aufgenommen: ' . $phone);
        $this->ScheduleQueueWorker(1);
        return true;
    }

    /** Queue worker: starts exactly one job when no call is active. */
    public function ProcessQueue()
    {
        if ($this->ReadAttributeInteger('ConnectionID') !== 0) {
            return;
        }
        $queue = $this->GetQueue();
        $this->UpdateQueueLength();
        if (count($queue) === 0) {
            $this->SetTimerInterval('QueueWorker', 0);
            if ($this->ReadAttributeInteger('NextProcessAt') !== 0) {
                $this->WriteAttributeInteger('NextProcessAt', 0);
            }
            $this->SetValue('ActiveCall', '');
            $this->SetStatusText('Bereit – Warteschlange leer.');
            return;
        }

        $nextAt = $this->ReadAttributeInteger('NextProcessAt');
        if ($nextAt > time()) {
            $this->ScheduleQueueWorker(max(1000, ($nextAt - time()) * 1000));
            return;
        }

        $job = array_shift($queue);
        $this->SaveQueue($queue);
        $this->WriteAttributeString('CurrentJob', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->WriteAttributeString('CurrentPhone', $job['Phone']);
        $this->WriteAttributeString('CurrentMessage', $job['Text']);
        $this->WriteAttributeInteger('NextProcessAt', 0);
        $this->SetValue('ActiveCall', $job['Phone']);
        $this->UpdateQueueLength();

        $voipID = $this->ReadPropertyInteger('VoIPInstanceID');
        $ttsID = $this->ReadPropertyInteger('TTSInstanceID');
        if ($voipID <= 0 || !IPS_InstanceExists($voipID)) {
            $this->FailCurrentJob('VoIP-Instanz-ID ist ungültig.', false);
            return;
        }
        if ($ttsID <= 0 || !IPS_InstanceExists($ttsID)) {
            $this->FailCurrentJob('TTS-Instanz-ID ist ungültig.', false);
            return;
        }
        if (!function_exists('TTSAWSPOLLY_GenerateFile')) {
            $this->FailCurrentJob('TTSAWSPOLLY_GenerateFile() ist nicht verfügbar. AWS-Polly-TTS-Modul prüfen.', false);
            return;
        }

        try {
            $connectionID = VoIP_Connect($voipID, $job['Phone']);
        } catch (Throwable $e) {
            $this->FailCurrentJob('Anruf konnte nicht gestartet werden: ' . $e->getMessage(), true);
            return;
        }
        if (!is_int($connectionID) && !ctype_digit((string)$connectionID)) {
            $this->FailCurrentJob('VoIP_Connect hat keine gültige Verbindungs-ID zurückgegeben.', true);
            return;
        }

        $this->WriteAttributeInteger('ConnectionID', (int)$connectionID);
        $this->WriteAttributeInteger('CallStartedAt', time());
        $this->WriteAttributeInteger('ConnectedAt', 0);
        $this->WriteAttributeString('WaveFile', '');
        $this->WriteAttributeBoolean('WaveStarted', false);
        $this->SetStatusText('Anruf wird aufgebaut: ' . $job['Phone'] . ' (Warteschlange: ' . count($queue) . ')');
        $this->SetTimerInterval('CheckCall', 1000);
    }

    public function CheckCall()
    {
        $connectionID = $this->ReadAttributeInteger('ConnectionID');
        if ($connectionID === 0) {
            $this->SetTimerInterval('CheckCall', 0);
            $this->ScheduleQueueWorker(1000);
            return;
        }

        $voipID = $this->ReadPropertyInteger('VoIPInstanceID');
        $ttsID = $this->ReadPropertyInteger('TTSInstanceID');
        $timeout = max(5, $this->ReadPropertyInteger('AnswerTimeout'));

        try {
            $connection = VoIP_GetConnection($voipID, $connectionID);
        } catch (Throwable $e) {
            $this->FailCurrentJob('Fehler beim Prüfen des Anrufs: ' . $e->getMessage(), true);
            return;
        }

        $connected = is_array($connection) && (!array_key_exists('Connected', $connection) || (bool)$connection['Connected']);
        if (!$connected) {
            if ((time() - $this->ReadAttributeInteger('CallStartedAt')) >= $timeout) {
                $this->DisconnectCurrentCall();
                $this->FailCurrentJob('Zeitüberschreitung: Der Anruf wurde nicht angenommen.', true, false);
            }
            return;
        }

        if (!$this->ReadAttributeBoolean('WaveStarted')) {
            // Give the telephone connection about two seconds to settle after answer.
            $connectedAt = $this->ReadAttributeInteger('ConnectedAt');
            if ($connectedAt === 0) {
                $this->WriteAttributeInteger('ConnectedAt', time());
                $this->SetStatusText('Gespräch angenommen – warte 2 Sekunden vor der Ansage.');
                return;
            }
            if ((time() - $connectedAt) < 2) {
                return;
            }

            try {
                $message = $this->ReadAttributeString('CurrentMessage');
                $waveFile = TTSAWSPOLLY_GenerateFile($ttsID, $message);
                if (!is_string($waveFile) || $waveFile === '' || !is_file($waveFile)) {
                    $this->FailCurrentJob('TTS hat keine vorhandene WAV-Datei geliefert.', true);
                    return;
                }
                $this->WriteAttributeString('WaveFile', $waveFile);
                $ok = VoIP_PlayWave($voipID, $connectionID, $waveFile);
                if (!$ok) {
                    $this->FailCurrentJob('Die WAV-Datei konnte nicht abgespielt werden.', true);
                    return;
                }
                $this->WriteAttributeBoolean('WaveStarted', true);
                $durationMs = $this->GetWaveDurationMs($waveFile);
                $this->SetStatusText('Verbunden – Sprachnachricht wird abgespielt: ' . $this->ReadAttributeString('CurrentPhone'));
                $this->SetTimerInterval('CheckCall', 0);
                $this->SetTimerInterval('Hangup', max(3000, $durationMs + 2000));
            } catch (Throwable $e) {
                $this->FailCurrentJob('Fehler bei der Sprachausgabe: ' . $e->getMessage(), true);
            }
        }
    }

    public function Hangup()
    {
        $this->SetTimerInterval('Hangup', 0);
        $this->DisconnectCurrentCall();
        $this->ClearCallState();
        $this->SetStatusText('Anruf beendet; nächster Queue-Eintrag wird geprüft.');
        $this->ScheduleQueueWorker(1000);
    }

    public function ClearQueue()
    {
        $this->SaveQueue([]);
        $this->WriteAttributeInteger('NextProcessAt', 0);
        $this->UpdateQueueLength();
        $this->SetStatusText('Warteschlange geleert. Ein aktiver Anruf wird nicht beendet.');
        if ($this->ReadAttributeInteger('ConnectionID') === 0) {
            $this->SetTimerInterval('QueueWorker', 0);
        }
    }

    private function FailCurrentJob(string $error, bool $retryable, bool $disconnect = true): void
    {
        if ($disconnect) {
            $this->DisconnectCurrentCall();
        }
        $jobJSON = $this->ReadAttributeString('CurrentJob');
        $job = json_decode($jobJSON, true);
        $this->SetLastError($error);
        $this->ClearCallState();

        if (is_array($job) && $retryable) {
            $attempts = (int)($job['Attempts'] ?? 0);
            $maxRetries = max(0, min(5, $this->ReadPropertyInteger('MaxRetries')));
            if ($attempts < $maxRetries) {
                $job['Attempts'] = $attempts + 1;
                $queue = $this->GetQueue();
                array_unshift($queue, $job);
                $this->SaveQueue($queue);
                $delay = max(1, min(600, $this->ReadPropertyInteger('RetryDelay')));
                $this->WriteAttributeInteger('NextProcessAt', time() + $delay);
                $this->SetStatusText($error . ' Wiederholung ' . ($attempts + 1) . '/' . $maxRetries . ' in ' . $delay . ' Sekunden.');
                $this->ScheduleQueueWorker($delay * 1000);
                return;
            }
        }
        $this->WriteAttributeString('CurrentJob', '');
        $this->WriteAttributeString('CurrentPhone', '');
        $this->WriteAttributeString('CurrentMessage', '');
        $this->SetValue('ActiveCall', '');
        $this->SetStatusText($error . ' Der Auftrag wird nicht erneut versucht.');
        $this->ScheduleQueueWorker(1000);
    }

    private function DisconnectCurrentCall(): void
    {
        $connectionID = $this->ReadAttributeInteger('ConnectionID');
        $voipID = $this->ReadPropertyInteger('VoIPInstanceID');
        if ($connectionID !== 0 && $voipID > 0 && IPS_InstanceExists($voipID)) {
            try {
                VoIP_Disconnect($voipID, $connectionID);
            } catch (Throwable $e) {
                IPS_LogMessage('Telefonansage Direkt', 'VoIP_Disconnect fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    private function ClearCallState(bool $clearJob = true): void
    {
        $this->SetTimerInterval('CheckCall', 0);
        $this->SetTimerInterval('Hangup', 0);
        $this->WriteAttributeInteger('ConnectionID', 0);
        $this->WriteAttributeInteger('CallStartedAt', 0);
        $this->WriteAttributeInteger('ConnectedAt', 0);
        $this->WriteAttributeString('WaveFile', '');
        $this->WriteAttributeBoolean('WaveStarted', false);
        if ($clearJob) {
            $this->WriteAttributeString('CurrentJob', '');
            $this->WriteAttributeString('CurrentPhone', '');
            $this->WriteAttributeString('CurrentMessage', '');
            $this->SetValue('ActiveCall', '');
        }
    }

    private function GetQueue(): array
    {
        $queue = json_decode($this->ReadAttributeString('QueueJSON'), true);
        return is_array($queue) ? array_values($queue) : [];
    }

    private function SaveQueue(array $queue): void
    {
        $this->WriteAttributeString('QueueJSON', json_encode(array_values($queue), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->UpdateQueueLength();
    }

    private function UpdateQueueLength(): void
    {
        SetValueInteger($this->GetIDForIdent('QueueLength'), count($this->GetQueue()));
    }

    private function ScheduleQueueWorker(int $milliseconds): void
    {
        $this->SetTimerInterval('QueueWorker', max(1, $milliseconds));
    }

    private function SetLastError(string $text): void
    {
        SetValueString($this->GetIDForIdent('LastError'), $text);
        IPS_LogMessage('Telefonansage Direkt Fehler', $text);
    }

    private function SetStatusText(string $text): void
    {
        SetValueString($this->GetIDForIdent('Status'), $text);
        IPS_LogMessage('Telefonansage Direkt', $text);
    }

    private function GetWaveDurationMs(string $file): int
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 10000;
        }
        $header = fread($handle, 44);
        fclose($handle);
        if (strlen($header) < 44 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
            return 10000;
        }
        $byteRate = unpack('V', substr($header, 28, 4))[1] ?? 0;
        $dataSize = unpack('V', substr($header, 40, 4))[1] ?? 0;
        if ($byteRate <= 0 || $dataSize <= 0) {
            return 10000;
        }
        return (int)ceil(($dataSize / $byteRate) * 1000);
    }
}
