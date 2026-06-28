<?php
declare(strict_types=1);

const CACHE_FILE = __DIR__ . '/.status-cache.json';
const CACHE_TTL  = 15;
const TIMEOUT    = 6;
const COLS       = 80;

$devices = [
    'happuter' => [
        'jellyfin'          => 'https://jellyfin.meihapps.gay',
        'openwebui'         => 'https://openwebui.meihapps.gay',
        'slskd'             => 'https://slskd.meihapps.gay',
        'prowlarr'          => 'https://prowlarr.meihapps.gay',
        'qbittorrent'       => 'https://qbittorrent.meihapps.gay',
        'lidarr'            => 'https://lidarr.meihapps.gay',
    ],
    'happvps' => [
        'website'     => 'https://meihapps.gay',
        'vaultwarden' => 'https://passwords.meihapps.gay',
        'plausible'   => 'https://analytics.meihapps.gay',
    ],
];

// ── cache ─────────────────────────────────────────────────────────────────────

$cache = null;
if (is_file(CACHE_FILE)) {
    $raw = file_get_contents(CACHE_FILE);
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)
            && isset($decoded['timestamp'], $decoded['results'])
            && (time() - (int) $decoded['timestamp']) < CACHE_TTL) {
            $cache = $decoded;
        }
    }
}

if ($cache === null) {
    // ── curl_multi parallel health checks ─────────────────────────────────────
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($devices as $services) {
        foreach ($services as $name => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'happnet-status/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.5);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $name => $ch) {
        $code            = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err             = curl_error($ch);
        $results[$name]  = ($err === '' && $code >= 200 && $code < 500);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    $cache = ['timestamp' => time(), 'results' => $results];
    file_put_contents(CACHE_FILE, json_encode($cache, JSON_PRETTY_PRINT), LOCK_EX);
}

$results     = $cache['results'];
$updated_ts  = (int) $cache['timestamp'];
$updated_iso = date('c', $updated_ts);

// ── rendering helpers ─────────────────────────────────────────────────────────

function dashes(int $n): string
{
    return str_repeat('─', max(0, $n));
}

// "┌─ " (3) + host + " " (1) + dashes + " " (1) + status + " ─┐" (3) = COLS
function header_dashes(string $host, string $status): int
{
    $fixed = 3 + mb_strlen($host, 'UTF-8') + 1 + 1 + mb_strlen($status, 'UTF-8') + 3;
    return max(0, COLS - $fixed);
}

// "└" (1) + dashes + "┘" (1) = COLS
function bottom_dashes(): int
{
    return COLS - 2;
}

// " ~ " (3) + dashes + " mei@happuter" (13) = COLS
function sep_dashes(): int
{
    return COLS - 3 - 13;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>happnet — status</title>
<link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="page">
    <div class="wrap">

      <header class="header rise delay-1">
        <img class="pfp" src="assets/pfp.png" alt="mei happs avatar" width="50" height="50">
        <div>
          <h1 class="title">happnet</h1>
        </div>
      </header>

      <main class="terminal">

        <div class="prompt-top">
          <span class="chevron">❯</span><span style="color:#bac2de;">&nbsp;happnet-status</span>
        </div>

<?php foreach ($devices as $host => $services):
    $down = 0;
    foreach ($services as $name => $_url) {
        if (!($results[$name] ?? false)) {
            $down++;
        }
    }
    $status_text  = $down > 0 ? "{$down} down" : 'active now';
    $status_class = $down > 0 ? 'status-down' : 'status-active';
    $svc_names    = array_keys($services);
    $last_idx     = count($svc_names) - 1;
?>
        <section class="box rise">
          <div class="row">
            <span>┌─&nbsp;</span>
            <span class="dev-name"><?= e($host) ?></span>
            <span class="fill">&nbsp;<?= dashes(header_dashes($host, $status_text)) ?>&nbsp;</span>
            <span class="<?= $status_class ?>"><?= e($status_text) ?></span>
            <span>&nbsp;─┐</span>
          </div>
<?php foreach ($svc_names as $i => $name):
    $online    = $results[$name] ?? false;
    $connector = ($i === $last_idx) ? '└──&nbsp;' : '├──&nbsp;';
?>
            <div class="row">
              <span>│&nbsp;</span>
              <span class="connector"><?= $connector ?></span>
<?php if ($online): ?>
              <span class="marker-online">●</span>
              <span class="svc-online">&nbsp;<?= e($name) ?></span>
              <span class="fill"></span>
              <span>│</span>
<?php else: ?>
              <span class="marker-offline">✕</span>
              <span class="svc-offline">&nbsp;<?= e($name) ?></span>
              <span class="fill"></span>
              <span class="down-tag">down&nbsp;</span>
              <span>│</span>
<?php endif; ?>
            </div>
<?php endforeach; ?>
          <div class="row">
            <span>└</span>
            <span class="fill"><?= dashes(bottom_dashes()) ?></span>
            <span>┘</span>
          </div>
        </section>

<?php endforeach; ?>
        <div class="prompt-bottom">
          <div class="row" style="color:#6c7086;">
            <span>&nbsp;~&nbsp;</span>
            <span class="fill" style="color:#45475a;"><?= dashes(sep_dashes()) ?></span>
            <span>&nbsp;<span class="host-mei">mei</span><span class="host-name">@happuter</span></span>
          </div>
          <div class="row">
            <span class="chevron">❯</span><span class="caret">&nbsp;█</span>
          </div>
        </div>
      </main>

      <footer class="legend rise delay-2">
        <span class="item"><span class="dot-online">●</span> online</span>
        <span class="item"><span class="dot-offline">✕</span> offline</span>
        <span class="spacer"></span>
        <time id="ts" datetime="<?= e($updated_iso) ?>"></time>
      </footer>

    </div>
  </div>
  <script>
  (function () {
    var el = document.getElementById('ts');
    var ts = new Date(el.getAttribute('datetime')).getTime();
    function fmt() {
      var s = Math.floor((Date.now() - ts) / 1000);
      if (s < 60)   return 'updated just now';
      if (s < 3600) return 'updated ' + Math.floor(s / 60) + ' min ago';
      return 'updated ' + Math.floor(s / 3600) + ' hr ago';
    }
    function tick() { el.textContent = fmt(); }
    tick();
    setInterval(tick, 30000);

    var TTL = <?= CACHE_TTL ?>;
    var elapsed = (Date.now() - ts) / 1000;
    var remaining = Math.max(1, TTL - elapsed);
    setTimeout(function () { location.reload(); }, remaining * 1000);
  }());
  </script>
</body>
</html>
