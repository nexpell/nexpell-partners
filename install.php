<?php

if (!function_exists('safe_query')) {
    die('Access denied');
}

global $_database, $plugin;

$modulname = 'partners';
$version = isset($plugin['version']) ? (string)$plugin['version'] : ($version ?? '1.0.0');
$pluginName = 'Partners';
$pluginPath = 'includes/plugins/partners/';

if (!function_exists('partners_sql')) {
    function partners_sql($value): string
    {
        return escape((string)$value);
    }
}

if (!function_exists('partners_column_exists')) {
    function partners_column_exists(string $table, string $column): bool
    {
        $res = safe_query("SHOW COLUMNS FROM `" . partners_sql($table) . "` LIKE '" . partners_sql($column) . "'");
        return $res && mysqli_num_rows($res) > 0;
    }
}

if (!function_exists('partners_table_exists')) {
    function partners_table_exists(string $table): bool
    {
        $res = safe_query("SHOW TABLES LIKE '" . partners_sql($table) . "'");
        return $res && mysqli_num_rows($res) > 0;
    }
}

if (!function_exists('partners_extract_lang')) {
    function partners_extract_lang(string $text, string $lang): string
    {
        $lang = strtolower($lang);

        if (preg_match('/\[\[lang:' . preg_quote($lang, '/') . '\]\](.*?)(?=\[\[lang:|$)/si', $text, $m)) {
            return trim((string)$m[1]);
        }
        if (preg_match('/\{\[' . preg_quote($lang, '/') . '\]\}(.*?)(?=\{\[[a-z]{2}\]\}|$)/si', $text, $m)) {
            return trim((string)$m[1]);
        }

        if ($lang === 'gb') {
            return partners_extract_lang($text, 'en');
        }
        if ($lang === 'en' && preg_match('/\{\[gb\]\}|\[\[lang:gb\]\]/i', $text)) {
            return partners_extract_lang($text, 'gb');
        }

        if (preg_match('/\[\[lang:[a-z]{2}\]\]|\{\[[a-z]{2}\]\}/i', $text)) {
            return '';
        }

        return trim($text);
    }
}

