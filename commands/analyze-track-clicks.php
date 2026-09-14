<?php

/**
 * Forensik fuer missbrauchte Click-Tracking-Redirects.
 *
 * Beantwortet genau eine Frage: welche der missbraeuchlichen Aufrufe von
 * /crelish/track/click sind tatsaechlich in der Klickstatistik gelandet?
 *
 * Hintergrund: bis crelish 0.21.10 hat TrackClickAction bei ungueltigem Token
 * zwar weitergeleitet, aber VOR dem Schreiben in analytics_element_views
 * abgebrochen. Ein Fremdklick wurde also nur dann gezaehlt, wenn das Token zum
 * Zeitpunkt des Aufrufs noch gueltig war: Signatur korrekt und juenger als
 * tokenValidityWindow.
 *
 * Die alte Signatur laesst sich vollstaendig nachrechnen, weil sie mit leerem
 * Schluessel gebildet wurde - genau das war einer der drei Fehler. Fuer Tokens
 * ab 0.21.10 funktioniert das Skript nicht und muss es auch nicht: seither
 * kann kein fremdes Redirect-Ziel mehr durchkommen.
 *
 * Aufruf:
 *   php analyze-track-clicks.php --own-hosts=beispiel.de,www.beispiel.de access.log
 *   php analyze-track-clicks.php --own-hosts=beispiel.de ~/logs/*.log > befund.txt
 *
 * Optionen:
 *   --own-hosts=a.de,b.de  Eigene, legitime Weiterleitungsziele. Alles andere
 *                          gilt als fremd. Ohne Angabe ist jedes Ziel fremd.
 *   --window=3600          tokenValidityWindow des alten Codes in Sekunden.
 *   --csv=pfad.csv         Zieldatei fuer die Trefferliste.
 *                          Vorgabe: zu-loeschende-klicks.csv im Arbeitsverzeichnis.
 *
 * Reines PHP ohne Abhaengigkeiten, laeuft direkt auf dem Server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ownHosts = [];
$window   = 3600;
$csvPath  = getcwd() . '/zu-loeschende-klicks.csv';
$files    = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--own-hosts=')) {
        $ownHosts = array_values(array_filter(array_map(
            static fn(string $h): string => strtolower(trim($h)),
            explode(',', substr($arg, 12))
        )));
    } elseif (str_starts_with($arg, '--window=')) {
        $window = max(1, (int)substr($arg, 9));
    } elseif (str_starts_with($arg, '--csv=')) {
        $csvPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unbekannte Option: $arg\n");
        exit(1);
    } else {
        $files[] = $arg;
    }
}

if (!$files) {
    fwrite(STDERR, "Aufruf: php " . basename(__FILE__) . " [--own-hosts=a.de,b.de] [--window=3600] [--csv=pfad] <access.log> [...]\n");
    exit(1);
}

if (!$ownHosts) {
    fwrite(STDERR, "Hinweis: ohne --own-hosts gilt jedes Weiterleitungsziel als fremd.\n\n");
}

$stats = [
    'zeilen'          => 0,
    'track_aufrufe'   => 0,
    'ohne_redirect'   => 0,
    'eigenes_ziel'    => 0,
    'fremd_gezaehlt'  => 0,
    'fremd_verworfen' => 0,
];

/** @var array<string,array{uuid:string,type:string,zeit:string,host:string}> */
$gezaehlt = [];
$fremdeHosts = [];

