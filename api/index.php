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
$orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : null;
$method = $_SERVER['REQUEST_METHOD'];

// Parse input
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
            if ($method === 'GET') handleGetOrganizations();
            elseif ($method === 'POST') handleCreateOrganization($input);
            else jsonError('Method not allowed', 405);
            break;
        case 'organization':
            requireAuth();
            if (!$id) jsonError('ID required', 400);
            if ($method === 'GET') handleGetOrganization($id);
            elseif ($method === 'PUT') handleUpdateOrganization($id, $input);
            elseif ($method === 'DELETE') handleDeleteOrganization($id);
            else jsonError('Method not allowed', 405);
            break;

        // Variables - NUNCA inclui vd.options
        case 'variables':
            requireAuth();
            handleGetVariables($id);
            break;
        case 'variables-update':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleUpdateVariables($input);
            break;
        case 'variable-add':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleAddVariable($input);
            break;

        // Scripts
        case 'scripts':
            requireAuth();
            handleGetScripts($orgId);
            break;
        case 'script':
            requireAuth();
            if ($method === 'GET' && $id) handleGetScript($id);
            elseif ($method === 'PUT' && $id) handleUpdateScript($id, $input);
            elseif ($method === 'DELETE' && $id) handleDeleteScript($id);
            elseif ($method === 'POST') handleCreateScript($input);
            else jsonError('Method not allowed', 405);
            break;
        case 'script-upload':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleUploadScript();
            break;

        // Bundle
        case 'generate-bundle':
            requireAuth();
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleGenerateBundle($input);
            break;
        case 'bundle-by-id':
            requireAuth();
            handleDownloadBundle($id);
            break;

        // Users
        case 'users':
            requireAuth();
            if ($method === 'GET') handleGetUsers();
            elseif ($method === 'POST') handleCreateUser($input);
            else jsonError('Method not allowed', 405);
            break;
        case 'user':
            requireAuth();
            if (!$id) jsonError('ID required', 400);
            if ($method === 'PUT') handleUpdateUser($id, $input);
            elseif ($method === 'DELETE') handleDeleteUser($id);
            elseif ($method === 'POST') handleToggleUserStatus($id);
            else jsonError('Method not allowed', 405);
            break;

        // Stations
        case 'stations':
            requireAuth();
            handleGetStations($orgId);
            break;
        case 'checkin':
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleStationCheckin($input);
            break;

        // Audit
        case 'audit':
            requireAuth();
            handleGetAuditEvents();
            break;

        // Uploads
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
        case 'wallpapers':
            requireAuth();
            handleGetWallpapers($orgId);
            break;
        case 'logos':
            requireAuth();
            handleGetLogos($orgId);
            break;

        default:
            jsonError('Endpoint invalido: ' . $action, 404);
    }
} catch (RuntimeException $e) {
    jsonError($e->getMessage());
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    jsonError('Erro interno do servidor', 500);
}

// ============ HANDLERS ============

function handleLogin($input) {
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonError('Username e senha obrigatorios');
    }

    $user = Database::fetchOne(
        "SELECT id, username, password_hash, full_name, email, role, organization_id, is_active FROM users WHERE username = ?",
        [$username]
    );

    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        jsonError('Credenciais invalidas', 401);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['organization_id'] = $user['organization_id'];

    $org = $user['organization_id'] ? Database::fetchOne("SELECT id, acronym, name, domain FROM organizations WHERE id = ?", [$user['organization_id']]) : null;

    log_audit('LOGIN', 'users', $user['id'], ['username' => $username]);

    jsonSuccess([
        'id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'organization_id' => $user['organization_id'],
        'org_acronym' => $org['acronym'] ?? null,
        'org_name' => $org['name'] ?? null
    ], 'Login realizado com sucesso');
}

function handleLogout() {
    log_audit('LOGOUT', 'users', $_SESSION['user_id'] ?? null);
    session_destroy();
    jsonSuccess(null, 'Logout realizado');
}

function handleSessionCheck() {
    if (isset($_SESSION['user_id'])) {
        $org = $_SESSION['organization_id'] ? Database::fetchOne("SELECT id, acronym, name, domain FROM organizations WHERE id = ?", [$_SESSION['organization_id']]) : null;
        jsonSuccess([
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'organization_id' => $_SESSION['organization_id'],
            'org_acronym' => $org['acronym'] ?? null,
            'org_name' => $org['name'] ?? null
        ], 'Sessao ativa');
    }
    jsonResponse(['success' => false, 'error' => 'Not authenticated'], 200);
}

