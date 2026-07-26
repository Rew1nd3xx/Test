<?php
/**
 * DApp — Wartende Benachrichtigungen abholen
 * Wird vom Service Worker aufgerufen, sobald eine (leere) Push-Nachricht
 * ankommt. Gibt die Benachrichtigungstexte für dieses Gerät zurück und
 * leert sie danach (jede Benachrichtigung wird nur einmal ausgeliefert).
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$dataDir = __DIR__ . '/sync-data';
$deviceId = isset($_GET['device']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['device']) : '';
if (!$deviceId) { echo json_encode(['notifications' => []]); exit; }

$pendingDir = $dataDir . '/pending';
$pendingFile = $pendingDir . '/' . $deviceId . '.json';

if (!file_exists($pendingFile)) {
  echo json_encode(['notifications' => []]);
  exit;
}

$notifications = json_decode(file_get_contents($pendingFile), true);
if (!is_array($notifications)) $notifications = [];

// Abgeholte Benachrichtigungen leeren, damit sie nicht doppelt ankommen.
file_put_contents($pendingFile, json_encode([]));

echo json_encode(['notifications' => $notifications]);