foreach ($files as $file) {
    $fh = @fopen($file, 'r');
    if (!$fh) {
        fwrite(STDERR, "Kann $file nicht lesen, uebersprungen.\n");
        continue;
    }

    while (($line = fgets($fh)) !== false) {
        $stats['zeilen']++;

        if (strpos($line, '/crelish/track/click') === false) {
            continue;
        }

        // Zeitstempel im Common-Log-Format: [08/Sep/2026:14:07:40 +0200].
        // Der Offset wird mit geparst, sonst verschiebt sich das Tokenalter.
        if (!preg_match('~\[(\d{2})/(\w{3})/(\d{4}):(\d{2}:\d{2}:\d{2})\s([+\-]\d{4})\]~', $line, $t)) {
            continue;
        }
        $requestTime = strtotime("{$t[1]} {$t[2]} {$t[3]} {$t[4]} {$t[5]}");
        if ($requestTime === false) {
            continue;
        }

        // Angefragter Pfad inklusive Query
        if (!preg_match('~"(?:GET|POST|HEAD)\s+(\S+)~', $line, $m)) {
            continue;
        }
        $query = parse_url(urldecode($m[1]), PHP_URL_QUERY);
        if ($query === null || $query === false) {
            continue;
        }

        parse_str($query, $p);
        $stats['track_aufrufe']++;

        $redirect = is_string($p['redirect'] ?? null) ? $p['redirect'] : '';
        if ($redirect === '') {
            $stats['ohne_redirect']++;
            continue;
        }

        $host = strtolower((string)parse_url($redirect, PHP_URL_HOST));
        if ($host !== '' && in_array($host, $ownHosts, true)) {
            $stats['eigenes_ziel']++;
            continue;
        }

        $fremdeHosts[$host !== '' ? $host : '(ohne Host)'] = ($fremdeHosts[$host !== '' ? $host : '(ohne Host)'] ?? 0) + 1;

        // Alte Pruefung nachrechnen: Signatur mit leerem Schluessel, 16 Hex-
        // Zeichen Hash plus 10 Zeichen base36-Zeitstempel.
        $uuid  = is_string($p['uuid'] ?? null) ? $p['uuid'] : '';
        $type  = is_string($p['type'] ?? null) ? $p['type'] : 'link';
        $token = is_string($p['token'] ?? null) ? $p['token'] : '';

        $counted = false;
        if ($uuid !== '' && strlen($token) >= 20 && preg_match('/^[0-9a-z]{10}$/', substr($token, -10))) {
            $hash      = substr($token, 0, -10);
            $tokenTime = (int)base_convert(substr($token, -10), 36, 10);
            $expected  = substr(hash_hmac('sha256', $uuid . $tokenTime, ''), 0, 16);
            $alter     = $requestTime - $tokenTime;

            $counted = hash_equals($expected, $hash) && $alter >= 0 && $alter <= $window;
        }

        if ($counted) {
            $stats['fremd_gezaehlt']++;
            $zeit = date('Y-m-d H:i:s', $requestTime);
            $gezaehlt[$uuid . '|' . $zeit] = [
                'uuid' => $uuid,
                'type' => $type,
                'zeit' => $zeit,
                'host' => $host,
            ];
        } else {
            $stats['fremd_verworfen']++;
        }
    }

    fclose($fh);
}

printf("Ausgewertete Logzeilen:            %s\n", number_format($stats['zeilen'], 0, ',', '.'));
printf("Aufrufe von /crelish/track/click:  %s\n", number_format($stats['track_aufrufe'], 0, ',', '.'));
printf("  davon ohne redirect (Ping):      %d\n", $stats['ohne_redirect']);
printf("  davon eigenes Ziel:              %d\n", $stats['eigenes_ziel']);
printf("  davon FREMDES Ziel:              %d\n", $stats['fremd_gezaehlt'] + $stats['fremd_verworfen']);
printf("     - nur weitergeleitet, nicht gezaehlt: %d\n", $stats['fremd_verworfen']);
printf("     - IN DER STATISTIK GELANDET:          %d\n", $stats['fremd_gezaehlt']);

if ($fremdeHosts) {
    arsort($fremdeHosts);
    echo "\nFremde Weiterleitungsziele:\n";
    foreach (array_slice($fremdeHosts, 0, 25, true) as $host => $n) {
        printf("  %-50s %d\n", $host, $n);
    }
}

if (!$gezaehlt) {
    echo "\nErgebnis: kein einziger Fremdklick ist in der Statistik gelandet.\n";
    echo "Es gibt nichts zu bereinigen.\n";
    exit(0);
}

echo "\nZu loeschende Zeilen aus analytics_element_views:\n";
foreach ($gezaehlt as $r) {
    printf("  %s  %-10s %s  -> %s\n", $r['uuid'], $r['type'], $r['zeit'], $r['host']);
}

$fh = @fopen($csvPath, 'w');
if (!$fh) {
    fwrite(STDERR, "\nKann $csvPath nicht schreiben.\n");
    exit(1);
}
fputcsv($fh, ['element_uuid', 'element_type', 'created_at', 'redirect_host'], ',', '"', '\\');
foreach ($gezaehlt as $r) {
    fputcsv($fh, [$r['uuid'], $r['type'], $r['zeit'], $r['host']], ',', '"', '\\');
}
fclose($fh);

echo "\nCSV geschrieben: $csvPath\n";
echo "Nach dem Loeschen die Aggregate neu rechnen, je betroffenem Tag:\n";
echo "  php yii crelish/analytics-aggregation/daily <JJJJ-MM-TT>\n";
