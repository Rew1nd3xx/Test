<?php
/**
 * DApp — Benachrichtigungen pruefen und verschicken
 * Per Cronjob alle paar Minuten aufrufen lassen (z. B. alle 5 Minuten).
 * Vergleicht den aktuellen Familien-Stand mit dem letzten Durchlauf und
 * schickt bei Neuem eine (leere) Push-Nachricht — der Inhalt liegt bereit
 * unter sync-data/pending/{deviceId}.json und wird von push-fetch.php
 * ausgeliefert, sobald der Service Worker aufwacht.
 *
 * Voraussetzung: PHP mit der openssl-Erweiterung (auf so gut wie jedem
 * Hosting bereits vorhanden).
 */

// --- Eigene VAPID-Schlüssel (zusammengehöriges Paar mit dem Public Key in der App) ---
define('VAPID_PRIVATE_KEY_PEM', "-----BEGIN EC PRIVATE KEY-----\nMHcCAQEEILaveDij/JJLPDI7aEn02ZZBIepf+1tIVQdcpbYwVOq9oAoGCCqGSM49\nAwEHoUQDQgAEfoP0ueCylVfXpRC8kEwm5QpaRrXnhiSK7WBysw5dR96KgwAbVnm3\nZY9y/cPGScyOtQKNHGVecw2XU+mRlon81A==\n-----END EC PRIVATE KEY-----\n");
define('VAPID_PUBLIC_KEY', 'BH6D9LngspVX16UQvJBMJuUKWka154Ykiu1gcrMOXUfeioMAG1Z5t2WPcv3DxknMjrUCjRxlXnMNl1PpkZaJ_NQ');
define('VAPID_SUBJECT', 'mailto:familie@example.com'); // gerne durch eure echte Adresse ersetzen

$dataDir = __DIR__ . '/sync-data';
$pendingDir = $dataDir . '/pending';
if (!is_dir($pendingDir)) mkdir($pendingDir, 0755, true);

$syncFile = $dataDir . '/data.json';           // dieselbe Datei, die sync.php schreibt
$subsFile = $dataDir . '/push-subscriptions.json';
$lastFile = $dataDir . '/push-last-state.json';

if (!file_exists($syncFile) || !file_exists($subsFile)) exit("Nichts zu tun (noch keine Daten/Abos).\n");

$state = json_decode(file_get_contents($syncFile), true);
$subs = json_decode(file_get_contents($subsFile), true);
$last = file_exists($lastFile) ? json_decode(file_get_contents($lastFile), true) : [];
if (!is_array($state)) exit("Sync-Daten ungültig.\n");
if (!is_array($subs)) $subs = [];
if (!is_array($last)) $last = [];

$lastTaskIds     = $last['taskIds'] ?? [];
$lastApprovalIds = $last['approvalIds'] ?? [];
$lastMealDates   = $last['mealDates'] ?? [];
$lastWarningIds  = $last['warningIds'] ?? [];

// ---------- 1) Neue/zugewiesene Aufgaben & Termine ----------
$newTaskNotifications = []; // [ text ]
$currentTaskIds = [];
foreach (($state['tasks'] ?? []) as $t) {
  $currentTaskIds[] = $t['id'];
  if (($t['approved'] ?? true) !== false && !in_array($t['id'], $lastTaskIds, true)) {
    $newTaskNotifications[] = ($t['category'] ?? '') === 'homework'
      ? "📚 Neue Hausaufgabe: " . $t['title']
      : "📋 Neue Aufgabe: " . $t['title'];
  }
}
$currentEventIds = [];
foreach (($state['events'] ?? []) as $ev) {
  $currentEventIds[] = $ev['id'];
  if (!in_array($ev['id'], $lastTaskIds, true) && !($ev['private'] ?? false)) {
    $newTaskNotifications[] = "📅 Neuer Termin: " . $ev['title'] . " (" . $ev['date'] . ")";
  }
}

// ---------- 2) Essensplan wartet auf Abstimmung/Freigabe ----------
$mealNotifications = [];
$currentMealDates = [];
foreach (($state['mealPlan'] ?? []) as $dateStr => $entry) {
  if (!is_array($entry)) continue;
  $options = $entry['options'] ?? [];
  if (count($options) && empty($entry['confirmed']) && !in_array($dateStr, $lastMealDates, true)) {
    $currentMealDates[] = $dateStr;
    $mealNotifications[] = "🍽️ Essensplan wartet auf Abstimmung für " . $dateStr;
  } elseif (count($options)) {
    $currentMealDates[] = $dateStr;
  }
}

// ---------- 3) Freigabe-Anfragen von Kindern ----------
$approvalNotifications = [];
$currentApprovalIds = [];
foreach (($state['tasks'] ?? []) as $t) {
  if (($t['approved'] ?? true) === false) {
    $currentApprovalIds[] = $t['id'];
    if (!in_array($t['id'], $lastApprovalIds, true)) {
      $approvalNotifications[] = "✅ Freigabe nötig: Aufgabe „" . $t['title'] . "\"";
    }
  }
}
foreach (($state['rewards'] ?? []) as $r) {
  if (($r['approved'] ?? true) === false) {
    $currentApprovalIds[] = $r['id'];
    if (!in_array($r['id'], $lastApprovalIds, true)) {
      $approvalNotifications[] = "✅ Freigabe nötig: Belohnung „" . $r['title'] . "\"";
    }
  }
}

