<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    match(true) {
        $action === 'session' && $method === 'GET' => handleSession(),
        $action === 'login' && $method === 'POST' => handleLogin(),
        $action === 'logout' && $method === 'POST' => handleLogout(),
        $action === 'dashboard' && $method === 'GET' => handleDashboard(),
        $action === 'organizations' && $method === 'GET' => handleGetOrganizations(),
        $action === 'organizations' && $method === 'POST' => handleCreateOrganization(),
        $action === 'organization' && $method === 'PUT' => handleUpdateOrganization(),
        $action === 'organization' && $method === 'DELETE' => handleDeleteOrganization(),
        $action === 'variables' && $method === 'GET' => handleGetVariables(),
        $action === 'variables-update' && $method === 'POST' => handleUpdateVariables(),
        $action === 'scripts' && $method === 'GET' => handleGetScripts(),
        $action === 'script' && $method === 'GET' => handleGetScript(),
        $action === 'script' && $method === 'POST' => handleCreateScript(),
        $action === 'script' && $method === 'PUT' => handleUpdateScript(),
        $action === 'script' && $method === 'DELETE' => handleDeleteScript(),
        $action === 'users' && $method === 'GET' => handleGetUsers(),
        $action === 'users' && $method === 'POST' => handleCreateUser(),
        $action === 'user' && $method === 'PUT' => handleUpdateUser(),
        $action === 'user' && $method === 'DELETE' => handleDeleteUser(),
        $action === 'stations' && $method === 'GET' => handleGetStations(),
        $action === 'audit' && $method === 'GET' => handleGetAudit(),
        $action === 'generate-bundle' && $method === 'POST' => handleGenerateBundle(),
        default => jsonError('Acao invalida', 400)
    };
} catch (Exception $e) {
    jsonError('Erro interno: ' . $e->getMessage(), 500);
}

// ── Handlers ──────────────────────────────────────────────────────────────────

function handleSession() {
    if (!isset($_SESSION['user_id'])) jsonError('Nao autenticado', 401);
    $user = Database::fetchOne("SELECT u.id, u.username, u.full_name, u.email, u.role, u.organization_id, o.acronym as org_acronym FROM users u LEFT JOIN organizations o ON o.id=u.organization_id WHERE u.id=?", [$_SESSION['user_id']]);
    if (!$user) jsonError('Usuario nao encontrado', 404);
    jsonSuccess($user);
}

