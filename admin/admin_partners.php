<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use nexpell\AccessControl;
use nexpell\NavigationUpdater;

global $_database, $languageService;

AccessControl::checkAdminAccess('partners');

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

$currentLang = null;
if (!empty($_SESSION['partners_active_lang'])) {
    $currentLang = (string)$_SESSION['partners_active_lang'];
    unset($_SESSION['partners_active_lang']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['active_lang'])) {
    $currentLang = (string)$_POST['active_lang'];
} elseif (!empty($_SESSION['language'])) {
    $currentLang = (string)$_SESSION['language'];
} else {
    $currentLang = $languageService->detectLanguage();
}

$uploadDir = dirname(__DIR__) . '/images/';

function handleLogoUpload($file, $oldFile = null)
{
    global $uploadDir;

    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

        if (!in_array($ext, $allowed, true)) {
            return ['error' => 'Nur JPG, PNG, GIF, WEBP, SVG erlaubt'];
        }

        $filename = uniqid('partners_') . '.' . $ext;
        $target = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            if ($oldFile && file_exists($uploadDir . $oldFile)) {
                @unlink($uploadDir . $oldFile);
            }
            return ['filename' => $filename];
        }

        return ['error' => 'Fehler beim Hochladen'];
    }

    return ['filename' => $oldFile];
}

