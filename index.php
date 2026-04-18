<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * ---------------------------
 * DEBUG CONFIG
 * ---------------------------
 */
$debug = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);

// Force error reporting
error_reporting(E_ALL);

// Show errors ONLY if debug is true
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');

// ALWAYS log errors (important for Render logs)
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr'); // Render reads stderr logs

/**
 * ---------------------------
 * GLOBAL ERROR HANDLERS
 * ---------------------------
 */

// Catch normal PHP errors
set_error_handler(function ($severity, $message, $file, $line) {
    error_log("ERROR [$severity] $message in $file on line $line");
});

// Catch uncaught exceptions
set_exception_handler(function ($exception) {
    error_log("EXCEPTION: " . $exception->getMessage());
    error_log($exception->getTraceAsString());

    http_response_code(500);
    echo "Internal Server Error";
});

// Catch fatal errors (VERY IMPORTANT)
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL) {
        error_log("FATAL ERROR: " . print_r($error, true));
    }
});

/**
 * ---------------------------
 * SECURITY HEADERS
 * ---------------------------
 */
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.ckeditor.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; connect-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'self';");
}

error_log("✅ Step 1: Headers loaded");

/**
 * ---------------------------
 * SESSION
 * ---------------------------
 */
require_once __DIR__ . '/init/session.php';
error_log("✅ Step 2: Session initialized");

/**
 * ---------------------------
 * DATABASE + CLEANUP
 * ---------------------------
 */
require_once __DIR__ . '/init/_dbconnect.php';
error_log("✅ Step 3: Database connected");

require_once __DIR__ . '/init/event_cleanup.php';
error_log("✅ Step 4: Cleanup executed");

/**
 * ---------------------------
 * ROUTER
 * ---------------------------
 */
$router = require_once __DIR__ . '/config/routes.php';

if (!is_object($router) || !method_exists($router, 'dispatch')) {
    error_log("❌ Router not initialized correctly");
    throw new RuntimeException('Router not initialized correctly.');
}

error_log("✅ Step 5: Router loaded");

/**
 * ---------------------------
 * DISPATCH REQUEST
 * ---------------------------
 */
$router->dispatch();

error_log("✅ Step 6: Request dispatched");

exit;
