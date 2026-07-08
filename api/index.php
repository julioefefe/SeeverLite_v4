<?php
/**
 * SeederLinux Lite - API Router
 * Main entry point for all API requests
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS headers (restrict in production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;

// Get request data
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? $_POST;

try {
    switch ($path) {
        // ===============================
        // Authentication Endpoints
        // ===============================
        case 'login':
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleLogin($input);
            break;

        case 'logout':
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleLogout();
            break;

        case 'session':
            handleSessionCheck();
            break;

        // ===============================
        // Organizations Endpoints
        // ===============================
        case 'organizations':
            requireAuth();
            switch ($method) {
                case 'GET':
                    handleGetOrganizations();
                    break;
                case 'POST':
                    handleCreateOrganization($input);
                    break;
                default:
                    jsonError('Method not allowed', 405);
            }
            break;

        case 'organization':
            requireAuth();
            if (!$id) {
                jsonError('Organization ID required', 400);
            }
            switch ($method) {
                case 'GET':
                    handleGetOrganization((int) $id);
                    break;
                case 'PUT':
                    handleUpdateOrganization((int) $id, $input);
                    break;
                case 'DELETE':
                    handleDeleteOrganization((int) $id);
                    break;
                default:
                    jsonError('Method not allowed', 405);
            }
            break;

        // ===============================
        // Variables Endpoints
        // ===============================
        case 'variables':
            requireAuth();
            if ($method === 'GET') {
                handleGetVariables($id);
            } elseif ($method === 'POST') {
                handleAddVariable($input);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        case 'variables-update':
            requireAuth();
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleUpdateVariables($input);
            break;

        // ===============================
        // Bundle Endpoints
        // ===============================
        case 'bundle':
            requireAuth();
            if ($method !== 'GET') {
                jsonError('Method not allowed', 405);
            }
            handleGetBundle($id);
            break;

        case 'bundle-download':
            requireAuth();
            if ($method !== 'GET') {
                jsonError('Method not allowed', 405);
            }
            handleDownloadBundle($id);
            break;

        case 'generate-bundle':
            requireAuth();
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleGenerateBundle($input);
            break;

        case 'bundle-by-id':
            requireAuth();
            if ($method !== 'GET') {
                jsonError('Method not allowed', 405);
            }
            handleDownloadBundleById((int) $id);
            break;

        // ===============================
        // Dashboard Endpoint
        // ===============================
        case 'dashboard':
            requireAuth();
            handleGetDashboard();
            break;

        // ===============================
        // Stats Endpoint
        // ===============================
        case 'stats':
            handleGetStats();
            break;

        // ===============================
        // Health Check Endpoint (public)
        // ===============================
        case 'health':
            handleHealthCheck();
            break;

        // ===============================
        // Activity Log Endpoint
        // ===============================
        case 'activity-log':
            requireAuth();
            handleGetActivityLog($id);
            break;

        // ===============================
        // Audit Events Endpoint
        // ===============================
        case 'audit':
            requireAuth();
            handleGetAuditEvents();
            break;

        // ===============================
        // Variable Catalog Endpoint
        // ===============================
        case 'variable-catalog':
            requireAuth();
            handleGetVariableCatalog();
            break;

        // ===============================
        // Users Endpoints
        // ===============================
        case 'users':
            requireAuth();
            requireRole(['admin', 'admin_gap']);
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
            requireRole(['admin', 'admin_gap']);
            if (!$id) {
                jsonError('User ID required', 400);
            }
            if ($method === 'PUT') {
                handleUpdateUser((int) $id, $input);
            } elseif ($method === 'DELETE') {
                handleDeleteUser((int) $id);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        // ===============================
        // System Settings Endpoints
        // ===============================
        case 'settings':
            requireAdmin();
            if ($method === 'GET') {
                handleGetSettings();
            } elseif ($method === 'POST') {
                handleUpdateSettings($input);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        // ===============================
        // Scripts Endpoints
        // ===============================
        case 'scripts':
            requireAuth();
            handleGetScripts();
            break;

        case 'script-upload':
            requireAuth();
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleUploadScript();
            break;

        case 'script':
            requireAuth();
            if (!$id) {
                jsonError('Script ID required', 400);
            }
            if ($method === 'GET') {
                handleGetScript((int) $id);
            } elseif ($method === 'PUT') {
                handleUpdateScript((int) $id, $input);
            } elseif ($method === 'DELETE') {
                handleDeleteScript((int) $id);
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        // ===============================
        // Wallpaper Upload Endpoint
        // ===============================
        case 'upload-wallpaper':
            requireAuth();
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleUploadWallpaper();
            break;

        // ===============================
        // Station Check-in Endpoint (token auth, no session)
        // ===============================
        case 'checkin':
            if ($method !== 'POST') {
                jsonError('Method not allowed', 405);
            }
            handleStationCheckin($input);
            break;

        // ===============================
        // Stations Endpoints (session auth)
        // ===============================
        case 'stations':
            requireAuth();
            if ($method === 'GET') {
                handleGetStations();
            } else {
                jsonError('Method not allowed', 405);
            }
            break;

        case 'station':
            requireAuth();
            if (!$id) {
                jsonError('Station ID required', 400);
            }
            if ($method === 'GET') {
                handleGetStation((int) $id);
            } elseif ($method === 'DELETE') {
                handleDeleteStation((int) $id);
            } else {
                jsonError('Method not allowed', 405);
            }
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

// ===============================
// Handler Functions
// ===============================

/**
 * Handle login
 */
function handleLogin(array $input): void {
    if (empty($input['username']) || empty($input['password'])) {
        jsonError('Usuário e senha são obrigatórios');
    }

    // Validate CSRF if provided
    if (!empty($input['csrf_token']) && !validateCSRFToken($input['csrf_token'])) {
        jsonError('Token CSRF inválido');
    }

    try {
        $user = login($input['username'], $input['password']);

        // Log successful login
        logActivity($user['id'], 'login', 'user', $user['id'], "User '{$user['username']}' logged in successfully");

        jsonSuccess($user, 'Login realizado com sucesso');
    } catch (Exception $e) {
        // Log failed login attempt
        logActivity(null, 'login_failed', 'user', null, "Failed login attempt for user '{$input['username']}': " . $e->getMessage());

        jsonError($e->getMessage());
    }
}

/**
 * Handle logout
 */
function handleLogout(): void {
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    $username = $user['username'] ?? 'unknown';

    logout();

    // Log logout
    logActivity($userId, 'logout', 'user', $userId, "User '$username' logged out");

    jsonSuccess(null, 'Logout realizado com sucesso');
}

/**
 * Check session status
 */
function handleSessionCheck(): void {
    if (isLoggedIn()) {
        $user = getCurrentUser();
        jsonSuccess($user, 'Sessão ativa');
    } else {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 200);
    }
}

/**
 * Get all organizations
 */
