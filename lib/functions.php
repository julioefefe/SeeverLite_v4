<?php
/**
 * SeederLinux Lite - Helper Functions
 */

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

function logActivity($userId, $action, $target, $targetId, $details = '') {
    // Stub - can be implemented later
}

function logAuditEvent($orgId, $entity, $action, $entityId, $details = []) {
    // Stub - can be implemented later
}

// Category labels for variables
$CATEGORY_LABELS = [
    'dominio' => 'Dominio e Autenticacao',
    'rede' => 'Configuracao de Rede',
    'proxy' => 'Proxy e Internet',
    'inventario' => 'Inventario',
    'navegador' => 'Navegador',
    'seguranca' => 'Seguranca',
    'branding' => 'Identidade Visual',
    'general' => 'Geral',
    'custom' => 'Personalizadas',
    'arquivos' => 'Arquivos e Diretorios',
    'acesso_remoto' => 'Acesso Remoto',
    'impressoras' => 'Impressoras',
    'certificados' => 'Certificados',
    'repositorios' => 'Repositorios'
];
