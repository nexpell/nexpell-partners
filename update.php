<?php

if (!function_exists('safe_query')) {
    die('Access denied');
}

global $str, $modulname, $version, $plugin;

$modulname = 'partners';
$version = isset($plugin['version']) ? (string)$plugin['version'] : ($version ?? '0.0.0');

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

if (!function_exists('partners_create_current_table')) {
    function partners_create_current_table(): void
    {
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

if (partners_table_exists('plugins_partners') && !partners_column_exists('plugins_partners', 'content_key')) {
    safe_query("DROP TABLE IF EXISTS plugins_partners_source");
    safe_query("RENAME TABLE plugins_partners TO plugins_partners_source");
    partners_create_current_table();
    partners_sync_from_source('plugins_partners_source');
    safe_query("DROP TABLE IF EXISTS plugins_partners_source");
}

require __DIR__ . '/install.php';

if (partners_table_exists('plugins_partners_legacy')) {
    partners_sync_from_source('plugins_partners_legacy', true);
    safe_query("DROP TABLE IF EXISTS plugins_partners_legacy");
}

if (partners_table_exists('plugins_partners_source')) {
    partners_sync_from_source('plugins_partners_source', true);
    safe_query("DROP TABLE IF EXISTS plugins_partners_source");
}
