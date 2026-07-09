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

function isAuditor() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'auditor';
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
    error_log("[$level] " . date('Y-m-d H:i:s') . " - $msg");
}

function log_audit($action, $entity, $entityId = null, $details = null) {
    $userId = $_SESSION['user_id'] ?? null;
    $orgId = $_SESSION['organization_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    Database::execute(
        "INSERT INTO audit_events (user_id, organization_id, action, entity, entity_id, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
        [$userId, $orgId, $action, $entity, $entityId, $details ? json_encode($details) : null, $ip]
    );
}

/**
 * Substitui placeholders {{VARIAVEL}} pelos valores reais das variáveis da organização
 */
function substituir_placeholders($content, $orgId) {
    $vars = Database::fetchAll(
        "SELECT vd.name, ov.value FROM organization_variables ov
         JOIN variable_definitions vd ON vd.id = ov.variable_id
         WHERE ov.organization_id = ?",
        [$orgId]
    );

    foreach ($vars as $v) {
        $placeholder = '{{' . $v['name'] . '}}';
        $value = $v['value'] ?? '';
        $content = str_replace($placeholder, $value, $content);
    }

    return $content;
}

/**
 * Gera valores dinâmicos para uma nova organização
 */
function generateDefaultVariables($orgId, $name, $acronym, $domain, $dcIp = null, $dnsPrimario = null, $dnsSecundario = null, $proxyHttp = null, $proxyPorta = null) {
    // Valores padrão usando caminhos LOCAIS
    $defaultValues = [
        'DOMINIO' => $domain,
        'DOMINIO_NETBIOS' => strtoupper($acronym),
        'OM_ACRONYM' => strtoupper($acronym),
        'OM_NAME' => $name,
        'DISPLAY_NAME' => $name,
        'BASE_URL' => $domain ? "https://softwarelivre.{$domain}" : '',
        'WALLPAPER_URL' => '/assets/wallpapers/default.jpg',
        'LOGO_URL' => '/assets/logos/default.png',
        'HOMEPAGE' => $domain ? "www.{$domain}" : '',
        'OCS_SERVER' => $domain ? "http://ocs.{$domain}/ocsinventory" : '',
        'OCS_TAG' => strtoupper($acronym) . '-ESTACOES',
        'PROXY_URL' => $domain ? "http://proxy.{$domain}:8080" : '',
        'NO_PROXY' => $domain ? "localhost,127.0.0.1,{$domain}" : '',
        'OU_PADRAO' => $domain ? 'OU=Estacoes,' . implode(',', array_map(fn($p) => "DC=$p", explode('.', $domain))) : '',
        'REPOSITORY_URL' => $domain ? "https://softwarelivre.{$domain}" : '',
    ];

    if ($dcIp) $defaultValues['DC_IP'] = $dcIp;
    if ($dnsPrimario) $defaultValues['DNS_PRIMARIO'] = $dnsPrimario;
    if ($dnsSecundario) $defaultValues['DNS_SECUNDARIO'] = $dnsSecundario;
    if ($proxyHttp) $defaultValues['PROXY_HTTP'] = $proxyHttp;
    if ($proxyPorta) $defaultValues['PROXY_PORTA'] = $proxyPorta;

    // Atualiza as variáveis da organização
    foreach ($defaultValues as $varName => $varValue) {
        Database::execute(
            "UPDATE organization_variables ov SET value = ?
             FROM variable_definitions vd
             WHERE ov.organization_id = ? AND ov.variable_id = vd.id AND vd.name = ?",
            [$varValue, $orgId, $varName]
        );
    }
}

/**
 * Gera thumbnail de imagem
 */
function generateThumbnail($srcPath, $dstPath, $width = 100, $height = 70) {
    try {
        $info = getimagesize($srcPath);
        if (!$info) return false;

        $type = $info[2];
        $src = match($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG => imagecreatefrompng($srcPath),
            IMAGETYPE_GIF => imagecreatefromgif($srcPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($srcPath),
            default => false
        };

        if (!$src) return false;

        $thumb = imagecreatetruecolor($width, $height);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $width, $height, imagesx($src), imagesy($src));

        match($type) {
            IMAGETYPE_JPEG => imagejpeg($thumb, $dstPath, 85),
            IMAGETYPE_PNG => imagepng($thumb, $dstPath, 8),
            IMAGETYPE_GIF => imagegif($thumb, $dstPath),
            IMAGETYPE_WEBP => imagewebp($thumb, $dstPath, 85),
            default => false
        };

        imagedestroy($src);
        imagedestroy($thumb);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
