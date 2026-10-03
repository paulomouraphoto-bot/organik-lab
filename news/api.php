<?php
/**
 * Daily Deck — news feed endpoint for lab.organikreations.com/news/
 *
 * GET api.php?groups=world,tech,good
 * Returns JSON: { items: [...], groups: {...}, failed: [...], generated: ISO date }
 *
 * Only the feeds listed below can ever be fetched (no open proxy).
 * Each feed is cached on disk for CACHE_TTL seconds; a stale copy is served if a feed is down.
 */

const CACHE_TTL   = 1200;   // 20 minutes
const FETCH_LIMIT = 8;      // seconds per feed
const PER_FEED    = 25;     // newest items kept per feed
const SUMMARY_LEN = 420;

// group => [ [source name, feed url, options], ... ]
// options: noimg  = ignore the feed's images (too small / logos)
//          filter = keep only items whose title, summary or categories match this regex
//          bright = drop items that look like bad news (for the uplifting groups)
$FEEDS = [
  'world' => [
    ['BBC News',       'https://feeds.bbci.co.uk/news/world/rss.xml'],
    ['The Guardian',   'https://www.theguardian.com/world/rss'],
    ['Al Jazeera',     'https://www.aljazeera.com/xml/rss/all.xml'],
    ['NPR',            'https://feeds.npr.org/1004/rss.xml'],
  ],
  'tech' => [
    ['BBC News',       'https://feeds.bbci.co.uk/news/technology/rss.xml'],
    ['The Guardian',   'https://www.theguardian.com/technology/rss'],
    ['Ars Technica',   'https://feeds.arstechnica.com/arstechnica/index'],
    ['The Verge',      'https://www.theverge.com/rss/index.xml'],
  ],
  'science' => [
    ['BBC News',       'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml'],
    ['The Guardian',   'https://www.theguardian.com/science/rss'],
    ['ScienceDaily',   'https://www.sciencedaily.com/rss/top/science.xml'],
  ],
  'business' => [
    ['BBC News',       'https://feeds.bbci.co.uk/news/business/rss.xml'],
    ['The Guardian',   'https://www.theguardian.com/uk/business/rss'],
    ['NPR',            'https://feeds.npr.org/1006/rss.xml'],
  ],
  'health' => [
    ['BBC News',       'https://feeds.bbci.co.uk/news/health/rss.xml'],
    ['NPR',            'https://feeds.npr.org/1128/rss.xml'],
    ['ScienceDaily',   'https://www.sciencedaily.com/rss/top/health.xml'],
  ],
  'culture' => [
    ['BBC News',       'https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml'],
    ['The Guardian',   'https://www.theguardian.com/uk/culture/rss'],
    ['NPR',            'https://feeds.npr.org/1008/rss.xml'],
  ],
  'sport' => [
    ['BBC Sport',      'https://feeds.bbci.co.uk/sport/rss.xml'],
    ['The Guardian',   'https://www.theguardian.com/uk/sport/rss'],
  ],
  'environment' => [
    ['The Guardian',   'https://www.theguardian.com/uk/environment/rss'],
    ['Anthropocene',   'https://www.anthropocenemagazine.org/feed/'],
    ['Mongabay',       'https://news.mongabay.com/feed/'],
  ],

  // --- the bright side -------------------------------------------------
  'good' => [
    ['Good News Network',    'https://www.goodnewsnetwork.org/feed/', ['bright' => true]],
    ['Positive News',        'https://www.positive.news/feed/',       ['bright' => true]],
    ['Reasons to be Cheerful','https://reasonstobecheerful.world/feed/', ['bright' => true]],
    ['The Optimist Daily',   'https://www.optimistdaily.com/feed/',   ['bright' => true, 'skip' => '/podcast transcript/i']],
  ],
  'wonders' => [
    ['ScienceDaily',   'https://www.sciencedaily.com/rss/top/science.xml', ['bright' => true]],
    ['Phys.org',       'https://phys.org/rss-feed/breaking/',             ['bright' => true, 'noimg' => true]],
    ['NASA',           'https://www.nasa.gov/news-release/feed/',         ['bright' => true]],
    ['Atlas Obscura',  'https://www.atlasobscura.com/feeds/latest',       ['bright' => true]],
  ],
  'green' => [
    ['Good News Network', 'https://www.goodnewsnetwork.org/category/news/earth/feed/', ['bright' => true]],
    ['Anthropocene',      'https://www.anthropocenemagazine.org/feed/',  ['bright' => true]],
    ['Positive News',     'https://www.positive.news/feed/', ['bright' => true,
        'filter' => '/environment|conservation|climate|renewable|wildlife|nature|ocean|marine|rewild|forest|energy|solar|wind|biodiversity|species|electric vehicle/i']],
    ['Reasons to be Cheerful', 'https://reasonstobecheerful.world/feed/', ['bright' => true,
        'filter' => '/climate|environment|nature|wildlife|energy|solar|green|forest|river|ocean|landscape|seed|garden|planet|species|pollution|recycl/i']],
    ['Electrek',          'https://electrek.co/feed/', ['bright' => true,
        'filter' => '/solar|wind|battery|grid|renewable|clean|record|emission|install|storage|e-bike|charging/i',
        'skip'   => '/deal|discount|\boff\b|sale|price cut|lease|coupon|best .* to buy/i']],
  ],
];

