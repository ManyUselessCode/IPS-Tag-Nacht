<?php

declare(strict_types=1);

/**
 * Kleiner Nachbau von Symcon für das Modul TagNacht: Variablen, Nachrichten, Timer und
 * KNX-DPT-1-Instanzen, die geschriebene Werte merken. Aufruf: php tests/test.php
 */

const IPS_KERNELMESSAGE = 10100;
const KR_READY = 10103;
const VM_UPDATE = 10603;
const IS_ACTIVE = 102;
const IS_INACTIVE = 104;
const KL_WARNING = 10204;
const KL_ERROR = 10206;
const VARIABLE_PRESENTATION_SWITCH = '{60AE6B26-B3E2-BDB1-A3A9-BE232A5B6E0C}';

set_error_handler(function (int $nr, string $text, string $datei, int $zeile): bool {
    if ((error_reporting() & $nr) == 0) {
        return true;
    }
    throw new ErrorException($text, 0, $nr, $datei, $zeile);
});

final class Sym
{
    public static array $variablen = [];
    public static array $instanzen = [];
    public static array $hidden = [];
    public static array $abos = [];
    public static array $bus = [];
    public static array $log = [];
    public static int $runlevel = KR_READY;
    public static int $id = 10000;
}

function IPS_GetKernelRunlevel(): int { return Sym::$runlevel; }
function IPS_VariableExists(int $id): bool { return isset(Sym::$variablen[$id]); }
function IPS_InstanceExists(int $id): bool { return isset(Sym::$instanzen[$id]); }
function IPS_SetHidden(int $id, bool $h): bool { Sym::$hidden[$id] = $h; return true; }
function IPS_GetObjectIDByIdent(string $ident, int $eltern): int|false
{
    return Sym::$instanzen[$eltern]['idents'][$ident] ?? false;
}
function GetValue(int $id): mixed { return Sym::$variablen[$id]; }
function KNX_WriteDPT1(int $id, bool $wert): bool { Sym::$bus[] = [$id, $wert]; return true; }

function variable(mixed $wert): int
{
    $id = ++Sym::$id;
    Sym::$variablen[$id] = $wert;
    return $id;
}

function setze(int $id, mixed $wert): void
{
    $geaendert = Sym::$variablen[$id] !== $wert;
    Sym::$variablen[$id] = $wert;
    foreach (Sym::$abos[$id] ?? [] as $modul) {
        $modul->MessageSink(0, $id, VM_UPDATE, [$wert, $geaendert, null, 0]);
    }
}

class IPSModule
{
    public int $InstanceID;
    private array $props = [];
    private array $timer = [];
    private int $status = 0;
    private array $refs = [];

    public function __construct()
    {
        $this->InstanceID = ++Sym::$id;
        Sym::$instanzen[$this->InstanceID] = ['idents' => []];
    }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyInteger(string $n, int $w): void { $this->props[$n] = $w; }
    protected function RegisterPropertyFloat(string $n, float $w): void { $this->props[$n] = $w; }
    protected function RegisterPropertyString(string $n, string $w): void { $this->props[$n] = $w; }
    public function SetProperty(string $n, mixed $w): void { $this->props[$n] = $w; }
    protected function ReadPropertyInteger(string $n): int { return $this->props[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float) $this->props[$n]; }
    protected function ReadPropertyString(string $n): string { return $this->props[$n]; }
    protected function RegisterTimer(string $n, int $ms, string $s): void { $this->timer[$n] = $ms; }
    protected function SetTimerInterval(string $n, int $ms): void { $this->timer[$n] = $ms; }
    public function Timer(string $n): int { return $this->timer[$n]; }
    private function Var(string $ident, mixed $wert): void
    {
        if (!isset(Sym::$instanzen[$this->InstanceID]['idents'][$ident])) {
            Sym::$instanzen[$this->InstanceID]['idents'][$ident] = variable($wert);
        }
    }
    protected function RegisterVariableBoolean(string $i, string $n, array $d, int $p): void { $this->Var($i, false); }
    protected function RegisterVariableString(string $i, string $n, string $d, int $p): void { $this->Var($i, ''); }
    public function Id(string $ident): int { return Sym::$instanzen[$this->InstanceID]['idents'][$ident]; }
    protected function GetValue(string $ident): mixed { return Sym::$variablen[$this->Id($ident)]; }
    protected function SetValue(string $ident, mixed $w): bool { Sym::$variablen[$this->Id($ident)] = $w; return true; }
    protected function RegisterMessage(int $s, int $m): void { if ($m == VM_UPDATE) { Sym::$abos[$s][spl_object_id($this)] = $this; } }
    protected function UnregisterMessage(int $s, int $m): void { unset(Sym::$abos[$s][spl_object_id($this)]); }
    protected function GetMessageList(): array
    {
        $l = [];
        foreach (Sym::$abos as $s => $m) {
            if (isset($m[spl_object_id($this)])) {
                $l[$s] = [VM_UPDATE];
            }
        }
        return $l;
    }
    protected function RegisterReference(int $id): void { $this->refs[$id] = $id; }
    protected function UnregisterReference(int $id): void { unset($this->refs[$id]); }
    protected function GetReferenceList(): array { return array_values($this->refs); }
    protected function SetStatus(int $s): void { $this->status = $s; }
    public function GetStatus(): int { return $this->status; }
    protected function LogMessage(string $t, int $typ): void { Sym::$log[] = $t; }
}

