<?php
/**
 * DApp — Push-Anmeldung speichern
 * In denselben Ordner wie sync.php hochladen. Speichert, welche Geräte
 * über welche Kategorien benachrichtigt werden wollen.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header("Content-Type: application/json");

$dataDir = __DIR__ . '/sync-data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
$subsFile = $dataDir . '/push-subscriptions.json';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['deviceId'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Ungültige Anfrage']);
  exit;
}

$subs = file_exists($subsFile) ? json_decode(file_get_contents($subsFile), true) : [];
if (!is_array($subs)) $subs = [];

if (!empty($input['unsubscribe'])) {
  unset($subs[$input['deviceId']]);
} else {
  $subs[$input['deviceId']] = [
    'subscription' => $input['subscription'],
    'deviceLabel'  => $input['deviceLabel'] ?? '',
    'categories'   => $input['categories'] ?? [],
    'updatedAt'    => time(),
  ];
}

file_put_contents($subsFile, json_encode($subs, JSON_PRETTY_PRINT));
echo json_encode(['ok' => true]);
