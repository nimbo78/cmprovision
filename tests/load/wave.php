<?php
// Bench only: 48 modules start at once against the installed package, report progress every 5 s for a minute, then
// finish (45 done, 3 failed). Prints the start times (p50, p95, max), the boards found 15 s after the wave (with the
// database path; run as www-data, the database is in WAL) and every answer that was not 200.
//   runuser -u www-data -- php /tmp/wave.php http://127.0.0.1 /var/lib/cmprovision/database/database.sqlite
$base = rtrim($argv[1] ?? 'http://127.0.0.1', '/');
$db = isset($argv[2]) ? new PDO('sqlite:'.$argv[2], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : null;
if ($db)
    $db->exec('PRAGMA busy_timeout = 5000');
$modules = [];
for ($n = 1; $n <= 48; $n++)
    $modules[] = ['serial' => sprintf('10000000aa%06d', $n), 'mac' => sprintf('e4:5f:01:64:%02x:%02x', intdiv($n, 256), $n % 256)];

function run(array $urls, array $posts = []): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url)
    {
        $h = curl_init($url);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        if (isset($posts[$i]))
            curl_setopt($h, CURLOPT_POSTFIELDS, $posts[$i]);
        curl_multi_add_handle($mh, $h);
        $handles[$i] = $h;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running);
    $out = [];
    foreach ($handles as $i => $h)
    {
        $out[$i] = ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'seconds' => curl_getinfo($h, CURLINFO_TOTAL_TIME)];
        curl_multi_remove_handle($mh, $h);
    }
    curl_multi_close($mh);
    return $out;
}

$bad = [];
$t0 = microtime(true);
$starts = run(array_map(fn ($m) => "$base/scriptexecute?serial={$m['serial']}&mac={$m['mac']}&model=CM4&storagesize=62500000", $modules));
$times = array_column($starts, 'seconds');
sort($times);
printf("starts: p50 %.2f s, p95 %.2f s, max %.2f s\n", $times[23], $times[45], end($times));
foreach ($starts as $i => $r)
    if ($r['status'] !== 200) $bad[] = "start {$modules[$i]['serial']}: {$r['status']}";

for ($t = 1; $t <= 12; $t++)
{
    sleep(5);
    foreach (run(array_map(fn ($m) => "$base/scriptexecute?serial={$m['serial']}&progress=write&sectors=".($t * 100000), $modules)) as $i => $r)
        if ($r['status'] !== 200) $bad[] = "progress $t {$modules[$i]['serial']}: {$r['status']}";
    if ($t === 3 && $db)
        printf("boards %.0f s after the wave: %d of 48\n", microtime(true) - $t0, $db->query(
            "select count(*) from cms where serial like '10000000aa%' and provisioning_board like 'stand48%' and board_pending_since is null")->fetchColumn());
}

$urls = $posts = [];
foreach ($modules as $i => $m)
    if ($i < 45)
        $urls[$i] = "$base/scriptexecute?serial={$m['serial']}&alldone=1&temp=50C&verify=0";
    else
    {
        $urls[$i] = "$base/scriptexecute?serial={$m['serial']}&retcode=1&phase=dd";
        $posts[$i] = ['log' => new CURLStringFile("curl exit code 18\n", 'dd.log', 'text/plain')];
    }
foreach (run($urls, $posts) as $i => $r)
    if ($r['status'] !== 200) $bad[] = "finish {$modules[$i]['serial']}: {$r['status']}";

echo $bad ? "NOT 200:\n".implode("\n", $bad)."\n" : "every request answered 200\n";
