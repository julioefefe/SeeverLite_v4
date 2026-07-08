<?php
/**
 * SeederLinux Lite - Helper Functions Library
 * Security, validation, and utility functions
 */

declare(strict_types=1);

/**
 * Generate CSRF token
 */
function generateCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken(?string $token): bool {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input string
 */
function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize array of inputs
 */
function sanitizeArray(array $input): array {
    return array_map('sanitizeInput', $input);
}

/**
 * Validate email format
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate IP address
 */
function isValidIP(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Get client IP address
 */
function getClientIP(): string {
    $headers = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = filter_var($_SERVER[$header], FILTER_VALIDATE_IP);
            if ($ip !== false) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Generate secure password hash
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Generate random token
 */
function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/**
 * Send JSON response
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error JSON response
 */
function jsonError(string $message, int $code = 400, array $errors = []): void {
    jsonResponse([
        'success' => false,
        'error' => $message,
        'errors' => $errors
    ], $code);
}

/**
 * Send success JSON response
 */
function jsonSuccess($data = null, string $message = 'Success'): void {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Check if request is POST
 */
function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if request is GET
 */
function isGet(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Get JSON input from request body
 */
function getJsonInput(): array {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

/**
 * Redirect to URL using absolute path
 */
function redirect(string $url): void {
    // Use Config for absolute URLs if available
    if (class_exists('Config')) {
        // Make relative URLs absolute
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = Config::url($url);
        }
    } else {
        // Fallback: ensure absolute URL path
        if (!preg_match('/^https?:\/\//', $url) && !str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }
    }
    header("Location: $url", true, 302);
    exit;
}

/**
 * Get base URL for the application
 */
function getBaseUrl(): string {
    if (class_exists('Config')) {
        return Config::getBaseUrl();
    }
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$protocol://$host";
}

/**
 * Format date for display
 */
function formatDate(?string $date, string $format = 'd/m/Y H:i'): string {
    if (empty($date)) return '-';
    $dt = new DateTime($date);
    return $dt->format($format);
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Validate placeholder format
 */
function isValidPlaceholder(string $placeholder): bool {
    return preg_match('/^\{\{[A-Z_][A-Z0-9_]*\}\}$/', $placeholder) === 1;
}

/**
 * Validate IP address or CIDR
 */
function isValidIPorCIDR(string $value): bool {
    $value = trim($value);
    // Validate simple IP
    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return true;
    }
    // Validate CIDR notation
    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $value)) {
        return true;
    }
    return false;
}

/**
 * Validate URL (supports http, https, ftp)
 */
function isValidURL(string $url): bool {
    $url = trim($url);
    if (empty($url)) return false;

    // Allow URLs without protocol
    if (!preg_match('/^https?:\/\//', $url) && !preg_match('/^ftp:\/\//', $url)) {
        $url = 'http://' . $url;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Validate domain format (e.g., comara.intraer, example.com)
 */
function isValidDomain(string $domain): bool {
    $domain = trim($domain);
    if (empty($domain)) return false;
    return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9](\.[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9])*$/', $domain) === 1;
}

/**
 * Validate port number (1-65535)
 */
function isValidPort(string $port): bool {
    $port = trim($port);
    if (!is_numeric($port)) return false;
    $p = (int) $port;
    return $p >= 1 && $p <= 65535;
}

/**
 * Validate NetBIOS name (max 15 chars, alphanumeric and hyphens)
 */
function isValidNetBIOS(string $name): bool {
    $name = trim($name);
    if (empty($name)) return false;
    return strlen($name) <= 15 && preg_match('/^[A-Z0-9][A-Z0-9-]*[A-Z0-9]$/i', $name) === 1;
}

/**
 * Validate group name (Windows/Linux group format)
 */
function isValidGroupName(string $group): bool {
    $group = trim($group);
    if (empty($group)) return false;
    // Windows group with domain or Linux group
    return preg_match('/^([A-Za-z0-9_\\-]+\\)?[A-Za-z0-9_\- ]+$/', $group) === 1;
}

/**
 * Validate OCS tag format
 */
function isValidOCSTag(string $tag): bool {
    $tag = trim($tag);
    if (empty($tag)) return false;
    return preg_match('/^[A-Z0-9][A-Z0-9\-_]*$/i', $tag) === 1 && strlen($tag) <= 50;
}

/**
 * Validate variable value based on name with detailed error messages
 * Returns array with 'valid' boolean and 'errors' array
 */
function validateVariableValue(string $name, string $value, ?string $varType = null): array {
    $errors = [];
    $warnings = [];
    $value = trim($value);
    $valid = true;

    // Define variable validation rules
    $validationRules = [
        'DOMINIO' => [
            'required' => true,
            'type' => 'domain',
            'description' => 'Dominio AD completo'
        ],
        'DOMINIO_NETBIOS' => [
            'required' => true,
            'type' => 'netbios',
            'description' => 'Nome NetBIOS do dominio'
        ],
        'DC_IP' => [
            'required' => true,
            'type' => 'array',
            'item_type' => 'ip',
            'description' => 'IP(s) do Controlador de Dominio'
        ],
        'DNS_PRIMARIO' => [
            'required' => true,
            'type' => 'array',
            'item_type' => 'ip',
            'description' => 'DNS primario(s)'
        ],
        'DNS_SECUNDARIO' => [
            'required' => false,
            'type' => 'array',
            'item_type' => 'ip',
            'description' => 'DNS secundario(s)'
        ],
        'DNS_INTERNET' => [
            'required' => true,
            'type' => 'ip',
            'description' => 'DNS para internet (fallback)'
        ],
        'BASE_URL' => [
            'required' => true,
            'type' => 'url',
            'description' => 'URL base do repositorio de scripts'
        ],
        'OCS_SERVER' => [
            'required' => true,
            'type' => 'url',
            'description' => 'Servidor OCS Inventory'
        ],
        'OCS_TAG' => [
            'required' => true,
            'type' => 'ocstag',
            'description' => 'Tag OCS da organizacao'
        ],
        'PRINT_SERVER' => [
            'required' => false,
            'type' => 'ip',
            'description' => 'Servidor de impressao'
        ],
        'PROXY_HTTP' => [
            'required' => false,
            'type' => 'ip',
            'description' => 'Proxy HTTP corporativo'
        ],
        'PROXY_PORTA' => [
            'required' => false,
            'type' => 'port',
            'description' => 'Porta do proxy'
        ],
        'PROXY_URL' => [
            'required' => false,
            'type' => 'url',
            'description' => 'URL completa do proxy'
        ],
        'HOMEPAGE' => [
            'required' => false,
            'type' => 'domain_or_url',
            'description' => 'Pagina inicial do portal'
        ],
        'GRUPO_ADMIN_AD' => [
            'required' => true,
            'type' => 'group',
            'description' => 'Grupo admin no AD para sudo'
        ],
        'GRUPO_ADMIN_LINUX' => [
            'required' => true,
            'type' => 'group',
            'description' => 'Grupo local para sudo'
        ],
        'GRUPO_DASTI' => [
            'required' => false,
            'type' => 'group',
            'description' => 'Grupo DASTI para sudo'
        ],
        'WALLPAPER_URL' => [
            'required' => false,
            'type' => 'url',
            'description' => 'URL do wallpaper da OM'
        ],
        'LOGO_URL' => [
            'required' => false,
            'type' => 'url',
            'description' => 'URL do logo da OM'
        ],
        'COMPARTILHAMENTOS' => [
            'required' => false,
            'type' => 'array',
            'item_type' => 'string',
            'description' => 'Compartilhamentos de rede'
        ],
        'PRINTERS' => [
            'required' => false,
            'type' => 'array',
            'item_type' => 'string',
            'description' => 'Impressoras'
        ],
        'NO_PROXY' => [
            'required' => false,
            'type' => 'array',
            'item_type' => 'string',
            'description' => 'Hosts sem proxy'
        ]
    ];

    $rule = $validationRules[$name] ?? null;

    // If variable type from DB is array, override rule type
    if ($varType === 'array' && $rule) {
        $rule['type'] = 'array';
        if (!isset($rule['item_type'])) {
            $rule['item_type'] = 'string';
        }
    }

    if (!$rule) {
        // Unknown variable - use type from DB if provided
        if ($varType === 'array') {
            // Validate as array (comma-separated values)
            $values = array_map('trim', array_filter(explode(',', $value)));
            if (empty($value) || empty($values)) {
                return ['valid' => true, 'errors' => [], 'warnings' => ["Array vazio para variavel desconhecida: $name"]];
            }
        }
        return ['valid' => true, 'errors' => [], 'warnings' => []];
    }

    // Check required
    if ($rule['required'] && empty($value)) {
        $errors[] = "{$rule['description']} e obrigatorio";
        return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    // For array types, check if at least one value exists
    if ($rule['type'] === 'array' && $rule['required']) {
        $values = array_map('trim', array_filter(explode(',', $value)));
        if (empty($values)) {
            $errors[] = "{$rule['description']} e obrigatorio. Informe pelo menos um valor.";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }
    }

    // Skip validation if empty and not required
    if (empty($value) && !$rule['required']) {
        return ['valid' => true, 'errors' => [], 'warnings' => $warnings];
    }

    // Type-specific validation
    switch ($rule['type']) {
        case 'array':
            // For arrays, validate each item
            if (!empty($value)) {
                $values = array_map('trim', array_filter(explode(',', $value)));
                $itemType = $rule['item_type'] ?? 'string';

                foreach ($values as $idx => $item) {
                    $itemResult = validateSingleValue($item, $itemType, $rule['description'], $idx + 1);
                    if (!$itemResult['valid']) {
                        $errors = array_merge($errors, $itemResult['errors']);
                        $valid = false;
                    }
                }
            }
            break;

        case 'ip':
            if (!isValidIPorCIDR($value)) {
                $errors[] = "{$rule['description']} deve ser um IP valido (ex: 192.168.1.1)";
                $valid = false;
            }
            break;

        case 'url':
            if (!isValidURL($value)) {
                $errors[] = "{$rule['description']} deve ser uma URL valida (ex: http://server.com)";
                $valid = false;
            }
            break;

        case 'domain':
            if (!isValidDomain($value)) {
                $errors[] = "{$rule['description']} deve ser um dominio valido (ex: comara.intraer)";
                $valid = false;
            }
            break;

        case 'domain_or_url':
            if (!isValidDomain($value) && !isValidURL($value)) {
                $errors[] = "{$rule['description']} deve ser um dominio ou URL valida";
                $valid = false;
            }
            break;

        case 'netbios':
            if (!isValidNetBIOS($value)) {
                $errors[] = "{$rule['description']} deve ter no maximo 15 caracteres alfanumericos";
                $valid = false;
            }
            break;

        case 'port':
            if (!isValidPort($value)) {
                $errors[] = "{$rule['description']} deve ser uma porta valida (1-65535)";
                $valid = false;
            }
            break;

        case 'group':
            if (!isValidGroupName($value)) {
                $errors[] = "{$rule['description']} formato invalido de grupo";
                $valid = false;
            }
            break;

        case 'ocstag':
            if (!isValidOCSTag($value)) {
                $errors[] = "{$rule['description']} deve conter apenas letras, numeros, hifen e underline (max 50 chars)";
                $valid = false;
            }
            break;
    }

    return ['valid' => $valid, 'errors' => $errors, 'warnings' => $warnings];
}

/**
 * Validate a single value for array items
 */
function validateSingleValue(string $value, string $type, string $description, int $index): array {
    $errors = [];
    $valid = true;
    $value = trim($value);

    if (empty($value)) {
        return ['valid' => true, 'errors' => []];
    }

    switch ($type) {
        case 'ip':
            if (!isValidIPorCIDR($value)) {
                $errors[] = "$description - valor $index ('{$value}') nao e um IP valido";
                $valid = false;
            }
            break;
    }

    return ['valid' => $valid, 'errors' => $errors];
}

/**
 * Validate all variables for an organization
 * @param array $variables Associative array of variable_id => value
 * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
 */
function validateAllVariables(array $variables): array {
    $allErrors = [];
    $allWarnings = [];

    // Get variable definitions including type
    $varDefs = Database::fetchAll(
        "SELECT id, name, type FROM variable_definitions ORDER BY display_order"
    );

    $varNames = [];
    $varTypes = [];
    foreach ($varDefs as $def) {
        $varNames[$def['id']] = $def['name'];
        $varTypes[$def['id']] = $def['type'];
    }

    foreach ($variables as $varId => $value) {
        $varId = (int) $varId;
        $name = $varNames[$varId] ?? "ID:$varId";
        $type = $varTypes[$varId] ?? null;
        $value = trim($value);

        $result = validateVariableValue($name, $value, $type);

        if (!$result['valid']) {
            $allErrors = array_merge($allErrors, $result['errors']);
        }

        if (!empty($result['warnings'])) {
            $allWarnings = array_merge($allWarnings, $result['warnings']);
        }
    }

    return [
        'valid' => empty($allErrors),
        'errors' => $allErrors,
        'warnings' => $allWarnings
    ];
}

/**
 * Replace placeholders in content
 */
function replacePlaceholders(string $content, array $variables): string {
    foreach ($variables as $key => $value) {
        $placeholder = '{{' . strtoupper($key) . '}}';
        $content = str_replace($placeholder, $value, $content);
    }
    return $content;
}

/**
 * substituir_placeholders - Replace {{VARIAVEL}} placeholders in script content
 * with values from the organization's variables.
 *
 * For array-type variables:
 * - {{VAR_NAME}} -> first value
 * - {{VAR_NAME_LIST}} -> space-separated list of all values
 *
 * If a variable is not found, the placeholder is kept and a warning is logged.
 *
 * @param string $conteudo Script content with {{PLACEHOLDER}} tokens
 * @param int $orgId Organization ID to look up variable values for
 * @return array ['content' => string, 'warnings' => array]
 */
function substituir_placeholders(string $conteudo, int $orgId): array {
    $org = Database::fetchOne(
        "SELECT acronym, name, domain FROM organizations WHERE id = ?",
        [$orgId]
    );
    if (!$org) {
        throw new RuntimeException('Organizacao nao encontrada');
    }

    // Include type in the query to handle array types
    $vars = Database::fetchAll(
        "SELECT vd.name, vd.type, COALESCE(ov.value, vd.default_value) AS value
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.organization_id = ? AND ov.variable_id = vd.id",
        [$orgId]
    );

    $varMap = [];
    $varTypeMap = [];
    foreach ($vars as $v) {
        $varMap[$v['name']] = $v['value'] ?? '';
        $varTypeMap[$v['name']] = $v['type'] ?? 'string';
    }
    $varMap['OM_ACRONYM'] = $org['acronym'];
    $varMap['OM_NAME'] = $org['name'];
    $varMap['OM_DOMAIN'] = $org['domain'] ?? '';
    $varTypeMap['OM_ACRONYM'] = 'string';
    $varTypeMap['OM_NAME'] = 'string';
    $varTypeMap['OM_DOMAIN'] = 'string';

    $placeholders = extractPlaceholders($conteudo);
    $warnings = [];
    $result = $conteudo;

    foreach ($placeholders as $name) {
        // Check if this is a _LIST variant
        $isList = str_ends_with($name, '_LIST');
        $baseName = $isList ? substr($name, 0, -5) : $name;

        if (array_key_exists($baseName, $varMap)) {
            $value = $varMap[$baseName];
            $type = $varTypeMap[$baseName] ?? 'string';

            if ($type === 'array') {
                $values = array_map('trim', array_filter(explode(',', $value)));
                if ($isList) {
                    // {{VAR_NAME_LIST}} -> space-separated
                    $replaceValue = implode(' ', $values);
                } else {
                    // {{VAR_NAME}} -> first value
                    $replaceValue = $values[0] ?? '';
                }
            } else {
                // Non-array types: same value for both
                $replaceValue = $value;
            }

            $result = str_replace('{{' . $name . '}}', $replaceValue, $result);
        } else {
            $warnings[] = "Variavel nao definida: $name (placeholder mantido)";
        }
    }

    return ['content' => $result, 'warnings' => $warnings];
}

/**
 * Log event to file system log
 * Writes to storage/logs/system.log with timestamp and level
 */
function log_event(string $message, string $level = 'INFO'): void {
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    $logFile = $logDir . '/system.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $line = "[$timestamp] [$level] [IP:$ip] $message" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Extract placeholders from content
 */
function extractPlaceholders(string $content): array {
    preg_match_all('/\{\{([A-Z_][A-Z0-9_]*)\}\}/', $content, $matches);
    return array_unique($matches[1]);
}

/**
 * Log activity - Complete audit trail function
 *
 * @param int|null $userId ID do usuário que realizou a ação
 * @param string $action Tipo de ação (login, logout, create, update, delete, download, etc.)
 * @param string $target Tipo de alvo (organization, variable, script, bundle, user, settings)
 * @param int|null $targetId ID do alvo afetado
 * @param string $details Detalhes adicionais da ação
 * @param int|null $orgId ID da organização relacionada
 */
function logActivity(
    ?int $userId,
    string $action,
    ?string $target = null,
    ?int $targetId = null,
    ?string $details = null,
    ?int $orgId = null
): void {
    $sessionId = session_id();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ipAddress = getClientIP();

    Database::execute(
        "INSERT INTO activity_log
         (user_id, action, target, target_id, details, organization_id, ip_address, user_agent, session_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$userId, $action, $target, $targetId, $details, $orgId, $ipAddress, $userAgent, $sessionId]
    );
}

/**
 * Log helper for quick logging with session user
 */
function logUserAction(
    string $action,
    ?string $target = null,
    ?int $targetId = null,
    ?string $details = null,
    ?int $orgId = null
): void {
    $userId = $_SESSION['user_id'] ?? null;
    logActivity($userId, $action, $target, $targetId, $details, $orgId);
}

/**
 * Get recent activity logs
 *
 * @param int $limit Maximum number of records to return
 * @param string|null $action Filter by action type
 * @param int|null $orgId Filter by organization
 * @return array Array of log entries
 */
function getActivityLogs(int $limit = 50, ?string $action = null, ?int $orgId = null): array {
    $sql = "SELECT
                al.*,
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
        $params[] = $action;
    }

    if ($orgId) {
        $sql .= " AND al.organization_id = ?";
        $params[] = $orgId;
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT ?";
    $params[] = $limit;

    return Database::fetchAll($sql, $params);
}

/**
 * Build bundle from scripts
 */
function buildBundle(int $organizationId): array {
    // Get organization info
    $org = Database::fetchOne(
        "SELECT * FROM organizations WHERE id = ? AND is_active = TRUE",
        [$organizationId]
    );

    if (!$org) {
        throw new RuntimeException('Organization not found');
    }

    // Get organization variables
    $variables = [];
    $vars = Database::fetchAll(
        "SELECT vd.name, COALESCE(ov.value, vd.default_value) AS value
         FROM variable_definitions vd
         LEFT JOIN organization_variables ov ON ov.organization_id = ? AND ov.variable_id = vd.id
         ORDER BY vd.display_order",
        [$organizationId]
    );

    foreach ($vars as $var) {
        $variables[$var['name']] = $var['value'];
    }

    // Add OM-specific variables
    $variables['OM_ACRONYM'] = $org['acronym'];
    $variables['OM_NAME'] = $org['name'];
    $variables['OM_DOMAIN'] = $org['domain'];

    // Get all active core scripts in order
    $scripts = Database::fetchAll(
        "SELECT * FROM scripts WHERE is_active = TRUE ORDER BY is_core DESC, execution_order ASC"
    );

    if (empty($scripts)) {
        throw new RuntimeException('No scripts found');
    }

    // Build bundle content
    $bundleHeader = "#!/bin/bash
# ============================================================================
# SeederLinux Lite - Provisioning Bundle
# Generated: " . date('Y-m-d H:i:s') . "
# Organization: {$org['acronym']} - {$org['name']}
# Domain: {$org['domain']}
# ============================================================================
#
# This script was automatically generated by SeederLinux Lite
# DO NOT EDIT MANUALLY - Changes will be overwritten
#
# ============================================================================

set -e

# Colors for output
RED='\\033[0;31m'
GREEN='\\033[0;32m'
YELLOW='\\033[1;33m'
NC='\\033[0m' # No Color

log_info() {
    echo -e \"\${GREEN}[INFO]\${NC} \$1\"
}

log_warn() {
    echo -e \"\${YELLOW}[WARN]\${NC} \$1\"
}

log_error() {
    echo -e \"\${RED}[ERROR]\${NC} \$1\"
}

# Check if running as root
if [ \"\$EUID\" -ne 0 ]; then
    log_error \"Este script deve ser executado como root (sudo)\"
    exit 1
fi

clear
echo \"============================================================\"
echo \"SEEDEALINUX LITE - PROVISIONING BUNDLE\"
echo \"============================================================\"
echo \"\"
log_info \"Organização: {$org['acronym']}\"
log_info \"Domínio: {$org['domain']}\"
log_info \"Gerado em: " . date('d/m/Y H:i:s') . "\"
echo \"\"
echo \"============================================================\"
read -p \"Pressione ENTER para iniciar o provisionamento...\"
echo \"\"

";

    $bundleContent = $bundleHeader;
    $scriptIndex = 0;
    $totalScripts = count($scripts);

    foreach ($scripts as $script) {
        $scriptIndex++;
        $scriptContent = $script['content'];

        // Replace placeholders
        $scriptContent = replacePlaceholders($scriptContent, $variables);

        // Add script marker
        $bundleContent .= "
# ============================================================================
# SCRIPT {$scriptIndex}/{$totalScripts}: {$script['name']}
# File: {$script['filename']}
# ============================================================================
echo \"\"
log_info \"Executando: {$script['name']} ({$scriptIndex}/{$totalScripts})\"
echo \"\"
";

        // Add script content (remove shebang if exists)
        $scriptContent = preg_replace('/^#!\/bin\/bash\s*\n/', '', $scriptContent);
        $bundleContent .= $scriptContent . "\n\n";
    }

    // Add footer
    $bundleContent .= "
# ============================================================================
# END OF PROVISIONING BUNDLE
# ============================================================================
echo \"\"
echo \"============================================================\"
log_info \"PROVISIONAMENTO CONCLUÍDO COM SUCESSO!\"
log_info \"Por favor, reinicie a estação para aplicar todas as alterações.\"
echo \"============================================================\"

# Log execution
echo \"\"
echo \"Organização: {$org['acronym']}\" >> /var/log/seederlinux-provision.log
echo \"Data: \$(date)\" >> /var/log/seederlinux-provision.log
echo \"Hostname: \$(hostname)\" >> /var/log/seederlinux-provision.log
echo \"---\" >> /var/log/seederlinux-provision.log

exit 0
";

    $filename = sprintf(
        'provision-%s-%s.sh',
        strtolower($org['acronym']),
        date('Ymd-His')
    );

    return [
        'filename' => $filename,
        'content' => $bundleContent,
        'organization' => $org['acronym'],
        'scripts_count' => count($scripts),
        'generated_at' => date('Y-m-d H:i:s')
    ];
}