function handleDashboard() {
    $userOrgId = getUserOrgId();
    $isAdmin = isAdminGap();

    $stats = [
        'organizations' => 0,
        'scripts' => 0,
        'variables' => 0,
        'bundles_this_month' => 0,
        'stations_online' => 0,
        'stations_outdated' => 0,
        'recent_stations' => [],
        'recent_orgs' => []
    ];

    // Organizations count
    $stats['organizations'] = (int)Database::fetchOne("SELECT COUNT(*) as c FROM organizations WHERE is_active = true")['c'];

    // Scripts count
    $stats['scripts'] = (int)Database::fetchOne("SELECT COUNT(*) as c FROM scripts WHERE is_active = true")['c'];

    // Variables count
    $stats['variables'] = (int)Database::fetchOne("SELECT COUNT(*) as c FROM variable_definitions")['c'];

    // Bundles this month
    $stats['bundles_this_month'] = (int)Database::fetchOne("SELECT COUNT(*) as c FROM deploy_bundles WHERE generated_at >= date_trunc('month', CURRENT_DATE)")['c'];

    // Stations online (checked in last 2 hours)
    $twoHoursAgo = date('Y-m-d H:i:s', strtotime('-2 hours'));
    if ($userOrgId !== null) {
        $stats['stations_online'] = (int)Database::fetchOne("SELECT COUNT(*) as c FROM stations WHERE organization_id = ? AND last_checkin >= ?", [$userOrgId, $twoHoursAgo])['c'];
    } else {
        $stats['stations_online'] = (int)Database::fetchOne("SELECT COUNT(*) as c FROM stations WHERE last_checkin >= ?", [$twoHoursAgo])['c'];
    }

    // Stations outdated
    if ($userOrgId !== null) {
        $stats['stations_outdated'] = (int)Database::fetchOne(
            "SELECT COUNT(*) as c FROM stations s
             JOIN organizations o ON o.id = s.organization_id
             WHERE s.organization_id = ? AND s.configuration_serial < o.serial_config",
            [$userOrgId]
        )['c'];
    } else {
        $stats['stations_outdated'] = (int)Database::fetchOne(
            "SELECT COUNT(*) as c FROM stations s
             JOIN organizations o ON o.id = s.organization_id
             WHERE s.configuration_serial < o.serial_config"
        )['c'];
    }

    // Recent stations
    if ($userOrgId !== null) {
        $stats['recent_stations'] = Database::fetchAll(
            "SELECT hostname, ip_address, last_checkin, o.acronym as org_acronym,
                    CASE WHEN s.configuration_serial >= o.serial_config THEN 'Atualizado' ELSE 'Desatualizado' END as status
             FROM stations s
             JOIN organizations o ON o.id = s.organization_id
             WHERE s.organization_id = ?
             ORDER BY last_checkin DESC NULLS LAST LIMIT 10",
            [$userOrgId]
        );
    } else {
        $stats['recent_stations'] = Database::fetchAll(
            "SELECT hostname, ip_address, last_checkin, o.acronym as org_acronym,
                    CASE WHEN s.configuration_serial >= o.serial_config THEN 'Atualizado' ELSE 'Desatualizado' END as status
             FROM stations s
             JOIN organizations o ON o.id = s.organization_id
             ORDER BY last_checkin DESC NULLS LAST LIMIT 10"
        );
    }

    // Recent orgs
    $stats['recent_orgs'] = Database::fetchAll(
        "SELECT id, name, acronym, domain FROM organizations WHERE is_active = true ORDER BY created_at DESC LIMIT 5"
    );

    jsonSuccess($stats);
}