require __DIR__ . '/../TagNacht/module.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $was, mixed $ist, mixed $soll): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if ($ist !== $soll) {
        $fehler++;
        echo "FEHLER $was: ist " . var_export($ist, true) . ', soll ' . var_export($soll, true) . "\n";
    }
}

function aufbau(float $lux, int $objekte = 2): array
{
    Sym::$bus = Sym::$log = [];
    $hell = variable($lux);
    $ids = [];
    for ($i = 0; $i < $objekte; $i++) {
        $ids[] = ++Sym::$id;
        Sym::$instanzen[Sym::$id] = ['idents' => []];
    }
    $m = new TagNacht();
    $m->Create();
    $m->SetProperty('HelligkeitID', $hell);
    $m->SetProperty('Objekte', json_encode(array_map(fn ($id) => ['InstanceID' => $id], $ids)));
    $m->ApplyChanges();
    return [$m, $hell, $ids];
}

// Übernehmen bei Dunkelheit: Nacht an alle Objekte
[$m, $hell, $ids] = aufbau(2.0);
pruefe('Status', $m->GetStatus(), IS_ACTIVE);
pruefe('Übernehmen dunkel', Sym::$bus, [[$ids[0], false], [$ids[1], false]]);
pruefe('Grund versteckt', Sym::$hidden[$m->Id('Grund')] ?? null, true);
pruefe('Grund', GetValue($m->Id('Grund')), 'Nacht: Helligkeit 2 lx unter 5 lx, nach dem Übernehmen');

// heller, aber zwischen den Schwellen: nichts
Sym::$bus = [];
setze($hell, 10.0);
pruefe('zwischen', Sym::$bus, []);
// über Tag-Schwelle: Tag
setze($hell, 16.5);
pruefe('Tag', Sym::$bus, [[$ids[0], true], [$ids[1], true]]);
pruefe('Variable Tag', GetValue($m->Id('Tag')), true);
pruefe('Grund Tag', GetValue($m->Id('Grund')), 'Tag: Helligkeit 16,5 lx über 15 lx');
// weiter hell: kein neues Telegramm
Sym::$bus = [];
setze($hell, 300.0);
pruefe('bleibt Tag', Sym::$bus, []);
// Aktualisierung ohne Änderung zählt nicht
setze($hell, 300.0);
pruefe('ohne Änderung', Sym::$bus, []);
// zurück in die Dämmerung: bleibt Tag
setze($hell, 6.0);
pruefe('Dämmerung bleibt Tag', Sym::$bus, []);
// genau auf der Schwelle: noch nicht Nacht
setze($hell, 5.0);
pruefe('auf Schwelle', Sym::$bus, []);
setze($hell, 4.9);
pruefe('Nacht', Sym::$bus, [[$ids[0], false], [$ids[1], false]]);

// Start: Modus erst nach dem Warten neu schreiben, auch zwischen den Schwellen
Sym::$bus = [];
Sym::$variablen[$hell] = 10.0;
$m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
pruefe('Start wartet', Sym::$bus, []);
pruefe('Start-Timer', $m->Timer('Start'), 30000);
$m->RequestAction('Start', 0);
pruefe('Start schreibt bisherigen Modus', Sym::$bus, [[$ids[0], false], [$ids[1], false]]);
pruefe('Start-Timer aus', $m->Timer('Start'), 0);
pruefe('Grund Start', GetValue($m->Id('Grund')), 'Nacht: Helligkeit 10 lx zwischen den Schwellen, bisheriger Modus, nach dem Start');

// Übernehmen bei Tageslicht
[$m, $hell, $ids] = aufbau(500.0, 1);
pruefe('Übernehmen hell', Sym::$bus, [[$ids[0], true]]);

// ohne Objekte: inaktiv, nichts geschrieben
[$m, $hell, $ids] = aufbau(500.0, 0);
pruefe('inaktiv', $m->GetStatus(), IS_INACTIVE);
pruefe('inaktiv schreibt nicht', Sym::$bus, []);
setze($hell, 1.0);
pruefe('inaktiv reagiert nicht', Sym::$bus, []);

// falsche Schwellen: Fehler
[$m, $hell, $ids] = aufbau(500.0, 1);
$m->SetProperty('SchwelleTag', 5.0);
Sym::$bus = [];
$m->ApplyChanges();
pruefe('Schwellen Fehler', $m->GetStatus(), 201);
pruefe('Schwellen Log', count(Sym::$log), 1);
setze($hell, 1.0);
pruefe('Fehler reagiert nicht', Sym::$bus, []);

// fehlende Instanz: Fehler
[$m, $hell, $ids] = aufbau(500.0, 1);
unset(Sym::$instanzen[$ids[0]]);
$m->ApplyChanges();
pruefe('fehlende Instanz', $m->GetStatus(), 201);

echo $fehler == 0 ? "OK, $anzahl Prüfungen\n" : "$fehler von $anzahl Prüfungen fehlgeschlagen\n";
exit($fehler == 0 ? 0 : 1);