function handleGetOrganizations(): void {
    $userOrgId = getUserOrgId();

    if ($userOrgId !== null) {
        // operador_om: only their org
        $orgs = Database::fetchAll(
            "SELECT id, name, acronym, domain, description, is_active,
                    created_at, updated_at
             FROM organizations
             WHERE is_active = TRUE AND id = ?
             ORDER BY acronym ASC",
            [$userOrgId]
        );
    } else {
        // admin_gap/auditor: all orgs
        $orgs = Database::fetchAll(
            "SELECT id, name, acronym, domain, description, is_active,
                    created_at, updated_at
             FROM organizations
             WHERE is_active = TRUE
             ORDER BY acronym ASC"
        );
    }

    jsonSuccess($orgs);
}

/**
 * Get single organization
 */
function handleGetOrganization(int $id): void {
    $org = Database::fetchOne(
        "SELECT id, name, acronym, domain, description, is_active,
                created_at, updated_at
         FROM organizations
         WHERE id = ?",
        [$id]
    );

    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    jsonSuccess($org);
}

/**
 * Create organization
 */
function handleCreateOrganization(array $input): void {
    // Only admin_gap can create organizations
    if (!isAdminGap()) {
        jsonError('Sem permissao para criar organizacoes', 403);
    }

    if (empty($input['name']) || empty($input['acronym'])) {
        jsonError('Nome e sigla sao obrigatorios');
    }

    $acronym = strtoupper(sanitizeInput($input['acronym']));
    $name = sanitizeInput($input['name']);
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');

    // Check if acronym already exists
    $existing = Database::fetchOne(
        "SELECT id FROM organizations WHERE acronym = ?",
        [$acronym]
    );

    if ($existing) {
        jsonError('Sigla ja cadastrada');
    }

    try {
        Database::beginTransaction();

        // Insert organization
        Database::execute(
            "INSERT INTO organizations (name, acronym, domain, description) VALUES (?, ?, ?, ?)",
            [$name, $acronym, $domain, $description]
        );

        $orgId = (int) Database::lastInsertId();

        // Copy all default variables for this organization in a single query
        Database::execute(
            "INSERT INTO organization_variables (organization_id, variable_id, value)
             SELECT ?, id, COALESCE(default_value, '') FROM variable_definitions",
            [$orgId]
        );

        Database::commit();

        // Log organization creation
        logActivity($_SESSION['user_id'] ?? null, 'create', 'organization', $orgId, "Created organization '$acronym' - '$name'");
        logAuditEvent($orgId, 'organization', 'create', $orgId, ['acronym' => $acronym, 'name' => $name]);
        log_event("Organization created: acronym=$acronym, name=$name, org_id=$orgId", 'INFO');

        // Return the created organization
        $org = Database::fetchOne(
            "SELECT id, name, acronym, domain, description, is_active, created_at, updated_at FROM organizations WHERE id = ?",
            [$orgId]
        );

        jsonSuccess($org, 'Organizacao criada com sucesso');
    } catch (Exception $e) {
        Database::rollback();
        throw new RuntimeException('Erro ao criar organizacao: ' . $e->getMessage());
    }
}

/**
 * Update organization
 */
function handleUpdateOrganization(int $id, array $input): void {
    $org = Database::fetchOne("SELECT id FROM organizations WHERE id = ?", [$id]);

    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    $name = sanitizeInput($input['name'] ?? '');
    $domain = sanitizeInput($input['domain'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');

    $updates = [];
    $params = [];

    if ($name) {
        $updates[] = 'name = ?';
        $params[] = $name;
    }

    if (isset($input['domain'])) {
        $updates[] = 'domain = ?';
        $params[] = $domain;
    }

    if (isset($input['description'])) {
        $updates[] = 'description = ?';
        $params[] = $description;
    }

    if (empty($updates)) {
        jsonError('Nenhum campo para atualizar');
    }

    $params[] = $id;

    Database::execute(
        "UPDATE organizations SET " . implode(', ', $updates) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        $params
    );

    // Log organization update
    $org = Database::fetchOne("SELECT acronym FROM organizations WHERE id = ?", [$id]);
    logActivity($_SESSION['user_id'] ?? null, 'update', 'organization', $id, "Updated organization '{$org['acronym']}': " . implode(', ', $updates));

    jsonSuccess(null, 'Organização atualizada com sucesso');
}

/**
 * Delete organization (soft delete)
 */
function handleDeleteOrganization(int $id): void {
    $org = Database::fetchOne("SELECT id, acronym, name FROM organizations WHERE id = ?", [$id]);

    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    // Soft delete
    Database::execute(
        "UPDATE organizations SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$id]
    );

    // Log organization deletion
    logActivity($_SESSION['user_id'] ?? null, 'delete', 'organization', $id, "Deleted organization '{$org['acronym']}' - '{$org['name']}'");

    jsonSuccess(null, 'Organização removida com sucesso');
}

/**
 * Get variables for organization
 */
function handleGetVariables(?string $orgId): void {
    if (!$orgId) {
        jsonError('Organization ID required');
    }

    $orgId = (int) $orgId;

    // Permission check
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissão para esta organização', 403);
    }

    $org = Database::fetchOne("SELECT id, acronym FROM organizations WHERE id = ?", [$orgId]);

    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    $variables = Database::fetchAll(
        "SELECT vd.id, vd.name, vd.placeholder, vd.description, vd.category,
                vd.default_value, COALESCE(ov.value, vd.default_value) AS current_value,
                vd.is_required, vd.display_order, vd.type
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.organization_id = ? AND ov.variable_id = vd.id
         ORDER BY vd.display_order",
        [$orgId]
    );

    jsonSuccess([
        'organization' => $org['acronym'],
        'variables' => $variables
    ]);
}

/**
 * Update organization variables with validation
 */
// ============================================================================
// Handler: Add Variable to Organization
// ============================================================================
function handleAddVariable(array $input): void {
    $orgId = (int) ($input['organization_id'] ?? 0);
    $name = sanitizeInput($input['name'] ?? '');
    $value = $input['value'] ?? '';
    $description = sanitizeInput($input['description'] ?? '');
    $type = sanitizeInput($input['type'] ?? 'string');
    $category = sanitizeInput($input['category'] ?? 'general');
    $required = !empty($input['required']);

    if (!$orgId || !$name) {
        jsonError('organization_id e name sao obrigatorios', 400);
    }

    // Permission check
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissao para esta organizacao', 403);
    }

    // Check if variable definition exists, create if not
    $varDef = Database::fetchOne("SELECT id FROM variable_definitions WHERE name = ?", [$name]);
    if (!$varDef) {
        Database::execute(
            "INSERT INTO variable_definitions (name, placeholder, description, category, is_required, type)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$name, '{{' . $name . '}}', $description, $category, $required, $type]
        );
        $varDefId = (int) Database::lastInsertId();
    } else {
        $varDefId = (int) $varDef['id'];
    }

    // Check if org already has this variable
    $existing = Database::fetchOne(
        "SELECT id FROM organization_variables WHERE organization_id = ? AND variable_id = ?",
        [$orgId, $varDefId]
    );

    if ($existing) {
        // Update existing
        Database::execute(
            "UPDATE organization_variables SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$value, $existing['id']]
        );
    } else {
        // Insert new
        Database::execute(
            "INSERT INTO organization_variables (organization_id, variable_id, value) VALUES (?, ?, ?)",
            [$orgId, $varDefId, $value]
        );
    }

    logAuditEvent($orgId, 'variable', 'create', $varDefId, ['name' => $name]);

    jsonSuccess(['variable_id' => $varDefId], 'Variavel adicionada com sucesso');
}

