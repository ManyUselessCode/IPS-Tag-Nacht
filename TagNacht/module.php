<?php

declare(strict_types=1);

/**
 * Schaltet die Tag/Nacht-Objekte von Tastern nach der Helligkeit im Raum um, damit
 * die Taster im Dunkeln nicht blenden. Vorbild ist der Home-Assistant-Blueprint
 * "Taster | Tag/Nacht | Helligkeit", ohne Umschalten von Hand.
 * Dunkler als "Nacht unter": Nacht. Heller als "Tag über": Tag. Dazwischen bleibt es.
 * Die Taster lassen sich nicht lesen, deshalb schreibt das Modul den Modus beim
 * Übernehmen und nach dem Start neu.
 */
class TagNacht extends IPSModule
{
    private const STATUS_FEHLER = 201;
    private const START_WARTEN_MS = 30000; // Gateway und Bus nach dem Start abwarten

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('HelligkeitID', 0);
        $this->RegisterPropertyFloat('SchwelleNacht', 5);
        $this->RegisterPropertyFloat('SchwelleTag', 15);
        $this->RegisterPropertyString('Objekte', '[]');

        $this->RegisterTimer('Start', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Start\', 0);');

        $this->RegisterVariableBoolean('Tag', 'Tag', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'USE_ICON_FALSE' => true,
            'ICON_TRUE'      => 'sun',
            'ICON_FALSE'     => 'moon',
            'USAGE_TYPE'     => 0
        ], 1);
        $this->RegisterVariableString('Grund', 'Grund', '', 2);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            foreach ($nachrichten as $nachricht) {
                $this->UnregisterMessage($sender, $nachricht);
            }
        }
        foreach ($this->GetReferenceList() as $referenz) {
            $this->UnregisterReference($referenz);
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $grund = @IPS_GetObjectIDByIdent('Grund', $this->InstanceID);
        if ($grund !== false) {
            IPS_SetHidden($grund, true);
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            if ($this->Einrichten()) {
                $this->Setzen('nach dem Übernehmen', true);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE) {
            if ($Data[0] == KR_READY && $this->Einrichten()) {
                $this->SetTimerInterval('Start', self::START_WARTEN_MS);
            }
            return;
        }
        if ($Message == VM_UPDATE && $this->GetStatus() == IS_ACTIVE
            && $SenderID == $this->ReadPropertyInteger('HelligkeitID') && $Data[1]) {
            $this->Setzen('', false);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == 'Start') {
            $this->SetTimerInterval('Start', 0);
            if ($this->GetStatus() == IS_ACTIVE) {
                $this->Setzen('nach dem Start', true);
            }
            return;
        }
        throw new Exception('Unbekannter Ident ' . $Ident);
    }

    private function Einrichten(): bool
    {
        $helligkeit = $this->ReadPropertyInteger('HelligkeitID');
        $objekte = $this->Objekte();
        if ($helligkeit == 0 || count($objekte) == 0) {
            $this->SetStatus(IS_INACTIVE);
            return false;
        }
        $fehler = [];
        if (!IPS_VariableExists($helligkeit)) {
            $fehler[] = 'Helligkeit ' . $helligkeit . ' ist keine Variable';
        }
        foreach ($objekte as $id) {
            if (!IPS_InstanceExists($id)) {
                $fehler[] = 'Tag/Nacht-Objekt ' . $id . ' ist keine Instanz';
            }
        }
        if ($this->ReadPropertyFloat('SchwelleTag') <= $this->ReadPropertyFloat('SchwelleNacht')) {
            $fehler[] = '"Tag über" muss größer als "Nacht unter" sein';
        }
        if (count($fehler) > 0) {
            $this->LogMessage(implode('; ', $fehler), KL_ERROR);
            $this->SetStatus(self::STATUS_FEHLER);
            return false;
        }

        $this->RegisterMessage($helligkeit, VM_UPDATE);
        $this->RegisterReference($helligkeit);
        foreach ($objekte as $id) {
            $this->RegisterReference($id);
        }
        $this->SetStatus(IS_ACTIVE);
        return true;
    }

    /**
     * Rechnet den Modus aus der Helligkeit. Ohne $immer wird nur geschrieben, wenn
     * sich der Modus ändert. Mit $immer (Übernehmen, Start) wird auch dann geschrieben,
     * wenn die Helligkeit zwischen den Schwellen liegt, dann mit dem bisherigen Modus.
     */
    private function Setzen(string $anlass, bool $immer): void
    {
        $lux = (float) GetValue($this->ReadPropertyInteger('HelligkeitID'));
        $nacht = $this->ReadPropertyFloat('SchwelleNacht');
        $tag = $this->ReadPropertyFloat('SchwelleTag');
        $bisher = $this->GetValue('Tag');
        $text = $this->Zahl($lux) . ' lx';

        if ($lux < $nacht) {
            $neu = false;
            $warum = 'Helligkeit ' . $text . ' unter ' . $this->Zahl($nacht) . ' lx';
        } elseif ($lux > $tag) {
            $neu = true;
            $warum = 'Helligkeit ' . $text . ' über ' . $this->Zahl($tag) . ' lx';
        } else {
            $neu = $bisher;
            $warum = 'Helligkeit ' . $text . ' zwischen den Schwellen, bisheriger Modus';
        }
        if (!$immer && $neu === $bisher) {
            return;
        }
        if ($anlass != '') {
            $warum .= ', ' . $anlass;
        }

        $this->SetValue('Tag', $neu);
        $this->SetValue('Grund', ($neu ? 'Tag' : 'Nacht') . ': ' . $warum);
        foreach ($this->Objekte() as $id) {
            if (!@KNX_WriteDPT1($id, $neu)) {
                $this->LogMessage('Tag/Nacht-Objekt ' . $id . ' konnte nicht geschrieben werden', KL_WARNING);
            }
        }
    }

    private function Objekte(): array
    {
        $liste = json_decode($this->ReadPropertyString('Objekte'), true) ?: [];
        return array_values(array_filter(array_map(fn ($zeile) => (int) ($zeile['InstanceID'] ?? 0), $liste)));
    }

    private function Zahl(float $wert): string
    {
        return str_replace('.', ',', (string) round($wert, 1));
    }
}
