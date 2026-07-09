<?php
/**
 * SeederLinux Lite - Configuration
 */

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('DB_HOST', getenv('SUPABASE_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SUPABASE_DB_NAME') ?: 'postgres');
define('DB_USER', getenv('SUPABASE_DB_USER') ?: 'postgres');
define('DB_PASS', getenv('SUPABASE_DB_PASSWORD') ?: '');
define('DB_PORT', getenv('SUPABASE_DB_PORT') ?: 5432);

date_default_timezone_set('America/Sao_Paulo');