// Words that usually mean "not a happy story" — used for the bright groups only.
const GLOOM = '/\b(kill(?:s|ed|ing)?|dead|deaths?|dies|died|murder\w*|war|wars|attack\w*|shoot\w*|bomb\w*|terror\w*|crash\w*|disaster|tragedy|abuse\w*|assault\w*|hostage|famine|massacre|lawsuit|sued|arrest\w*|convicted|fraud|scandal|collapse\w*|layoffs?|recession|threat\w*|warns?|crisis|extinct(?:ion)?|devastat\w*|catastroph\w*|wildfire|drought|flood(?:s|ing)?|outbreak|pandemic|epidemic|cancer risk|tariffs?)\b/i';

// --------------------------------------------------------------------------

if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; } }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$asked = array_filter(array_map('trim', explode(',', strtolower($_GET['groups'] ?? 'world'))));
$asked = array_values(array_unique(array_intersect($asked, array_keys($FEEDS))));
if (!$asked) { http_response_code(400); echo json_encode(['error' => 'unknown groups', 'groups' => array_keys($FEEDS)]); exit; }

$cacheDir = cache_dir();

// Work out which feed urls are needed and which are stale
$jobs = [];   // url => [group, name, opts]
foreach ($asked as $g) {
  foreach ($FEEDS[$g] as $f) $jobs[$f[1] . '#' . $g] = ['group' => $g, 'name' => $f[0], 'url' => $f[1], 'opts' => $f[2] ?? []];
}

$raw = [];     // url => xml string
$need = [];
foreach ($jobs as $job) {
  $u = $job['url'];
  if (isset($raw[$u]) || isset($need[$u])) continue;
  $file = $cacheDir ? $cacheDir . '/' . md5($u) . '.xml' : null;
  if ($file && is_file($file) && time() - filemtime($file) < CACHE_TTL) $raw[$u] = file_get_contents($file);
  elseif ($file && is_file($file . '.fail') && time() - filemtime($file . '.fail') < 600) {
    if (is_file($file)) $raw[$u] = file_get_contents($file);   // feed was down a moment ago: don't wait on it again
  }
  else $need[$u] = $file;
}

// Fetch stale feeds in parallel
if ($need) {
  $fresh = fetch_all(array_keys($need));
  foreach ($need as $u => $file) {
    $body = $fresh[$u] ?? null;
    if ($body && looks_like_feed($body)) {
      $raw[$u] = $body;
      if ($file) { @file_put_contents($file, $body, LOCK_EX); @unlink($file . '.fail'); }
    } else {
      if ($file) @touch($file . '.fail');
      if ($file && is_file($file)) $raw[$u] = file_get_contents($file);   // stale but better than nothing
    }
  }
}

