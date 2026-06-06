<?php
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

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

## SYSTEM #####################################################################################################################################

safe_query("
    INSERT IGNORE INTO settings_plugins
        (pluginID, modulname, admin_file, activate, author, website, index_link, hiddenfiles, version, path, status_display, plugin_display, widget_display, delete_display, sidebar)
    VALUES
        ('', 'partners', 'admin_partners', 1, 'T-Seven', 'https://www.nexpell.de', 'partners', '', '0.1', 'includes/plugins/partners/', 1, 1, 1, 1, 'deactivated');
");

safe_query("
    INSERT IGNORE INTO settings_plugins_lang 
        (`content_key`, `language`, `content`, `updated_at`)
    VALUES
        ('plugin_name_partners', 'de', 'Partner', NOW()),
        ('plugin_name_partners', 'en', 'Partners', NOW()),
        ('plugin_name_partners', 'it', 'Partner', NOW()),

        ('plugin_info_partners', 'de', 'Mit diesem Plugin könnt ihr eure Partner mit Slider und Page anzeigen lassen.', NOW()),
        ('plugin_info_partners', 'en', 'With this plugin you can display your partners with slider and page.', NOW()),
        ('plugin_info_partners', 'it', 'Con questo plugin puoi visualizzare i tuoi partner con slider e pagina.', NOW())
");

## NAVIGATION #####################################################################################################################################

safe_query("
    INSERT IGNORE INTO navigation_dashboard_links
        (catID, modulname, url, sort)
    VALUES
        (13, 'partners', 'admincenter.php?site=admin_partners', 1)
");
$linkID = mysqli_insert_id($_database);

safe_query("
    INSERT IGNORE INTO navigation_dashboard_lang
        (`content_key`, `language`, `content`, `updated_at`)
    VALUES
        ('nav_link_{$linkID}', 'de', 'Partner', NOW()),
        ('nav_link_{$linkID}', 'en', 'Partners', NOW()),
        ('nav_link_{$linkID}', 'it', 'Partner', NOW())
");

safe_query("
    INSERT IGNORE INTO navigation_website_sub
        (`mnavID`, `modulname`, `url`, `sort`, `indropdown`, `last_modified`)
    VALUES
        (5, 'partners', 'index.php?site=partners', 1, 1, NOW())
");

$snavID = mysqli_insert_id($_database);

safe_query("
    INSERT IGNORE INTO navigation_website_lang
        (`content_key`, `language`, `content`, `updated_at`)
    VALUES
        ('nav_sub_{$snavID}', 'de', 'Partner', NOW()),
        ('nav_sub_{$snavID}', 'en', 'Partners', NOW()),
        ('nav_sub_{$snavID}', 'it', 'Partner', NOW())
");

#######################################################################################################################################
safe_query("
  INSERT IGNORE INTO user_role_admin_navi_rights (id, roleID, type, modulname)
  VALUES ('', 1, 'link', 'partners')
");
 ?>