function handleUpdateVariables(array $input): void {
    if (empty($input['organization_id'])) {
    error_log("VARIABLES-UPDATE INPUT: " . json_encode($input));
        jsonError('Organization ID is required');
    }

    $orgId = (int) $input['organization_id'];

    // Accept variables in either format: { "1": "value" } or { "variables": { "1": "value" } }
    $variables = $input['variables'] ?? $input;
    if (!is_array($variables) || empty($variables)) {
        jsonError('Variables array is required');
    }

    // Filter only numeric keys (variable IDs) from the input
    $filteredVars = [];
    foreach ($variables as $key => $value) {
        if (is_numeric($key)) {
            $filteredVars[(int)$key] = $value;
        }
    }

    if (empty($filteredVars)) {
        jsonError('No valid variable IDs found');
    }

    // Permission check
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissao para esta organizacao', 403);
    }

    $org = Database::fetchOne("SELECT id, acronym FROM organizations WHERE id = ?", [$orgId]);

    if (!$org) {
        jsonError('Organizacao nao encontrada', 404);
    }

    // Get warnings for empty required variables (but don't block save)
    $validation = validateAllVariables($filteredVars);
    $warnings = $validation['warnings'] ?? [];

    // Check for empty required variables and add as warnings (not errors - allow partial saves)
    $varDefs = Database::fetchAll("SELECT id, name, is_required FROM variable_definitions WHERE id = ANY(?)", ["{" . implode(",", array_keys($filteredVars)) . "}"]);
    $varMap = [];
    foreach ($varDefs as $v) {
        $varMap[$v['id']] = $v;
    }
    foreach ($filteredVars as $varId => $value) {
        if (isset($varMap[$varId]) && $varMap[$varId]['is_required'] && trim($value) === '') {
            $name = $varMap[$varId]['name'];
            $warnings[] = "Variavel obrigatoria vazia: $name";
        }
    }

    try {
        Database::beginTransaction();

        foreach ($filteredVars as $varId => $value) {
            $value = sanitizeInput((string) $value);

            // Upsert variable value
            Database::execute(
                "INSERT INTO organization_variables (organization_id, variable_id, value)
                 VALUES (?, ?, ?)
                 ON CONFLICT (organization_id, variable_id)
                 DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP",
                [$orgId, $varId, $value]
            );
        }

        Database::commit();

        // Log variables update
        logActivity($_SESSION['user_id'] ?? null, 'update', 'variables', $orgId, "Updated " . count($filteredVars) . " variables for organization '{$org['acronym']}'", $orgId);
        logAuditEvent($orgId, 'variable', 'update', null, ['count' => count($filteredVars)]);
        bumpOrgSerial($orgId);
        log_event("Variables updated for org_id=$orgId, count=" . count($filteredVars), 'INFO');

        jsonResponse([
            'success' => true,
            'message' => 'Variaveis atualizadas com sucesso',
            'warnings' => $warnings,
            'updated_count' => count($filteredVars)
        ]);
    } catch (Exception $e) {
        Database::rollback();
        throw new RuntimeException('Erro ao atualizar variaveis: ' . $e->getMessage());
    }
}

/**
 * Get scripts list
 */
function handleGetScripts(): void {
    $userOrgId = getUserOrgId();
    $orgFilter = $_GET['org'] ?? null;

    if ($userOrgId !== null) {
        // operador_om: core scripts + their org's custom scripts
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, description, is_core, execution_order, version, organization_id,
                    created_at, updated_at
             FROM scripts
             WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?)
             ORDER BY is_core DESC, execution_order ASC",
            [$userOrgId]
        );
    } else if ($orgFilter) {
        // admin_gap filtering by org
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, description, is_core, execution_order, version, organization_id,
                    created_at, updated_at
             FROM scripts
             WHERE is_active = TRUE AND (is_core = TRUE OR organization_id = ?)
             ORDER BY is_core DESC, execution_order ASC",
            [(int) $orgFilter]
        );
    } else {
        // admin_gap: all scripts
        $scripts = Database::fetchAll(
            "SELECT id, name, filename, description, is_core, execution_order, version, organization_id,
                    created_at, updated_at
             FROM scripts
             WHERE is_active = TRUE
             ORDER BY is_core DESC, execution_order ASC"
        );
    }

    jsonSuccess($scripts);
}

/**
 * Upload custom script
 */
function handleUploadScript(): void {
    $input = getJsonInput();

    if (empty($input['name']) || empty($input['content'])) {
        jsonError('Nome e conteudo sao obrigatorios');
    }

    $name = sanitizeInput($input['name']);
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content']; // Don't sanitize - script content
    $isCore = !empty($input['is_core']) && isAdminGap();
    $orgId = !empty($input['organization_id']) ? (int) $input['organization_id'] : null;

    // operador_om can only upload custom scripts for their own org
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null) {
        $orgId = $userOrgId;
        $isCore = false;
    }

    // For non-core scripts, require organization_id
    if (!$isCore && !$orgId && $userOrgId === null) {
        // Admin without org - need to get currentOrgId from session or allow any org
        // For now, use the first active organization as fallback for admin
        $firstOrg = Database::fetchOne("SELECT id FROM organizations WHERE is_active = TRUE ORDER BY id LIMIT 1");
        if ($firstOrg) {
            $orgId = (int) $firstOrg['id'];
        }
    }

    // Validate script content
    if (strpos($content, '#!/bin/bash') === false && strpos($content, '#!/usr/bin/env bash') === false) {
        jsonError('Script deve comecar com shebang (#!/bin/bash)');
    }

    // Extract placeholders
    preg_match_all('/\{\{([A-Z_][A-Z0-9_]*)\}\}/', $content, $matches);
    $placeholders = array_unique($matches[1]);

    // Check if all placeholders exist
    foreach ($placeholders as $placeholder) {
        $exists = Database::fetchOne(
            "SELECT id FROM variable_definitions WHERE name = ?",
            [$placeholder]
        );
        if (!$exists) {
            // Create placeholder if doesn't exist
            Database::execute(
                "INSERT INTO variable_definitions (name, placeholder, description, category, is_required)
                 VALUES (?, '{{' || ? || '}}', ?, 'custom', FALSE)",
                [$placeholder, $placeholder, "Variável personalizada: $placeholder"]
            );
        }
    }

    // Generate filename
    $prefix = $isCore ? 'core' : 'custom';
    $filename = $prefix . '_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name)) . '.sh';

    // Check if filename exists
    $existing = Database::fetchOne(
        "SELECT id FROM scripts WHERE filename = ?",
        [$filename]
    );

    if ($existing) {
        $filename = $prefix . '_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name)) . '_' . time() . '.sh';
    }

    // Get max execution order
    $maxOrder = (int) Database::fetchOne(
        "SELECT COALESCE(MAX(execution_order), 0) as max_order FROM scripts WHERE is_core = ?",
        [$isCore]
    )['max_order'];

    // Insert script
    Database::execute(
        "INSERT INTO scripts (name, filename, description, content, is_core, execution_order, organization_id, version)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
        [$name, $filename, $description, $content, $isCore, $maxOrder + 1, $orgId]
    );

    $scriptId = (int) Database::lastInsertId();
    logAuditEvent($orgId, 'script', 'create', $scriptId, ['name' => $name, 'is_core' => $isCore]);

    jsonSuccess([
        'id' => $scriptId,
        'filename' => $filename,
        'placeholders' => $placeholders
    ], 'Script enviado com sucesso');
}

