<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$method = $_SERVER['REQUEST_METHOD'];

$input = [];
if ($method === 'POST' || $method === 'PUT') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'multipart/form-data') !== false) {
        $input = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }
}

try {
    switch ($action) {
        case 'login': if ($method !== 'POST') jsonError('Method not allowed', 405); handleLogin($input); break;
        case 'logout': if ($method !== 'POST') jsonError('Method not allowed', 405); handleLogout(); break;
        case 'session': handleSessionCheck(); break;
        case 'dashboard': requireAuth(); handleDashboard(); break;
        case 'organizations': requireAuth(); if ($method === 'GET') handleGetOrganizations(); elseif ($method === 'POST') handleCreateOrganization($input); else jsonError('Method not allowed', 405); break;
        case 'organization': requireAuth(); if (!$id) jsonError('ID required', 400); if ($method === 'GET') handleGetOrganization($id); elseif ($method === 'PUT') handleUpdateOrganization($id, $input); elseif ($method === 'DELETE') handleDeleteOrganization($id); else jsonError('Method not allowed', 405); break;
        case 'variables': requireAuth(); if ($method === 'GET') handleGetVariables($id); elseif ($method === 'POST') handleUpdateVariables($input); else jsonError('Method not allowed', 405); break;
        case 'variables-update': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUpdateVariables($input); break;
        case 'scripts': requireAuth(); handleGetScripts(); break;
        case 'script': requireAuth(); if ($method === 'GET' && $id) handleGetScript($id); elseif ($method === 'PUT' && $id) handleUpdateScript($id, $input); elseif ($method === 'DELETE' && $id) handleDeleteScript($id); elseif ($method === 'POST') handleCreateScript($input); else jsonError('Method not allowed', 405); break;
        case 'script-upload': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadScript(); break;
        case 'users': requireAuth(); if ($method === 'GET') handleGetUsers(); elseif ($method === 'POST') handleCreateUser($input); else jsonError('Method not allowed', 405); break;
        case 'user': requireAuth(); if (!$id) jsonError('ID required', 400); if ($method === 'PUT') handleUpdateUser($id, $input); elseif ($method === 'DELETE') handleDeleteUser($id); else jsonError('Method not allowed', 405); break;
        case 'audit': requireAuth(); handleGetAuditEvents(); break;
        case 'bundle': requireAuth(); if ($method === 'POST') handleGenerateBundle($input); else jsonError('Method not allowed', 405); break;
        case 'bundle-by-id': requireAuth(); if ($method !== 'GET' || !$id) jsonError('Invalid request', 400); handleDownloadBundle($id); break;
        case 'upload-wallpaper': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadWallpaper(); break;
        case 'upload-logo': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadLogo(); break;
        case 'wallpapers': requireAuth(); if ($method !== 'GET') jsonError('Method not allowed', 405); handleGetWallpapers(); break;
        case 'logos': requireAuth(); if ($method !== 'GET') jsonError('Method not allowed', 405); handleGetLogos(); break;
        default: jsonError('Invalid endpoint', 404);
    }
} catch (RuntimeException $e) { jsonError($e->getMessage()); } catch (Exception $e) { error_log('API Error: ' . $e->getMessage()); jsonError('Internal server error', 500); }

// ============ HANDLERS ============

