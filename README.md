# IPS-Tag-Nacht

Symcon-Modul **TagNacht** (Präfix `TAGNACHT`): schaltet die Tag/Nacht-Objekte von Tastern (etwa MDT-Glastaster, 1 = Tag, 0 = Nacht) nach der Helligkeit im Raum, damit die Taster im Dunkeln nicht blenden.

- Dunkler als „Nacht unter“ (Vorgabe 5 lx): Nacht. Heller als „Tag über“ (Vorgabe 15 lx): Tag. Dazwischen bleibt der Modus, damit in der Dämmerung nichts hin und her schaltet.
- Geschrieben wird nur bei einem Wechsel. Weil sich die Tag/Nacht-Objekte nicht lesen lassen, schreibt das Modul den Modus außerdem beim Übernehmen und 30 Sekunden nach dem Start neu.
- Variablen: „Tag“ (an = Tag) und „Grund“ (versteckt, zum Aufzeichnen).

Eine Instanz je Raum. Einstellungen: Helligkeit (Variable), die beiden Schwellen und die Liste der Tag/Nacht-Instanzen (KNX DPT 1).

Tests: `php tests/test.php`