/**
 * Get single script details
 */
function handleGetScript(int $id): void {
    $script = Database::fetchOne(
        "SELECT id, name, filename, description, content, is_core, execution_order, version, organization_id,
                created_at, updated_at
         FROM scripts
         WHERE id = ? AND is_active = TRUE",
        [$id]
    );

    if (!$script) {
        jsonError('Script não encontrado', 404);
    }

    // Permission check for operador_om
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && !$script['is_core'] && $script['organization_id'] !== $userOrgId) {
        jsonError('Sem permissão para este script', 403);
    }

    jsonSuccess($script);
}

/**
 * Update script
 */
function handleUpdateScript(int $id, array $input): void {
    $script = Database::fetchOne(
        "SELECT id, name, is_core, organization_id FROM scripts WHERE id = ? AND is_active = TRUE",
        [$id]
    );

    if (!$script) {
        jsonError('Script não encontrado', 404);
    }

    // Permission check
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && !$script['is_core'] && $script['organization_id'] !== $userOrgId) {
        jsonError('Sem permissão para este script', 403);
    }

    $name = sanitizeInput($input['name'] ?? $script['name']);
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';

    if (empty($name) || empty($content)) {
        jsonError('Nome e conteúdo são obrigatórios');
    }

    // Validate script content
    if (strpos($content, '#!/bin/bash') === false && strpos($content, '#!/usr/bin/env bash') === false) {
        jsonError('Script deve começar com shebang (#!/bin/bash)');
    }

    Database::execute(
        "UPDATE scripts SET name = ?, description = ?, content = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$name, $description, $content, $id]
    );

    logAuditEvent($script['organization_id'], 'script', 'update', $id, ['name' => $name]);

    jsonSuccess(['id' => $id], 'Script atualizado com sucesso');
}

/**
 * Delete custom script (soft delete)
 */
function handleDeleteScript(int $id): void {
    $script = Database::fetchOne(
        "SELECT id, name, is_core FROM scripts WHERE id = ?",
        [$id]
    );

    if (!$script) {
        jsonError('Script não encontrado', 404);
    }

    if ($script['is_core']) {
        jsonError('Scripts core não podem ser removidos');
    }

    Database::execute(
        "UPDATE scripts SET is_active = FALSE WHERE id = ?",
        [$id]
    );

    logActivity($_SESSION['user_id'] ?? null, 'delete', 'script', $id, "Script '{$script['name']}' deleted");

    jsonSuccess(null, 'Script removido com sucesso');
}

/**
 * Upload wallpaper image
 */
function handleUploadWallpaper(): void {
    $orgId = (int) ($_POST['organization_id'] ?? $_GET['org_id'] ?? 0);

    if (!$orgId) {
        jsonError('Organization ID required', 400);
    }

    // Permission check
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissao para esta organizacao', 403);
    }

    if (!isset($_FILES['wallpaper']) || $_FILES['wallpaper']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Nenhum arquivo enviado ou erro no upload', 400);
    }

    $file = $_FILES['wallpaper'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowedTypes)) {
        jsonError('Tipo de arquivo nao permitido. Use JPG, PNG, GIF ou WebP', 400);
    }

    if ($file['size'] > $maxSize) {
        jsonError('Arquivo muito grande. Maximo 5MB', 400);
    }

    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'wallpaper_org' . $orgId . '_' . time() . '.' . $ext;
    // Use assets/wallpapers for public access (bypassing storage/ protection)
    $uploadDir = __DIR__ . '/../assets/wallpapers/';
    $filepath = $uploadDir . $filename;

    // Create directory if not exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonError('Erro ao salvar arquivo', 500);
    }

    // Delete old wallpaper if exists
    $oldVar = Database::fetchOne(
        "SELECT ov.value FROM organization_variables ov
         JOIN variable_definitions vd ON vd.id = ov.variable_id
         WHERE ov.organization_id = ? AND vd.name = 'WALLPAPER_URL'",
        [$orgId]
    );

    if ($oldVar && !empty($oldVar['value'])) {
        // Check both old and new paths
        $oldPaths = [
            __DIR__ . '/../assets/wallpapers/' . basename($oldVar['value']),
            __DIR__ . '/../storage/wallpapers/' . basename($oldVar['value'])
        ];
        foreach ($oldPaths as $oldFile) {
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }
    }

    // Update WALLPAPER_URL variable
    $wallpaperUrl = '/assets/wallpapers/' . $filename;

    // Get or create variable definition
    $varDef = Database::fetchOne(
        "SELECT id FROM variable_definitions WHERE name = 'WALLPAPER_URL'"
    );

    if (!$varDef) {
        Database::execute(
            "INSERT INTO variable_definitions (name, placeholder, description, category, type, is_required)
             VALUES ('WALLPAPER_URL', '{{WALLPAPER_URL}}', 'URL do wallpaper da organizacao', 'branding', 'url', FALSE)"
        );
        $varDefId = (int) Database::lastInsertId();
    } else {
        $varDefId = (int) $varDef['id'];
    }

    // Upsert organization variable
    $existing = Database::fetchOne(
        "SELECT id FROM organization_variables WHERE organization_id = ? AND variable_id = ?",
        [$orgId, $varDefId]
    );

    if ($existing) {
        Database::execute(
            "UPDATE organization_variables SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$wallpaperUrl, $existing['id']]
        );
    } else {
        Database::execute(
            "INSERT INTO organization_variables (organization_id, variable_id, value) VALUES (?, ?, ?)",
            [$orgId, $varDefId, $wallpaperUrl]
        );
    }

    logAuditEvent($orgId, 'variable', 'update', $varDefId, ['name' => 'WALLPAPER_URL', 'action' => 'upload']);

    jsonSuccess([
        'url' => $wallpaperUrl,
        'filename' => $filename
    ], 'Wallpaper enviado com sucesso');
}

/**
 * Get bundle information
 */
function handleGetBundle(?string $orgId): void {
    if (!$orgId) {
        jsonError('Organization ID required');
    }

    $org = Database::fetchOne(
        "SELECT id, acronym, name FROM organizations WHERE acronym = ? OR id = ?",
        [strtoupper($orgId), (int) $orgId]
    );

    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    $scripts = Database::fetchAll(
        "SELECT id, name, filename, is_core, execution_order
         FROM scripts
         WHERE is_active = TRUE
         ORDER BY is_core DESC, execution_order ASC"
    );

    $variables = Database::fetchAll(
        "SELECT vd.name, COALESCE(ov.value, vd.default_value) AS value
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.organization_id = ? AND ov.variable_id = vd.id",
        [$org['id']]
    );

    jsonSuccess([
        'organization' => $org,
        'scripts' => $scripts,
        'variables_count' => count($variables)
    ]);
}

/**
 * Download bundle
 */
