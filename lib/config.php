<?php
session_start();
define('DB_HOST', getenv('SUPABASE_HOST') ?: 'db');
define('DB_PORT', getenv('SUPABASE_PORT') ?: '5432');
define('DB_NAME', getenv('SUPABASE_DB_NAME') ?: 'postgres');
define('DB_USER', getenv('SUPABASE_DB_USER') ?: 'postgres');
define('DB_PASS', getenv('SUPABASE_DB_PASS') ?: getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '');