function partners_extract_id_from_key(string $contentKey): int
{
    if (preg_match('/^partner_(\d+)_/', $contentKey, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function partners_get_languages(mysqli $db): array
{
    $languages = [];
    $res = mysqli_query($db, "SELECT iso_639_1, name_de FROM settings_languages WHERE active = 1 ORDER BY id ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $languages[$row['iso_639_1']] = $row['name_de'];
        }
    }
    if (!$languages) {
        $languages = ['de' => 'Deutsch', 'en' => 'English', 'it' => 'Italiano'];
    }
    return $languages;
}

if ($action === 'save_sort') {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error']);
        exit;
    }

    foreach ($data as $row) {
        $partnerID = (int)($row['id'] ?? 0);
        $sortOrder = (int)($row['sort_order'] ?? 0);
        if ($partnerID > 0) {
            $_database->query("UPDATE plugins_partners SET sort_order = $sortOrder WHERE content_key LIKE 'partner_{$partnerID}_%'");
        }
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

$deleteId = 0;
if (($action === 'delete') && isset($_GET['id'])) {
    $deleteId = (int)$_GET['id'];
} elseif (isset($_POST['delete_id'])) {
    $deleteId = (int)$_POST['delete_id'];
}

if ($deleteId > 0) {
    $partnerID = $deleteId;

    $partnerName = '';
    $logo = '';

    $res = safe_query("SELECT content, logo FROM plugins_partners WHERE content_key = 'partner_{$partnerID}_name' ORDER BY FIELD(language, '" . escape($currentLang) . "', 'de', 'en', 'it') LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        $partnerName = (string)($row['content'] ?? '');
        $logo = (string)($row['logo'] ?? '');
    }

    if ($logo !== '' && file_exists($uploadDir . $logo)) {
        @unlink($uploadDir . $logo);
    }

    safe_query("DELETE FROM plugins_partners WHERE content_key LIKE 'partner_{$partnerID}_%'");

    nx_audit_delete(
        'admin_partners',
        (string)$partnerID,
        ($partnerName !== '' ? $partnerName : (string)$partnerID),
        'admincenter.php?site=admin_partners'
    );

    nx_redirect('admincenter.php?site=admin_partners', 'success', 'alert_deleted', false);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_partner'])) {
    $partnerID = (int)($_POST['partner_id'] ?? 0);
    $activeLang = strtolower((string)($_POST['active_lang'] ?? 'de'));
    $_SESSION['partners_active_lang'] = $activeLang;

    $languages = partners_get_languages($_database);
    if (!isset($languages[$activeLang])) {
        $activeLang = (string)array_key_first($languages);
    }

    $titleLang = $_POST['title_lang'] ?? [];
    $descriptionLang = $_POST['description_lang'] ?? [];

    $title = trim((string)($titleLang[$activeLang] ?? ''));

    $slug = $_database->real_escape_string(trim((string)($_POST['slug'] ?? '')));
    $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $userID = (int)($_SESSION['userID'] ?? 0);

    if ($partnerID <= 0) {
        $resMax = $_database->query("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(content_key,'_',2),'_',-1) AS UNSIGNED)) AS max_id FROM plugins_partners WHERE content_key LIKE 'partner_%_name'");
        $rowMax = $resMax ? $resMax->fetch_assoc() : null;
        $partnerID = ((int)($rowMax['max_id'] ?? 0)) + 1;
    }

    $oldLogo = '';
    $resLogo = safe_query("SELECT logo FROM plugins_partners WHERE content_key = 'partner_{$partnerID}_name' LIMIT 1");
    if ($resLogo && mysqli_num_rows($resLogo) > 0) {
        $rowLogo = mysqli_fetch_assoc($resLogo);
        $oldLogo = (string)($rowLogo['logo'] ?? '');
    }

    $uploadResult = handleLogoUpload($_FILES['logo'] ?? null, $oldLogo);
    if (isset($uploadResult['error'])) {
        nx_alert('danger', $uploadResult['error'], true, true, true);
    } else {
        $logo = $_database->real_escape_string((string)$uploadResult['filename']);
        $nameKey = "partner_{$partnerID}_name";
        $descriptionKey = "partner_{$partnerID}_description";

        foreach ($languages as $iso => $_label) {
            $lang = $_database->real_escape_string((string)$iso);
            $titleEsc = $_database->real_escape_string(trim((string)($titleLang[$iso] ?? '')));
            $descriptionEsc = $_database->real_escape_string((string)($descriptionLang[$iso] ?? ''));

            safe_query("INSERT INTO plugins_partners (content_key, language, content, slug, logo, updated_at, userID, sort_order, is_active)
                        VALUES ('{$nameKey}', '{$lang}', '{$titleEsc}', '{$slug}', '{$logo}', NOW(), {$userID}, {$sortOrder}, {$isActive})
                        ON DUPLICATE KEY UPDATE
                            content = VALUES(content),
                            slug = VALUES(slug),
                            logo = VALUES(logo),
                            updated_at = NOW(),
                            userID = VALUES(userID),
                            sort_order = VALUES(sort_order),
                            is_active = VALUES(is_active)");

            safe_query("INSERT INTO plugins_partners (content_key, language, content, slug, logo, updated_at, userID, sort_order, is_active)
                        VALUES ('{$descriptionKey}', '{$lang}', '{$descriptionEsc}', '{$slug}', '{$logo}', NOW(), {$userID}, {$sortOrder}, {$isActive})
                        ON DUPLICATE KEY UPDATE
                            content = VALUES(content),
                            slug = VALUES(slug),
                            logo = VALUES(logo),
                            updated_at = NOW(),
                            userID = VALUES(userID),
                            sort_order = VALUES(sort_order),
                            is_active = VALUES(is_active)");
        }

        if (isset($_POST['partner_id']) && (int)$_POST['partner_id'] > 0) {
            nx_audit_update('admin_partners', (string)$partnerID, true, $title, 'admincenter.php?site=admin_partners');
        } else {
            nx_audit_create('admin_partners', (string)$partnerID, $title, 'admincenter.php?site=admin_partners');
        }

        $admin_file = basename(__FILE__, '.php');
        echo NavigationUpdater::updateFromAdminFile($admin_file);

        nx_redirect('admincenter.php?site=admin_partners', 'success', 'alert_saved', false);
    }
}

if ($action === 'add' || $action === 'edit') {
    $isEdit = ($action === 'edit');
    $partnerID = (int)($_GET['edit'] ?? 0);

    $languages = partners_get_languages($_database);
    if (!isset($languages[$currentLang])) {
        $currentLang = array_key_first($languages) ?: 'de';
    }

    $titles = [];
    $descriptions = [];
    $meta = [
        'slug' => '',
        'sort_order' => 0,
        'is_active' => 1,
        'logo' => ''
    ];

    if ($isEdit && $partnerID > 0) {
        $res = safe_query("SELECT content_key, language, content, slug, logo, sort_order, is_active
                           FROM plugins_partners
                           WHERE content_key LIKE 'partner_{$partnerID}_%'");

        while ($row = mysqli_fetch_assoc($res)) {
            $key = (string)$row['content_key'];
            $lang = (string)$row['language'];

            if (substr($key, -5) === '_name') {
                $titles[$lang] = (string)$row['content'];
            } elseif (substr($key, -12) === '_description') {
                $descriptions[$lang] = (string)$row['content'];
            }

            if ($meta['slug'] === '' && !empty($row['slug'])) {
                $meta['slug'] = (string)$row['slug'];
            }
            if ($meta['logo'] === '' && !empty($row['logo'])) {
                $meta['logo'] = (string)$row['logo'];
            }
            $meta['sort_order'] = (int)$row['sort_order'];
            $meta['is_active'] = (int)$row['is_active'];
        }
    }
    ?>

<div class="card shadow-sm mt-4">
    <div class="card-header">
        <div class="card-title d-flex align-items-center justify-content-between w-100">
            <span>
                <i class="bi bi-link-45deg"></i> <?= $languageService->get('partners_manage') ?>
                <small class="text-muted"><?= $isEdit ? $languageService->get('edit') : $languageService->get('add') ?></small>
            </span>
            <div class="btn-group" id="lang-switch">
                <?php foreach ($languages as $iso => $label): ?>
                    <button type="button"
                            class="btn <?= $iso === $currentLang ? 'btn-primary' : 'btn-secondary' ?>"
                            data-lang="<?= htmlspecialchars($iso) ?>">
                        <?= strtoupper($iso) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card-body">

    <form method="post" enctype="multipart/form-data"
          action="admincenter.php?site=admin_partners&action=<?= $action ?><?= $partnerID > 0 ? '&edit=' . $partnerID : '' ?>">

        <input type="hidden" name="partner_id" value="<?= (int)$partnerID ?>">
        <input type="hidden" name="active_lang" id="active_lang" value="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">

        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <div class="row g-3">

                    <div class="col-12 col-md-5">
                        <label for="partners-title-main" class="form-label"><?= $languageService->get('partners_name') ?> *</label>
                        <?php foreach ($languages as $iso => $label): ?>
                            <?php $display = ($iso === $currentLang) ? '' : 'display:none;'; ?>
                            <div class="lang-pane lang-<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') ?>" style="<?= $display ?>">
                                <input
                                    type="text"
                                    class="form-control"
                                    id="partners-title-<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') ?>"
                                    name="title_lang[<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') ?>]"
                                    <?= $iso === $currentLang ? 'required' : '' ?>
                                    value="<?= htmlspecialchars($titles[$iso] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="slug" class="form-label"><?= $languageService->get('partners_slug') ?></label>
                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control"
                                id="slug"
                                name="slug"
                                value="<?= htmlspecialchars($meta['slug']) ?>"
                            >
                        </div>
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="sort_order" class="form-label"><?= $languageService->get('partners_sort_order') ?></label>
                        <input
                            type="number"
                            class="form-control"
                            id="sort_order"
                            name="sort_order"
                            min="0"
                            step="1"
                            value="<?= (int)$meta['sort_order'] ?>"
                        >
                    </div>

                    <div class="col-12">
                        <label for="partners-description-main" class="form-label mb-0"><?= $languageService->get('partners_description') ?></label>
                        <?php foreach ($languages as $iso => $label): ?>
                            <?php $display = ($iso === $currentLang) ? '' : 'display:none;'; ?>
                            <div class="lang-pane lang-<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') ?>" style="<?= $display ?>">
                                <textarea
                                    class="form-control"
                                    rows="10"
                                    id="partners-description-<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') ?>"
                                    name="description_lang[<?= htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') ?>]"
                                    data-editor="nx_editor"
                                ><?= htmlspecialchars($descriptions[$iso] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch mt-2">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                id="is_active"
                                name="is_active"
                                <?= $meta['is_active'] ? 'checked' : '' ?>
                            >
                            <label for="is_active" class="form-check-label"><?= $languageService->get('active') ?></label>
                        </div>
                    </div>

                    <div class="col-12 pt-2">
                        <div class="d-flex gap-2">
                            <button type="submit" name="save_partner" class="btn btn-primary">
                                <?= $languageService->get('save') ?>
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="p-3 h-100">
                    <div class="fw-semibold mb-2"><?= $languageService->get('partners_logo') ?></div>

                    <?php if (!empty($meta['logo'])): ?>
                        <div class="mb-3">
                            <img src="/includes/plugins/partners/images/<?= htmlspecialchars($meta['logo']) ?>" class="img-thumbnail" style="max-width:100%;height:auto;" alt="Logo">
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <input type="file" class="form-control" id="logo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" <?= $isEdit ? '' : 'required' ?>>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
</div>
<?php

} else {
    $langEsc = escape($currentLang ?: 'de');

    $respartners = $_database->query("SELECT
        base.partner_id,
        COALESCE(cur.content, de.content, CONCAT('Partner ', base.partner_id)) AS name,
        COALESCE(cur.slug, de.slug, '') AS slug,
        COALESCE(cur.logo, de.logo, '') AS logo,
        COALESCE(cur.sort_order, de.sort_order, 0) AS sort_order,
        COALESCE(cur.is_active, de.is_active, 0) AS is_active,
        COALESCE(k.click_count, 0) AS clicks,
        COALESCE(cur.updated_at, de.updated_at, NOW()) AS updated_at
    FROM (
        SELECT DISTINCT CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(content_key,'_',2),'_',-1) AS UNSIGNED) AS partner_id
        FROM plugins_partners
        WHERE content_key LIKE 'partner_%_name'
    ) base
    LEFT JOIN plugins_partners cur
        ON cur.content_key = CONCAT('partner_', base.partner_id, '_name')
       AND cur.language = '{$langEsc}'
    LEFT JOIN plugins_partners de
        ON de.content_key = CONCAT('partner_', base.partner_id, '_name')
       AND de.language = 'de'
    LEFT JOIN (
        SELECT itemID, COUNT(*) AS click_count
        FROM link_clicks
        WHERE plugin = 'partners'
        GROUP BY itemID
    ) k ON base.partner_id = k.itemID
    ORDER BY sort_order ASC, base.partner_id ASC");

    ?>

<div class="card shadow-sm mt-4">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-link-45deg"></i> <?= $languageService->get('partners_manage') ?>
            <small class="text-muted"><?= $languageService->get('overview') ?></small>
        </div>

        <a href="admincenter.php?site=admin_partners&action=add" class="btn btn-secondary">
            <?= $languageService->get('add') ?>
        </a>
    </div>

    <div class="card-body">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:40px;"></th>
                    <th><?= $languageService->get('partners_logo') ?></th>
                    <th><?= $languageService->get('partners_name') ?></th>
                    <th><?= $languageService->get('partners_slug') ?></th>
                    <th><?= $languageService->get('partners_clicks') ?></th>
                    <th><?= $languageService->get('partners_active') ?></th>
                    <th><?= $languageService->get('partners_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($partner = $respartners->fetch_assoc()):
                    $updatedTimestamp = isset($partner['updated_at']) ? strtotime((string)$partner['updated_at']) : time();
                    $days = max(1, round((time() - $updatedTimestamp) / (60 * 60 * 24)));
                    $perday = round(((int)$partner['clicks']) / $days, 2);
                    $partnerID = (int)$partner['partner_id'];
                ?>
                <tr data-id="<?= $partnerID ?>">
                    <td class="text-muted cursor-move"><i class="bi bi-list"></i></td>
                    <td>
                        <?php if ($partner['logo'] && file_exists($uploadDir . $partner['logo'])): ?>
                            <img src="/includes/plugins/partners/images/<?= htmlspecialchars($partner['logo']) ?>" alt="Logo" style="max-height:40px;">
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($partner['name']) ?></td>
                    <td>
                        <?php if (!empty($partner['slug'])): ?>
                            <a href="<?= htmlspecialchars($partner['slug']) ?>" target="_blank" rel="nofollow"><?= htmlspecialchars($partner['slug']) ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= (int)$partner['clicks'] ?> (<i class="bi bi-activity"></i> <?= $perday ?>/Tag)
                    </td>
                    <td><?= (int)$partner['is_active'] ? '<span class="badge bg-success">' . htmlspecialchars($languageService->get('yes'), ENT_QUOTES, 'UTF-8') . '</span>' : '<span class="badge bg-secondary">' . htmlspecialchars($languageService->get('no'), ENT_QUOTES, 'UTF-8') . '</span>' ?></td>
                    <td>
                        <a href="admincenter.php?site=admin_partners&action=edit&edit=<?= $partnerID ?>" class="btn btn-warning d-inline-flex align-items-center gap-1 w-auto">
                            <i class="bi bi-pencil-square"></i> <?= $languageService->get('edit') ?>
                        </a>

                        <?php $deleteUrl = 'admincenter.php?site=admin_partners&action=delete&id=' . $partnerID; ?>
                        <a
                        href="#"
                        class="btn btn-danger d-inline-flex align-items-center gap-1 w-auto"
                        data-bs-toggle="modal"
                        data-bs-target="#confirmDeleteModal"
                        data-confirm-url="<?= htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-trash3"></i> <?= $languageService->get('delete') ?>
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($respartners->num_rows === 0): ?>
                    <tr>
                        <td colspan="8" class="text-center">
                            <?= $languageService->get('partners_none_found') ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php
}
?>
<style>
.cursor-move {
    cursor: grab;
}
.sortable-ghost {
    opacity: 0.5;
}
</style>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.querySelector('.table tbody');
    if (tbody && typeof Sortable !== 'undefined' && tbody.querySelector('tr[data-id]')) {
        Sortable.create(tbody, {
            handle: '.cursor-move',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function () {
                const order = [];
                tbody.querySelectorAll('tr[data-id]').forEach((tr, i) => {
                    order.push({
                        id: tr.dataset.id,
                        sort_order: i + 1
                    });
                });

                fetch('admincenter.php?site=admin_partners&action=save_sort', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(order)
                });
            }
        });
    }
});
</script>