function handleDownloadBundle(?string $orgId): void {
    if (!$orgId) {
        jsonError('Organization ID required');
    }

    $org = Database::fetchOne(
        "SELECT id, acronym, name FROM organizations WHERE acronym = ? OR id = ?",
        [strtoupper($orgId), (int) $orgId]
    );

    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    try {
        $bundle = buildBundle($org['id']);

        // Log execution
        Database::execute(
            "INSERT INTO script_executions (organization_id, script_filename, execution_ip, status, agent_version)
             VALUES (?, ?, ?, ?, ?)",
            [$org['id'], $bundle['filename'], getClientIP(), 'downloaded', 'manual']
        );

        // Log bundle download
        logActivity($_SESSION['user_id'] ?? null, 'download', 'bundle', $org['id'], "Downloaded bundle '{$bundle['filename']}' for organization '{$org['acronym']}'", $org['id']);

        // Send file as download
        header('Content-Type: application/x-sh');
        header('Content-Disposition: attachment; filename="' . $bundle['filename'] . '"');
        header('Content-Length: ' . strlen($bundle['content']));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        echo $bundle['content'];
        exit;
    } catch (Exception $e) {
        jsonError('Erro ao gerar bundle: ' . $e->getMessage());
    }
}

/**
 * Get public statistics
 */
function handleGetStats(): void {
    $stats = [
        'organizations' => (int) Database::fetchOne(
            "SELECT COUNT(*) as count FROM organizations WHERE is_active = TRUE"
        )['count'],
        'scripts' => (int) Database::fetchOne(
            "SELECT COUNT(*) as count FROM scripts WHERE is_active = TRUE"
        )['count'],
        'core_scripts' => (int) Database::fetchOne(
            "SELECT COUNT(*) as count FROM scripts WHERE is_active = TRUE AND is_core = TRUE"
        )['count'],
        'variables' => (int) Database::fetchOne(
            "SELECT COUNT(*) as count FROM variable_definitions"
        )['count'],
        'stations' => (int) Database::fetchOne(
            "SELECT COUNT(*) as count FROM script_executions"
        )['count']
    ];

    jsonSuccess($stats);
}

/**
 * Health check endpoint
 */
function handleHealthCheck(): void {
    $status = 'ok';
    $checks = [];

    // Check database
    try {
        Database::fetchOne("SELECT 1 as test");
        $checks['database'] = ['status' => 'ok', 'message' => 'PostgreSQL connected'];
    } catch (Exception $e) {
        $status = 'error';
        $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // Check PHP version
    $checks['php'] = [
        'status' => 'ok',
        'version' => PHP_VERSION
    ];

    // Check writable directories
    $storagePath = realpath(__DIR__ . '/../storage');
    if ($storagePath) {
        $checks['storage'] = [
            'status' => is_writable($storagePath) ? 'ok' : 'error',
            'path' => $storagePath,
            'writable' => is_writable($storagePath)
        ];
    } else {
        $checks['storage'] = ['status' => 'error', 'message' => 'Storage directory not found'];
    }

    $response = [
        'status' => $status,
        'timestamp' => date('c'),
        'version' => '1.0.0',
        'checks' => $checks
    ];

    http_response_code($status === 'ok' ? 200 : 503);
    jsonResponse($response);
}

/**
 * Get activity log - Protected by admin session
 */
function handleGetActivityLog(?string $limit): void {
    // Rate limiting
    $limit = min((int) ($limit ?? 50), 100);

    // Optional filters from query string
    $action = $_GET['filter_action'] ?? null;
    $orgId = isset($_GET['filter_org']) ? (int) $_GET['filter_org'] : null;
    $target = $_GET['filter_target'] ?? null;

    $sql = "SELECT
                al.id,
                al.user_id,
                al.action,
                al.target,
                al.target_id,
                al.details,
                al.organization_id,
                al.ip_address,
                al.user_agent,
                al.session_id,
                al.created_at,
                u.username,
                u.full_name as user_name,
                o.acronym as org_acronym,
                o.name as org_name
            FROM activity_log al
            LEFT JOIN users u ON u.id = al.user_id
            LEFT JOIN organizations o ON o.id = al.organization_id
            WHERE 1=1";

    $params = [];

    if ($action) {
        $sql .= " AND al.action = ?";
        $params[] = sanitizeInput($action);
    }

    if ($orgId) {
        $sql .= " AND al.organization_id = ?";
        $params[] = $orgId;
    }

    if ($target) {
        $sql .= " AND al.target = ?";
        $params[] = sanitizeInput($target);
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT ?";
    $params[] = $limit;

    $logs = Database::fetchAll($sql, $params);

    jsonSuccess([
        'total' => count($logs),
        'limit' => $limit,
        'filters' => [
            'action' => $action,
            'organization_id' => $orgId,
            'target' => $target
        ],
        'data' => $logs
    ]);
}

/**
 * Get system settings (admin only)
 */
function handleGetSettings(): void {
    $settings = Database::fetchAll(
        "SELECT key, value, value_type, description, is_public, updated_at FROM system_settings ORDER BY key"
    );

    $result = [];
    foreach ($settings as $setting) {
        $result[$setting['key']] = [
            'value' => $setting['value'],
            'type' => $setting['value_type'],
            'description' => $setting['description'],
            'is_public' => (bool) $setting['is_public'],
            'updated_at' => $setting['updated_at']
        ];
    }

    jsonSuccess([
        'settings' => $result,
        'count' => count($result)
    ]);
}

/**
 * Update system settings (admin only) with validation
 */
function handleUpdateSettings(array $input): void {
    // Define allowed settings and their validation rules
    $allowedSettings = [
        'app_name' => ['type' => 'string', 'max_length' => 100],
        'require_https' => ['type' => 'boolean'],
        'max_login_attempts' => ['type' => 'integer', 'min' => 1, 'max' => 10],
        'login_lockout_minutes' => ['type' => 'integer', 'min' => 5, 'max' => 60],
        'session_timeout' => ['type' => 'integer', 'min' => 3600, 'max' => 604800],
        'bundle_retention_days' => ['type' => 'integer', 'min' => 1, 'max' => 365],
        'max_bundle_downloads' => ['type' => 'integer', 'min' => 10, 'max' => 1000],
        'enable_activity_log' => ['type' => 'boolean'],
        'default_timezone' => ['type' => 'timezone']
    ];

    $updated = [];
    $errors = [];

    foreach ($input as $key => $value) {
        if (!isset($allowedSettings[$key])) {
            $errors[] = "Configuração '$key' não pode ser modificada";
            continue;
        }

        $rule = $allowedSettings[$key];
        $value = sanitizeInput((string) $value);

        // Validate based on type
        $valid = true;
        switch ($rule['type']) {
            case 'string':
                if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                    $errors[] = "$key deve ter no máximo {$rule['max_length']} caracteres";
                    $valid = false;
                }
                break;

            case 'integer':
                if (!is_numeric($value)) {
                    $errors[] = "$key deve ser um número inteiro";
                    $valid = false;
                } else {
                    $intVal = (int) $value;
                    if (isset($rule['min']) && $intVal < $rule['min']) {
                        $errors[] = "$key deve ser no mínimo {$rule['min']}";
                        $valid = false;
                    }
                    if (isset($rule['max']) && $intVal > $rule['max']) {
                        $errors[] = "$key deve ser no máximo {$rule['max']}";
                        $valid = false;
                    }
                }
                break;

            case 'boolean':
                if (!in_array($value, ['true', 'false', '1', '0', 'yes', 'no'])) {
                    $errors[] = "$key deve ser true ou false";
                    $valid = false;
                }
                // Normalize boolean
                $value = in_array($value, ['true', '1', 'yes']) ? 'true' : 'false';
                break;

            case 'timezone':
                if (!in_array($value, timezone_identifiers_list())) {
                    $errors[] = "$key não é um fuso horário válido";
                    $valid = false;
                }
                break;
        }

        if ($valid) {
            Database::execute(
                "UPDATE system_settings
                 SET value = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?
                 WHERE key = ?",
                [$value, $_SESSION['user_id'] ?? null, $key]
            );
            $updated[] = $key;
        }
    }

    if (!empty($errors)) {
        jsonError('Erros de validação', 400, $errors);
        return;
    }

    logActivity($_SESSION['user_id'] ?? null, 'update', 'settings', null, 'Updated settings: ' . implode(', ', $updated));

    jsonSuccess([
        'updated' => $updated,
        'message' => count($updated) . ' configurações atualizadas'
    ], 'Configurações atualizadas com sucesso');
}

// ============================================================================
// Handler: Get Dashboard
// ============================================================================
function handleGetDashboard(): void {
    $userOrgId = getUserOrgId();

    if ($userOrgId !== null) {
        // operador_om: only their org
        $orgCount = 1;
        $varCount = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM organization_variables WHERE organization_id = ?",
            [$userOrgId]
        )['c'];
        $scriptCount = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM scripts WHERE is_active = TRUE AND (organization_id = ? OR is_core = TRUE)",
            [$userOrgId]
        )['c'];
        $bundleCount = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM deploy_bundles WHERE organization_id = ? AND generated_at >= date_trunc('month', CURRENT_TIMESTAMP)",
            [$userOrgId]
        )['c'];
        $stationsOnline = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM stations WHERE organization_id = ? AND status = 'online'",
            [$userOrgId]
        )['c'];
        $stationsOutdated = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM stations s JOIN organizations o ON o.id = s.organization_id WHERE s.organization_id = ? AND s.configuration_serial < o.serial_config",
            [$userOrgId]
        )['c'];
        $recentStations = Database::fetchAll(
            "SELECT s.id, s.hostname, s.ip_address, s.last_checkin, s.status, s.configuration_serial, o.serial_config
             FROM stations s JOIN organizations o ON o.id = s.organization_id
             WHERE s.organization_id = ? AND s.last_checkin IS NOT NULL
             ORDER BY s.last_checkin DESC LIMIT 5",
            [$userOrgId]
        );
    } else {
        // admin_gap/auditor: all orgs
        $orgCount = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM organizations WHERE is_active = TRUE")['c'];
        $varCount = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM organization_variables")['c'];
        $scriptCount = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM scripts WHERE is_active = TRUE")['c'];
        $bundleCount = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM deploy_bundles WHERE generated_at >= date_trunc('month', CURRENT_TIMESTAMP)")['c'];
        $stationsOnline = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM stations WHERE status = 'online'")['c'];
        $stationsOutdated = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM stations s JOIN organizations o ON o.id = s.organization_id WHERE s.configuration_serial < o.serial_config"
        )['c'];
        $recentStations = Database::fetchAll(
            "SELECT s.id, s.hostname, s.ip_address, s.last_checkin, s.status, s.configuration_serial, o.serial_config, o.acronym AS org_acronym
             FROM stations s JOIN organizations o ON o.id = s.organization_id
             WHERE s.last_checkin IS NOT NULL
             ORDER BY s.last_checkin DESC LIMIT 5"
        );
    }

    jsonSuccess([
        'organizations' => $orgCount,
        'variables' => $varCount,
        'scripts' => $scriptCount,
        'bundles_this_month' => $bundleCount,
        'stations_online' => $stationsOnline,
        'stations_outdated' => $stationsOutdated,
        'recent_stations' => $recentStations,
    ]);
}