if (!function_exists('partners_sync_from_source')) {
    function partners_sync_from_source(string $sourceTable, bool $updateOnly = false): void
    {
        $idColumn = partners_column_exists($sourceTable, 'partnerID') ? 'partnerID' : 'id';
        $nameColumn = partners_column_exists($sourceTable, 'name')
            ? 'name'
            : (partners_column_exists($sourceTable, 'title') ? 'title' : 'content');
        $infoColumn = partners_column_exists($sourceTable, 'info')
            ? 'info'
            : (partners_column_exists($sourceTable, 'description')
                ? 'description'
                : (partners_column_exists($sourceTable, 'text') ? 'text' : 'content'));
        $urlColumn = partners_column_exists($sourceTable, 'url') ? 'url' : 'slug';
        $bannerColumn = partners_column_exists($sourceTable, 'banner') ? 'banner' : 'logo';
        $sortColumn = partners_column_exists($sourceTable, 'sort') ? 'sort' : 'sort_order';
        $displayedColumn = partners_column_exists($sourceTable, 'displayed') ? 'displayed' : 'is_active';
        $dateColumn = partners_column_exists($sourceTable, 'date') ? 'date' : 'updated_at';
        $languageColumn = partners_column_exists($sourceTable, 'language') ? 'language' : null;

        $res = safe_query("SELECT * FROM `" . partners_sql($sourceTable) . "` ORDER BY `" . partners_sql($idColumn) . "` ASC");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $partnerID = (int)($row[$idColumn] ?? 0);
            if ($partnerID <= 0) {
                continue;
            }

            $rawName = (string)($row[$nameColumn] ?? '');
            $rawDescription = (string)($row[$infoColumn] ?? '');
            $slug = partners_sql((string)($row[$urlColumn] ?? ''));
            $logo = partners_sql((string)($row[$bannerColumn] ?? ''));
            $sortOrder = (int)($row[$sortColumn] ?? 0);

            $activeRaw = $row[$displayedColumn] ?? 1;
            $isActive = in_array((string)$activeRaw, ['1', 'true', 'yes'], true) ? 1 : (int)$activeRaw;

            $dateValue = $row[$dateColumn] ?? null;
            if (is_numeric($dateValue)) {
                $timestamp = (int)$dateValue;
                $updatedAtSql = $timestamp > 0 ? "FROM_UNIXTIME({$timestamp})" : "NOW()";
            } else {
                $dateEsc = partners_sql((string)$dateValue);
                $updatedAtSql = $dateEsc !== '' ? "'{$dateEsc}'" : "NOW()";
            }

            $languages = ['de', 'en', 'it'];
            if ($languageColumn !== null) {
                $sourceLang = strtolower(trim((string)($row[$languageColumn] ?? '')));
                if ($sourceLang !== '') {
                    $languages = [$sourceLang];
                }
            }

            foreach ($languages as $language) {
                $langEsc = partners_sql($language);
                $name = partners_sql($languageColumn !== null ? $rawName : partners_extract_lang($rawName, $language));
                $description = partners_sql($languageColumn !== null ? $rawDescription : partners_extract_lang($rawDescription, $language));

                if ($updateOnly) {
                    safe_query("
                        UPDATE plugins_partners
                        SET content = '{$name}'
                        WHERE content_key = 'partner_{$partnerID}_name'
                          AND language = '{$langEsc}'
                          AND (content = '' OR content IS NULL)
                    ");
                    safe_query("
                        UPDATE plugins_partners
                        SET content = '{$description}'
                        WHERE content_key = 'partner_{$partnerID}_description'
                          AND language = '{$langEsc}'
                          AND (content = '' OR content IS NULL)
                    ");
                    continue;
                }

                safe_query("
                    INSERT INTO plugins_partners
                        (content_key, language, content, slug, logo, updated_at, userID, sort_order, is_active)
                    VALUES
                        ('partner_{$partnerID}_name', '{$langEsc}', '{$name}', '{$slug}', '{$logo}', {$updatedAtSql}, 0, {$sortOrder}, {$isActive}),
                        ('partner_{$partnerID}_description', '{$langEsc}', '{$description}', '{$slug}', '{$logo}', {$updatedAtSql}, 0, {$sortOrder}, {$isActive})
                    ON DUPLICATE KEY UPDATE
                        content = VALUES(content),
                        slug = VALUES(slug),
                        logo = VALUES(logo),
                        updated_at = VALUES(updated_at),
                        sort_order = VALUES(sort_order),
                        is_active = VALUES(is_active)
                ");
            }
        }
    }
}

safe_query("CREATE TABLE IF NOT EXISTS plugins_partners (
  id INT(11) NOT NULL AUTO_INCREMENT,
  content_key VARCHAR(80) NOT NULL,
  language CHAR(2) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  slug VARCHAR(255) NOT NULL DEFAULT '',
  logo VARCHAR(255) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  userID INT(11) NOT NULL DEFAULT 0,
  sort_order INT(11) DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_content_lang (content_key, language),
  KEY idx_content_key (content_key),
  KEY idx_language (language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if (!partners_column_exists('plugins_partners', 'content_key')) {
    safe_query("DROP TABLE IF EXISTS plugins_partners_source");
    safe_query("RENAME TABLE plugins_partners TO plugins_partners_source");

    safe_query("CREATE TABLE plugins_partners (
      id INT(11) NOT NULL AUTO_INCREMENT,
      content_key VARCHAR(80) NOT NULL,
      language CHAR(2) NOT NULL,
      content MEDIUMTEXT NOT NULL,
      slug VARCHAR(255) NOT NULL DEFAULT '',
      logo VARCHAR(255) DEFAULT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      userID INT(11) NOT NULL DEFAULT 0,
      sort_order INT(11) DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_content_lang (content_key, language),
      KEY idx_content_key (content_key),
      KEY idx_language (language)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    partners_sync_from_source('plugins_partners_source');
    safe_query("DROP TABLE IF EXISTS plugins_partners_source");
}

if (partners_table_exists('plugins_partners_legacy')) {
    partners_sync_from_source('plugins_partners_legacy', true);
    safe_query("DROP TABLE IF EXISTS plugins_partners_legacy");
}

if (partners_table_exists('plugins_partners_source')) {
    partners_sync_from_source('plugins_partners_source', true);
    safe_query("DROP TABLE IF EXISTS plugins_partners_source");
}

safe_query("
INSERT IGNORE INTO plugins_partners
(id, content_key, language, content, slug, logo, updated_at, userID, sort_order, is_active)
VALUES
(1, 'partner_1_name', 'de', 'Partner 1', 'https://www.nexpell.de', 'partners_684593e67f7cc.png', NOW(), 1, 1, 1),
(2, 'partner_1_name', 'en', 'Partner 1', 'https://www.nexpell.de', 'partners_684593e67f7cc.png', NOW(), 1, 1, 1),
(3, 'partner_1_name', 'it', 'Partner 1', 'https://www.nexpell.de', 'partners_684593e67f7cc.png', NOW(), 1, 1, 1),
(4, 'partner_1_description', 'de', 'Hallo. Ich bin ein kleiner Blindtext. Und zwar schon so lange ich denken kann.', 'https://www.nexpell.de', 'partners_684593e67f7cc.png', NOW(), 1, 1, 1),
(5, 'partner_1_description', 'en', 'Hello. I am a small placeholder text and have been here for a long time.', 'https://www.nexpell.de', 'partners_684593e67f7cc.png', NOW(), 1, 1, 1),
(6, 'partner_1_description', 'it', 'Ciao. Sono un piccolo testo segnaposto presente da molto tempo.', 'https://www.nexpell.de', 'partners_684593e67f7cc.png', NOW(), 1, 1, 1)
");

safe_query("CREATE TABLE IF NOT EXISTS plugins_partners_settings (
  partnerssetID int(11) NOT NULL AUTO_INCREMENT,
  partners int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (partnerssetID)
) AUTO_INCREMENT=2
  DEFAULT CHARSET=utf8 DEFAULT COLLATE utf8_unicode_ci");

safe_query("INSERT IGNORE INTO plugins_partners_settings (partnerssetID, partners) VALUES (1, 5)");

## SYSTEM #######################################################################

$pluginRes = safe_query("SELECT pluginID FROM settings_plugins WHERE modulname = 'partners' LIMIT 1");
if ($pluginRes && ($pluginRow = mysqli_fetch_assoc($pluginRes))) {
    safe_query("UPDATE settings_plugins SET
        admin_file = 'admin_partners',
        activate = 1,
        author = 'T-Seven',
        website = 'https://www.nexpell.de',
        index_link = 'partners',
        hiddenfiles = '',
        version = '" . partners_sql($version) . "',
        path = '" . partners_sql($pluginPath) . "',
        status_display = 1,
        plugin_display = 1,
        widget_display = 1,
        delete_display = 1,
        sidebar = 'deactivated'
        WHERE pluginID = " . (int)$pluginRow['pluginID'] . "
    ");
} else {
    safe_query("INSERT INTO settings_plugins
        (modulname, admin_file, activate, author, website, index_link, hiddenfiles, version, path, status_display, plugin_display, widget_display, delete_display, sidebar)
    VALUES
        ('partners', 'admin_partners', 1, 'T-Seven', 'https://www.nexpell.de', 'partners', '', '" . partners_sql($version) . "', '" . partners_sql($pluginPath) . "', 1, 1, 1, 1, 'deactivated')
    ");
}

safe_query("
    INSERT INTO settings_plugins_lang
        (content_key, language, content, modulname, updated_at)
    VALUES
        ('plugin_name_partners', 'de', 'Partner', 'partners', NOW()),
        ('plugin_name_partners', 'en', 'Partners', 'partners', NOW()),
        ('plugin_name_partners', 'it', 'Partner', 'partners', NOW()),
        ('plugin_info_partners', 'de', 'Mit diesem Plugin könnt ihr eure Partner mit Slider und Page anzeigen lassen.', 'partners', NOW()),
        ('plugin_info_partners', 'en', 'With this plugin you can display your partners with slider and page.', 'partners', NOW()),
        ('plugin_info_partners', 'it', 'Con questo plugin puoi visualizzare i tuoi partner con slider e pagina.', 'partners', NOW())
    ON DUPLICATE KEY UPDATE
        content = VALUES(content),
        modulname = VALUES(modulname),
        updated_at = VALUES(updated_at)
");

safe_query("
    INSERT INTO settings_plugins_installed
        (name, modulname, description, version, author, url, folder, installed_date)
    VALUES
        ('Partners', 'partners', 'With this plugin you can display your partners with slider and page.', '" . partners_sql($version) . "', 'nexpell-team', 'https://www.nexpell.de', 'partners', NOW())
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        description = VALUES(description),
        version = VALUES(version),
        author = VALUES(author),
        url = VALUES(url),
        folder = VALUES(folder),
        installed_date = NOW()
");

## NAVIGATION ###################################################################

$linkID = 0;
$linkRes = safe_query("
    SELECT linkID FROM navigation_dashboard_links
    WHERE modulname = 'partners'
    ORDER BY linkID ASC LIMIT 1
");
if ($linkRes && ($linkRow = mysqli_fetch_assoc($linkRes))) {
    $linkID = (int)($linkRow['linkID'] ?? 0);
    safe_query("
        UPDATE navigation_dashboard_links SET
            catID = 13,
            url = 'admincenter.php?site=admin_partners',
            sort = 1
        WHERE linkID = " . $linkID . "
    ");
} else {
    safe_query("
        INSERT INTO navigation_dashboard_links
            (catID, modulname, url, sort)
        VALUES
            (13, 'partners', 'admincenter.php?site=admin_partners', 1)
    ");
    $linkID = (int)mysqli_insert_id($_database);
}

if ($linkID > 0) {
    safe_query("
        INSERT INTO navigation_dashboard_lang
            (content_key, language, content, modulname, updated_at)
        VALUES
            ('nav_link_{$linkID}', 'de', 'Partner', 'partners', NOW()),
            ('nav_link_{$linkID}', 'en', 'Partners', 'partners', NOW()),
            ('nav_link_{$linkID}', 'it', 'Partner', 'partners', NOW())
        ON DUPLICATE KEY UPDATE
            content = VALUES(content),
            modulname = VALUES(modulname),
            updated_at = VALUES(updated_at)
    ");
}

$snavID = 0;
$snavRes = safe_query("
    SELECT snavID FROM navigation_website_sub
    WHERE modulname = 'partners'
    ORDER BY snavID ASC LIMIT 1
");
if ($snavRes && ($snavRow = mysqli_fetch_assoc($snavRes))) {
    $snavID = (int)($snavRow['snavID'] ?? 0);
    safe_query("
        UPDATE navigation_website_sub SET
            mnavID = 5,
            url = 'index.php?site=partners',
            sort = 1,
            indropdown = 1,
            last_modified = NOW()
        WHERE snavID = " . $snavID . "
    ");
} else {
    safe_query("
        INSERT INTO navigation_website_sub
            (mnavID, modulname, url, sort, indropdown, last_modified)
        VALUES
            (5, 'partners', 'index.php?site=partners', 1, 1, NOW())
    ");
    $snavID = (int)mysqli_insert_id($_database);
}

if ($snavID > 0) {
    safe_query("
        INSERT INTO navigation_website_lang
            (content_key, language, content, modulname, updated_at)
        VALUES
            ('nav_sub_{$snavID}', 'de', 'Partner', 'partners', NOW()),
            ('nav_sub_{$snavID}', 'en', 'Partners', 'partners', NOW()),
            ('nav_sub_{$snavID}', 'it', 'Partner', 'partners', NOW())
        ON DUPLICATE KEY UPDATE
            content = VALUES(content),
            modulname = VALUES(modulname),
            updated_at = VALUES(updated_at)
    ");
}

safe_query("
    INSERT IGNORE INTO user_role_admin_navi_rights
        (id, roleID, type, modulname)
    VALUES
        ('', 1, 'link', 'partners')
");