function handleLogin() {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = sanitizeInput($data['username'] ?? '');
    $password = $data['password'] ?? '';
    if (!$username || !$password) jsonError('Usuario e senha obrigatorios', 400);

    $user = Database::fetchOne("SELECT id, username, full_name, role, organization_id, password_hash FROM users WHERE username=? AND is_active=true", [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) jsonError('Credenciais invalidas', 401);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['organization_id'] = $user['organization_id'];

    log_audit('login', 'user', $user['id']);
    jsonSuccess(['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]);
}

function handleLogout() {
    log_audit('logout', 'user', $_SESSION['user_id'] ?? null);
    session_destroy();
    jsonSuccess([], 'Logout realizado');
}

function handleDashboard() {
    requireAuth();
    $stats = Database::fetchOne("SELECT
        (SELECT COUNT(*) FROM organizations) as organizations,
        (SELECT COUNT(*) FROM scripts) as scripts,
        (SELECT COUNT(*) FROM variable_definitions) as variables,
        (SELECT COUNT(*) FROM bundles WHERE created_at >= date_trunc('month', CURRENT_DATE)) as bundles_this_month,
        (SELECT COUNT(*) FROM stations WHERE last_checkin > NOW() - INTERVAL '1 hour') as stations_online,
        (SELECT COUNT(*) FROM stations WHERE config_status='outdated' OR last_checkin < NOW() - INTERVAL '24 hours') as stations_outdated");

    $recentStations = Database::fetchAll("SELECT s.hostname, s.ip_address, s.last_checkin, o.acronym as org_acronym, CASE WHEN s.last_checkin > NOW() - INTERVAL '24 hours' THEN 'Atualizado' ELSE 'Desatualizado' END as status FROM stations s JOIN organizations o ON o.id=s.organization_id ORDER BY s.last_checkin DESC LIMIT 10");
    $recentOrgs = Database::fetchAll("SELECT id, name, acronym FROM organizations ORDER BY created_at DESC LIMIT 5");

    jsonSuccess(array_merge($stats, ['recent_stations' => $recentStations, 'recent_orgs' => $recentOrgs]));
}

function handleGetOrganizations() {
    requireAuth();
    $orgs = Database::fetchAll("SELECT id, name, acronym, domain, description, logo_url, created_at FROM organizations ORDER BY acronym");
    jsonSuccess($orgs);
}

function handleCreateOrganization() {
    requireAuth();
    if (!isAdminGap()) jsonError('Permissao negada', 403);

    $data = json_decode(file_get_contents('php://input'), true);
    $name = sanitizeInput($data['name'] ?? '');
    $acronym = strtoupper(sanitizeInput($data['acronym'] ?? ''));
    $domain = sanitizeInput($data['domain'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $dcIp = sanitizeInput($data['dc_ip'] ?? '');
    $dnsPrimario = sanitizeInput($data['dns_primario'] ?? '');
    $dnsSecundario = sanitizeInput($data['dns_secundario'] ?? '');

    if (!$name || !$acronym) jsonError('Nome e sigla obrigatorios', 400);

    $existing = Database::fetchOne("SELECT id FROM organizations WHERE acronym=?", [$acronym]);
    if ($existing) jsonError('Sigla ja cadastrada', 400);

    Database::execute("INSERT INTO organizations (name, acronym, domain, description) VALUES (?, ?, ?, ?)", [$name, $acronym, $domain, $description]);
    $orgId = Database::lastInsertId();

    // Create default variable values for the organization
    Database::execute("INSERT INTO organization_variables (organization_id, variable_id, value) SELECT ?, id, default_value FROM variable_definitions", [$orgId]);

    generateDefaultVariables($orgId, $name, $acronym, $domain, $dcIp, $dnsPrimario, $dnsSecundario);
    log_audit('create', 'organization', $orgId, ['name' => $name, 'acronym' => $acronym]);

    jsonSuccess(['id' => $orgId], 'Organizacao criada');
}

function handleUpdateOrganization() {
    requireAuth();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);

    $data = json_decode(file_get_contents('php://input'), true);
    $name = sanitizeInput($data['name'] ?? '');
    $domain = sanitizeInput($data['domain'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');

    Database::execute("UPDATE organizations SET name=?, domain=?, description=? WHERE id=?", [$name, $domain, $description, $id]);
    log_audit('update', 'organization', $id);
    jsonSuccess([], 'Atualizado');
}

function handleDeleteOrganization() {
    requireAuth();
    if (!isAdminGap()) jsonError('Permissao negada', 403);

    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);

    Database::execute("DELETE FROM organization_variables WHERE organization_id=?", [$id]);
    Database::execute("DELETE FROM organizations WHERE id=?", [$id]);
    log_audit('delete', 'organization', $id);
    jsonSuccess([], 'Excluido');
}

function handleGetVariables($orgId = null) {
    requireAuth();
    if (!$orgId) $orgId = getUserOrgId();
    if (!$orgId) {
        $id = intval($_GET['id'] ?? 0);
        if ($id) $orgId = $id;
    }
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

function handleUpdateVariables() {
    requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $orgId = intval($data['organization_id'] ?? 0);
    $variables = $data['variables'] ?? [];

    if (!$orgId) jsonError('Organization ID obrigatorio', 400);

    foreach ($variables as $varId => $value) {
        Database::execute("UPDATE organization_variables SET value=? WHERE organization_id=? AND variable_id=?", [$value, $orgId, $varId]);
    }
    log_audit('update_variables', 'organization', $orgId);
    jsonSuccess([], 'Variaveis atualizadas');
}

function handleGetScripts() {
    requireAuth();
    $orgId = intval($_GET['org_id'] ?? 0);
    if ($orgId) {
        $scripts = Database::fetchAll("SELECT id, name, filename, description, is_core FROM scripts ORDER BY is_core DESC, name");
    } else {
        $scripts = Database::fetchAll("SELECT id, name, filename, description, is_core FROM scripts ORDER BY is_core DESC, name");
    }
    jsonSuccess($scripts);
}

function handleGetScript() {
    requireAuth();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);

    $script = Database::fetchOne("SELECT id, name, filename, description, content, is_core FROM scripts WHERE id=?", [$id]);
    if (!$script) jsonError('Script nao encontrado', 404);
    jsonSuccess($script);
}

function handleCreateScript() {
    requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $name = sanitizeInput($data['name'] ?? '');
    $filename = sanitizeInput($data['filename'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $content = $data['content'] ?? '';
    $isCore = false;

    if (!$name || !$filename || !$content) jsonError('Nome, arquivo e conteudo obrigatorios', 400);

    Database::execute("INSERT INTO scripts (name, filename, description, content, is_core) VALUES (?, ?, ?, ?, ?)", [$name, $filename, $description, $content, $isCore]);
    $id = Database::lastInsertId();
    log_audit('create', 'script', $id, ['name' => $name]);
    jsonSuccess(['id' => $id], 'Script criado');
}

function handleUpdateScript() {
    requireAuth();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);

    $data = json_decode(file_get_contents('php://input'), true);
    $name = sanitizeInput($data['name'] ?? '');
    $description = sanitizeInput($data['description'] ?? '');
    $content = $data['content'] ?? '';

    Database::execute("UPDATE scripts SET name=?, description=?, content=? WHERE id=?", [$name, $description, $content, $id]);
    log_audit('update', 'script', $id);
    jsonSuccess([], 'Script atualizado');
}

function handleDeleteScript() {
    requireAuth();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);

    $script = Database::fetchOne("SELECT is_core FROM scripts WHERE id=?", [$id]);
    if ($script && $script['is_core']) jsonError('Scripts core nao podem ser excluidos', 403);

    Database::execute("DELETE FROM scripts WHERE id=?", [$id]);
    log_audit('delete', 'script', $id);
    jsonSuccess([], 'Script excluido');
}

function handleGetUsers() {
    requireAuth();
    if (!isAdminGap() && !isAuditor()) jsonError('Permissao negada', 403);

    $users = Database::fetchAll("SELECT u.id, u.username, u.full_name, u.email, u.role, u.organization_id, u.is_active, o.acronym as org_acronym FROM users u LEFT JOIN organizations o ON o.id=u.organization_id ORDER BY u.username");
    jsonSuccess($users);
}

function handleCreateUser() {
    requireAuth();
    if (!isAdminGap()) jsonError('Permissao negada', 403);

    $data = json_decode(file_get_contents('php://input'), true);
    $username = sanitizeInput($data['username'] ?? '');
    $fullName = sanitizeInput($data['full_name'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $role = sanitizeInput($data['role'] ?? 'operador_om');
    $orgId = intval($data['organization_id'] ?? 0) ?: null;
    $password = $data['password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';

    if (!$username || !$password) jsonError('Usuario e senha obrigatorios', 400);
    if ($password !== $confirmPassword) jsonError('Senhas nao conferem', 400);

    $existing = Database::fetchOne("SELECT id FROM users WHERE username=?", [$username]);
    if ($existing) jsonError('Usuario ja existe', 400);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    Database::execute("INSERT INTO users (username, full_name, email, role, organization_id, password_hash) VALUES (?, ?, ?, ?, ?, ?)", [$username, $fullName, $email, $role, $orgId, $passwordHash]);
    $id = Database::lastInsertId();
    log_audit('create', 'user', $id, ['username' => $username]);

    jsonSuccess(['id' => $id], 'Usuario criado');
}

function handleUpdateUser() {
    requireAuth();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);

    $data = json_decode(file_get_contents('php://input'), true);
    $username = sanitizeInput($data['username'] ?? '');
    $fullName = sanitizeInput($data['full_name'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $role = sanitizeInput($data['role'] ?? 'operador_om');
    $orgId = intval($data['organization_id'] ?? 0) ?: null;
    $password = $data['password'] ?? '';

    if ($password) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        Database::execute("UPDATE users SET username=?, full_name=?, email=?, role=?, organization_id=?, password_hash=? WHERE id=?", [$username, $fullName, $email, $role, $orgId, $passwordHash, $id]);
    } else {
        Database::execute("UPDATE users SET username=?, full_name=?, email=?, role=?, organization_id=? WHERE id=?", [$username, $fullName, $email, $role, $orgId, $id]);
    }
    log_audit('update', 'user', $id);
    jsonSuccess([], 'Usuario atualizado');
}

function handleDeleteUser() {
    requireAuth();
    if (!isAdminGap()) jsonError('Permissao negada', 403);

    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonError('ID obrigatorio', 400);
    if ($id === $_SESSION['user_id']) jsonError('Nao pode excluir proprio usuario', 400);

    Database::execute("DELETE FROM users WHERE id=?", [$id]);
    log_audit('delete', 'user', $id);
    jsonSuccess([], 'Usuario excluido');
}

function handleGetStations() {
    requireAuth();
    $orgId = intval($_GET['org_id'] ?? 0);
    if ($orgId) {
        $stations = Database::fetchAll("SELECT s.*, o.acronym as org_acronym FROM stations s JOIN organizations o ON o.id=s.organization_id WHERE s.organization_id=? ORDER BY s.hostname", [$orgId]);
    } else {
        $stations = Database::fetchAll("SELECT s.*, o.acronym as org_acronym FROM stations s JOIN organizations o ON o.id=s.organization_id ORDER BY s.hostname");
    }
    jsonSuccess($stations);
}

function handleGetAudit() {
    requireAuth();
    if (!isAdminGap() && !isAuditor()) jsonError('Permissao negada', 403);

    $startDate = sanitizeInput($_GET['start_date'] ?? '');
    $endDate = sanitizeInput($_GET['end_date'] ?? '');

    $sql = "SELECT a.*, u.username, u.full_name, o.acronym as org_acronym FROM audit_events a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN organizations o ON o.id=a.organization_id";
    $params = [];
    $conditions = [];

    if ($startDate) { $conditions[] = "a.created_at >= ?"; $params[] = $startDate . ' 00:00:00'; }
    if ($endDate) { $conditions[] = "a.created_at <= ?"; $params[] = $endDate . ' 23:59:59'; }

    if ($conditions) $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY a.created_at DESC LIMIT 500";

    $events = Database::fetchAll($sql, $params);
    jsonSuccess($events);
}

function handleGenerateBundle() {
    requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $orgId = intval($data['organization_id'] ?? 0);
    $scriptIds = $data['scripts'] ?? [];

    if (!$orgId) jsonError('Organization ID obrigatorio', 400);

    $org = Database::fetchOne("SELECT * FROM organizations WHERE id=?", [$orgId]);
    if (!$org) jsonError('Organizacao nao encontrada', 404);

    // Generate bundle content
    $bundleContent = "#!/bin/bash\n# Bundle para " . $org['name'] . " (" . $org['acronym'] . ")\n# Gerado em: " . date('Y-m-d H:i:s') . "\n\n";

    if (!empty($scriptIds)) {
        $placeholders = implode(',', array_fill(0, count($scriptIds), '?'));
        $scripts = Database::fetchAll("SELECT * FROM scripts WHERE id IN ($placeholders) ORDER BY name", $scriptIds);
        foreach ($scripts as $script) {
            $content = substituir_placeholders($script['content'], $orgId);
            $bundleContent .= "\n# ────────────────────────────────────────\n";
            $bundleContent .= "# Script: " . $script['name'] . "\n";
            $bundleContent .= "# ────────────────────────────────────────\n";
            $bundleContent .= $content . "\n";
        }
    }

    // Log bundle generation
    Database::execute("INSERT INTO bundles (organization_id, script_count, created_by) VALUES (?, ?, ?)", [$orgId, count($scriptIds), $_SESSION['user_id']]);
    log_audit('generate_bundle', 'bundle', null, ['org_id' => $orgId, 'scripts' => count($scriptIds)]);

    // Return bundle for download
    $filename = "bundle_" . $org['acronym'] . "_" . date('Ymd_His') . ".sh";
    $dataUri = "data:application/x-sh;base64," . base64_encode($bundleContent);

    jsonSuccess(['download_url' => $dataUri, 'filename' => $filename, 'content' => $bundleContent]);
}