// ============================================================================
// Handler: Get Audit Events
// ============================================================================
function handleGetAuditEvents(): void {
    $userOrgId = getUserOrgId();
    $limit = min((int) ($_GET['limit'] ?? 100), 500);
    $orgFilter = $userOrgId ?? (isset($_GET['org']) ? (int) $_GET['org'] : null);

    if ($orgFilter !== null) {
        $events = Database::fetchAll(
            "SELECT ae.*, u.username, u.full_name, o.acronym AS org_acronym
             FROM audit_events ae
             LEFT JOIN users u ON u.id = ae.user_id
             LEFT JOIN organizations o ON o.id = ae.organization_id
             WHERE ae.organization_id = ?
             ORDER BY ae.created_at DESC
             LIMIT ?",
            [$orgFilter, $limit]
        );
    } else {
        $events = Database::fetchAll(
            "SELECT ae.*, u.username, u.full_name, o.acronym AS org_acronym
             FROM audit_events ae
             LEFT JOIN users u ON u.id = ae.user_id
             LEFT JOIN organizations o ON o.id = ae.organization_id
             ORDER BY ae.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    jsonSuccess($events);
}

// ============================================================================
// Handler: Get Variable Catalog
// ============================================================================
function handleGetVariableCatalog(): void {
    $catalog = Database::fetchAll(
        "SELECT id, name, placeholder, description, default_value, category, is_required, display_order
         FROM variable_definitions
         ORDER BY display_order, name"
    );

    jsonSuccess($catalog);
}

// ============================================================================
// Handler: Generate Bundle (POST /api/?action=generate-bundle)
// ============================================================================
function handleGenerateBundle(array $input): void {
    $orgId = (int) ($input['organization_id'] ?? 0);
    $scriptIds = $input['script_ids'] ?? [];

    if (!$orgId || empty($scriptIds)) {
        jsonError('organization_id e script_ids sao obrigatorios', 400);
    }

    // Permission check: operador_om can only generate for their own org
    $userOrgId = getUserOrgId();
    if ($userOrgId !== null && $userOrgId !== $orgId) {
        jsonError('Sem permissao para esta organizacao', 403);
    }

    // Verify org exists
    $org = Database::fetchOne("SELECT * FROM organizations WHERE id = ? AND is_active = TRUE", [$orgId]);
    if (!$org) {
        jsonError('Organização não encontrada', 404);
    }

    // Load scripts first (needed to determine which placeholders are used)
    $idList = array_map('intval', $scriptIds);
    $phList = implode(',', array_fill(0, count($idList), '?'));

    $scripts = Database::fetchAll(
        "SELECT * FROM scripts WHERE id IN ($phList) AND is_active = TRUE ORDER BY is_core DESC, execution_order, name",
        $idList
    );

    if (empty($scripts)) {
        jsonError('Nenhum script válido selecionado', 400);
    }

    // Extract all placeholders used in the selected scripts
    $usedPlaceholders = [];
    foreach ($scripts as $script) {
        preg_match_all('/\{\{([A-Z_][A-Z0-9_]*)\}\}/', $script['content'], $matches);
        foreach ($matches[1] as $name) {
            $usedPlaceholders[$name] = true;
        }
    }

    // Check only required variables that are actually used in the selected scripts
    $missingRequired = [];
    foreach (array_keys($usedPlaceholders) as $varName) {
        $varDef = Database::fetchOne("SELECT id, is_required, default_value FROM variable_definitions WHERE name = ?", [$varName]);
        if (!$varDef) continue;
        if (!$varDef['is_required']) continue;

        // Check if org has a non-empty value
        $orgVar = Database::fetchOne(
            "SELECT value FROM organization_variables WHERE organization_id = ? AND variable_id = ?",
            [$orgId, $varDef['id']]
        );
        $hasValue = ($orgVar && $orgVar['value'] !== null && $orgVar['value'] !== '')
            || ($varDef['default_value'] !== null && $varDef['default_value'] !== '');

        if (!$hasValue) {
            $missingRequired[] = $varName;
        }
    }

    if (!empty($missingRequired)) {
        jsonError('Variáveis obrigatórias não preenchidas: ' . implode(', ', $missingRequired), 400, $missingRequired);
    }

    // Process each script: substitute placeholders
    $bundleContent = "#!/bin/bash\n";
    $bundleContent .= "# ============================================================================\n";
    $bundleContent .= "# SeederLinux Lite - Provisionamento Automatizado\n";
    $bundleContent .= "# Organização: " . $org['name'] . " (" . $org['acronym'] . ")\n";
    $bundleContent .= "# Domínio: " . ($org['domain'] ?? 'N/A') . "\n";
    $bundleContent .= "# Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $bundleContent .= "# Scripts: " . count($scripts) . "\n";
    $bundleContent .= "# ============================================================================\n\n";

    $allWarnings = [];

    foreach ($scripts as $script) {
        $result = substituir_placeholders($script['content'], $orgId);
        $bundleContent .= $result['content'] . "\n\n";
        if (!empty($result['warnings'])) {
            $allWarnings = array_merge($allWarnings, $result['warnings']);
        }
    }

    // Save to deploy_bundles
    $filename = "provision-" . strtolower($org['acronym']) . ".sh";
    $scriptIdsStr = implode(',', $idList);

    Database::execute(
        "INSERT INTO deploy_bundles (organization_id, user_id, filename, content, script_ids, scripts_count)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$orgId, $_SESSION['user_id'] ?? null, $filename, $bundleContent, $scriptIdsStr, count($scripts)]
    );

    $bundleId = (int) Database::lastInsertId();

    // Log audit event
    logAuditEvent($orgId, 'bundle', 'generate', $bundleId, ['scripts' => count($scripts), 'filename' => $filename]);
    log_event("Bundle generated: org_id=$orgId, bundle_id=$bundleId, scripts=" . count($scripts), 'INFO');

    jsonSuccess([
        'bundle_id' => $bundleId,
        'download_url' => "/api/?action=bundle-by-id&id={$bundleId}",
        'filename' => $filename,
        'scripts_count' => count($scripts),
        'warnings' => $allWarnings,
    ], 'Bundle gerado com sucesso');
}

// ============================================================================
// Handler: Download Bundle by ID
// ============================================================================
function handleDownloadBundleById(int $bundleId): void {
    $userOrgId = getUserOrgId();

    $query = "SELECT * FROM deploy_bundles WHERE id = ?";
    $params = [$bundleId];

    if ($userOrgId !== null) {
        $query .= " AND organization_id = ?";
        $params[] = $userOrgId;
    }

    $bundle = Database::fetchOne($query, $params);
    if (!$bundle) {
        jsonError('Bundle não encontrado', 404);
    }

    $filename = $bundle['filename'];
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bundle['content']));
    echo $bundle['content'];
    exit;
}