function handleLogin($input) {
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if (empty($username) || empty($password)) jsonError('Username e senha obrigatorios');

    $user = Database::fetchOne("SELECT id, username, password_hash, full_name, role, organization_id, is_active FROM users WHERE username = ?", [$username]);
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) jsonError('Credenciais invalidas', 401);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['organization_id'] = $user['organization_id'];

    $org = $user['organization_id'] ? Database::fetchOne("SELECT id, acronym, name, domain FROM organizations WHERE id = ?", [$user['organization_id']]) : null;
    jsonSuccess(['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'organization_id' => $user['organization_id'], 'org_acronym' => $org['acronym'] ?? null, 'org_name' => $org['name'] ?? null], 'Login realizado');
}

function handleLogout() { session_destroy(); jsonSuccess(null, 'Logout realizado'); }

function handleSessionCheck() {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        $org = $user['organization_id'] ? Database::fetchOne("SELECT id, acronym, name, domain FROM organizations WHERE id = ?", [$user['organization_id']]) : null;
        jsonSuccess(['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['username'], 'role' => $user['role'], 'organization_id' => $user['organization_id'], 'org_acronym' => $org['acronym'] ?? null, 'org_name' => $org['name'] ?? null], 'Sessao ativa');
    }
    jsonResponse(['success' => false, 'error' => 'Not authenticated'], 200);
}

function handleDashboard() {
    $stats = [
        'organizations' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM organizations WHERE is_active = true")['c'],
        'scripts' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM scripts WHERE is_active = true")['c'],
        'variables' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM variable_definitions")['c'],
        'bundles_this_month' => 0, 'stations_online' => 0, 'stations_outdated' => 0, 'recent_stations' => []
    ];
    jsonSuccess($stats);
}

function handleGetOrganizations() {
    $userOrgId = getUserOrgId();
    $orgs = $userOrgId !== null ? Database::fetchAll("SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE is_active = TRUE AND id = ? ORDER BY acronym", [$userOrgId]) : Database::fetchAll("SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE is_active = TRUE ORDER BY acronym");
    foreach ($orgs as &$org) {
        $logo = Database::fetchOne("SELECT ov.value FROM organization_variables ov JOIN variable_definitions vd ON vd.id = ov.variable_id WHERE ov.organization_id = ? AND vd.name = 'LOGO_URL'", [$org['id']]);
        $org['logo_url'] = $logo['value'] ?? null;
    }
    jsonSuccess($orgs);
}

function handleGetOrganization($id) {
    $org = Database::fetchOne("SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE id = ?", [$id]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);
    jsonSuccess($org);
}

function handleCreateOrganization($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    if (empty($input['name']) || empty($input['acronym'])) jsonError('Nome e sigla obrigatorios');

    $acronym = strtoupper(sanitizeInput($input['acronym']));
    $name = sanitizeInput($input['name']);
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $dc_ip = sanitizeInput($input['dc_ip'] ?? '');
    $dns_primario = sanitizeInput($input['dns_primario'] ?? '');
    $dns_secundario = sanitizeInput($input['dns_secundario'] ?? '');
    $homepage = sanitizeInput($input['homepage'] ?? '');
    $proxy_http = sanitizeInput($input['proxy_http'] ?? '');
    $proxy_porta = sanitizeInput($input['proxy_porta'] ?? '');

    if ($domain && (empty($dc_ip) || empty($dns_primario))) jsonError('DC_IP e DNS Primario obrigatorios');
    if (Database::fetchOne("SELECT id FROM organizations WHERE acronym = ?", [$acronym])) jsonError('Sigla ja cadastrada');

    Database::beginTransaction();
    Database::execute("INSERT INTO organizations (name, acronym, domain, description) VALUES (?, ?, ?, ?)", [$name, $acronym, $domain, $description]);
    $orgId = (int)Database::lastInsertId();
    Database::execute("INSERT INTO organization_variables (organization_id, variable_id, value) SELECT ?, id, COALESCE(default_value, '') FROM variable_definitions", [$orgId]);

    $ouPadrao = $domain ? 'OU=Estacoes,' . implode(',', array_map(fn($p) => "DC=$p", explode('.', $domain))) : '';
    $dynamicValues = [
        'DOMINIO' => $domain, 'DOMINIO_NETBIOS' => $acronym, 'OM_ACRONYM' => $acronym, 'OM_NAME' => $name,
        'BASE_URL' => $domain ? "https://softwarelivre.{$domain}" : '',
        'WALLPAPER_URL' => $domain ? "https://softwarelivre.{$domain}/wallpapers/default.jpg" : '',
        'LOGO_URL' => $domain ? "https://softwarelivre.{$domain}/logos/default.png" : '',
        'HOMEPAGE' => $homepage ?: ($domain ? "www.{$domain}" : ''),
        'OCS_SERVER' => $domain ? "http://ocs.{$domain}/ocsinventory" : '',
        'OCS_TAG' => $acronym . '-ESTACOES',
        'PROXY_URL' => $domain ? "http://proxy.{$domain}:8080" : '',
        'NO_PROXY' => $domain ? "localhost,127.0.0.1,{$domain}" : '',
        'OU_PADRAO' => $ouPadrao,
        'REPOSITORY_URL' => $domain ? "https://softwarelivre.{$domain}" : '',
    ];
    if ($dc_ip) $dynamicValues['DC_IP'] = $dc_ip;
    if ($dns_primario) $dynamicValues['DNS_PRIMARIO'] = $dns_primario;
    if ($dns_secundario) $dynamicValues['DNS_SECUNDARIO'] = $dns_secundario;
    if ($proxy_http) $dynamicValues['PROXY_HTTP'] = $proxy_http;
    if ($proxy_porta) $dynamicValues['PROXY_PORTA'] = $proxy_porta;

    foreach ($dynamicValues as $varName => $varValue) {
        Database::execute("UPDATE organization_variables ov SET value = ? FROM variable_definitions vd WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = ?", [$varValue, $orgId, $varName]);
    }
    Database::commit();
    jsonSuccess(Database::fetchOne("SELECT id, name, acronym, domain, description, is_active FROM organizations WHERE id = ?", [$orgId]), 'Organizacao criada');
}

function handleUpdateOrganization($id, $input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $name = sanitizeInput($input['name'] ?? '');
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    if (!$name) jsonError('Nome obrigatorio');
    Database::execute("UPDATE organizations SET name = ?, domain = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$name, $domain, $description, $id]);
    jsonSuccess(null, 'Organizacao atualizada');
}

function handleDeleteOrganization($id) { if (!isAdminGap()) jsonError('Sem permissao', 403); Database::execute("UPDATE organizations SET is_active = FALSE WHERE id = ?", [$id]); jsonSuccess(null, 'Organizacao excluida'); }

function handleGetVariables($orgId) {
    if (!$orgId) $orgId = getUserOrgId();
    $vars = Database::fetchAll("SELECT vd.id, vd.name, vd.description, vd.category, vd.type, vd.is_required, vd.default_value, ov.value as current_value FROM variable_definitions vd LEFT JOIN organization_variables ov ON ov.variable_id = vd.id AND ov.organization_id = ? ORDER BY vd.category, vd.name", [$orgId]);
    jsonSuccess(['variables' => $vars]);
}

function handleUpdateVariables($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    $variables = $input['variables'] ?? [];
    if (!$orgId) jsonError('Organization ID required');
    foreach ($variables as $varId => $value) {
        Database::execute("UPDATE organization_variables SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE organization_id = ? AND variable_id = ?", [$value, $orgId, $varId]);
    }
    jsonSuccess(null, 'Variaveis salvas');
}

// ============ SCRIPT HANDLERS ============

function handleGetScripts() {
    $userOrgId = getUserOrgId();
    $scripts = $userOrgId !== null
        ? Database::fetchAll("SELECT id, name, filename, description, is_core, is_active, organization_id, version, created_at FROM scripts WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?) ORDER BY is_core DESC, name", [$userOrgId])
        : Database::fetchAll("SELECT id, name, filename, description, is_core, is_active, organization_id, version, created_at FROM scripts WHERE is_active = TRUE ORDER BY is_core DESC, name");
    jsonSuccess($scripts);
}

function handleGetScript($id) {
    $script = Database::fetchOne("SELECT id, name, filename, description, content, is_core, is_active, organization_id, version, created_at, updated_at FROM scripts WHERE id = ? AND is_active = TRUE", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    $userOrgId = getUserOrgId();
    if (!$script['is_core'] && $userOrgId !== null && $script['organization_id'] != $userOrgId) jsonError('Sem permissao', 403);
    jsonSuccess($script);
}

function handleCreateScript($input) {
    $name = sanitizeInput($input['name'] ?? '');
    $filename = sanitizeInput($input['filename'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';
    if (!$name || !$filename) jsonError('Nome e arquivo obrigatorios');

    $userOrgId = getUserOrgId();
    if ($userOrgId === null && !isAdminGap()) jsonError('Sem permissao', 403);
    if (Database::fetchOne("SELECT id FROM scripts WHERE filename = ?", [$filename])) jsonError('Arquivo ja existe');

    Database::execute("INSERT INTO scripts (name, filename, description, content, is_core, organization_id, is_active) VALUES (?, ?, ?, ?, FALSE, ?, TRUE)", [$name, $filename, $description, $content, $userOrgId ?: $input['organization_id'] ?? null]);
    jsonSuccess(['id' => Database::lastInsertId()], 'Script criado');
}

function handleUpdateScript($id, $input) {
    $script = Database::fetchOne("SELECT id, is_core, organization_id FROM scripts WHERE id = ?", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    if ($script['is_core']) jsonError('Scripts core nao podem ser alterados', 403);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $script['organization_id'] != $userOrgId) jsonError('Sem permissao', 403);

    $name = sanitizeInput($input['name'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';
    if (!$name) jsonError('Nome obrigatorio');

    Database::execute("UPDATE scripts SET name = ?, description = ?, content = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$name, $description, $content, $id]);
    jsonSuccess(null, 'Script atualizado');
}

function handleDeleteScript($id) {
    $script = Database::fetchOne("SELECT id, is_core, organization_id FROM scripts WHERE id = ?", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    if ($script['is_core']) jsonError('Scripts core nao podem ser excluidos', 403);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $script['organization_id'] != $userOrgId) jsonError('Sem permissao', 403);

    Database::execute("UPDATE scripts SET is_active = FALSE WHERE id = ?", [$id]);
    jsonSuccess(null, 'Script excluido');
}

function handleUploadScript() {
    $userOrgId = getUserOrgId();
    if ($userOrgId === null && !isAdminGap()) jsonError('Sem permissao', 403);
    if (!isset($_FILES['script']) || $_FILES['script']['error'] !== UPLOAD_ERR_OK) jsonError('Nenhum arquivo enviado', 400);

    $file = $_FILES['script'];
    $name = sanitizeInput($_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME));
    $description = sanitizeInput($_POST['description'] ?? '');
    if ($file['size'] > 500 * 1024) jsonError('Arquivo muito grande (max 500KB)', 400);

    $content = file_get_contents($file['tmp_name']);
    $filename = sanitizeInput($file['name']);
    if (Database::fetchOne("SELECT id FROM scripts WHERE filename = ?", [$filename])) jsonError('Arquivo ja existe');

    Database::execute("INSERT INTO scripts (name, filename, description, content, is_core, organization_id, is_active) VALUES (?, ?, ?, ?, FALSE, ?, TRUE)", [$name, $filename, $description, $content, $userOrgId]);
    jsonSuccess(['id' => Database::lastInsertId(), 'filename' => $filename], 'Script enviado');
}

// ============ BUNDLE HANDLERS ============

function handleGenerateBundle($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required');

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) jsonError('Sem permissao', 403);

    $org = Database::fetchOne("SELECT acronym, domain FROM organizations WHERE id = ?", [$orgId]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    $vars = Database::fetchAll("SELECT vd.name, ov.value FROM organization_variables ov JOIN variable_definitions vd ON vd.id = ov.variable_id WHERE ov.organization_id = ?", [$orgId]);
    $scripts = Database::fetchAll("SELECT id, name, filename, content, is_core FROM scripts WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?) ORDER BY is_core DESC, execution_order, name", [$orgId]);

    $bundle = "#!/bin/bash\n# SeederLinux Lite Bundle\n# Organization: {$org['acronym']}\n# Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $bundle .= "# === VARIABLES ===\n";
    foreach ($vars as $v) $bundle .= "export {$v['name']}='" . str_replace("'", "'\\''", $v['value'] ?? '') . "'\n";
    $bundle .= "\n# === SCRIPTS ===\n\n";

    $scriptIds = [];
    foreach ($scripts as $s) {
        $bundle .= "# --- {$s['name']} ({$s['filename']}) ---\n{$s['content']}\n\n";
        $scriptIds[] = $s['id'];
    }

    $filename = "bundle_{$org['acronym']}_" . date('Ymd_His') . ".sh";
    $userId = $_SESSION['user_id'] ?? null;

    Database::execute("INSERT INTO deploy_bundles (organization_id, user_id, filename, content, script_ids, scripts_count, generated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)", [$orgId, $userId, $filename, $bundle, json_encode($scriptIds), count($scripts)]);
    $bundleId = (int)Database::lastInsertId();

    jsonSuccess(['bundle_id' => $bundleId, 'filename' => $filename, 'download_url' => "/api/?action=bundle-by-id&id={$bundleId}", 'scripts_count' => count($scripts)], 'Bundle gerado');
}

function handleDownloadBundle($id) {
    $bundle = Database::fetchOne("SELECT id, filename, content FROM deploy_bundles WHERE id = ?", [$id]);
    if (!$bundle) jsonError('Bundle nao encontrado', 404);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $bundle['filename'] . '"');
    header('Content-Length: ' . strlen($bundle['content']));
    echo $bundle['content'];
    exit;
}

// ============ IMAGE HANDLERS ============

function handleUploadWallpaper() {
    $orgId = (int)($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) jsonError('Sem permissao', 403);

    if (!isset($_FILES['wallpaper']) || $_FILES['wallpaper']['error'] !== UPLOAD_ERR_OK) jsonError('Nenhum arquivo enviado', 400);

    $file = $_FILES['wallpaper'];
    if (!in_array($file['type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) jsonError('Tipo invalido', 400);
    if ($file['size'] > 5 * 1024 * 1024) jsonError('Arquivo muito grande (max 5MB)', 400);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'wallpaper_org' . $orgId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../assets/wallpapers/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) jsonError('Erro ao salvar', 500);

    $wallpaperUrl = '/assets/wallpapers/' . $filename;
    Database::execute("UPDATE organization_variables ov SET value = ? FROM variable_definitions vd WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = 'WALLPAPER_URL'", [$wallpaperUrl, $orgId]);
    jsonSuccess(['url' => $wallpaperUrl, 'filename' => $filename], 'Wallpaper enviado');
}

function handleUploadLogo() {
    $orgId = (int)($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) jsonError('Sem permissao', 403);

    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) jsonError('Nenhum arquivo enviado', 400);

    $file = $_FILES['logo'];
    if (!in_array($file['type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'])) jsonError('Tipo invalido', 400);
    if ($file['size'] > 2 * 1024 * 1024) jsonError('Arquivo muito grande (max 2MB)', 400);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'logo_org' . $orgId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../assets/logos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) jsonError('Erro ao salvar', 500);

    $logoUrl = '/assets/logos/' . $filename;
    Database::execute("UPDATE organization_variables ov SET value = ? FROM variable_definitions vd WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = 'LOGO_URL'", [$logoUrl, $orgId]);
    jsonSuccess(['url' => $logoUrl, 'filename' => $filename], 'Logo enviado');
}

function handleGetWallpapers() {
    $orgId = (int)($_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('org_id required', 400);

    $uploadDir = __DIR__ . '/../assets/wallpapers/';
    $images = [];
    if (is_dir($uploadDir)) {
        foreach (scandir($uploadDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (preg_match('/^wallpaper_org' . $orgId . '_/', $file) || preg_match('/^default\./', $file)) {
                $images[] = ['filename' => $file, 'url' => '/assets/wallpapers/' . $file, 'timestamp' => filemtime($uploadDir . $file)];
            }
        }
    }
    usort($images, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    jsonSuccess(['images' => $images]);
}

function handleGetLogos() {
    $orgId = (int)($_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('org_id required', 400);

    $uploadDir = __DIR__ . '/../assets/logos/';
    $images = [];
    if (is_dir($uploadDir)) {
        foreach (scandir($uploadDir) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (preg_match('/^logo_org' . $orgId . '_/', $file) || preg_match('/^default\./', $file)) {
                $images[] = ['filename' => $file, 'url' => '/assets/logos/' . $file, 'timestamp' => filemtime($uploadDir . $file)];
            }
        }
    }
    usort($images, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    jsonSuccess(['images' => $images]);
}

// ============ USER HANDLERS ============

function handleGetUsers() {
    $users = Database::fetchAll("SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.organization_id, o.acronym as org_acronym FROM users u LEFT JOIN organizations o ON o.id = u.organization_id ORDER BY u.username");
    jsonSuccess($users);
}

function handleCreateUser($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $role = sanitizeInput($input['role'] ?? 'operador_om');
    $organization_id = $input['organization_id'] ?? null;
    $full_name = sanitizeInput($input['full_name'] ?? '');

    if (!$username || !$password) jsonError('Username e senha obrigatorios');
    if (Database::fetchOne("SELECT id FROM users WHERE username = ?", [$username])) jsonError('Username ja existe');

    Database::execute("INSERT INTO users (username, password_hash, full_name, role, organization_id) VALUES (?, ?, ?, ?, ?)", [$username, password_hash($password, PASSWORD_DEFAULT), $full_name, $role, $organization_id]);
    jsonSuccess(null, 'Usuario criado');
}

function handleUpdateUser($id, $input) {
    $username = sanitizeInput($input['username'] ?? '');
    $role = sanitizeInput($input['role'] ?? '');
    $organization_id = $input['organization_id'] ?? null;
    $full_name = sanitizeInput($input['full_name'] ?? '');
    $password = $input['password'] ?? '';

    if ($password) {
        Database::execute("UPDATE users SET username = ?, full_name = ?, role = ?, organization_id = ?, password_hash = ? WHERE id = ?", [$username, $full_name, $role, $organization_id, password_hash($password, PASSWORD_DEFAULT), $id]);
    } else {
        Database::execute("UPDATE users SET username = ?, full_name = ?, role = ?, organization_id = ? WHERE id = ?", [$username, $full_name, $role, $organization_id, $id]);
    }
    jsonSuccess(null, 'Usuario atualizado');
}

function handleDeleteUser($id) { Database::execute("UPDATE users SET is_active = FALSE WHERE id = ?", [$id]); jsonSuccess(null, 'Usuario excluido'); }

function handleGetAuditEvents() {
    $limit = (int)($_GET['limit'] ?? 100);
    $events = Database::fetchAll("SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.ip_address, a.created_at, u.username, u.full_name, o.acronym as org_acronym FROM audit_events a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN organizations o ON o.id = a.organization_id ORDER BY a.created_at DESC LIMIT ?", [$limit]);
    jsonSuccess($events);
}
