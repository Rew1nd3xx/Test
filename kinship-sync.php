<?php
/**
 * DApp — Verwandtschaftskreis-Sync
 * Eigener, unabhaengiger Datentopf getrennt vom normalen Familien-Sync (sync.php).
 * Mehrere Familien teilen sich einen Kreis (circleId) und pushen/pullen hierueber
 * ausgewaehlte Termine, News und Essensplan-Vorschlaege. Der Server merged eingehende
 * Pushes serverseitig (mehrere Familien schreiben gleichzeitig), damit niemand die
 * Beitraege der anderen ueberschreibt.
 *
 * In denselben Ordner wie sync.php hochladen.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header("Content-Type: application/json");

$dataDir = __DIR__ . '/kinship-data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

$circleId = $_GET['circle'] ?? ($_POST['circle'] ?? null);
if (isset($_GET['circle'])) $circleId = $_GET['circle'];

function emptyCircle() {
  return ['events' => [], 'news' => [], 'meals' => [], 'tombstones' => (object)[]];
}

function mergeByIdNewest($local, $incoming, $tombstones) {
  $map = [];
  foreach ($local as $item) { $map[$item['id']] = $item; }
  foreach ($incoming as $item) {
    if (!isset($item['id']) || isset($tombstones[$item['id']])) continue;
    if (!isset($map[$item['id']])) { $map[$item['id']] = $item; continue; }
    $locT = $map[$item['id']]['updatedAt'] ?? 0;
    $remT = $item['updatedAt'] ?? 0;
    if ($remT > $locT) $map[$item['id']] = $item;
  }
  return array_values($map);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (!$circleId) { http_response_code(400); echo json_encode(['error' => 'circle fehlt']); exit; }
  $file = $dataDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $circleId) . '.json';
  if (!file_exists($file)) { echo json_encode(emptyCircle()); exit; }
  echo file_get_contents($file);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $input = json_decode(file_get_contents('php://input'), true);
  if (!$input || empty($input['circle'])) { http_response_code(400); echo json_encode(['error' => 'Ungueltige Anfrage']); exit; }
  $circleId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['circle']);
  $file = $dataDir . '/' . $circleId . '.json';

  $current = file_exists($file) ? json_decode(file_get_contents($file), true) : emptyCircle();
  if (!is_array($current)) $current = emptyCircle();
  $current['events'] = $current['events'] ?? [];
  $current['news'] = $current['news'] ?? [];
  $current['meals'] = $current['meals'] ?? [];
  $current['tombstones'] = $current['tombstones'] ?? [];
  if (is_object($current['tombstones'])) $current['tombstones'] = (array)$current['tombstones'];

  // Neue Loeschungen von diesem Push zuerst uebernehmen, damit sie beim Zusammenfuehren respektiert werden.
  $incomingTomb = $input['tombstones'] ?? [];
  if (is_object($incomingTomb)) $incomingTomb = (array)$incomingTomb;
  foreach ($incomingTomb as $id => $ts) {
    if (!isset($current['tombstones'][$id]) || $ts > $current['tombstones'][$id]) $current['tombstones'][$id] = $ts;
  }
  // Bereits geloeschte IDs aus allen Listen entfernen, falls sie durch einen aelteren Snapshot wieder auftauchen.
  $tomb = $current['tombstones'];
  $isLive = function ($item) use ($tomb) { return !isset($tomb[$item['id']]); };
  $current['events'] = array_values(array_filter($current['events'], $isLive));
  $current['news'] = array_values(array_filter($current['news'], $isLive));
  $current['meals'] = array_values(array_filter($current['meals'], $isLive));

  $current['events'] = mergeByIdNewest($current['events'], $input['events'] ?? [], $tomb);
  $current['news'] = mergeByIdNewest($current['news'], $input['news'] ?? [], $tomb);
  $current['meals'] = mergeByIdNewest($current['meals'], $input['meals'] ?? [], $tomb);

  file_put_contents($file, json_encode($current, JSON_PRETTY_PRINT));
  echo json_encode($current);
  exit;
}

http_response_code(405);
echo json_encode(['error' => 'Methode nicht erlaubt']);
