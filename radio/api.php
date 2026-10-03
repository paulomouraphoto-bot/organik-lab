<?php
/**
 * Tuner — helper endpoint for lab.organikreations.com/radio/
 *
 *   api.php?a=fip&ch=fip_jazz        now playing on a FIP channel (Radio France live API)
 *   api.php?a=band&name=scotland     curated station list (merged Radio Browser searches)
 *   api.php?a=search&q=..&cc=GB      search the Radio Browser directory
 *   api.php?a=countries              countries with station counts
 *   api.php?a=icy&id=<stationuuid>   now playing read from a station's Icecast/Shoutcast stream
 *   api.php?a=click&id=<stationuuid> tell Radio Browser a station was played (keeps their stats honest)
 *
 * No open proxy: only Radio France and Radio Browser are ever contacted, plus the stream
 * of a station looked up by its Radio Browser id (public addresses only, metadata bytes only).
 */

const UA = 'OrganikTuner/1.0 (+https://lab.organikreations.com/radio/)';
const RB_HOSTS = ['de1.api.radio-browser.info', 'de2.api.radio-browser.info', 'fi1.api.radio-browser.info', 'nl1.api.radio-browser.info'];

$FIP = ['fip','fip_rock','fip_jazz','fip_groove','fip_world','fip_nouveautes','fip_reggae','fip_electro','fip_metal','fip_pop','fip_hiphop','fip_sacre_francais'];

$BANDS = [
  'scotland' => [
    'searches' => [
      ['state' => 'Scotland', 'countrycode' => 'GB', 'limit' => 80],
      ['name' => 'BBC Radio Scotland', 'limit' => 6],
      ['name' => 'nan Gaidheal', 'limit' => 4],
      ['name' => 'nan Gàidheal', 'limit' => 4],
      ['name' => 'Clyde', 'countrycode' => 'GB', 'limit' => 6],
      ['name' => 'Forth', 'countrycode' => 'GB', 'limit' => 6],
      ['name' => 'Northsound', 'limit' => 4],
      ['name' => 'Radio Borders', 'limit' => 3],
      ['name' => 'MFR', 'countrycode' => 'GB', 'limit' => 4],
      ['name' => 'Tay', 'countrycode' => 'GB', 'limit' => 4],
      ['name' => 'Cool FM Scotland', 'limit' => 3],
      ['tag' => 'scottish', 'limit' => 40],
      ['tag' => 'scotland', 'limit' => 40],
    ],
    'max' => 40,
  ],
];

if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; } }

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$a = $_GET['a'] ?? '';
try {
  switch ($a) {
    case 'fip':       out(fip_now($_GET['ch'] ?? 'fip'), 10); break;
    case 'band':      out(band($_GET['name'] ?? ''), 3600); break;
    case 'search':    out(search(), 600); break;
    case 'countries': out(countries(), 86400); break;
    case 'icy':       out(icy_now($_GET['id'] ?? ''), 15); break;
    case 'click':     out(click($_GET['id'] ?? ''), 0); break;
    case 'diag':      out(diag(), 0); break;
    default:          http_response_code(400); out(['error' => 'unknown action'], 0);
  }
} catch (Throwable $e) {
  http_response_code(502);
  out(['error' => 'upstream unavailable'], 0);
}

// ==========================================================================
// Actions

function fip_now($ch) {
  global $FIP;
  if (!in_array($ch, $FIP, true)) { http_response_code(400); return ['error' => 'unknown channel']; }
  return cached('fip-' . $ch, 15, function () use ($ch) {
    $body = fetch('https://www.radiofrance.fr/fip/api/live?webradio=' . rawurlencode($ch), 6, ['Accept: application/json']);
    $j = $body ? json_decode($body, true) : null;
    if (!is_array($j)) return null;
    $now = $j['now'] ?? $j;
    $first  = trim(strip_tags((string)($now['firstLine']['title'] ?? $now['firstLine'] ?? '')));
    $second = trim(strip_tags((string)($now['secondLine']['title'] ?? $now['secondLine'] ?? '')));
    $song = $now['song'] ?? [];
    $cover = find_img($now['visuals'] ?? null) ?: find_img($now['cover'] ?? null) ?: find_img($song);
    $delay = (int)($j['delayToRefresh'] ?? 30000);
    return [
      'title'  => $first ?: null,
      'artist' => $second ?: null,
      'album'  => trim((string)($song['release']['title'] ?? '')) ?: null,
      'year'   => $song['year'] ?? null,
      'cover'  => $cover,
      'start'  => $now['startTime'] ?? null,
      'end'    => $now['endTime'] ?? null,
      'refresh'=> max(10, min(90, (int)round($delay / 1000))),
    ];
  }) ?? ['title' => null, 'artist' => null, 'cover' => null, 'refresh' => 45];
}

