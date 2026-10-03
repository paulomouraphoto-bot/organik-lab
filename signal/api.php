<?php
/**
 * Signal Mapper — storage for lab.organikreations.com/signal/
 *
 *   GET  api.php?a=load&code=FIG-OTTER-PLUM-274        places and readings saved under a code
 *   POST api.php?a=sync   {code, add:[readings], del:[ids], places:[places], delPlaces:[ids]}
 *
 * A code is the only key: whoever knows it can read and add to that map. Codes are random
 * (three words + three digits), stored only as a hash, and each map lives in its own JSON file.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const MAX_READINGS = 5000;
const MAX_PLACES = 100;

function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

function data_dir() {
  // prefer a folder outside the website; fall back to a locked folder next to this script
  $outside = dirname(__DIR__, 2) . '/organik-lab-data/signal';
  if (is_dir($outside) || (is_writable(dirname(__DIR__, 2)) && @mkdir($outside, 0700, true))) return $outside;
  $inside = __DIR__ . '/data';
  if (!is_dir($inside)) {
    @mkdir($inside, 0700, true);
    @file_put_contents($inside . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents($inside . '/index.html', '');
  }
  return $inside;
}

function check_code($code) {
  $code = strtoupper(trim((string)$code));
  if (!preg_match('/^[A-Z]{2,10}-[A-Z]{2,10}-[A-Z]{2,10}-\d{3}$/', $code)) out(['error' => 'bad code'], 400);
  return $code;
}

function file_for($code) { return data_dir() . '/' . hash('sha256', 'organik-signal|' . $code) . '.json'; }

function str($v, $max) { $v = trim((string)$v); return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max); }
function num($v, $min, $max) { if ($v === null || $v === '' || !is_numeric($v)) return null; $v = +$v; return ($v < $min || $v > $max) ? null : $v; }
function id_ok($v) { return is_string($v) && preg_match('/^[a-z0-9]{6,32}$/', $v); }

function clean_reading($r) {
  if (!is_array($r) || !id_ok($r['id'] ?? null)) return null;
  return [
    'id'    => $r['id'],
    'place' => id_ok($r['place'] ?? null) ? $r['place'] : null,
    't'     => (int) num($r['t'] ?? 0, 1.5e12, 4e12),
    'label' => str($r['label'] ?? '', 60),
    'lat'   => num($r['lat'] ?? null, -90, 90),
    'lon'   => num($r['lon'] ?? null, -180, 180),
    'acc'   => num($r['acc'] ?? null, 0, 100000),
    'ms'    => num($r['ms'] ?? null, 0, 60000),
    'mbps'  => num($r['mbps'] ?? 0, 0, 100000) ?? 0,
    'loss'  => num($r['loss'] ?? 0, 0, 1) ?? 0,
    'dead'  => !empty($r['dead']),
    'net'   => ($n = str($r['net'] ?? '', 16)) === '' ? null : $n,
    'score' => (int) (num($r['score'] ?? 0, 0, 99) ?? 0),
  ];
}
function clean_place($p) {
  if (!is_array($p) || !id_ok($p['id'] ?? null)) return null;
  return [
    'id'   => $p['id'],
    'name' => str($p['name'] ?? 'Place', 40) ?: 'Place',
    'lat'  => num($p['lat'] ?? null, -90, 90),
    'lon'  => num($p['lon'] ?? null, -180, 180),
    't'    => (int) num($p['t'] ?? 0, 1.5e12, 4e12),
  ];
}

$a = $_GET['a'] ?? '';

if ($a === 'load') {
  $f = file_for(check_code($_GET['code'] ?? ''));
  if (!is_file($f)) out(['places' => [], 'readings' => []]);
  $d = json_decode(@file_get_contents($f), true) ?: [];
  out(['places' => $d['places'] ?? [], 'readings' => $d['readings'] ?? []]);
}

if ($a === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input', false, null, 0, 2000000);
  $in = json_decode($raw, true);
  if (!is_array($in)) out(['error' => 'bad body'], 400);
  $f = file_for(check_code($in['code'] ?? ''));

  $h = fopen($f, 'c+');
  if (!$h || !flock($h, LOCK_EX)) out(['error' => 'storage unavailable'], 503);
  $d = json_decode(stream_get_contents($h), true) ?: ['places' => [], 'readings' => []];

  // readings: keyed by id so the same reading sent twice is stored once
  $R = [];
  foreach ($d['readings'] ?? [] as $r) $R[$r['id']] = $r;
  foreach ((array)($in['add'] ?? []) as $r) if ($c = clean_reading($r)) $R[$c['id']] = $c;
  foreach ((array)($in['del'] ?? []) as $id) if (is_string($id)) unset($R[$id]);

  $P = [];
  foreach ($d['places'] ?? [] as $p) $P[$p['id']] = $p;
  foreach ((array)($in['places'] ?? []) as $p) if ($c = clean_place($p)) $P[$c['id']] = $c;
  foreach ((array)($in['delPlaces'] ?? []) as $id) if (is_string($id)) {
    unset($P[$id]);
    foreach ($R as $k => $r) if (($r['place'] ?? null) === $id) unset($R[$k]);
  }

  // keep the newest if a map ever grows past the limits
  $R = array_values($R); usort($R, function ($x, $y) { return $y['t'] <=> $x['t']; }); $R = array_slice($R, 0, MAX_READINGS);
  $P = array_slice(array_values($P), 0, MAX_PLACES);

  $d = ['places' => $P, 'readings' => $R, 'updated' => time()];
  ftruncate($h, 0); rewind($h);
  fwrite($h, json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($h); flock($h, LOCK_UN); fclose($h);
  @chmod($f, 0600);
  out(['places' => $P, 'readings' => $R]);
}

out(['error' => 'unknown action'], 400);
