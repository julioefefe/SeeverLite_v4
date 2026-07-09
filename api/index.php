<?php
/**
 * SeederLinux Lite - API
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$method = $_SERVER['REQUEST_METHOD'];

// Parse JSON input for POST/PUT
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
        // Auth
        case 'login':
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleLogin($input);
            break;
        case 'logout':
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleLogout();
            break;
        case 'session':
            handleSessionCheck();
            break;

        // Dashboard
        case 'dashboard':
            requireAuth();
            handleDashboard();
            break;

        // Organizations
        case 'organizations':
            requireAuth();
            if ($method === 'GET') {
                handleGetOrganizations();
            } elseif ($method === 'POST') {
                handleCreateOrganization($input);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;
        case 'organization':
            requireAuth();
            if (!$id) jsonError('Organization ID required', 400);
            if ($method === 'GET') {
                handleGetOrganization($id);
            } elseif ($method === 'PUT') {
                handleUpdateOrganization($id, $input);
            } elseif ($method === 'DELETE') {
                handleDeleteOrganization($id);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        // Variables
        case 'variables':
            requireAuth();
            if ($method === 'GET') {
                handleGetVariables($id);
            } elseif ($method === 'POST') {
                handleUpdateVariables($input);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;
        case 'variables-update':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleUpdateVariables($input);
            break;

        // Scripts
        case 'scripts':
            requireAuth();
            handleGetScripts();
            break;

        // Users
        case 'users':
            requireAuth();
            if ($method === 'GET') {
                handleGetUsers();
            } elseif ($method === 'POST') {
                handleCreateUser($input);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;
        case 'user':
            requireAuth();
            if (!$id) jsonError('User ID required', 400);
            if ($method === 'PUT') {
                handleUpdateUser($id, $input);
            } elseif ($method === 'DELETE') {
                handleDeleteUser($id);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        // Audit
        case 'audit':
            requireAuth();
            handleGetAuditEvents();
            break;

        // Bundle
        case 'bundle':
            requireAuth();
            if ($method === 'POST') {
                handleGenerateBundle($input);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        // Upload
        case 'upload-wallpaper':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleUploadWallpaper();
            break;
        case 'upload-logo':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleUploadLogo();
            break;

        default:
            jsonError('Invalid endpoint', 404);
    }
} catch (RuntimeException $e) {
    jsonError($e->getMessage());
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    jsonError('Internal server error', 500);
}

// ============ HANDLERS ============

function handleLogin($input) {
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonError('Username e senha obrigatorios');
    }

    $user = Database::fetchOne(
        "SELECT id, username, password_hash, full_name, role, organization_id, is_active FROM users WHERE username = ?",
        [$username]
    );

    if (!$user || !$user['is_active']) {
        jsonError('Credenciais invalidas', 401);
    }

    if (!password_verify($password, $user['password_hash'])) {
        jsonError('Credenciais invalidas', 401);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['organization_id'] = $user['organization_id'];

    $org = null;
    if ($user['organization_id']) {
        $org = Database::fetchOne("SELECT id, acronym, name, domain FROM organizations WHERE id = ?", [$user['organization_id']]);
    }

    jsonSuccess([
        'id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'organization_id' => $user['organization_id'],
        'org_acronym' => $org['acronym'] ?? null,
        'org_name' => $org['name'] ?? null
    ], 'Login realizado');
}

function handleLogout() {
    session_destroy();
    jsonSuccess(null, 'Logout realizado');
}

function handleSessionCheck() {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        jsonSuccess($user, 'Sessao ativa');
    } else {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 200);
    }
}

function handleDashboard() {
    $orgId = getUserOrgId();
    $where = $orgId ? "WHERE id = $orgId" : '';

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

    if ($userOrgId !== null) {
        $orgs = Database::fetchAll(
            "SELECT id, name, acronym, domain, description, is_active, created_at
             FROM organizations WHERE is_active = TRUE AND id = ? ORDER BY acronym",
            [$userOrgId]
        );
    } else {
        $orgs = Database::fetchAll(
            "SELECT id, name, acronym, domain, description, is_active, created_at
             FROM organizations WHERE is_active = TRUE ORDER BY acronym"
        );
    }

    // Add logo_url from variables
    foreach ($orgs as &$org) {
        $logo = Database::fetchOne(
            "SELECT ov.value FROM organization_variables ov
             JOIN variable_definitions vd ON vd.id = ov.variable_id
             WHERE ov.organization_id = ? AND vd.name = 'LOGO_URL'",
            [$org['id']]
        );
        $org['logo_url'] = $logo['value'] ?? null;
    }

    jsonSuccess($orgs);
}

function handleGetOrganization($id) {
    $org = Database::fetchOne(
        "SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE id = ?",
        [$id]
    );
    if (!$org) jsonError('Organizacao nao encontrada', 404);
    jsonSuccess($org);
}

function handleCreateOrganization($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    if (empty($input['name']) || empty($input['acronym'])) {
        jsonError('Nome e sigla obrigatorios');
    }

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
    $displayName = sanitizeInput($input['display_name'] ?? $name);

    if ($domain) {
        if (empty($dc_ip)) jsonError('DC_IP obrigatorio quando dominio informado');
        if (empty($dns_primario)) jsonError('DNS Primario obrigatorio');
    }

    $existing = Database::fetchOne("SELECT id FROM organizations WHERE acronym = ?", [$acronym]);
    if ($existing) jsonError('Sigla ja cadastrada');

    try {
        Database::beginTransaction();

        Database::execute(
            "INSERT INTO organizations (name, acronym, domain, description) VALUES (?, ?, ?, ?)",
            [$name, $acronym, $domain, $description]
        );
        $orgId = (int)Database::lastInsertId();

        // Copy default variables
        Database::execute(
            "INSERT INTO organization_variables (organization_id, variable_id, value)
             SELECT ?, id, COALESCE(default_value, '') FROM variable_definitions",
            [$orgId]
        );

        // Generate OU_PADRAO
        $ouPadrao = '';
        if ($domain) {
            $dcParts = explode('.', $domain);
            $ouPadrao = 'OU=Estacoes,' . implode(',', array_map(function($p) { return "DC=$p"; }, $dcParts));
        }

        // Dynamic values
        $dynamicValues = [
            'DOMINIO' => $domain,
            'DOMINIO_NETBIOS' => $acronym,
            'OM_ACRONYM' => $acronym,
            'OM_NAME' => $name,
            'DISPLAY_NAME' => $displayName,
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
            Database::execute(
                "UPDATE organization_variables ov SET value = ?
                 FROM variable_definitions vd
                 WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = ?",
                [$varValue, $orgId, $varName]
            );
        }

        Database::commit();

        log_event("Organization created: $acronym", 'INFO');

        $org = Database::fetchOne("SELECT id, name, acronym, domain, description, is_active FROM organizations WHERE id = ?", [$orgId]);
        jsonSuccess($org, 'Organizacao criada');
    } catch (Exception $e) {
        Database::rollback();
        throw new RuntimeException('Erro ao criar: ' . $e->getMessage());
    }
}

function handleUpdateOrganization($id, $input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $name = sanitizeInput($input['name'] ?? '');
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');

    if (!$name) jsonError('Nome obrigatorio');

    Database::execute(
        "UPDATE organizations SET name = ?, domain = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$name, $domain, $description, $id]
    );

    jsonSuccess(null, 'Organizacao atualizada');
}

function handleDeleteOrganization($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    Database::execute("UPDATE organizations SET is_active = FALSE WHERE id = ?", [$id]);
    jsonSuccess(null, 'Organizacao excluida');
}

function handleGetVariables($orgId) {
    if (!$orgId) {
        $orgId = getUserOrgId();
    }

    $vars = Database::fetchAll(
        "SELECT vd.id, vd.name, vd.description, vd.category, vd.type, vd.is_required, vd.default_value,
                ov.value as current_value
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.variable_id = vd.id AND ov.organization_id = ?
         ORDER BY vd.category, vd.name",
        [$orgId]
    );

    jsonSuccess(['variables' => $vars]);
}

function handleUpdateVariables($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    $variables = $input['variables'] ?? [];

    if (!$orgId) jsonError('Organization ID required');

    foreach ($variables as $varId => $value) {
        Database::execute(
            "UPDATE organization_variables SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE organization_id = ? AND variable_id = ?",
            [$value, $orgId, $varId]
        );
    }

    jsonSuccess(null, 'Variaveis salvas');
}

function handleGetScripts() {
    $scripts = Database::fetchAll(
        "SELECT id, name, filename, description, content, is_core, is_active FROM scripts WHERE is_active = TRUE ORDER BY is_core DESC, name"
    );
    jsonSuccess($scripts);
}

function handleGetUsers() {
    $users = Database::fetchAll(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.organization_id, o.acronym as org_acronym
         FROM users u LEFT JOIN organizations o ON o.id = u.organization_id ORDER BY u.username"
    );
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

    $existing = Database::fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
    if ($existing) jsonError('Username ja existe');

    $hash = password_hash($password, PASSWORD_DEFAULT);

    Database::execute(
        "INSERT INTO users (username, password_hash, full_name, role, organization_id) VALUES (?, ?, ?, ?, ?)",
        [$username, $hash, $full_name, $role, $organization_id]
    );

    jsonSuccess(null, 'Usuario criado');
}

function handleUpdateUser($id, $input) {
    $username = sanitizeInput($input['username'] ?? '');
    $role = sanitizeInput($input['role'] ?? '');
    $organization_id = $input['organization_id'] ?? null;
    $full_name = sanitizeInput($input['full_name'] ?? '');
    $password = $input['password'] ?? '';

    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        Database::execute(
            "UPDATE users SET username = ?, full_name = ?, role = ?, organization_id = ?, password_hash = ? WHERE id = ?",
            [$username, $full_name, $role, $organization_id, $hash, $id]
        );
    } else {
        Database::execute(
            "UPDATE users SET username = ?, full_name = ?, role = ?, organization_id = ? WHERE id = ?",
            [$username, $full_name, $role, $organization_id, $id]
        );
    }

    jsonSuccess(null, 'Usuario atualizado');
}

function handleDeleteUser($id) {
    Database::execute("UPDATE users SET is_active = FALSE WHERE id = ?", [$id]);
    jsonSuccess(null, 'Usuario excluido');
}

function handleGetAuditEvents() {
    $limit = (int)($_GET['limit'] ?? 100);
    $events = Database::fetchAll(
        "SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.ip_address, a.created_at,
                u.username, u.full_name, o.acronym as org_acronym
         FROM audit_events a
         LEFT JOIN users u ON u.id = a.user_id
         LEFT JOIN organizations o ON o.id = a.organization_id
         ORDER BY a.created_at DESC LIMIT ?",
        [$limit]
    );
    jsonSuccess($events);
}

function handleGenerateBundle($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required');

    // Simple bundle generation - return success with placeholder URL
    $filename = 'bundle_' . $orgId . '_' . date('YmdHis') . '.sh';

    jsonSuccess(['url' => '/downloads/' . $filename, 'filename' => $filename]);
}

function handleUploadWallpaper() {
    $orgId = (int)($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissao', 403);
    }

    if (!isset($_FILES['wallpaper']) || $_FILES['wallpaper']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Nenhum arquivo enviado', 400);
    }

    $file = $_FILES['wallpaper'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($file['type'], $allowedTypes)) jsonError('Tipo invalido', 400);
    if ($file['size'] > 5 * 1024 * 1024) jsonError('Arquivo muito grande (max 5MB)', 400);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'wallpaper_org' . $orgId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../assets/wallpapers/';
    $filepath = $uploadDir . $filename;

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonError('Erro ao salvar', 500);
    }

    $wallpaperUrl = '/assets/wallpapers/' . $filename;

    // Update WALLPAPER_URL variable
    Database::execute(
        "UPDATE organization_variables ov SET value = ?
         FROM variable_definitions vd
         WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = 'WALLPAPER_URL'",
        [$wallpaperUrl, $orgId]
    );

    jsonSuccess(['url' => $wallpaperUrl, 'filename' => $filename], 'Wallpaper enviado');
}

function handleUploadLogo() {
    $orgId = (int)($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissao', 403);
    }

    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Nenhum arquivo enviado', 400);
    }

    $file = $_FILES['logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

    if (!in_array($file['type'], $allowedTypes)) jsonError('Tipo invalido', 400);
    if ($file['size'] > 2 * 1024 * 1024) jsonError('Arquivo muito grande (max 2MB)', 400);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'logo_org' . $orgId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../assets/logos/';
    $filepath = $uploadDir . $filename;

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonError('Erro ao salvar', 500);
    }

    $logoUrl = '/assets/logos/' . $filename;

    // Update LOGO_URL variable
    Database::execute(
        "UPDATE organization_variables ov SET value = ?
         FROM variable_definitions vd
         WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = 'LOGO_URL'",
        [$logoUrl, $orgId]
    );

    jsonSuccess(['url' => $logoUrl, 'filename' => $filename], 'Logo enviado');
}

function jsonSuccess($data, $message = '') {
    jsonResponse(['success' => true, 'data' => $data, 'message' => $message], 200);
}

function jsonError($message, $code = 400) {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sanitizeInput($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function requireAuth() {
    if (!isLoggedIn()) {
        jsonError('Autenticacao necessaria', 401);
    }
}

function isAdminGap() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin_gap';
}

function getUserOrgId() {
    return $_SESSION['organization_id'] ?? null;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'organization_id' => $_SESSION['organization_id'] ?? null
    ];
}

function log_event($msg, $level = 'INFO') {
    error_log("[$level] $msg");
}