function band($name) {
  global $BANDS;
  if (!isset($BANDS[$name])) { http_response_code(400); return ['error' => 'unknown band']; }
  $b = $BANDS[$name];
  $list = cached('band-' . $name, 6 * 3600, function () use ($b) {
    $all = [];
    foreach ($b['searches'] as $s) {
      $s += ['hidebroken' => 'true', 'order' => 'clickcount', 'reverse' => 'true'];
      foreach (rb('/json/stations/search', $s) ?: [] as $st) $all[] = $st;
    }
    if (!$all) return null;
    return dedupe($all, $b['max']);
  });
  return ['stations' => $list ?? []];
}

function search() {
  $p = ['hidebroken' => 'true', 'order' => 'clickcount', 'reverse' => 'true', 'limit' => 60];
  $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
  $cc = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_GET['cc'] ?? '')));
  $tag = trim(mb_substr((string)($_GET['tag'] ?? ''), 0, 40));
  if ($q !== '') $p['name'] = $q;
  if (strlen($cc) === 2) $p['countrycode'] = $cc;
  if ($tag !== '') $p['tag'] = mb_strtolower($tag);
  if (count($p) === 4) { http_response_code(400); return ['error' => 'say what to look for']; }
  $key = 'search-' . md5(json_encode($p));
  $list = cached($key, 1800, function () use ($p) {
    $r = rb('/json/stations/search', $p);
    return $r === null ? null : dedupe($r, 60);
  });
  return ['stations' => $list ?? []];
}

function countries() {
  $list = cached('countries', 7 * 86400, function () {
    $r = rb('/json/countries', ['hidebroken' => 'true']);
    if ($r === null) return null;
    $out = [];
    foreach ($r as $c) {
      $code = strtoupper($c['iso_3166_1'] ?? '');
      $n = (int)($c['stationcount'] ?? 0);
      if (strlen($code) !== 2 || $n < 3) continue;
      $out[] = ['code' => $code, 'name' => $c['name'], 'count' => $n];
    }
    usort($out, fn($x, $y) => strcasecmp($x['name'], $y['name']));
    return $out;
  });
  return ['countries' => $list ?? []];
}

function click($id) {
  if (!valid_uuid($id)) { http_response_code(400); return ['error' => 'bad id']; }
  rb('/json/url/' . $id, []);
  return ['ok' => true];
}

/** Read the StreamTitle from a station's ICY metadata (first metadata block only). */
function icy_now($id) {
  if (!valid_uuid($id)) { http_response_code(400); return ['error' => 'bad id']; }
  $res = cached('icy-' . $id, 20, function () use ($id) {
    $st = cached('st-' . $id, 86400, function () use ($id) {
      $r = rb('/json/stations/byuuid/' . $id, []);
      return $r && isset($r[0]) ? ['url' => $r[0]['url_resolved'] ?: $r[0]['url'], 'hls' => (int)$r[0]['hls']] : null;
    });
    if (!$st || $st['hls'] || preg_match('/\.(m3u8|pls|m3u|asx)(\?|$)/i', $st['url'])) return ['title' => null];
    return ['title' => read_icy($st['url'])];
  });
  $t = $res['title'] ?? null;
  $artist = null;
  if ($t && strpos($t, ' - ') !== false) { [$artist, $t] = array_map('trim', explode(' - ', $t, 2)); }
  return ['title' => $t ?: null, 'artist' => $artist ?: null, 'refresh' => 30];
}

// ==========================================================================
// Helpers

