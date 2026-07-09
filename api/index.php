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
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

try {
    switch ($action) {
        case 'login': handleLogin($input); break;
        case 'logout': handleLogout(); break;
        case 'session': handleSessionCheck(); break;
        case 'dashboard': requireAuth(); handleDashboard(); break;
        case 'organizations': requireAuth(); handleGetOrganizations(); break;
        case 'organization': requireAuth(); handleGetOrganization($id); break;
        case 'variables': requireAuth(); handleGetVariables($id); break;
        case 'variables-update': requireAuth(); handleUpdateVariables($input); break;
        case 'scripts': requireAuth(); handleGetScripts(); break;
        case 'bundle': requireAuth(); handleGenerateBundle($input); break;
        case 'bundle-by-id': requireAuth(); handleDownloadBundle($id); break;
        case 'users': requireAuth(); handleGetUsers(); break;
        case 'user': requireAuth(); handleUpdateUser($id, $input); break;
        case 'wallpapers': requireAuth(); handleGetWallpapers($id); break;
        case 'logos': requireAuth(); handleGetLogos($id); break;
        default: jsonError('Endpoint invalido', 404);
    }
} catch (Exception $e) { error_log('API Error: ' . $e->getMessage()); jsonError('Erro interno', 500); }

// === HANDLERS ===

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

    jsonSuccess(['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'organization_id' => $user['organization_id']]);
}

function handleLogout() { session_destroy(); jsonSuccess(null, 'Logout realizado'); }

function handleSessionCheck() {
    if (isset($_SESSION['user_id'])) {
        $org = $_SESSION['organization_id'] ? Database::fetchOne("SELECT acronym, name FROM organizations WHERE id = ?", [$_SESSION['organization_id']]) : null;
        jsonSuccess(['id' => $_SESSION['user_id'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role'], 'organization_id' => $_SESSION['organization_id'], 'org_acronym' => $org['acronym'] ?? null]);
    }
    jsonResponse(['success' => false], 200);
}

function handleDashboard() {
    $stats = [
        'organizations' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM organizations WHERE is_active = true")['c'],
        'scripts' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM scripts WHERE is_active = true")['c'],
        'variables' => (int)Database::fetchOne("SELECT COUNT(*) as c FROM variable_definitions")['c'],
        'bundles_this_month' => 0,
        'stations_online' => 0,
        'stations_outdated' => 0,
        'recent_stations' => []
    ];
    jsonSuccess($stats);
}

function handleGetOrganizations() {
    $userOrgId = getUserOrgId();
    $orgs = $userOrgId ? Database::fetchAll("SELECT id, name, acronym, domain, description FROM organizations WHERE is_active = TRUE AND id = ? ORDER BY acronym", [$userOrgId]) : Database::fetchAll("SELECT id, name, acronym, domain, description FROM organizations WHERE is_active = TRUE ORDER BY acronym");
    foreach ($orgs as &$org) {
        $logo = Database::fetchOne("SELECT ov.value FROM organization_variables ov JOIN variable_definitions vd ON vd.id = ov.variable_id WHERE ov.organization_id = ? AND vd.name = 'LOGO_URL'", [$org['id']]);
        $org['logo_url'] = $logo['value'] ?? null;
    }
    jsonSuccess($orgs);
}

function handleGetOrganization($id) {
    $org = Database::fetchOne("SELECT id, name, acronym, domain, description FROM organizations WHERE id = ?", [$id]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);
    jsonSuccess($org);
}

// FIX: Removed vd.options from query - column doesn't exist
function handleGetVariables($orgId) {
    if (!$orgId) $orgId = getUserOrgId();
    if (!$orgId) jsonError('Organization ID required', 400);

    // Query uses only existing columns: id, name, description, type, category, is_required, default_value
    $vars = Database::fetchAll(
        "SELECT vd.id, vd.name, vd.description, vd.category, vd.type, vd.is_required, vd.default_value, ov.value as current_value
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.variable_id = vd.id AND ov.organization_id = ?
         ORDER BY vd.category, vd.name",
        [$orgId]
    );

    jsonSuccess(['variables' => $vars, 'organization_id' => $orgId]);
}

function handleUpdateVariables($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    $variables = $input['variables'] ?? [];
    if (!$orgId) jsonError('Organization ID required');

    foreach ($variables as $varId => $value) {
        Database::execute("UPDATE organization_variables SET value = CURRENT_TIMESTAMP WHERE organization_id = ? AND variable_id = ?", [$value, $orgId, $varId]);
    }
    jsonSuccess(null, 'Variaveis salvas');
}

function handleGetScripts() {
    $userOrgId = getUserOrgId();
    $scripts = $userOrgId ? Database::fetchAll("SELECT id, name, filename, description, is_core FROM scripts WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?)", [$userOrgId]) : Database::fetchAll("SELECT id, name, filename, description, is_core FROM scripts WHERE is_active = TRUE");
    jsonSuccess($scripts);
}

function handleGenerateBundle($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required');

    $org = Database::fetchOne("SELECT acronym FROM organizations WHERE id = ?", [$orgId]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    $vars = Database::fetchAll("SELECT vd.name, ov.value FROM organization_variables ov JOIN variable_definitions vd ON vd.id = ov.variable_id WHERE ov.organization_id = ?", [$orgId]);
    $scripts = Database::fetchAll("SELECT id, name, filename, content FROM scripts WHERE is_active = TRUE ORDER BY is_core DESC, name");

    $bundle = "#!/bin/bash\n# SeederLinux Lite Bundle\n# Organization: {$org['acronym']}\n# Generated: " . date('Y-m-d H:i:s') . "\n\n# === VARIABLES ===\n";
    foreach ($vars as $v) $bundle .= "export {$v['name']}='" . str_replace("'", "'\\''", $v['value'] ?? '') . "'\n";
    $bundle .= "\n# === SCRIPTS ===\n\n";

    foreach ($scripts as $s) {
        $scriptContent = substituir_placeholders($s['content'], $orgId);
        $bundle .= "# --- {$s['name']} ---\n{$scriptContent}\n\n";
    }

    $filename = "bundle_{$org['acronym']}_" . date('Ymd_His') . ".sh";
    Database::execute("INSERT INTO deploy_bundles (organization_id, filename, content, scripts_count, generated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)", [$orgId, $filename, $bundle, count($scripts)]);
    $bundleId = (int)Database::lastInsertId();

    jsonSuccess(['bundle_id' => $bundleId, 'filename' => $filename, 'download_url' => "/api/?action=bundle-by-id&id={$bundleId}"]);
}

function handleDownloadBundle($id) {
    $bundle = Database::fetchOne("SELECT filename, content FROM deploy_bundles WHERE id = ?", [$id]);
    if (!$bundle) jsonError('Bundle nao encontrado', 404);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $bundle['filename'] . '"');
    echo $bundle['content'];
    exit;
}

function handleGetUsers() {
    $users = Database::fetchAll("SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.organization_id, o.acronym as org_acronym FROM users u LEFT JOIN organizations o ON o.id = u.organization_id ORDER BY u.username");
    jsonSuccess($users);
}

function handleUpdateUser($id, $input) {
    $data = [
        sanitizeInput($input['username'] ?? ''),
        sanitizeInput($input['full_name'] ?? ''),
        sanitizeInput($input['role'] ?? 'operador_om'),
        $input['organization_id'] ?? null
    ];
    Database::execute("UPDATE users SET username = ?, full_name = ?, role = ?, organization_id = ? WHERE id = ?", [...$data, $id]);
    jsonSuccess(null, 'Usuario atualizado');
}

function handleGetWallpapers($orgId) {
    $images = [];
    $dir = __DIR__ . '/../assets/wallpapers/';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && !is_dir($dir . $f)) $images[] = ['url' => '/assets/wallpapers/' . $f, 'filename' => $f];
        }
    }
    jsonSuccess(['images' => $images]);
}

function handleGetLogos($orgId) {
    $images = [];
    $dir = __DIR__ . '/../assets/logos/';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && !is_dir($dir . $f)) $images[] = ['url' => '/assets/logos/' . $f, 'filename' => $f];
        }
    }
    jsonSuccess(['images' => $images]);
}