$items = []; $seen = []; $failed = []; $counts = array_fill_keys($asked, 0);
foreach ($jobs as $job) {
  $xml = $raw[$job['url']] ?? null;
  if (!$xml) { $failed[] = $job['name'] . ' (' . $job['group'] . ')'; continue; }
  foreach (parse_feed($xml) as $it) {
    $o = $job['opts'];
    $hay = $it['title'] . ' ' . $it['summary'] . ' ' . implode(' ', $it['tags']);
    if (!empty($o['filter']) && !preg_match($o['filter'], $hay)) continue;
    if (!empty($o['skip'])   &&  preg_match($o['skip'], $it['title'])) continue;
    if (!empty($o['bright']) &&  preg_match(GLOOM, $it['title'] . ' ' . $it['summary'])) continue;
    if (!empty($o['noimg'])) $it['image'] = null;

    $key = norm_key($it['link'], $it['title']);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $it['id'] = substr(md5($key), 0, 12);
    $it['source'] = $job['name'];
    $it['group'] = $job['group'];
    $items[] = $it;
    $counts[$job['group']]++;
  }
}

usort($items, fn($a, $b) => $b['ts'] <=> $a['ts']);

echo json_encode([
  'items' => $items,
  'groups' => $counts,
  'failed' => array_values(array_unique($failed)),
  'generated' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);


// ==========================================================================

function cache_dir() {
  foreach ([__DIR__ . '/cache', sys_get_temp_dir() . '/daily-deck-cache'] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && is_writable($d)) {
      if (strpos($d, __DIR__) === 0 && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
      return $d;
    }
  }
  return null;
}

function fetch_all(array $urls) {
  $out = [];
  if (!function_exists('curl_multi_init')) {
    $ctx = stream_context_create(['http' => ['timeout' => FETCH_LIMIT, 'user_agent' => ua(), 'follow_location' => 1]]);
    foreach ($urls as $u) $out[$u] = @file_get_contents($u, false, $ctx) ?: null;
    return $out;
  }
  $mh = curl_multi_init(); $hs = [];
  foreach ($urls as $u) {
    $h = curl_init($u);
    curl_setopt_array($h, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
      CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => FETCH_LIMIT, CURLOPT_ENCODING => '',
      CURLOPT_USERAGENT => ua(),
      CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5'],
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    curl_multi_add_handle($mh, $h); $hs[$u] = $h;
  }
  do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st == CURLM_OK);
  foreach ($hs as $u => $h) {
    $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
    $out[$u] = ($code >= 200 && $code < 300) ? curl_multi_getcontent($h) : null;
    curl_multi_remove_handle($mh, $h); curl_close($h);
  }
  curl_multi_close($mh);
  return $out;
}

function ua() { return 'Mozilla/5.0 (compatible; DailyDeck/1.0; +https://lab.organikreations.com/news/)'; }

function looks_like_feed($s) { return (bool)preg_match('/<(rss|feed|rdf:RDF)[\s>]/i', substr($s, 0, 4000)); }

function parse_feed($xmlString) {
  $prev = libxml_use_internal_errors(true);
  $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
  libxml_clear_errors(); libxml_use_internal_errors($prev);
  if (!$xml) return [];

  $entries = [];
  if (isset($xml->channel->item)) $entries = $xml->channel->item;          // RSS 2
  elseif (isset($xml->item)) $entries = $xml->item;                        // RSS 1 / RDF
  elseif (isset($xml->entry)) $entries = $xml->entry;                      // Atom

  $out = []; $n = 0;
  foreach ($entries as $e) {
    if ($n++ >= PER_FEED) break;
    $ns = $e->getNamespaces(true);
    $media   = isset($ns['media'])   ? $e->children($ns['media'])   : null;
    $content = isset($ns['content']) ? $e->children($ns['content']) : null;
    $dc      = isset($ns['dc'])      ? $e->children($ns['dc'])      : null;

    $title = clean_text((string)$e->title);
    if ($title === '') continue;

    // link (RSS <link>text</link> or Atom <link href rel=alternate>)
    $link = trim((string)$e->link);
    if ($link === '' && isset($e->link)) {
      foreach ($e->link as $l) { $rel = (string)$l['rel']; if ($rel === '' || $rel === 'alternate') { $link = (string)$l['href']; break; } }
    }
    if ($link === '' && isset($e->guid) && preg_match('#^https?://#', (string)$e->guid)) $link = (string)$e->guid;
    if (!preg_match('#^https?://#i', $link)) continue;

    $html = (string)($e->description ?? '');
    if ($html === '' && isset($e->summary)) $html = (string)$e->summary;
    $full = $content && isset($content->encoded) ? (string)$content->encoded : (isset($e->content) ? (string)$e->content : '');
    $summary = clean_text($html !== '' ? $html : $full);
    if (mb_strlen($summary) < 80 && $full !== '') $summary = clean_text($full);
    $summary = preg_replace('/\s*(The post .* appeared first on .*|Continue reading\.*|Read more\.*|\[…\]|\[\.\.\.\])\s*$/u', '', $summary);
    if (mb_strlen($summary) > SUMMARY_LEN) $summary = rtrim(mb_substr($summary, 0, SUMMARY_LEN), " ,.;:-") . '…';
    if (strcasecmp($summary, $title) === 0) $summary = '';

    $date = (string)($e->pubDate ?? '') ?: (string)($e->published ?? '') ?: (string)($e->updated ?? '') ?: ($dc ? (string)$dc->date : '');
    $ts = $date ? strtotime($date) : false;
    if (!$ts || $ts > time() + 3600) $ts = time() - 86400;

    $tags = [];
    foreach ($e->category as $c) { $t = trim((string)$c) ?: trim((string)$c['term']); if ($t !== '' && mb_strlen($t) < 40) $tags[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    $tags = array_slice(array_values(array_unique($tags)), 0, 6);

    $out[] = [
      'title' => $title, 'summary' => $summary, 'link' => $link,
      'image' => pick_image($e, $media, $html . ' ' . $full),
      'ts' => $ts, 'date' => gmdate('c', $ts), 'tags' => $tags,
    ];
  }
  return $out;
}

function pick_image($e, $media, $html) {
  $best = null; $bestW = -1;
  $consider = function ($url, $w = 0) use (&$best, &$bestW) {
    $url = trim((string)$url);
    if (!preg_match('#^https?://#i', $url) || preg_match('/\.(mp3|mp4|m4a|pdf)(\?|$)/i', $url)) return;
    if (preg_match('/(gravatar|feedburner|pixel|tracking|1x1|spacer|logo|avatar)/i', $url)) return;
    $w = (int)$w ?: 300;
    if ($w > $bestW) { $best = $url; $bestW = $w; }
  };
  if ($media) {
    foreach ($media->content as $m) { $t = (string)$m['type']; $med = (string)$m['medium']; if ($t === '' || str_starts_with($t, 'image') || $med === 'image') $consider($m['url'], $m['width']); }
    foreach ($media->thumbnail as $m) $consider($m['url'], $m['width'] ?: 200);
    if (isset($media->group)) foreach ($media->group->content as $m) $consider($m['url'], $m['width']);
  }
  foreach ($e->enclosure as $enc) if (str_starts_with((string)$enc['type'], 'image')) $consider($enc['url'], 600);
  if (!$best && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) $consider(html_entity_decode($m[1]), 400);
  if (!$best) return null;
  // BBC thumbnails are 240px — ask for a larger rendition
  $best = preg_replace('#(ichef\.bbci\.co\.uk/(?:ace/standard|news))/\d{2,4}/#', '$1/800/', $best);
  return str_replace('http://', 'https://', $best);
}

function clean_text($s) {
  $s = preg_replace('#<(script|style|figure|figcaption)[^>]*>.*?</\1>#is', ' ', $s);
  $s = preg_replace('#<(br|/p|/div|/li)\s*/?>#i', ' ', $s);
  $s = strip_tags($s);
  $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function norm_key($link, $title) {
  $p = parse_url($link);
  $k = strtolower(($p['host'] ?? '') . rtrim($p['path'] ?? '', '/'));
  return $k !== '' ? $k : strtolower($title);
}