function diag() {   // TEMPORARY: see what Radio France answers from this server
  $out = [];
  foreach (['https://www.radiofrance.fr/fip/api/live?webradio=fip_jazz', 'https://www.radiofrance.fr/fip/api/live?webradio=fip',
            'https://api.radiofrance.fr/livemeta/live/65/fip_extended', 'https://api.radiofrance.fr/livemeta/pull/65'] as $u) {
    $h = curl_init($u);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $b = curl_exec($h);
    $out[] = ['url' => $u, 'code' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'err' => curl_error($h), 'type' => curl_getinfo($h, CURLINFO_CONTENT_TYPE), 'body' => substr((string)$b, 0, 1500)];
    curl_close($h);
  }
  return $out;
}

function out($data, $maxAge) {
  header('Cache-Control: ' . ($maxAge > 0 ? "public, max-age=$maxAge" : 'no-store'));
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function slim($s) {
  $url = $s['url_resolved'] ?: $s['url'];
  return [
    'id'      => $s['stationuuid'],
    'name'    => trim(preg_replace('/\s+/', ' ', $s['name'])),
    'url'     => $url,
    'hls'     => (int)($s['hls'] ?? 0) || (bool)preg_match('/\.m3u8(\?|$)/i', $url),
    'favicon' => (str_starts_with((string)$s['favicon'], 'https://') ? $s['favicon'] : null),
    'country' => $s['country'] ?? '',
    'cc'      => strtoupper($s['countrycode'] ?? ''),
    'state'   => $s['state'] ?? '',
    'tags'    => implode(', ', array_slice(array_filter(array_map('trim', explode(',', (string)$s['tags']))), 0, 4)),
    'codec'   => $s['codec'] ?? '',
    'bitrate' => (int)($s['bitrate'] ?? 0),
    'homepage'=> $s['homepage'] ?? '',
    'votes'   => (int)($s['clickcount'] ?? 0),
  ];
}

/** Merge duplicates (same name), keep the most-played working copy; prefer https streams. */
function dedupe(array $all, $max) {
  $best = [];
  foreach ($all as $s) {
    if (empty($s['stationuuid']) || empty($s['name'])) continue;
    if (isset($s['lastcheckok']) && !$s['lastcheckok']) continue;
    $k = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $s['name']));
    $score = (int)$s['clickcount'] + (str_starts_with((string)($s['url_resolved'] ?: $s['url']), 'https://') ? 50 : 0);
    if (!isset($best[$k]) || $score > $best[$k][0]) $best[$k] = [$score, $s];
  }
  uasort($best, fn($x, $y) => $y[0] <=> $x[0]);
  return array_map('slim', array_slice(array_column(array_values($best), 1), 0, $max));
}

function rb($path, array $params) {
  $qs = $params ? '?' . http_build_query($params) : '';
  $hosts = RB_HOSTS;
  // spread load across mirrors, but stick to one per request
  $start = crc32($path . $qs) % count($hosts);
  for ($i = 0; $i < count($hosts); $i++) {
    $h = $hosts[($start + $i) % count($hosts)];
    $body = fetch("https://$h$path$qs", 7);
    if ($body !== null) {
      $j = json_decode($body, true);
      if (is_array($j)) return $j;
    }
  }
  return null;
}

function fetch($url, $timeout, array $headers = []) {
  if (function_exists('curl_init')) {
    $h = curl_init($url);
    curl_setopt_array($h, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => $headers,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($h);
    $code = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);
    return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
  }
  $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => UA, 'header' => implode("\r\n", $headers)]]);
  $body = @file_get_contents($url, false, $ctx);
  return $body === false ? null : $body;
}

/** Only talk to public internet addresses. */
function public_host($url) {
  $p = parse_url($url);
  if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
  $host = $p['host'];
  $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
  if (!$ips) return false;
  foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
  }
  return true;
}

