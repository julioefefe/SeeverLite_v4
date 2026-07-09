<?php
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
    if (!isset($_SESSION['user_id'])) jsonError('Autenticacao necessaria', 401);
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

function substituir_placeholders($content, $orgId) {
    $vars = Database::fetchAll(
        "SELECT vd.name, ov.value FROM organization_variables ov
         JOIN variable_definitions vd ON vd.id = ov.variable_id
         WHERE ov.organization_id = ?",
        [$orgId]
    );
    foreach ($vars as $v) {
        $content = str_replace('{{' . $v['name'] . '}}', $v['value'] ?? '', $content);
    }
    return $content;
}