function handleGetOrganizations() {
    $userOrgId = getUserOrgId();
    $isAdmin = isAdminGap();

    if ($userOrgId !== null && !$isAdmin) {
        $orgs = Database::fetchAll(
            "SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE is_active = TRUE AND id = ? ORDER BY acronym",
            [$userOrgId]
        );
    } else {
        $orgs = Database::fetchAll(
            "SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE is_active = TRUE ORDER BY acronym"
        );
    }

    // Add logo URL to each org
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
    $org = Database::fetchOne("SELECT id, name, acronym, domain, description, is_active, created_at FROM organizations WHERE id = ?", [$id]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    $logo = Database::fetchOne(
        "SELECT ov.value FROM organization_variables ov
         JOIN variable_definitions vd ON vd.id = ov.variable_id
         WHERE ov.organization_id = ? AND vd.name = 'LOGO_URL'",
        [$id]
    );
    $org['logo_url'] = $logo['value'] ?? null;

    jsonSuccess($org);
}

function handleCreateOrganization($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $name = sanitizeInput($input['name'] ?? '');
    $acronym = strtoupper(sanitizeInput($input['acronym'] ?? ''));
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $dcIp = sanitizeInput($input['dc_ip'] ?? '');
    $dnsPrimario = sanitizeInput($input['dns_primario'] ?? '');
    $dnsSecundario = sanitizeInput($input['dns_secundario'] ?? '');
    $proxyHttp = sanitizeInput($input['proxy_http'] ?? '');
    $proxyPorta = sanitizeInput($input['proxy_porta'] ?? '');

    if (empty($name) || empty($acronym)) jsonError('Nome e sigla obrigatorios');
    if ($domain && (empty($dcIp) || empty($dnsPrimario))) {
        jsonError('DC_IP e DNS Primario obrigatorios quando dominio informado');
    }

    // Check if acronym already exists
    if (Database::fetchOne("SELECT id FROM organizations WHERE acronym = ?", [$acronym])) {
        jsonError('Sigla ja cadastrada');
    }

    Database::beginTransaction();

    Database::execute(
        "INSERT INTO organizations (name, acronym, domain, description) VALUES (?, ?, ?, ?)",
        [$name, $acronym, $domain, $description]
    );

    $newOrgId = (int)Database::lastInsertId();

    // Create default variable values for this org
    Database::execute(
        "INSERT INTO organization_variables (organization_id, variable_id, value)
         SELECT ?, id, COALESCE(default_value, '') FROM variable_definitions",
        [$newOrgId]
    );

    // Generate dynamic values
    generateDefaultVariables($newOrgId, $name, $acronym, $domain, $dcIp, $dnsPrimario, $dnsSecundario, $proxyHttp, $proxyPorta);

    Database::commit();

    log_audit('CREATE', 'organizations', $newOrgId, ['name' => $name, 'acronym' => $acronym]);

    jsonSuccess(
        Database::fetchOne("SELECT id, name, acronym, domain, description FROM organizations WHERE id = ?", [$newOrgId]),
        'Organizacao criada com sucesso'
    );
}

function handleUpdateOrganization($id, $input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $name = sanitizeInput($input['name'] ?? '');
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');

    if (empty($name)) jsonError('Nome obrigatorio');

    Database::execute(
        "UPDATE organizations SET name = ?, domain = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$name, $domain, $description, $id]
    );

    log_audit('UPDATE', 'organizations', $id, ['name' => $name]);
    jsonSuccess(null, 'Organizacao atualizada');
}

function handleDeleteOrganization($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $org = Database::fetchOne("SELECT acronym FROM organizations WHERE id = ?", [$id]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    Database::execute("UPDATE organizations SET is_active = FALSE WHERE id = ?", [$id]);

    log_audit('DELETE', 'organizations', $id, ['acronym' => $org['acronym']]);
    jsonSuccess(null, 'Organizacao excluida');
}

// VARIABLES - NUNCA inclui vd.options
function handleGetVariables($orgId) {
    if (!$orgId) $orgId = getUserOrgId();
    if (!$orgId) jsonError('Organization ID required', 400);

    // Query usa APENAS colunas existentes: id, name, description, type, category, is_required, default_value
    // NUNCA vd.options
    $vars = Database::fetchAll(
        "SELECT vd.id, vd.name, vd.description, vd.category, vd.type, vd.is_required, vd.default_value,
                ov.value as current_value
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
        Database::execute(
            "UPDATE organization_variables SET value = ?, updated_at = CURRENT_TIMESTAMP
             WHERE organization_id = ? AND variable_id = ?",
            [$value, $orgId, $varId]
        );
    }

    log_audit('UPDATE', 'variables', null, ['organization_id' => $orgId, 'count' => count($variables)]);
    jsonSuccess(null, 'Variaveis salvas com sucesso');
}

function handleAddVariable($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $name = strtoupper(sanitizeInput($input['name'] ?? ''));
    $type = sanitizeInput($input['type'] ?? 'text');
    $value = sanitizeInput($input['value'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $category = sanitizeInput($input['category'] ?? 'generic');
    $isRequired = isset($input['is_required']) && $input['is_required'] ? true : false;

    if (empty($name)) jsonError('Nome da variavel obrigatorio');

    if (Database::fetchOne("SELECT id FROM variable_definitions WHERE name = ?", [$name])) {
        jsonError('Variavel ja existe');
    }

    Database::execute(
        "INSERT INTO variable_definitions (name, description, type, category, is_required, default_value)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$name, $description, $type, $category, $isRequired, $value]
    );

    $varId = (int)Database::lastInsertId();

    // Add to all organizations
    $orgs = Database::fetchAll("SELECT id FROM organizations WHERE is_active = true");
    foreach ($orgs as $org) {
        Database::execute(
            "INSERT INTO organization_variables (organization_id, variable_id, value) VALUES (?, ?, ?)",
            [$org['id'], $varId, $value]
        );
    }

    log_audit('CREATE', 'variable_definitions', $varId, ['name' => $name]);
    jsonSuccess(['id' => $varId], 'Variavel criada');
}

// SCRIPTS
function handleGetScripts($orgId) {
    $userOrgId = getUserOrgId();
    $isAdmin = isAdminGap();

    if ($userOrgId !== null && !$isAdmin) {
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, description, is_core, is_active, organization_id, version, created_at
             FROM scripts
             WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?)
             ORDER BY is_core DESC, name",
            [$userOrgId]
        );
    } else {
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, description, is_core, is_active, organization_id, version, created_at
             FROM scripts
             WHERE is_active = TRUE
             ORDER BY is_core DESC, name"
        );
    }

    jsonSuccess($scripts);
}

function handleGetScript($id) {
    $script = Database::fetchOne(
        "SELECT id, name, filename, description, content, is_core, is_active, organization_id, version, created_at, updated_at
         FROM scripts WHERE id = ? AND is_active = TRUE",
        [$id]
    );

    if (!$script) jsonError('Script nao encontrado', 404);

    $userOrgId = getUserOrgId();
    if (!$script['is_core'] && $userOrgId !== null && $script['organization_id'] != $userOrgId) {
        jsonError('Sem permissao', 403);
    }

    jsonSuccess($script);
}

function handleCreateScript($input) {
    $name = sanitizeInput($input['name'] ?? '');
    $filename = sanitizeInput($input['filename'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';
    $isCore = isset($input['is_core']) && $input['is_core'] ? true : false;

    if (empty($name) || empty($filename)) jsonError('Nome e arquivo obrigatorios');

    $userOrgId = getUserOrgId();
    if (!$isCore && $userOrgId === null && !isAdminGap()) {
        jsonError('Sem permissao para criar scripts', 403);
    }

    if (Database::fetchOne("SELECT id FROM scripts WHERE filename = ?", [$filename])) {
        jsonError('Arquivo ja existe');
    }

    Database::execute(
        "INSERT INTO scripts (name, filename, description, content, is_core, organization_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, TRUE)",
        [$name, $filename, $description, $content, $isCore, $userOrgId ?: null]
    );

    $scriptId = (int)Database::lastInsertId();
    log_audit('CREATE', 'scripts', $scriptId, ['name' => $name, 'filename' => $filename]);
    jsonSuccess(['id' => $scriptId], 'Script criado');
}

function handleUpdateScript($id, $input) {
    $script = Database::fetchOne("SELECT id, is_core, organization_id FROM scripts WHERE id = ?", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    if ($script['is_core']) jsonError('Scripts core nao podem ser alterados', 403);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $script['organization_id'] != $userOrgId) {
        jsonError('Sem permissao', 403);
    }

    $name = sanitizeInput($input['name'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';

    if (empty($name)) jsonError('Nome obrigatorio');

    Database::execute(
        "UPDATE scripts SET name = ?, description = ?, content = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$name, $description, $content, $id]
    );

    log_audit('UPDATE', 'scripts', $id, ['name' => $name]);
    jsonSuccess(null, 'Script atualizado');
}

function handleDeleteScript($id) {
    $script = Database::fetchOne("SELECT id, is_core, organization_id, name FROM scripts WHERE id = ?", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    if ($script['is_core']) jsonError('Scripts core nao podem ser excluidos', 403);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $script['organization_id'] != $userOrgId) {
        jsonError('Sem permissao', 403);
    }

    Database::execute("UPDATE scripts SET is_active = FALSE WHERE id = ?", [$id]);
    log_audit('DELETE', 'scripts', $id, ['name' => $script['name']]);
    jsonSuccess(null, 'Script excluido');
}

function handleUploadScript() {
    $userOrgId = getUserOrgId();
    if ($userOrgId === null && !isAdminGap()) jsonError('Sem permissao', 403);

    if (!isset($_FILES['script']) || $_FILES['script']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Nenhum arquivo enviado', 400);
    }

    $file = $_FILES['script'];
    $name = sanitizeInput($_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME));
    $description = sanitizeInput($_POST['description'] ?? '');
    $isCore = isset($_POST['is_core']) && $_POST['is_core'] ? true : false;

    if ($file['size'] > 500 * 1024) jsonError('Arquivo muito grande (max 500KB)', 400);

    $content = file_get_contents($file['tmp_name']);
    $filename = sanitizeInput($file['name']);

    if (Database::fetchOne("SELECT id FROM scripts WHERE filename = ?", [$filename])) {
        jsonError('Arquivo ja existe');
    }

    Database::execute(
        "INSERT INTO scripts (name, filename, description, content, is_core, organization_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, TRUE)",
        [$name, $filename, $description, $content, $isCore, $userOrgId]
    );

    $scriptId = (int)Database::lastInsertId();
    log_audit('UPLOAD', 'scripts', $scriptId, ['name' => $name, 'filename' => $filename]);
    jsonSuccess(['id' => $scriptId, 'filename' => $filename], 'Script enviado');
}

// BUNDLE
function handleGenerateBundle($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    $selectedScripts = $input['scripts'] ?? [];

    if (!$orgId) jsonError('Organization ID required');

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) jsonError('Sem permissao', 403);

    $org = Database::fetchOne("SELECT id, acronym, domain, serial_config FROM organizations WHERE id = ?", [$orgId]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    // Get variables
    $vars = Database::fetchAll(
        "SELECT vd.name, ov.value FROM organization_variables ov
         JOIN variable_definitions vd ON vd.id = ov.variable_id
         WHERE ov.organization_id = ?",
        [$orgId]
    );

    // Get scripts (all core + selected custom)
    if (empty($selectedScripts)) {
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, content, is_core FROM scripts
             WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?)
             ORDER BY is_core DESC, execution_order, name",
            [$orgId]
        );
    } else {
        $placeholders = implode(',', array_fill(0, count($selectedScripts), '?'));
        $params = array_merge([$orgId], $selectedScripts);
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, content, is_core FROM scripts
             WHERE is_active = TRUE AND (is_core = TRUE OR id IN ($placeholders))
             ORDER BY is_core DESC, execution_order, name",
            $params
        );
    }

    // Generate bundle content
    $bundle = "#!/bin/bash\n";
    $bundle .= "# ============================================\n";
    $bundle .= "# SeederLinux Lite Bundle\n";
    $bundle .= "# ============================================\n";
    $bundle .= "# Organizacao: {$org['acronym']}\n";
    $bundle .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $bundle .= "# Serial: {$org['serial_config']}\n";
    $bundle .= "# Scripts: " . count($scripts) . "\n";
    $bundle .= "# ============================================\n\n";

    // Variables section
    $bundle .= "# === VARIAVEIS ===\n";
    foreach ($vars as $v) {
        $bundle .= "export {$v['name']}='" . str_replace("'", "'\\''", $v['value'] ?? '') . "'\n";
    }
    $bundle .= "\n";

    // Scripts section - SUBSTITUI PLACEHOLDERS
    $bundle .= "# === SCRIPTS ===\n\n";
    $scriptIds = [];
    foreach ($scripts as $s) {
        $scriptContent = substituir_placeholders($s['content'], $orgId);
        $bundle .= "# --- {$s['name']} ({$s['filename']}) ---\n";
        $bundle .= $scriptContent . "\n\n";
        $scriptIds[] = $s['id'];
    }

    $bundle .= "# === FIM DO BUNDLE ===\n";
    $bundle .= "echo 'Bundle executado com sucesso!'\n";

    $filename = "bundle_{$org['acronym']}_" . date('Ymd_His') . ".sh";
    $userId = $_SESSION['user_id'] ?? null;

    Database::execute(
        "INSERT INTO deploy_bundles (organization_id, user_id, filename, content, script_ids, scripts_count, generated_at)
         VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
        [$orgId, $userId, $filename, $bundle, json_encode($scriptIds), count($scripts)]
    );

    $bundleId = (int)Database::lastInsertId();

    log_audit('GENERATE', 'bundles', $bundleId, ['organization' => $org['acronym'], 'scripts' => count($scripts)]);

    jsonSuccess([
        'bundle_id' => $bundleId,
        'filename' => $filename,
        'download_url' => "/api/?action=bundle-by-id&id={$bundleId}",
        'scripts_count' => count($scripts)
    ], 'Bundle gerado com sucesso');
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

// USERS
function handleGetUsers() {
    if (!isAdminGap() && !isAuditor()) jsonError('Sem permissao', 403);

    $users = Database::fetchAll(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.organization_id, u.created_at,
                o.acronym as org_acronym
         FROM users u
         LEFT JOIN organizations o ON o.id = u.organization_id
         ORDER BY u.username"
    );

    jsonSuccess($users);
}

function handleCreateUser($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    $fullName = sanitizeInput($input['full_name'] ?? '');
    $email = sanitizeInput($input['email'] ?? '');
    $role = sanitizeInput($input['role'] ?? 'operador_om');
    $organizationId = $input['organization_id'] ?? null;

    if (empty($username) || empty($password)) jsonError('Username e senha obrigatorios');
    if ($password !== $confirmPassword) jsonError('Senhas nao conferem');
    if (strlen($password) < 6) jsonError('Senha deve ter no minimo 6 caracteres');

    if (Database::fetchOne("SELECT id FROM users WHERE username = ?", [$username])) {
        jsonError('Username ja existe');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    Database::execute(
        "INSERT INTO users (username, password_hash, full_name, email, role, organization_id)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$username, $passwordHash, $fullName, $email, $role, $organizationId ?: null]
    );

    $userId = (int)Database::lastInsertId();
    log_audit('CREATE', 'users', $userId, ['username' => $username, 'role' => $role]);

    jsonSuccess(['id' => $userId], 'Usuario criado');
}

function handleUpdateUser($id, $input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $username = sanitizeInput($input['username'] ?? '');
    $fullName = sanitizeInput($input['full_name'] ?? '');
    $email = sanitizeInput($input['email'] ?? '');
    $role = sanitizeInput($input['role'] ?? 'operador_om');
    $organizationId = $input['organization_id'] ?? null;
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (empty($username)) jsonError('Username obrigatorio');
    if ($password && $password !== $confirmPassword) jsonError('Senhas nao conferem');

    if ($password) {
        if (strlen($password) < 6) jsonError('Senha deve ter no minimo 6 caracteres');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        Database::execute(
            "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, organization_id = ?, password_hash = ? WHERE id = ?",
            [$username, $fullName, $email, $role, $organizationId ?: null, $passwordHash, $id]
        );
    } else {
        Database::execute(
            "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, organization_id = ? WHERE id = ?",
            [$username, $fullName, $email, $role, $organizationId ?: null, $id]
        );
    }

    log_audit('UPDATE', 'users', $id, ['username' => $username]);
    jsonSuccess(null, 'Usuario atualizado');
}

function handleDeleteUser($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $user = Database::fetchOne("SELECT username FROM users WHERE id = ?", [$id]);
    if (!$user) jsonError('Usuario nao encontrado', 404);

    Database::execute("UPDATE users SET is_active = FALSE WHERE id = ?", [$id]);
    log_audit('DELETE', 'users', $id, ['username' => $user['username']]);
    jsonSuccess(null, 'Usuario excluido');
}

function handleToggleUserStatus($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);

    $user = Database::fetchOne("SELECT is_active, username FROM users WHERE id = ?", [$id]);
    if (!$user) jsonError('Usuario nao encontrado', 404);

    $newStatus = !$user['is_active'];
    Database::execute("UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $id]);
    log_audit($newStatus ? 'ACTIVATE' : 'DEACTIVATE', 'users', $id, ['username' => $user['username']]);
    jsonSuccess(null, $newStatus ? 'Usuario ativado' : 'Usuario desativado');
}

// STATIONS
function handleGetStations($orgId) {
    $userOrgId = getUserOrgId();
    $isAdmin = isAdminGap();

    $where = "s.is_active = TRUE";
    $params = [];

    if ($userOrgId !== null && !$isAdmin) {
        $where .= " AND s.organization_id = ?";
        $params[] = $userOrgId;
    } elseif ($orgId) {
        $where .= " AND s.organization_id = ?";
        $params[] = $orgId;
    }

    $stations = Database::fetchAll(
        "SELECT s.id, s.hostname, s.ip_address, s.mac_address, s.os_name, s.os_version,
                s.last_checkin, s.configuration_serial, s.organization_id, o.acronym as org_acronym,
                o.serial_config,
                CASE
                    WHEN s.last_checkin >= ? THEN 'online'
                    WHEN s.last_checkin < ? AND s.last_checkin IS NOT NULL THEN 'delayed'
                    WHEN s.last_checkin IS NULL THEN 'never'
                    ELSE 'unknown'
                END as connection_status,
                CASE
                    WHEN s.configuration_serial >= o.serial_config THEN 'updated'
                    ELSE 'outdated'
                END as config_status
         FROM stations s
         JOIN organizations o ON o.id = s.organization_id
         WHERE {$where}
         ORDER BY s.last_checkin DESC NULLS LAST",
        array_merge([date('Y-m-d H:i:s', strtotime('-2 hours')), date('Y-m-d H:i:s', strtotime('-2 hours'))], $params)
    );

    jsonSuccess($stations);
}

function handleStationCheckin($input) {
    $hostname = sanitizeInput($input['hostname'] ?? '');
    $ipAddress = sanitizeInput($input['ip_address'] ?? '');
    $macAddress = sanitizeInput($input['mac_address'] ?? '');
    $osName = sanitizeInput($input['os_name'] ?? '');
    $osVersion = sanitizeInput($input['os_version'] ?? '');
    $organizationId = (int)($input['organization_id'] ?? 0);
    $configSerial = (int)($input['configuration_serial'] ?? 0);

    if (empty($hostname) || empty($organizationId)) {
        jsonError('Hostname e organization_id obrigatorios');
    }

    // Check if station exists
    $existing = Database::fetchOne(
        "SELECT id FROM stations WHERE hostname = ? AND organization_id = ?",
        [$hostname, $organizationId]
    );

    if ($existing) {
        Database::execute(
            "UPDATE stations SET ip_address = ?, mac_address = ?, os_name = ?, os_version = ?, configuration_serial = ?, last_checkin = CURRENT_TIMESTAMP WHERE id = ?",
            [$ipAddress, $macAddress, $osName, $osVersion, $configSerial, $existing['id']]
        );
    } else {
        Database::execute(
            "INSERT INTO stations (hostname, ip_address, mac_address, os_name, os_version, organization_id, configuration_serial, last_checkin, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, TRUE)",
            [$hostname, $ipAddress, $macAddress, $osName, $osVersion, $organizationId, $configSerial]
        );
    }

    jsonSuccess(['status' => 'ok'], 'Check-in registrado');
}

// AUDIT
function handleGetAuditEvents() {
    if (!isAdminGap() && !isAuditor()) jsonError('Sem permissao', 403);

    $limit = (int)($_GET['limit'] ?? 100);
    $orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : null;
    $startDate = sanitizeInput($_GET['start_date'] ?? '');
    $endDate = sanitizeInput($_GET['end_date'] ?? '');

    $where = "1=1";
    $params = [];

    if ($orgId) {
        $where .= " AND a.organization_id = ?";
        $params[] = $orgId;
    }
    if ($startDate) {
        $where .= " AND a.created_at >= ?";
        $params[] = $startDate . ' 00:00:00';
    }
    if ($endDate) {
        $where .= " AND a.created_at <= ?";
        $params[] = $endDate . ' 23:59:59';
    }

    $params[] = $limit;

    $events = Database::fetchAll(
        "SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.ip_address, a.created_at,
                u.username, u.full_name, o.acronym as org_acronym
         FROM audit_events a
         LEFT JOIN users u ON u.id = a.user_id
         LEFT JOIN organizations o ON o.id = a.organization_id
         WHERE {$where}
         ORDER BY a.created_at DESC
         LIMIT ?",
        $params
    );

    jsonSuccess($events);
}

// UPLOADS
function handleUploadWallpaper() {
    $orgId = (int)($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId && !isAdminGap()) {
        jsonError('Sem permissao', 403);
    }

    if (!isset($_FILES['wallpaper']) || $_FILES['wallpaper']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Nenhum arquivo enviado', 400);
    }

    $file = $_FILES['wallpaper'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($file['type'], $allowedTypes)) {
        jsonError('Tipo de arquivo invalido. Use JPG, PNG, GIF ou WebP', 400);
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        jsonError('Arquivo muito grande (max 10MB)', 400);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'wallpaper_org' . $orgId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../assets/wallpapers/';

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        jsonError('Erro ao salvar arquivo', 500);
    }

    // Create thumbnail
    $thumbDir = $uploadDir . 'thumbs/';
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
    generateThumbnail($uploadDir . $filename, $thumbDir . $filename, 100, 70);

    $wallpaperUrl = '/assets/wallpapers/' . $filename;

    // Update WALLPAPER_URL variable
    Database::execute(
        "UPDATE organization_variables ov SET value = ?
         FROM variable_definitions vd
         WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = 'WALLPAPER_URL'",
        [$wallpaperUrl, $orgId]
    );

    log_audit('UPLOAD', 'wallpaper', null, ['organization_id' => $orgId, 'filename' => $filename]);
    jsonSuccess(['url' => $wallpaperUrl, 'filename' => $filename, 'thumbnail' => '/assets/wallpapers/thumbs/' . $filename], 'Wallpaper enviado');
}

function handleUploadLogo() {
    $orgId = (int)($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);

    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId && !isAdminGap()) {
        jsonError('Sem permissao', 403);
    }

    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Nenhum arquivo enviado', 400);
    }

    $file = $_FILES['logo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

    if (!in_array($file['type'], $allowedTypes)) {
        jsonError('Tipo de arquivo invalido', 400);
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        jsonError('Arquivo muito grande (max 10MB)', 400);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'logo_org' . $orgId . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/../assets/logos/';

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        jsonError('Erro ao salvar arquivo', 500);
    }

    $logoUrl = '/assets/logos/' . $filename;

    // Update LOGO_URL variable
    Database::execute(
        "UPDATE organization_variables ov SET value = ?
         FROM variable_definitions vd
         WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = 'LOGO_URL'",
        [$logoUrl, $orgId]
    );

    log_audit('UPLOAD', 'logo', null, ['organization_id' => $orgId, 'filename' => $filename]);
    jsonSuccess(['url' => $logoUrl, 'filename' => $filename], 'Logo enviado');
}

function handleGetWallpapers($orgId) {
    if (!$orgId) jsonError('org_id required', 400);

    $uploadDir = __DIR__ . '/../assets/wallpapers/';
    $thumbDir = $uploadDir . 'thumbs/';
    $images = [];

    if (is_dir($uploadDir)) {
        foreach (scandir($uploadDir) as $file) {
            if ($file === '.' || $file === '..' || is_dir($uploadDir . $file)) continue;
            if (preg_match('/^wallpaper_org' . $orgId . '_/', $file) || preg_match('/^default\./', $file)) {
                $images[] = [
                    'filename' => $file,
                    'url' => '/assets/wallpapers/' . $file,
                    'thumbnail' => file_exists($thumbDir . $file) ? '/assets/wallpapers/thumbs/' . $file : '/assets/wallpapers/' . $file,
                    'timestamp' => filemtime($uploadDir . $file)
                ];
            }
        }
    }

    usort($images, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    jsonSuccess(['images' => $images]);
}

function handleGetLogos($orgId) {
    if (!$orgId) jsonError('org_id required', 400);

    $uploadDir = __DIR__ . '/../assets/logos/';
    $images = [];

    if (is_dir($uploadDir)) {
        foreach (scandir($uploadDir) as $file) {
            if ($file === '.' || $file === '..' || is_dir($uploadDir . $file)) continue;
            if (preg_match('/^logo_org' . $orgId . '_/', $file) || preg_match('/^default\./', $file)) {
                $images[] = [
                    'filename' => $file,
                    'url' => '/assets/logos/' . $file,
                    'timestamp' => filemtime($uploadDir . $file)
                ];
            }
        }
    }

    usort($images, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    jsonSuccess(['images' => $images]);
}