function read_icy($url) {
  if (!function_exists('curl_init')) return null;
  for ($hop = 0; $hop < 3; $hop++) {
    if (!public_host($url)) return null;
    $metaint = 0; $buf = ''; $raw = ''; $location = null; $code = 0;
    $h = curl_init($url);
    if (defined('CURLOPT_HTTP09_ALLOWED')) curl_setopt($h, CURLOPT_HTTP09_ALLOWED, true);
    curl_setopt_array($h, [
      CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6,
      CURLOPT_USERAGENT => UA, CURLOPT_HTTPHEADER => ['Icy-MetaData: 1'],
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$metaint, &$location, &$code) {
        if (preg_match('#^(HTTP/\S+|ICY)\s+(\d{3})#i', $line, $m)) $code = (int)$m[2];
        if (preg_match('/^icy-metaint:\s*(\d+)/i', $line, $m)) $metaint = (int)$m[1];
        if (preg_match('/^location:\s*(\S+)/i', $line, $m)) $location = trim($m[1]);
        return strlen($line);
      },
      CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$metaint, &$raw) {
        $n = strlen($chunk);
        if (!$metaint) {
          // Old Shoutcast servers answer "ICY 200 OK", which curl passes through as body: read the headers ourselves.
          $raw .= $chunk;
          if (strncmp($raw, 'ICY', 3) !== 0 || strlen($raw) > 16384) return -1;   // no metadata on offer: stop
          $end = strpos($raw, "\r\n\r\n");
          if ($end === false) return $n;
          if (!preg_match('/^icy-metaint:\s*(\d+)/im', substr($raw, 0, $end), $m)) return -1;
          $metaint = (int)$m[1];
          $chunk = substr($raw, $end + 4); $raw = '';
          $buf .= $chunk;
          if (strlen($buf) > $metaint && strlen($buf) >= $metaint + 1 + ord($buf[$metaint]) * 16) return -1;
          return $n;
        }
        $buf .= $chunk;
        if (strlen($buf) > $metaint) {
          $len = ord($buf[$metaint]) * 16;
          if (strlen($buf) >= $metaint + 1 + $len) return -1;   // got the whole block
        }
        return strlen($buf) > 512 * 1024 ? -1 : $n;
      },
    ]);
    curl_exec($h);
    curl_close($h);
    if ($code >= 300 && $code < 400 && $location) { $url = $location; continue; }
    if (!$metaint || strlen($buf) <= $metaint) return null;
    $len = ord($buf[$metaint]) * 16;
    $meta = substr($buf, $metaint + 1, $len);
    if (!preg_match("/StreamTitle='(.*?)';/s", $meta, $m)) return null;
    $t = trim($m[1]);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
    return ($t === '' || $t === '-' ) ? null : mb_substr($t, 0, 200);
  }
  return null;
}

function find_img($v) {
  if (is_string($v)) return preg_match('#^https://\S+\.(jpe?g|png|webp)(\?\S*)?$#i', $v) ? $v : null;
  if (!is_array($v)) return null;
  foreach (['card', 'src', 'url', 'cover', 'player', 'square', 'visual'] as $k) if (isset($v[$k]) && ($r = find_img($v[$k]))) return $r;
  foreach ($v as $x) if (is_array($x) && ($r = find_img($x))) return $r;
  return null;
}

function valid_uuid($id) { return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id); }

/** Disk cache: fresh copy if young enough, else refetch; on failure serve stale. */
function cached($key, $ttl, callable $make) {
  $dir = cache_dir();
  $file = $dir ? $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json' : null;
  if ($file && is_file($file) && time() - filemtime($file) < $ttl) {
    $v = json_decode((string)file_get_contents($file), true);
    if ($v !== null) return $v;
  }
  $v = $make();
  if ($v !== null) { if ($file) @file_put_contents($file, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); return $v; }
  if ($file && is_file($file)) return json_decode((string)file_get_contents($file), true);
  return null;
}

function cache_dir() {
  static $d = false;
  if ($d !== false) return $d;
  foreach ([__DIR__ . '/cache', sys_get_temp_dir() . '/organik-tuner-cache'] as $c) {
    if (!is_dir($c)) @mkdir($c, 0755, true);
    if (is_dir($c) && is_writable($c)) {
      if (strpos($c, __DIR__) === 0 && !is_file($c . '/.htaccess')) @file_put_contents($c . '/.htaccess', "Require all denied\nDeny from all\n");
      return $d = $c;
    }
  }
  return $d = null;
}