// ---------- 4) Wetterwarnungen (direkter Serverzugriff, kein CORS-Problem) ----------
$weatherNotifications = [];
$currentWarningIds = [];
$federalStateCode = $state['federalState'] ?? '';
$stateNames = [
  'BW'=>'Baden-Württemberg','BY'=>'Bayern','BE'=>'Berlin','BB'=>'Brandenburg','HB'=>'Bremen',
  'HH'=>'Hamburg','HE'=>'Hessen','MV'=>'Mecklenburg-Vorpommern','NI'=>'Niedersachsen',
  'NW'=>'Nordrhein-Westfalen','RP'=>'Rheinland-Pfalz','SL'=>'Saarland','SN'=>'Sachsen',
  'ST'=>'Sachsen-Anhalt','SH'=>'Schleswig-Holstein','TH'=>'Thüringen',
];
$stateName = $stateNames[$federalStateCode] ?? null;
$warnRegionFilter = mb_strtolower(trim($state['warnRegionFilter'] ?? ''));
if ($stateName) {
  $ctx = stream_context_create(['http' => ['timeout' => 8]]);
  $raw = @file_get_contents('https://www.dwd.de/DWD/warnungen/warnapp/json/warnings.json', false, $ctx);
  if ($raw) {
    $json = preg_replace('/^\s*warnWetter\.loadWarnings\(/', '', $raw);
    $json = preg_replace('/\);\s*$/', '', $json);
    $parsed = json_decode($json, true);
    if (is_array($parsed) && isset($parsed['warnings'])) {
      foreach ($parsed['warnings'] as $cellWarnings) {
        foreach ($cellWarnings as $w) {
          $regionMatches = $warnRegionFilter === '' || strpos(mb_strtolower($w['regionName'] ?? ''), $warnRegionFilter) !== false;
          if (($w['state'] ?? '') === $stateName && $regionMatches && ($w['level'] ?? 0) >= 3) {
            $wid = md5(($w['headline'] ?? '') . ($w['regionName'] ?? '') . ($w['start'] ?? ''));
            $currentWarningIds[] = $wid;
            if (!in_array($wid, $lastWarningIds, true)) {
              $weatherNotifications[] = "⚠️ " . preg_replace('/^Amtliche /', '', $w['headline'] ?? 'Unwetterwarnung') . " — " . ($w['regionName'] ?? '');
            }
          }
        }
      }
    }
  }
}

// ---------- Verteilen: für jedes abonnierte Gerät passende Meldungen einsammeln ----------
foreach ($subs as $deviceId => $sub) {
  $cats = $sub['categories'] ?? [];
  $toSend = [];
  if (!empty($cats['tasks'])) foreach ($newTaskNotifications as $t) $toSend[] = $t;
  if (!empty($cats['meals'])) foreach ($mealNotifications as $t) $toSend[] = $t;
  if (!empty($cats['approvals'])) foreach ($approvalNotifications as $t) $toSend[] = $t;
  if (!empty($cats['weather'])) foreach ($weatherNotifications as $t) $toSend[] = $t;

  if (!$toSend) continue;

  $pendingFile = $pendingDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $deviceId) . '.json';
  $existing = file_exists($pendingFile) ? json_decode(file_get_contents($pendingFile), true) : [];
  if (!is_array($existing)) $existing = [];
  foreach ($toSend as $text) $existing[] = ['title' => 'DApp', 'body' => $text, 'url' => './'];
  file_put_contents($pendingFile, json_encode($existing));

  sendWebPush($sub['subscription']);
}

// ---------- Letzten Stand merken, damit nichts doppelt gemeldet wird ----------
file_put_contents($lastFile, json_encode([
  'taskIds'     => array_merge($currentTaskIds, $currentEventIds),
  'approvalIds' => $currentApprovalIds,
  'mealDates'   => $currentMealDates,
  'warningIds'  => $currentWarningIds,
]));

echo "Fertig.\n";

// ==================== Hilfsfunktionen ====================

function base64UrlEncode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function derToRawSignature($der) {
  $offset = 2; // SEQUENCE-Tag + Länge (P-256-Signaturen sind immer < 128 Byte lang)
  $offset++; // INTEGER-Tag von r
  $rLen = ord($der[$offset]); $offset++;
  $r = substr($der, $offset, $rLen); $offset += $rLen;
  $offset++; // INTEGER-Tag von s
  $sLen = ord($der[$offset]); $offset++;
  $s = substr($der, $offset, $sLen);
  $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
  $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
  $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
  return $r . $s;
}

function vapidAuthHeader($endpoint) {
  $parts = parse_url($endpoint);
  $audience = $parts['scheme'] . '://' . $parts['host'];
  $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $claims = base64UrlEncode(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => VAPID_SUBJECT]));
  $signingInput = $header . '.' . $claims;
  $pkey = openssl_pkey_get_private(VAPID_PRIVATE_KEY_PEM);
  openssl_sign($signingInput, $derSig, $pkey, OPENSSL_ALGO_SHA256);
  $jwt = $signingInput . '.' . base64UrlEncode(derToRawSignature($derSig));
  return 'vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY;
}

function sendWebPush($subscription) {
  if (empty($subscription['endpoint'])) return;
  $endpoint = $subscription['endpoint'];
  $auth = vapidAuthHeader($endpoint);
  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, '');
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'TTL: 60',
    'Authorization: ' . $auth,
    'Content-Length: 0',
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_exec($ch);
  curl_close($ch);
}
