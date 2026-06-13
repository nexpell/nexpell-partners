<?php

if (!function_exists('safe_query')) {
    die('Access denied');
}

global $str, $modulname, $version, $plugin;

$modulname = 'partners';
$version = isset($plugin['version']) ? (string)$plugin['version'] : ($version ?? '1.0.0');
$str = 'Partners';

echo '<div class="card"><div class="card-body">';
echo '<h4>Update: ' . htmlspecialchars($str, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . ')</h4>';

require __DIR__ . '/install.php';

echo '</div></div>';
