<?php

if (!function_exists('safe_query')) {
    die('Access denied');
}

global $plugin;

PluginInstallerHelper::install([

    'modulname'  => 'partners',
    'name'       => 'Partners',
    'version'    => (string)($plugin['version'] ?? '0.0.0'),
    'author'     => 'T-Seven',
    'website'    => 'https://www.nexpell.de',
    'path'       => 'includes/plugins/partners/',

    'admin_file' => 'admin_partners',
    'index_link' => 'partners',
    'sidebar'    => 'deactivated',

    'languages' => [
        'plugin_info_partners' => [
            'de' => 'Mit diesem Plugin könnt ihr eure Partner mit Slider und Page anzeigen lassen.',
            'en' => 'With this plugin you can display your partners with slider and page.',
            'it' => 'Con questo plugin puoi visualizzare i tuoi partner con slider e pagina.'
        ]
    ],

    'permissions' => [
        'partners'
    ],

    'admin_navigation' => [
        [
            'url'   => 'admincenter.php?site=admin_partners',
            'catID' => 13,
            'sort'  => 1,
            'labels' => [
                'de' => 'Partner',
                'en' => 'Partners',
                'it' => 'Partner'
            ]
        ]
    ],

    'website_navigation' => [
        [
            'url'        => 'index.php?site=partners',
            'mnavID'     => 5,
            'sort'       => 1,
            'indropdown' => 1,
            'labels' => [
                'de' => 'Partner',
                'en' => 'Partners',
                'it' => 'Partner'
            ]
        ]
    ]

]);

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
