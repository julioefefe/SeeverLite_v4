<?php
/**
 * SeederLinux Lite - Authentication Library
 * Session management and authentication handling
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Detect HTTPS reliably (works behind reverse proxy / SSL termination)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 86400, // 24 hours
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Attempt to login user
 */
function login(string $username, string $password): array {
    // Rate limiting - prevent brute force
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lockout'] = 0;
    }

    if ($_SESSION['login_lockout'] > time()) {
        throw new RuntimeException('Conta temporariamente bloqueada. Tente novamente em alguns minutos.');
    }

    // Find user
    $user = Database::fetchOne(
        "SELECT * FROM users WHERE username = ? AND is_active = TRUE",
        [$username]
    );

    if (!$user || !verifyPassword($password, $user['password_hash'])) {
        $_SESSION['login_attempts']++;

        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['login_lockout'] = time() + 900; // 15 minutes lockout
            throw new RuntimeException('Muitas tentativas. Conta bloqueada por 15 minutos.');
        }

        throw new RuntimeException('Usuário ou senha inválidos');
    }

    // Reset attempts on successful login
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lockout'] = 0;

    // Set session data
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_fullname'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Generate new CSRF token
    generateCSRFToken();

    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role']
    ];
}

/**
 * Logout current user
 */
function logout(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Require authentication (redirect if not logged in)
 */
function requireAuth(): void {
    if (!isLoggedIn()) {
        if (isAjax()) {
            jsonError('Unauthorized', 401);
        }
        redirect('/login.html');
    }

    // Session timeout check (24 hours)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 86400) {
        logout();
        redirect('/login.html?timeout=1');
    }
}

/**
 * Get current user data
 */
function getCurrentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'full_name' => $_SESSION['user_fullname'] ?? null,
        'role' => $_SESSION['user_role'] ?? null
    ];
}

/**
 * Change user password
 */
function changePassword(int $userId, string $currentPassword, string $newPassword): bool {
    $user = Database::fetchOne(
        "SELECT password_hash FROM users WHERE id = ?",
        [$userId]
    );

    if (!$user || !verifyPassword($currentPassword, $user['password_hash'])) {
        throw new RuntimeException('Senha atual incorreta');
    }

    if (strlen($newPassword) < 8) {
        throw new RuntimeException('Nova senha deve ter pelo menos 8 caracteres');
    }

    $newHash = hashPassword($newPassword);

    return Database::execute(
        "UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$newHash, $userId]
    );
}

/**
 * Require admin role (admin_gap or admin)
 */
function requireAdmin(): void {
    requireAuth();

    $user = getCurrentUser();
    if (!in_array($user['role'], ['admin', 'admin_gap'])) {
        if (isAjax()) {
            jsonError('Forbidden: Admin access required', 403);
        }
        redirect('/admin.html');
    }
}

/**
 * Require a specific role or set of roles
 */
function requireRole(array $roles): void {
    requireAuth();

    $user = getCurrentUser();
    if (!in_array($user['role'], $roles)) {
        if (isAjax()) {
            jsonError('Forbidden: insufficient permissions', 403);
        }
        redirect('/admin.html');
    }
}

/**
 * Check if current user is admin_gap (or legacy admin)
 */
function isAdminGap(): bool {
    $user = getCurrentUser();
    return $user !== null && in_array($user['role'], ['admin', 'admin_gap']);
}

/**
 * Check if current user is operador_om
 */
function isOperador(): bool {
    $user = getCurrentUser();
    return $user !== null && $user['role'] === 'operador_om';
}

/**
 * Check if current user is auditor
 */
function isAuditor(): bool {
    $user = getCurrentUser();
    return $user !== null && $user['role'] === 'auditor';
}

/**
 * Get the organization ID the current user is restricted to.
 * Returns null for admin_gap/auditor (can see all), or the org ID for operador_om.
 */
function getUserOrgId(): ?int {
    $user = getCurrentUser();
    if (!$user) return null;
    if (in_array($user['role'], ['admin', 'admin_gap', 'auditor'])) return null;
    // operador_om: look up their organization_id from the database
    $row = Database::fetchOne("SELECT organization_id FROM users WHERE id = ?", [$user['id']]);
    return $row && $row['organization_id'] ? (int) $row['organization_id'] : null;
}
