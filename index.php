<?php

require_once __DIR__ . '/vendor/autoload.php';

// 🔴 SHOW ALL ERRORS   
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🚀 DEBUG START</h2>";

// 🔹 STEP 1: Check PHP working
echo "✅ STEP 1: PHP is working<br>";

// 🔹 STEP 2: Show environment variables
echo "<h3>ENV VARIABLES:</h3>";

$host = getenv('MYSQLHOST');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MYSQLDATABASE');
$port = getenv('MYSQLPORT');

echo "HOST: " . ($host ?: "❌ NOT FOUND") . "<br>";
echo "USER: " . ($user ?: "❌ NOT FOUND") . "<br>";
echo "DB: " . ($db ?: "❌ NOT FOUND") . "<br>";
echo "PORT: " . ($port ?: "❌ NOT FOUND") . "<br>";

// 🔹 STEP 3: Try DB connection
echo "<h3>DB CONNECTION:</h3>";

$conn = @new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    echo "❌ DB ERROR: " . $conn->connect_error . "<br>";
} else {
    echo "✅ DB Connected Successfully<br>";
}

// 🔹 STEP 4: Simple query test
echo "<h3>QUERY TEST:</h3>";

$result = $conn->query("SHOW TABLES");

if (!$result) {
    echo "❌ Query Failed: " . $conn->error . "<br>";
} else {
    echo "✅ Query Success<br>";
}

// 🔹 END
echo "<h2>🏁 DEBUG END</h2>";

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