// ============================================================================
// Handler: Get Users
// ============================================================================
function handleGetUsers(): void {
    $users = Database::fetchAll(
        "SELECT u.id, u.username, u.email, u.full_name, u.role, u.is_active, u.organization_id,
                o.acronym AS org_acronym, u.created_at
         FROM users u
         LEFT JOIN organizations o ON o.id = u.organization_id
         ORDER BY u.id"
    );

    jsonSuccess($users);
}

// ============================================================================
// Handler: Create User
// ============================================================================
function handleCreateUser(array $input): void {
    $username = sanitizeInput($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $email = sanitizeInput($input['email'] ?? '');
    $fullName = sanitizeInput($input['full_name'] ?? '');
    $role = sanitizeInput($input['role'] ?? 'operador_om');
    $orgId = !empty($input['organization_id']) ? (int) $input['organization_id'] : null;

    if (!$username || !$password) {
        jsonError('Username e password são obrigatórios', 400);
    }

    $existing = Database::fetchOne("SELECT id FROM users WHERE username = ?", [$username]);
    if ($existing) {
        jsonError('Username já existe', 400);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    Database::execute(
        "INSERT INTO users (username, password_hash, email, full_name, role, organization_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, TRUE)",
        [$username, $hash, $email, $fullName, $role, $orgId]
    );

    $userId = (int) Database::lastInsertId();
    logAuditEvent($orgId, 'user', 'create', $userId, ['username' => $username, 'role' => $role]);

    jsonSuccess(['id' => $userId], 'Usuário criado com sucesso');
}

// ============================================================================
// Handler: Update User
// ============================================================================
function handleUpdateUser(int $userId, array $input): void {
    $email = sanitizeInput($input['email'] ?? '');
    $fullName = sanitizeInput($input['full_name'] ?? '');
    $role = sanitizeInput($input['role'] ?? null);
    $orgId = !empty($input['organization_id']) ? (int) $input['organization_id'] : null;
    $isActive = isset($input['is_active']) ? (bool) $input['is_active'] : null;
    $password = $input['password'] ?? '';

    $updates = [];
    $params = [];

    if ($email) { $updates[] = 'email = ?'; $params[] = $email; }
    if ($fullName) { $updates[] = 'full_name = ?'; $params[] = $fullName; }
    if ($role) { $updates[] = 'role = ?'; $params[] = $role; }
    if ($orgId !== null) { $updates[] = 'organization_id = ?'; $params[] = $orgId; }
    if ($isActive !== null) { $updates[] = 'is_active = ?'; $params[] = $isActive; }
    if ($password) {
        $updates[] = 'password_hash = ?';
        $params[] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    if (empty($updates)) {
        jsonError('Nada para atualizar', 400);
    }

    $updates[] = 'updated_at = CURRENT_TIMESTAMP';
    $params[] = $userId;

    Database::execute(
        "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?",
        $params
    );

    logAuditEvent($orgId, 'user', 'update', $userId, ['role' => $role]);

    jsonSuccess(null, 'Usuário atualizado com sucesso');
}

// ============================================================================
// Handler: Delete User
// ============================================================================
function handleDeleteUser(int $userId): void {
    $user = Database::fetchOne("SELECT id FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        jsonError('Usuário não encontrado', 404);
    }

    Database::execute("DELETE FROM users WHERE id = ?", [$userId]);
    logAuditEvent(null, 'user', 'delete', $userId, null);

    jsonSuccess(null, 'Usuário excluído com sucesso');
}

// ============================================================================
// Helper: Log Audit Event
// ============================================================================
function logAuditEvent(?int $orgId, string $entity, string $action, ?int $entityId, ?array $details): void {
    try {
        Database::execute(
            "INSERT INTO audit_events (organization_id, user_id, entity, entity_id, action, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?)",
            [
                $orgId,
                $_SESSION['user_id'] ?? null,
                $entity,
                $entityId,
                $action,
                $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]
        );
    } catch (Exception $e) {
        error_log('Failed to log audit event: ' . $e->getMessage());
    }
}

// ============================================================================
// Helper: Bump organization serial_config (call when config changes)
// ============================================================================
function bumpOrgSerial(int $orgId): void {
    Database::execute(
        "UPDATE organizations SET serial_config = serial_config + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$orgId]
    );
}

// ============================================================================
// Handler: Station Check-in (POST /api/?action=checkin)
// Auth: Bearer token (station token), not session-based
// ============================================================================
function handleStationCheckin(array $input): void {
    log_event('Check-in request received', 'INFO');

    $token = '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }
    $token = $token ?: ($input['token'] ?? '');

    if (!$token) {
        log_event('Check-in failed: no token provided', 'WARNING');
        jsonError('Token de estação é obrigatório', 401);
    }

    $hostname = sanitizeInput($input['hostname'] ?? '');
    $osName = sanitizeInput($input['os_name'] ?? '');
    $osVersion = sanitizeInput($input['os_version'] ?? '');
    $ipAddress = sanitizeInput($input['ip_address'] ?? '');
    $macAddress = sanitizeInput($input['mac_address'] ?? '');
    $serialNumber = sanitizeInput($input['serial_number'] ?? '');

    if (!$hostname) {
        jsonError('hostname é obrigatório', 400);
    }

    // Find station by token
    $station = Database::fetchOne("SELECT * FROM stations WHERE token = ?", [$token]);

    if (!$station) {
        // Auto-register: create new station linked to default org (ID=1)
        $defaultOrg = Database::fetchOne("SELECT id FROM organizations WHERE is_active = TRUE ORDER BY id LIMIT 1");
        $orgId = $defaultOrg ? (int) $defaultOrg['id'] : 1;

        Database::execute(
            "INSERT INTO stations (organization_id, hostname, serial_number, os_name, os_version, ip_address, mac_address, last_checkin, status, configuration_serial, token)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'online', 0, ?)",
            [$orgId, $hostname, $serialNumber, $osName, $osVersion, $ipAddress, $macAddress, $token]
        );
        $stationId = (int) Database::lastInsertId();
        $station = Database::fetchOne("SELECT * FROM stations WHERE id = ?", [$stationId]);
        log_event("New station auto-registered: hostname=$hostname, org=$orgId", 'INFO');
        logAuditEvent($orgId, 'station', 'auto_register', $stationId, ['hostname' => $hostname]);
    } else {
        // Update existing station
        Database::execute(
            "UPDATE stations SET hostname = ?, os_name = ?, os_version = ?, ip_address = ?, mac_address = ?, serial_number = ?, last_checkin = CURRENT_TIMESTAMP, status = 'online' WHERE id = ?",
            [$hostname, $osName, $osVersion, $ipAddress, $macAddress, $serialNumber, $station['id']]
        );
        log_event("Station check-in: hostname=$hostname, station_id={$station['id']}", 'INFO');
    }

    $orgId = (int) $station['organization_id'];

    // Compare configuration_serial
    $org = Database::fetchOne("SELECT serial_config FROM organizations WHERE id = ?", [$orgId]);
    $orgSerial = $org ? (int) $org['serial_config'] : 0;
    $stationSerial = (int) $station['configuration_serial'];
    $updateAvailable = $orgSerial > $stationSerial;

    // Find latest bundle for this org
    $latestBundleId = null;
    if ($updateAvailable) {
        $latestBundle = Database::fetchOne(
            "SELECT id FROM deploy_bundles WHERE organization_id = ? ORDER BY generated_at DESC LIMIT 1",
            [$orgId]
        );
        $latestBundleId = $latestBundle ? (int) $latestBundle['id'] : null;
    }

    jsonSuccess([
        'station_id' => (int) $station['id'],
        'update_available' => $updateAvailable,
        'latest_bundle_id' => $latestBundleId,
        'configuration_serial' => $orgSerial,
    ], 'Check-in realizado com sucesso');
}

// ============================================================================
// Handler: Get Stations (GET /api/?action=stations)
// ============================================================================
function handleGetStations(): void {
    $userOrgId = getUserOrgId();
    $orgFilter = $userOrgId ?? (isset($_GET['org']) ? (int) $_GET['org'] : null);

    // Update offline status for stations that haven't checked in recently
    Database::execute(
        "UPDATE stations SET status = 'offline' WHERE last_checkin IS NOT NULL AND last_checkin < NOW() - INTERVAL '2 hours' AND status = 'online'"
    );

    if ($orgFilter !== null) {
        $stations = Database::fetchAll(
            "SELECT s.*, o.acronym AS org_acronym
             FROM stations s
             LEFT JOIN organizations o ON o.id = s.organization_id
             WHERE s.organization_id = ?
             ORDER BY s.last_checkin DESC NULLS LAST",
            [$orgFilter]
        );
    } else {
        $stations = Database::fetchAll(
            "SELECT s.*, o.acronym AS org_acronym
             FROM stations s
             LEFT JOIN organizations o ON o.id = s.organization_id
             ORDER BY s.last_checkin DESC NULLS LAST"
        );
    }

    jsonSuccess($stations);
}

// ============================================================================
// Handler: Get Station (GET /api/?action=station&id=N)
// ============================================================================
function handleGetStation(int $stationId): void {
    $userOrgId = getUserOrgId();

    $query = "SELECT s.*, o.acronym AS org_acronym, o.name AS org_name FROM stations s LEFT JOIN organizations o ON o.id = s.organization_id WHERE s.id = ?";
    $params = [$stationId];

    if ($userOrgId !== null) {
        $query .= " AND s.organization_id = ?";
        $params[] = $userOrgId;
    }

    $station = Database::fetchOne($query, $params);
    if (!$station) {
        jsonError('Estação não encontrada', 404);
    }

    jsonSuccess($station);
}

// ============================================================================
// Handler: Delete Station (DELETE /api/?action=station&id=N)
// ============================================================================
function handleDeleteStation(int $stationId): void {
    $userOrgId = getUserOrgId();

    $query = "SELECT id, organization_id FROM stations WHERE id = ?";
    $params = [$stationId];

    if ($userOrgId !== null) {
        $query .= " AND organization_id = ?";
        $params[] = $userOrgId;
    }

    $station = Database::fetchOne($query, $params);
    if (!$station) {
        jsonError('Estação não encontrada', 404);
    }

    Database::execute("DELETE FROM stations WHERE id = ?", [$stationId]);
    logAuditEvent($station['organization_id'], 'station', 'delete', $stationId, null);
    log_event("Station deleted: station_id=$stationId", 'INFO');

    jsonSuccess(null, 'Estação excluída com sucesso');
}
