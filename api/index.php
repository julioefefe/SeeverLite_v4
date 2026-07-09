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
$id     = isset($_GET['id'])     ? (int)$_GET['id']     : null;
$orgId  = isset($_GET['org_id']) ? (int)$_GET['org_id'] : null;
$method = $_SERVER['REQUEST_METHOD'];

$input = [];
if ($method === 'POST' || $method === 'PUT') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'multipart/form-data') !== false) {
        $input = $_POST;
    } else {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }
}

try {
    switch ($action) {
        case 'login':           if ($method !== 'POST') jsonError('Method not allowed', 405); handleLogin($input); break;
        case 'logout':          handleLogout(); break;
        case 'session':         handleSessionCheck(); break;
        case 'dashboard':       requireAuth(); handleDashboard(); break;
        case 'organizations':   requireAuth(); $method === 'GET' ? handleGetOrganizations() : ($method === 'POST' ? handleCreateOrganization($input) : jsonError('Method not allowed', 405)); break;
        case 'organization':    requireAuth(); if (!$id) jsonError('ID required', 400); if ($method === 'GET') handleGetOrganization($id); elseif ($method === 'PUT') handleUpdateOrganization($id, $input); elseif ($method === 'DELETE') handleDeleteOrganization($id); else jsonError('Method not allowed', 405); break;
        case 'variables':       requireAuth(); handleGetVariables($id); break;
        case 'variables-update': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUpdateVariables($input); break;
        case 'variable-add':    requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleAddVariable($input); break;
        case 'scripts':         requireAuth(); handleGetScripts($orgId); break;
        case 'script':          requireAuth(); if ($method === 'GET' && $id) handleGetScript($id); elseif ($method === 'PUT' && $id) handleUpdateScript($id, $input); elseif ($method === 'DELETE' && $id) handleDeleteScript($id); elseif ($method === 'POST') handleCreateScript($input); else jsonError('Method not allowed', 405); break;
        case 'script-upload':   requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadScript(); break;
        case 'generate-bundle': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleGenerateBundle($input); break;
        case 'bundle-by-id':    requireAuth(); handleDownloadBundle($id); break;
        case 'users':           requireAuth(); $method === 'GET' ? handleGetUsers() : ($method === 'POST' ? handleCreateUser($input) : jsonError('Method not allowed', 405)); break;
        case 'user':            requireAuth(); if (!$id) jsonError('ID required', 400); if ($method === 'PUT') handleUpdateUser($id, $input); elseif ($method === 'DELETE') handleDeleteUser($id); elseif ($method === 'POST') handleToggleUserStatus($id); else jsonError('Method not allowed', 405); break;
        case 'stations':        requireAuth(); handleGetStations($orgId); break;
        case 'checkin':         if ($method !== 'POST') jsonError('Method not allowed', 405); handleStationCheckin($input); break;
        case 'audit':           requireAuth(); handleGetAuditEvents(); break;
        case 'upload-wallpaper': requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadWallpaper(); break;
        case 'upload-logo':     requireAuth(); if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadLogo(); break;
        case 'wallpapers':      requireAuth(); handleGetWallpapers($orgId); break;
        case 'logos':           requireAuth(); handleGetLogos($orgId); break;
        default: jsonError('Endpoint invalido: ' . htmlspecialchars($action), 404);
    }
} catch (RuntimeException $e) {
    jsonError($e->getMessage());
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    jsonError('Erro interno do servidor', 500);
}

// ====== HANDLERS ======

