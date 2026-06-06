<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$configPath = __DIR__ . '/../../../system/config.inc.php';
if (!file_exists($configPath)) {
    die('Fehler: Konfigurationsdatei nicht gefunden.');
}
require_once $configPath;

$_database = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $partnerID = (int)$_GET['id'];

    $stmt = $_database->prepare("SELECT slug FROM plugins_partners WHERE content_key = ? ORDER BY FIELD(language, 'de', 'en', 'it') LIMIT 1");
    if (!$stmt) {
        die('Prepare failed: ' . $_database->error);
    }

    $nameKey = 'partner_' . $partnerID . '_name';
    $stmt->bind_param('s', $nameKey);
    $stmt->execute();
    $stmt->bind_result($url);

    if ($stmt->fetch()) {
        $stmt->close();

        $fullUrl = (stripos($url, 'http') === 0) ? $url : 'http://' . $url;

        $clickedAt = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';

        $insert = $_database->prepare(
            "INSERT INTO link_clicks (plugin, itemID, url, clicked_at, ip_address, user_agent, referrer)
             VALUES ('partners', ?, ?, ?, ?, ?, ?)"
        );
        if ($insert) {
            $insert->bind_param('isssss', $partnerID, $fullUrl, $clickedAt, $ip, $userAgent, $referrer);
            $insert->execute();
            $insert->close();
        }

        header('Location: ' . $fullUrl);
        exit;
    }

    $stmt->close();
    echo 'Partner nicht gefunden.';
} else {
    echo 'Ungültige ID.';
}