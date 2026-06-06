<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use nexpell\LanguageService;

global $_database, $languageService;

$lang = $languageService->detectLanguage();
if (method_exists($languageService, 'readPluginModule')) {
    $languageService->readPluginModule('partners');
} else {
    $languageService->readModule('partners');
}

// Styleklasse laden
$config = mysqli_fetch_array(safe_query("SELECT selected_style FROM settings_headstyle_config WHERE id=1"));
$class = htmlspecialchars((string)($config['selected_style'] ?? ''));

// Header-Daten
$data_array = [
    'class' => $class,
    'title' => $languageService->get('title'),
    'subtitle' => 'Partners'
];
echo $tpl->loadTemplate("partners", "head", $data_array, "plugin");

//$alertColors = ['primary', 'secondary', 'success', 'warning', 'danger', 'info'];
$alertColors = ['primary', 'success', 'warning', 'danger', 'info'];
$filepath = "/includes/plugins/partners/images/";
$langEsc = escape($lang ?: 'de');

$query = "SELECT
    base.partner_id,
    COALESCE(name_cur.content, name_de.content, CONCAT('Partner ', base.partner_id)) AS name,
    COALESCE(desc_cur.content, desc_de.content, '') AS description,
    COALESCE(name_cur.slug, name_de.slug, '') AS slug,
    COALESCE(name_cur.logo, name_de.logo, '') AS logo,
    COALESCE(name_cur.sort_order, name_de.sort_order, 0) AS sort_order,
    COALESCE(name_cur.is_active, name_de.is_active, 0) AS is_active
FROM (
    SELECT DISTINCT CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(content_key,'_',2),'_',-1) AS UNSIGNED) AS partner_id
    FROM plugins_partners
    WHERE content_key LIKE 'partner_%_name'
) base
LEFT JOIN plugins_partners name_cur
    ON name_cur.content_key = CONCAT('partner_', base.partner_id, '_name')
   AND name_cur.language = '{$langEsc}'
LEFT JOIN plugins_partners name_de
    ON name_de.content_key = CONCAT('partner_', base.partner_id, '_name')
   AND name_de.language = 'de'
LEFT JOIN plugins_partners desc_cur
    ON desc_cur.content_key = CONCAT('partner_', base.partner_id, '_description')
   AND desc_cur.language = '{$langEsc}'
LEFT JOIN plugins_partners desc_de
    ON desc_de.content_key = CONCAT('partner_', base.partner_id, '_description')
   AND desc_de.language = 'de'
WHERE COALESCE(name_cur.is_active, name_de.is_active, 0) = 1
ORDER BY sort_order ASC, base.partner_id ASC";

$result = $_database->query($query);

$partners = [];
$colorIndex = 0;

if ($result && $result->num_rows > 0) {
    while ($partner = $result->fetch_assoc()) {
        $name = htmlspecialchars((string)$partner['name']);
        $logo = !empty($partner['logo']) ? $filepath . $partner['logo'] : $filepath . 'no-image.jpg';
        $description = html_entity_decode((string)($partner['description'] ?? ''), ENT_QUOTES | ENT_HTML5);

        $colorKey = $alertColors[$colorIndex];
        $colorIndex = ($colorIndex + 1) % count($alertColors);

        $slug = '';
        $urlRaw = trim((string)($partner['slug'] ?? ''));
        if ($urlRaw !== '') {
            $urlCandidate = (stripos($urlRaw, 'http') === 0) ? $urlRaw : 'http://' . $urlRaw;
            if (filter_var($urlCandidate, FILTER_VALIDATE_URL)) {
                $slug = $urlCandidate;
            }
        }

        $partners[] = [
            'id' => (int)$partner['partner_id'],
            'name' => $name,
            'logo' => $logo,
            'description' => $description,
            'color' => $colorKey,
            'slug' => $slug,
            'learn_more' => $languageService->get('learn_more'),
            'no_valid_link' => $languageService->get('no_valid_link'),
        ];
    }

    echo $tpl->loadTemplate('partners', 'main', ['partners' => $partners], 'plugin');
} else {
    echo '<div class="alert alert-info">' . $languageService->get('no_partners_found') . '</div>';
}

?>