function handleLogin($input) {
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if (empty($username) || empty($password)) jsonError('Username e senha obrigatorios');

    $user = Database::fetchOne("SELECT id, username, password_hash, full_name, email, role, organization_id, is_active FROM users WHERE username = ?", [$username]);
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) jsonError('Credenciais invalidas', 401);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['organization_id'] = $user['organization_id'];

    $org = $user['organization_id'] ? Database::fetchOne("SELECT id, acronym, name FROM organizations WHERE id = ?", [$user['organization_id']]) : null;
    log_audit('LOGIN', 'users', $user['id']);
    jsonSuccess(['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'organization_id' => $user['organization_id'], 'org_acronym' => $org['acronym'] ?? null]);
}

function handleLogout() { session_destroy(); jsonSuccess(null, 'Logout realizado'); }

function handleSessionCheck() {
    if (isset($_SESSION['user_id'])) {
        $org = $_SESSION['organization_id'] ? Database::fetchOne("SELECT acronym FROM organizations WHERE id = ?", [$_SESSION['organization_id']]) : null;
        jsonSuccess(['id' => $_SESSION['user_id'], 'username' => $_SESSION['username'], 'role' => $_SESSION['role'], 'organization_id' => $_SESSION['organization_id'], 'org_acronym' => $org['acronym'] ?? null]);
    }
    jsonResponse(['success' => false], 200);
}

function handleDashboard() {
    $userOrgId = getUserOrgId();
    $ago2h = date('Y-m-d H:i:s', strtotime('-2 hours'));

    $stats = [
        'organizations' => (int)Database::fetchOne("SELECT COUNT(*) c FROM organizations WHERE is_active=true")['c'],
        'scripts'       => (int)Database::fetchOne("SELECT COUNT(*) c FROM scripts WHERE is_active=true")['c'],
        'variables'     => (int)Database::fetchOne("SELECT COUNT(*) c FROM variable_definitions")['c'],
        'bundles_this_month' => (int)Database::fetchOne("SELECT COUNT(*) c FROM deploy_bundles WHERE generated_at >= date_trunc('month', CURRENT_DATE)")['c'],
    ];

    if ($userOrgId) {
        $stats['stations_online']    = (int)Database::fetchOne("SELECT COUNT(*) c FROM stations WHERE organization_id=? AND last_checkin>=?", [$userOrgId, $ago2h])['c'];
        $stats['stations_outdated']  = (int)Database::fetchOne("SELECT COUNT(*) c FROM stations s JOIN organizations o ON o.id=s.organization_id WHERE s.organization_id=? AND s.configuration_serial<o.serial_config", [$userOrgId])['c'];
        $stats['recent_stations']    = Database::fetchAll("SELECT hostname, ip_address, last_checkin, o.acronym org_acronym, CASE WHEN s.configuration_serial>=o.serial_config THEN 'Atualizado' ELSE 'Desatualizado' END status FROM stations s JOIN organizations o ON o.id=s.organization_id WHERE s.organization_id=? ORDER BY last_checkin DESC NULLS LAST LIMIT 10", [$userOrgId]);
    } else {
        $stats['stations_online']    = (int)Database::fetchOne("SELECT COUNT(*) c FROM stations WHERE last_checkin>=?", [$ago2h])['c'];
        $stats['stations_outdated']  = (int)Database::fetchOne("SELECT COUNT(*) c FROM stations s JOIN organizations o ON o.id=s.organization_id WHERE s.configuration_serial<o.serial_config")['c'];
        $stats['recent_stations']    = Database::fetchAll("SELECT hostname, ip_address, last_checkin, o.acronym org_acronym, CASE WHEN s.configuration_serial>=o.serial_config THEN 'Atualizado' ELSE 'Desatualizado' END status FROM stations s JOIN organizations o ON o.id=s.organization_id ORDER BY last_checkin DESC NULLS LAST LIMIT 10");
    }

    $stats['recent_orgs'] = Database::fetchAll("SELECT id, name, acronym, domain FROM organizations WHERE is_active=true ORDER BY created_at DESC LIMIT 5");
    jsonSuccess($stats);
}

function handleGetOrganizations() {
    $userOrgId = getUserOrgId();
    $orgs = $userOrgId && !isAdminGap()
        ? Database::fetchAll("SELECT id, name, acronym, domain, description FROM organizations WHERE is_active=TRUE AND id=? ORDER BY acronym", [$userOrgId])
        : Database::fetchAll("SELECT id, name, acronym, domain, description FROM organizations WHERE is_active=TRUE ORDER BY acronym");
    foreach ($orgs as &$o) {
        $logo = Database::fetchOne("SELECT ov.value FROM organization_variables ov JOIN variable_definitions vd ON vd.id=ov.variable_id WHERE ov.organization_id=? AND vd.name='LOGO_URL'", [$o['id']]);
        $o['logo_url'] = $logo['value'] ?? null;
    }
    jsonSuccess($orgs);
}

function handleGetOrganization($id) {
    $org = Database::fetchOne("SELECT id, name, acronym, domain, description FROM organizations WHERE id=?", [$id]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);
    jsonSuccess($org);
}

function handleCreateOrganization($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $name     = sanitizeInput($input['name'] ?? '');
    $acronym  = strtoupper(sanitizeInput($input['acronym'] ?? ''));
    $domain   = sanitizeInput($input['domain'] ?? '');
    $desc     = sanitizeInput($input['description'] ?? '');
    $dcIp     = sanitizeInput($input['dc_ip'] ?? '');
    $dns1     = sanitizeInput($input['dns_primario'] ?? '');
    $dns2     = sanitizeInput($input['dns_secundario'] ?? '');
    if (empty($name) || empty($acronym)) jsonError('Nome e sigla obrigatorios');
    if (Database::fetchOne("SELECT id FROM organizations WHERE acronym=?", [$acronym])) jsonError('Sigla ja cadastrada');

    Database::beginTransaction();
    Database::execute("INSERT INTO organizations (name, acronym, domain, description) VALUES (?,?,?,?)", [$name, $acronym, $domain, $desc]);
    $newId = (int)Database::lastInsertId();
    Database::execute("INSERT INTO organization_variables (organization_id, variable_id, value) SELECT ?, id, COALESCE(default_value,'') FROM variable_definitions", [$newId]);
    generateDefaultVariables($newId, $name, $acronym, $domain, $dcIp ?: null, $dns1 ?: null, $dns2 ?: null);
    Database::commit();
    log_audit('CREATE', 'organizations', $newId);
    jsonSuccess(Database::fetchOne("SELECT id, name, acronym, domain FROM organizations WHERE id=?", [$newId]), 'Organizacao criada');
}

function handleUpdateOrganization($id, $input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $name = sanitizeInput($input['name'] ?? '');
    if (empty($name)) jsonError('Nome obrigatorio');
    Database::execute("UPDATE organizations SET name=?, domain=?, description=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$name, sanitizeInput($input['domain'] ?? ''), sanitizeInput($input['description'] ?? ''), $id]);
    log_audit('UPDATE', 'organizations', $id);
    jsonSuccess(null, 'Atualizado');
}

function handleDeleteOrganization($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    Database::execute("UPDATE organizations SET is_active=FALSE WHERE id=?", [$id]);
    log_audit('DELETE', 'organizations', $id);
    jsonSuccess(null, 'Excluido');
}

// VARIABLES — query NUNCA inclui vd.options
function handleGetVariables($orgId) {
    if (!$orgId) $orgId = getUserOrgId();
    if (!$orgId) jsonError('Organization ID required', 400);
    $vars = Database::fetchAll(
        "SELECT vd.id, vd.name, vd.description, vd.category, vd.type, vd.is_required, vd.default_value, ov.value as current_value
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.variable_id=vd.id AND ov.organization_id=?
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
        Database::execute("UPDATE organization_variables SET value=?, updated_at=CURRENT_TIMESTAMP WHERE organization_id=? AND variable_id=?", [$value, $orgId, $varId]);
    }
    log_audit('UPDATE', 'variables', null, ['org' => $orgId]);
    jsonSuccess(null, 'Variaveis salvas');
}

function handleAddVariable($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $name = strtoupper(sanitizeInput($input['name'] ?? ''));
    $type = sanitizeInput($input['type'] ?? 'text');
    $value = sanitizeInput($input['value'] ?? '');
    $desc = sanitizeInput($input['description'] ?? '');
    $cat = sanitizeInput($input['category'] ?? 'generic');
    $req = !empty($input['is_required']);
    if (empty($name)) jsonError('Nome obrigatorio');
    if (Database::fetchOne("SELECT id FROM variable_definitions WHERE name=?", [$name])) jsonError('Variavel ja existe');
    Database::execute("INSERT INTO variable_definitions (name, description, type, category, is_required, default_value) VALUES (?,?,?,?,?,?)", [$name, $desc, $type, $cat, $req, $value]);
    $varId = (int)Database::lastInsertId();
    $orgs = Database::fetchAll("SELECT id FROM organizations WHERE is_active=true");
    foreach ($orgs as $o) Database::execute("INSERT INTO organization_variables (organization_id, variable_id, value) VALUES (?,?,?)", [$o['id'], $varId, $value]);
    jsonSuccess(['id' => $varId], 'Variavel criada');
}

function handleGetScripts($filterOrgId) {
    $userOrgId = getUserOrgId();
    $scripts = ($userOrgId && !isAdminGap())
        ? Database::fetchAll("SELECT id, name, filename, description, is_core, organization_id, version, created_at FROM scripts WHERE is_active=TRUE AND (is_core=TRUE OR organization_id=?) ORDER BY is_core DESC, name", [$userOrgId])
        : Database::fetchAll("SELECT id, name, filename, description, is_core, organization_id, version, created_at FROM scripts WHERE is_active=TRUE ORDER BY is_core DESC, name");
    jsonSuccess($scripts);
}

function handleGetScript($id) {
    $script = Database::fetchOne("SELECT id, name, filename, description, content, is_core, organization_id, version, created_at FROM scripts WHERE id=? AND is_active=TRUE", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    jsonSuccess($script);
}

function handleCreateScript($input) {
    $name = sanitizeInput($input['name'] ?? '');
    $filename = sanitizeInput($input['filename'] ?? '');
    $desc = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';
    $isCore = !empty($input['is_core']);
    if (empty($name) || empty($filename)) jsonError('Nome e arquivo obrigatorios');
    if (Database::fetchOne("SELECT id FROM scripts WHERE filename=?", [$filename])) jsonError('Arquivo ja existe');
    Database::execute("INSERT INTO scripts (name, filename, description, content, is_core, organization_id, is_active) VALUES (?,?,?,?,?,?,TRUE)", [$name, $filename, $desc, $content, $isCore, getUserOrgId()]);
    jsonSuccess(['id' => (int)Database::lastInsertId()], 'Script criado');
}

function handleUpdateScript($id, $input) {
    $s = Database::fetchOne("SELECT id, is_core, organization_id FROM scripts WHERE id=?", [$id]);
    if (!$s) jsonError('Script nao encontrado', 404);
    if ($s['is_core']) jsonError('Scripts core nao podem ser alterados', 403);
    $name = sanitizeInput($input['name'] ?? '');
    if (empty($name)) jsonError('Nome obrigatorio');
    Database::execute("UPDATE scripts SET name=?, description=?, content=?, version=version+1, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$name, sanitizeInput($input['description'] ?? ''), $input['content'] ?? '', $id]);
    jsonSuccess(null, 'Script atualizado');
}

function handleDeleteScript($id) {
    $s = Database::fetchOne("SELECT id, is_core FROM scripts WHERE id=?", [$id]);
    if (!$s) jsonError('Script nao encontrado', 404);
    if ($s['is_core']) jsonError('Scripts core nao podem ser excluidos', 403);
    Database::execute("UPDATE scripts SET is_active=FALSE WHERE id=?", [$id]);
    jsonSuccess(null, 'Script excluido');
}

function handleUploadScript() {
    if (!isset($_FILES['script']) || $_FILES['script']['error'] !== UPLOAD_ERR_OK) jsonError('Nenhum arquivo enviado', 400);
    $file = $_FILES['script'];
    if ($file['size'] > 500 * 1024) jsonError('Arquivo muito grande (max 500KB)', 400);
    $name = sanitizeInput($_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = sanitizeInput($file['name']);
    $isCore = !empty($_POST['is_core']);
    if (Database::fetchOne("SELECT id FROM scripts WHERE filename=?", [$filename])) jsonError('Arquivo ja existe');
    $content = file_get_contents($file['tmp_name']);
    Database::execute("INSERT INTO scripts (name, filename, content, is_core, organization_id, is_active) VALUES (?,?,?,?,?,TRUE)", [$name, $filename, $content, $isCore, getUserOrgId()]);
    jsonSuccess(['id' => (int)Database::lastInsertId()], 'Script enviado');
}

function handleGenerateBundle($input) {
    $orgId = (int)($input['organization_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required');
    $org = Database::fetchOne("SELECT acronym, serial_config FROM organizations WHERE id=?", [$orgId]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    $vars = Database::fetchAll("SELECT vd.name, ov.value FROM organization_variables ov JOIN variable_definitions vd ON vd.id=ov.variable_id WHERE ov.organization_id=?", [$orgId]);
    $scripts = Database::fetchAll("SELECT id, name, filename, content FROM scripts WHERE is_active=TRUE AND (is_core=TRUE OR organization_id=?) ORDER BY is_core DESC, name", [$orgId]);

    $bundle = "#!/bin/bash\n# SeederLinux Lite Bundle\n# Organizacao: {$org['acronym']}\n# Gerado em: " . date('Y-m-d H:i:s') . "\n# Serial: {$org['serial_config']}\n\n# === VARIAVEIS ===\n";
    foreach ($vars as $v) $bundle .= "export {$v['name']}='" . str_replace("'", "'\\''", $v['value'] ?? '') . "'\n";
    $bundle .= "\n# === SCRIPTS ===\n\n";
    $scriptIds = [];
    foreach ($scripts as $s) {
        $bundle .= "# --- {$s['name']} ({$s['filename']}) ---\n" . substituir_placeholders($s['content'], $orgId) . "\n\n";
        $scriptIds[] = $s['id'];
    }
    $bundle .= "echo 'Bundle executado com sucesso!'\n";

    $filename = "bundle_{$org['acronym']}_" . date('Ymd_His') . ".sh";
    Database::execute("INSERT INTO deploy_bundles (organization_id, user_id, filename, content, script_ids, scripts_count, generated_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)", [$orgId, $_SESSION['user_id'] ?? null, $filename, $bundle, json_encode($scriptIds), count($scripts)]);
    $bundleId = (int)Database::lastInsertId();
    log_audit('GENERATE', 'bundles', $bundleId);
    jsonSuccess(['bundle_id' => $bundleId, 'filename' => $filename, 'download_url' => "/api/?action=bundle-by-id&id={$bundleId}", 'scripts_count' => count($scripts)]);
}

function handleDownloadBundle($id) {
    $b = Database::fetchOne("SELECT filename, content FROM deploy_bundles WHERE id=?", [$id]);
    if (!$b) jsonError('Bundle nao encontrado', 404);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $b['filename'] . '"');
    echo $b['content'];
    exit;
}

function handleGetUsers() {
    if (!isAdminGap() && !isAuditor()) jsonError('Sem permissao', 403);
    $users = Database::fetchAll("SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.organization_id, o.acronym org_acronym FROM users u LEFT JOIN organizations o ON o.id=u.organization_id ORDER BY u.username");
    jsonSuccess($users);
}

function handleCreateUser($input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $confirm  = $input['confirm_password'] ?? '';
    $fullName = sanitizeInput($input['full_name'] ?? '');
    $email    = sanitizeInput($input['email'] ?? '');
    $role     = sanitizeInput($input['role'] ?? 'operador_om');
    $orgId    = $input['organization_id'] ?? null;
    if (empty($username) || empty($password)) jsonError('Username e senha obrigatorios');
    if ($password !== $confirm) jsonError('Senhas nao conferem');
    if (strlen($password) < 6) jsonError('Senha deve ter minimo 6 caracteres');
    if (Database::fetchOne("SELECT id FROM users WHERE username=?", [$username])) jsonError('Username ja existe');
    Database::execute("INSERT INTO users (username, password_hash, full_name, email, role, organization_id) VALUES (?,?,?,?,?,?)", [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, $role, $orgId ?: null]);
    jsonSuccess(['id' => (int)Database::lastInsertId()], 'Usuario criado');
}

function handleUpdateUser($id, $input) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $confirm  = $input['confirm_password'] ?? '';
    if (empty($username)) jsonError('Username obrigatorio');
    if ($password && $password !== $confirm) jsonError('Senhas nao conferem');
    if ($password && strlen($password) < 6) jsonError('Senha deve ter minimo 6 caracteres');
    if ($password) {
        Database::execute("UPDATE users SET username=?, full_name=?, email=?, role=?, organization_id=?, password_hash=? WHERE id=?", [$username, sanitizeInput($input['full_name'] ?? ''), sanitizeInput($input['email'] ?? ''), sanitizeInput($input['role'] ?? ''), $input['organization_id'] ?: null, password_hash($password, PASSWORD_DEFAULT), $id]);
    } else {
        Database::execute("UPDATE users SET username=?, full_name=?, email=?, role=?, organization_id=? WHERE id=?", [$username, sanitizeInput($input['full_name'] ?? ''), sanitizeInput($input['email'] ?? ''), sanitizeInput($input['role'] ?? ''), $input['organization_id'] ?: null, $id]);
    }
    jsonSuccess(null, 'Usuario atualizado');
}

function handleDeleteUser($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    Database::execute("UPDATE users SET is_active=FALSE WHERE id=?", [$id]);
    jsonSuccess(null, 'Usuario excluido');
}

function handleToggleUserStatus($id) {
    if (!isAdminGap()) jsonError('Sem permissao', 403);
    $u = Database::fetchOne("SELECT is_active FROM users WHERE id=?", [$id]);
    if (!$u) jsonError('Usuario nao encontrado', 404);
    $new = !$u['is_active'];
    Database::execute("UPDATE users SET is_active=? WHERE id=?", [$new, $id]);
    jsonSuccess(null, $new ? 'Usuario ativado' : 'Usuario desativado');
}

function handleGetStations($filterOrgId) {
    $userOrgId = getUserOrgId();
    $ago2h = date('Y-m-d H:i:s', strtotime('-2 hours'));
    $where = "s.is_active=TRUE"; $params = [$ago2h, $ago2h];
    if ($userOrgId && !isAdminGap()) { $where .= " AND s.organization_id=?"; $params[] = $userOrgId; }
    elseif ($filterOrgId) { $where .= " AND s.organization_id=?"; $params[] = $filterOrgId; }
    $stations = Database::fetchAll(
        "SELECT s.id, s.hostname, s.ip_address, s.mac_address, s.os_name, s.last_checkin, o.acronym org_acronym,
                CASE WHEN s.last_checkin>=? THEN 'online' WHEN s.last_checkin IS NOT NULL THEN 'delayed' ELSE 'never' END conn_status,
                CASE WHEN s.configuration_serial>=o.serial_config THEN 'updated' ELSE 'outdated' END config_status
         FROM stations s JOIN organizations o ON o.id=s.organization_id WHERE {$where} ORDER BY last_checkin DESC NULLS LAST",
        $params
    );
    jsonSuccess($stations);
}

function handleStationCheckin($input) {
    $hostname = sanitizeInput($input['hostname'] ?? '');
    $orgId = (int)($input['organization_id'] ?? 0);
    if (empty($hostname) || !$orgId) jsonError('hostname e organization_id obrigatorios');
    $ex = Database::fetchOne("SELECT id FROM stations WHERE hostname=? AND organization_id=?", [$hostname, $orgId]);
    if ($ex) {
        Database::execute("UPDATE stations SET ip_address=?, mac_address=?, os_name=?, configuration_serial=?, last_checkin=CURRENT_TIMESTAMP WHERE id=?", [sanitizeInput($input['ip_address'] ?? ''), sanitizeInput($input['mac_address'] ?? ''), sanitizeInput($input['os_name'] ?? ''), (int)($input['configuration_serial'] ?? 0), $ex['id']]);
    } else {
        Database::execute("INSERT INTO stations (hostname, ip_address, mac_address, os_name, organization_id, configuration_serial, last_checkin, is_active) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,TRUE)", [$hostname, sanitizeInput($input['ip_address'] ?? ''), sanitizeInput($input['mac_address'] ?? ''), sanitizeInput($input['os_name'] ?? ''), $orgId, (int)($input['configuration_serial'] ?? 0)]);
    }
    jsonSuccess(['status' => 'ok']);
}

function handleGetAuditEvents() {
    if (!isAdminGap() && !isAuditor()) jsonError('Sem permissao', 403);
    $where = "1=1"; $params = [];
    if ($orgId = isset($_GET['org_id']) ? (int)$_GET['org_id'] : null) { $where .= " AND a.organization_id=?"; $params[] = $orgId; }
    if ($sd = sanitizeInput($_GET['start_date'] ?? '')) { $where .= " AND a.created_at>=?"; $params[] = $sd . ' 00:00:00'; }
    if ($ed = sanitizeInput($_GET['end_date'] ?? '')) { $where .= " AND a.created_at<=?"; $params[] = $ed . ' 23:59:59'; }
    $params[] = (int)($_GET['limit'] ?? 100);
    $events = Database::fetchAll("SELECT a.id, a.action, a.entity, a.details, a.created_at, u.username, u.full_name, o.acronym org_acronym FROM audit_events a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN organizations o ON o.id=a.organization_id WHERE {$where} ORDER BY a.created_at DESC LIMIT ?", $params);
    jsonSuccess($events);
}

function handleUploadWallpaper() {
    $orgId = (int)($_POST['organization_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);
    if (!isset($_FILES['wallpaper']) || $_FILES['wallpaper']['error'] !== UPLOAD_ERR_OK) jsonError('Nenhum arquivo enviado', 400);
    $file = $_FILES['wallpaper'];
    if (!in_array($file['type'], ['image/jpeg','image/png','image/gif','image/webp'])) jsonError('Tipo invalido', 400);
    if ($file['size'] > 10 * 1024 * 1024) jsonError('Arquivo muito grande (max 10MB)', 400);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'wp_org' . $orgId . '_' . time() . '.' . $ext;
    $dir = __DIR__ . '/../assets/wallpapers/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) jsonError('Erro ao salvar', 500);
    $thumbDir = $dir . 'thumbs/';
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
    generateThumbnail($dir . $filename, $thumbDir . $filename, 100, 70);
    $url = '/assets/wallpapers/' . $filename;
    Database::execute("UPDATE organization_variables ov SET value=? FROM variable_definitions vd WHERE ov.organization_id=? AND ov.variable_id=vd.id AND vd.name='WALLPAPER_URL'", [$url, $orgId]);
    jsonSuccess(['url' => $url, 'thumbnail' => '/assets/wallpapers/thumbs/' . $filename, 'filename' => $filename]);
}

function handleUploadLogo() {
    $orgId = (int)($_POST['organization_id'] ?? 0);
    if (!$orgId) jsonError('Organization ID required', 400);
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) jsonError('Nenhum arquivo enviado', 400);
    $file = $_FILES['logo'];
    if (!in_array($file['type'], ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'])) jsonError('Tipo invalido', 400);
    if ($file['size'] > 10 * 1024 * 1024) jsonError('Arquivo muito grande (max 10MB)', 400);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'logo_org' . $orgId . '_' . time() . '.' . $ext;
    $dir = __DIR__ . '/../assets/logos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) jsonError('Erro ao salvar', 500);
    $url = '/assets/logos/' . $filename;
    Database::execute("UPDATE organization_variables ov SET value=? FROM variable_definitions vd WHERE ov.organization_id=? AND ov.variable_id=vd.id AND vd.name='LOGO_URL'", [$url, $orgId]);
    jsonSuccess(['url' => $url, 'filename' => $filename]);
}

function handleGetWallpapers($orgId) {
    if (!$orgId) jsonError('org_id required', 400);
    $dir = __DIR__ . '/../assets/wallpapers/';
    $thumbDir = $dir . 'thumbs/';
    $images = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || is_dir($dir . $f)) continue;
            if (preg_match('/^wp_org' . $orgId . '_/', $f) || preg_match('/^default\./', $f)) {
                $images[] = ['url' => '/assets/wallpapers/' . $f, 'thumbnail' => file_exists($thumbDir . $f) ? '/assets/wallpapers/thumbs/' . $f : '/assets/wallpapers/' . $f, 'filename' => $f, 'ts' => filemtime($dir . $f)];
            }
        }
    }
    usort($images, fn($a, $b) => $b['ts'] - $a['ts']);
    jsonSuccess(['images' => $images]);
}

function handleGetLogos($orgId) {
    if (!$orgId) jsonError('org_id required', 400);
    $dir = __DIR__ . '/../assets/logos/';
    $images = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || is_dir($dir . $f)) continue;
            if (preg_match('/^logo_org' . $orgId . '_/', $f) || preg_match('/^default\./', $f)) {
                $images[] = ['url' => '/assets/logos/' . $f, 'filename' => $f, 'ts' => filemtime($dir . $f)];
            }
        }
    }
    usort($images, fn($a, $b) => $b['ts'] - $a['ts']);
    jsonSuccess(['images' => $images]);
}
