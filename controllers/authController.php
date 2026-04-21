<?php

/**
 * AuthController
 * Handles Login, Logout, Principal Signup + Forgot Password / Reset Flow
 */
use Ramsey\Uuid\Uuid;
require_once __DIR__ . '/../config/cloudinaryHelper.php';
use Cloudinary\Api\Upload\UploadApi;
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../validation/userValidation.php';


use Validation\UserValidation;

class AuthController extends BaseController
{
    public function __construct($pdo = null)
    {
        // Database connections - accept PDO from router or use global
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            global $pdo, $conn;
            $this->pdo = $pdo;
            $this->conn = $conn;
        }
    }
    

    public function showLogin() { 
        if ($this->isAuthenticated()) { 
            $this->redirect('/dashboard'); 
        } $this->render('auth/login');
    }

    public function showSignup()
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/dashboard');
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $this->render('auth/principal_signup', [
            'csrf_token' => $_SESSION['csrf_token']
        ]);
    }
    
    public function logout()
{
    $_SESSION = [];
    session_destroy();
    session_start(); // start a fresh session
    session_regenerate_id(true); // now safe
    $this->redirect('/event-reports');
}

    public function showForgotPassword()
    {
        if ($this->isAuthenticated()) {
            $this->redirect('/dashboard');
        }
        $this->render('auth/forgot_password');
    }

    public function showVerifyOtp()
{
    // Allow access to verify-otp page without requiring a valid session
    // This enables users to access the page even when they entered wrong credentials
    $this->render('auth/verify_otp');
}

    public function showResetPassword()
    {
        if (empty($_SESSION['reset_user_id']) || empty($_SESSION['otp_verified'])) {
            $this->redirect('/event-reports/forgot-password');
        }
        $this->render('auth/reset_password');
    }
    /* =====================================
       SIGNUP (Principal Create)
    ===================================== */
    public function signup()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/signup?error=method');
        }

        if (
            empty($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
        ) {
            die("Invalid CSRF token");
        }

        // Use UserValidation for all input validation
        $validation = UserValidation::validateCreateUser($_POST);

        if (!$validation['status']) {
            $_SESSION['errors'] = $validation['errors'];
            $this->redirect('/signup');
        }

        $data = $validation['data'];
        $username = $data['username'];
        $email = $data['email'];
        $recovery = $data['recovery_email'];
        $contact = $data['contact_number'];
        $password = $data['password'];

        $hashed = password_hash($password, PASSWORD_BCRYPT);

        $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $maxSize = 2 * 1024 * 1024;

                $profileUpload = $this->handleUpload(
                    'profile_image',
                    $allowed,
                    $maxSize,
                    'users/profile'
                );

                $signUpload = $this->handleUpload(
                    'sign_image',
                    $allowed,
                    $maxSize,
                    'users/signature'
                );

                $profilePath = $profileUpload['url'] ?? null;
                $profilePublicId = $profileUpload['public_id'] ?? null;

                $signPath = $signUpload['url'] ?? null;
                $signPublicId = $signUpload['public_id'] ?? null;

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $_SESSION['errors'] = ['Account with this email already exists'];
            $this->redirect('/signup');
        }
        
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo->prepare("
            INSERT INTO users
            (id, username, email, recovery_email, password, contact_number,
            profile_image, profile_public_id,
            sign_image, sign_public_id,
            role)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        try {

    $stmt->execute([
        $id,
        $username,
        $email,
        $recovery,
        $hashed,
        $contact,
        $profilePath,
        $profilePublicId,
        $signPath,
        $signPublicId,
        'principal'
    ]);

} catch (\PDOException $e) {

    error_log(
        "User Signup Error | File: " . __FILE__ .
        " | Line: " . __LINE__ .
        " | Message: " . $e->getMessage()
    );

    $_SESSION['errors'] = ["Unable to create account. Please try again."];
    return $this->redirect('/signup');
}

        session_regenerate_id(true);
        // Use the UUID for session
        $_SESSION['user_id'] = $id;
        $_SESSION['role']    = 'principal';
        $_SESSION['username']= $username;

        unset($_SESSION['csrf_token']);

        
        

      $_SESSION['success'] = "Account created successfully! Welcome to the Event Management System.";
return $this->redirect('/event-reports/dashboard');
    }





    /* =====================================
       LOGIN
    ===================================== */

public function login()
{
    
    // ---------------------------
    // 1️⃣ VALIDATION
    // ---------------------------
    $validation = UserValidation::validateLogin($_POST);

    if (!$validation['status']) {
        error_log("Login validation failed: ", true);
        return $this->redirectWithErrors(
            '/login',
            $validation['errors'],
            $_POST
        );
    }

    $email    = $validation['data']['email'];
    $password = $validation['data']['password'];
error_log("Login attempt started");
    

    try {

        // ---------------------------
        // 2️⃣ FETCH USER
        // ---------------------------
        $stmt = $this->pdo->prepare("
            SELECT id, username, password, role, department_id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

       
        // ---------------------------
        // 3️⃣ PASSWORD VERIFY
        // ---------------------------
        if ($user) {
            $valid = password_verify($password, $user['password']);
            error_log("Password verification result: " . ($valid ? 'SUCCESS' : 'FAILED'));
        } else {
            // Fake verify to prevent timing attack
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuv');
            $valid = false;
            error_log("User not found, password verification skipped");
        }

        if (!$valid) {
            error_log("Login failed for email: " . $email);

            return $this->redirectWithErrors(
                '/event-reports/login',
                ['Invalid email or password'],
                $_POST
            );
        }

        // ---------------------------
        // 4️⃣ SESSION SET
       

session_regenerate_id(true); 

$_SESSION['user_id']  = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];

if (!empty($user['department_id'])) {
    $_SESSION['department_id'] = $user['department_id'];
}

        if (!empty($user['department_id'])) {
            $_SESSION['department_id'] = $user['department_id'];
        }

        // Optional success message
        $_SESSION['success'] = "Login successful";

        error_log("Login successful: " . $user['username'] . " (ID: " . $user['id'] . ")");
        
        // ---------------------------
        // 5️⃣ REDIRECT
        // ---------------------------
        error_log("Redirecting to dashboard...");
        return $this->redirect('/event-reports/dashboard');

    } catch (\PDOException $e) {

    error_log(
        "Database Error | File: " . __FILE__ .
        " | Line: " . __LINE__ .
        " | Message: " . $e->getMessage()
    );

    return $this->redirect('/event-reports/login?error=Server error');

} catch (\Exception $e) {

    error_log(
        "Application Error | File: " . __FILE__ .
        " | Line: " . __LINE__ .
        " | Message: " . $e->getMessage()
    );

    return $this->redirect('/event-reports/login?error=Server error');
}
}






    /* =====================================
       FORGOT PASSWORD
    ===================================== */
    public function sendResetOtp()
{
    error_log("======== SEND RESET OTP STARTED ========");
    $startTime = microtime(true);

    // 🔐 Clear old reset session FIRST
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_recovery_email']);
    unset($_SESSION['otp_verified']);

    error_log("STEP 1: Session cleared");

    // ✅ 1️⃣ VALIDATION
    $validation = UserValidation::validateSendResetOtp($_POST);

    if (!$validation['status']) {
        error_log("STEP 2: Validation FAILED");
        error_log(print_r($validation['errors'], true));

        return $this->redirectWithErrors(
            '/event-reports/forgot-password',
            $validation['errors'],
            $_POST
        );
    }

    error_log("STEP 2: Validation SUCCESS");

    $email = $validation['data']['email'];
    $recovery_email = $validation['data']['recovery_email'];

    error_log("User Email: " . $email);
    error_log("Recovery Email: " . $recovery_email);

    try {

        // ✅ 2️⃣ FIND USER
        error_log("STEP 3: Fetching user from DB");

        $stmt = $this->pdo->prepare("
            SELECT id, reset_otp_last_sent, reset_otp_locked_until
            FROM users 
            WHERE email = ? AND recovery_email = ?
            LIMIT 1
        ");
        $stmt->execute([$email, $recovery_email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            error_log("STEP 3 RESULT: User NOT FOUND");

            return $this->constantTimeResponse(
                $startTime,
                '/event-reports/verify-otp?success=If details are correct, OTP has been sent'
            );
        }

        error_log("STEP 3 RESULT: User FOUND ID = " . $user['id']);

        // 🔒 LOCK CHECK
        if (!empty($user['reset_otp_locked_until']) &&
            strtotime($user['reset_otp_locked_until']) > time()) {

            error_log("STEP 4: Account LOCKED");

            return $this->constantTimeResponse(
                $startTime,
                '/event-reports/forgot-password?error=Account locked. Try again after 24 hours.'
            );
        }

        error_log("STEP 4: Lock check passed");

        // ✅ 3️⃣ COOLDOWN CHECK
        $cooldown = getenv('OTP_RESEND_COOLDOWN') ?: 60;

        if (!empty($user['reset_otp_last_sent'])) {
            $lastSent = strtotime($user['reset_otp_last_sent']);

            if (time() - $lastSent < $cooldown) {
                error_log("STEP 5: Cooldown ACTIVE");

                return $this->constantTimeResponse(
                    $startTime,
                    '/event-reports/forgot-password?error=Please wait before requesting another OTP'
                );
            }
        }

        error_log("STEP 5: Cooldown passed");

        // ✅ 4️⃣ GENERATE OTP
        $otp = random_int(100000, 999999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiry = date(
            'Y-m-d H:i:s',
            strtotime('+' . getenv('OTP_EXPIRY_MINUTES') . ' minutes')
        );

        error_log("STEP 6: OTP GENERATED = " . $otp);

        // ✅ 5️⃣ SAVE OTP
        error_log("STEP 7: Saving OTP to DB");

        $update = $this->pdo->prepare("
            UPDATE users 
            SET reset_otp = ?, 
                reset_otp_expiry = ?, 
                reset_otp_attempts = 0,
                reset_otp_last_sent = ?
            WHERE id = ?
        ");

        $update->execute([
            $otpHash,
            $expiry,
            date('Y-m-d H:i:s'),
            $user['id']
        ]);

        error_log("STEP 7: OTP SAVED");

       // ✅ 6️⃣ SEND EMAIL VIA BREVO
error_log("STEP 8: Sending email via Brevo...");

$apiKey = getenv('BREVO_API_KEY');

if (!$apiKey) {
    error_log("❌ BREVO API KEY MISSING");
    throw new Exception("Brevo API key not found");
}

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "sender" => [
        "name" => "Keystone School of Engineering ",
        "email" => getenv('MAIL_FROM')
    ],
    "to" => [
        ["email" => $recovery_email]
    ],
"subject" => "Password Reset OTP - Keystone School of Engineering",

"htmlContent" => "
<div style='font-family: Arial, sans-serif; line-height:1.6; color:#333;'>

    <h2 style='color:#2c3e50;'>Keystone School of Engineering</h2>
    <p style='font-size:14px;'>Event Documentation Portal</p>

    <hr>

    <p>Hello,</p>

    <p>You requested to reset your password. Use the OTP below to proceed:</p>

    <div style='margin:20px 0; padding:15px; background:#f4f6f8; border-radius:8px; text-align:center;'>
        <span style='font-size:24px; font-weight:bold; letter-spacing:3px; color:#2c3e50;'>
            {$otp}
        </span>
    </div>

    <p>This OTP is valid for <b>" . (getenv('OTP_EXPIRY_MINUTES') ?: 10) . " minutes</b>.</p>

    <p>If you did not request this, please ignore this email.</p>

    <br>

    <p>Regards,<br>
    <b>Keystone Event Portal Team</b></p>

    <hr>

    <p style='font-size:12px; color:#888;'>
        This is an automated email. Please do not reply.
    </p>

</div>
"
]));

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "accept: application/json",
    "api-key: " . $apiKey,
    "content-type: application/json"
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    error_log("❌ CURL ERROR: " . curl_error($ch));
    throw new Exception("Email sending failed");
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("Brevo response: " . $response);
error_log("HTTP Code: " . $httpCode);

if ($httpCode !== 201) {
    throw new Exception("Brevo API failed");
}

error_log("✅ EMAIL SENT VIA BREVO");

        error_log("STEP 9 SUCCESS: EMAIL SENT");

        // ✅ 8️⃣ SESSION SET
        $_SESSION['reset_user_id'] = $user['id'];
        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_recovery_email'] = $recovery_email;

        session_regenerate_id(true);

        error_log("STEP 10: Session stored");

        return $this->constantTimeResponse(
            $startTime,
            '/event-reports/verify-otp?success=If details are correct, OTP has been sent'
        );

    } catch (Exception $e) {

        error_log("❌ FINAL ERROR: " . $e->getMessage());

        return $this->constantTimeResponse(
            $startTime,
            '/event-reports/verify-otp?success=If details are correct, OTP has been sent'
        );
    }
}
    /* =====================================
       VERIFY OTP
    ===================================== */
    public function verifyOtp()
{
    // 1️⃣ Check reset session
    if (!isset($_SESSION['reset_user_id'])) {
        return $this->redirect('/event-reports/forgot-password?error=Session expired');
    }

    // 2️⃣ Validate input
    $validation = \Validation\UserValidation::validateVerifyOtp($_POST);

    if (!$validation['status']) {
        return $this->redirectWithErrors(
            '/event-reports/verify-otp',
            $validation['errors']
        );
    }

    $otpInput = $validation['data']['otp'];
    $userId   = $_SESSION['reset_user_id'];

    try {

        // 3️⃣ Fetch user OTP data
        $stmt = $this->pdo->prepare("
            SELECT reset_otp, 
                   reset_otp_expiry, 
                   reset_otp_attempts,
                   email,
                   recovery_email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['reset_otp'])) {
            $_SESSION['errors'] = ['Invalid session'];
            return $this->redirect('/event-reports/forgot-password');
        }

        // 3.5️⃣ Verify that OTP belongs to the user who requested it
        if (
            !isset($_SESSION['reset_email']) || 
            !isset($_SESSION['reset_recovery_email']) ||
            $user['email'] !== $_SESSION['reset_email'] ||
            $user['recovery_email'] !== $_SESSION['reset_recovery_email']
        ) {
            $_SESSION['errors'] = ['Invalid session'];
            return $this->redirect('/event-reports/forgot-password');
        }

        // 4️⃣ Check expiry
        if (strtotime($user['reset_otp_expiry']) < time()) {
            $_SESSION['errors'] = ['OTP expired'];
            return $this->redirect('/event-reports/forgot-password');
        }

        // 5️⃣ Check 24-hour lock
        if (!empty($user['reset_otp_locked_until']) && 
            strtotime($user['reset_otp_locked_until']) > time()) {

            $_SESSION['errors'] = ['Account locked. Try again after 24 hours.'];
            return $this->redirect('/event-reports/forgot-password');
        }

        // 6️⃣ Check attempt limit
        if ($user['reset_otp_attempts'] >= getenv('OTP_MAX_ATTEMPTS')) {

            // Lock account for 24 hours
            $lockUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $lockStmt = $this->pdo->prepare("
                UPDATE users
                SET reset_otp_locked_until = ?
                WHERE id = ?
            ");
            $lockStmt->execute([$lockUntil, $userId]);

            $_SESSION['errors'] = ['Too many attempts. Locked for 24 hours.'];
            return $this->redirect('/event-reports/forgot-password');
        }

        // 7️⃣ Verify OTP
        if (!password_verify($otpInput, $user['reset_otp'])) {

            // Increment attempts safely
            $update = $this->pdo->prepare("
                UPDATE users 
                SET reset_otp_attempts = reset_otp_attempts + 1
                WHERE id = ?
            ");
            $update->execute([$userId]);

            $_SESSION['errors'] = ['Invalid OTP'];
            return $this->redirect('/event-reports/verify-otp');
        }

        // 7️⃣ OTP SUCCESS — clear OTP
        $clear = $this->pdo->prepare("
            UPDATE users
            SET reset_otp = NULL,
            reset_otp_expiry = NULL,
            reset_otp_attempts = 0,
            reset_otp_locked_until = NULL
            WHERE id = ?
        ");
        $clear->execute([$userId]);

        // 8️⃣ Allow password reset
        $_SESSION['otp_verified'] = true;
        session_regenerate_id(true);

        $_SESSION['success'] = 'OTP verified';
        return $this->redirect('/event-reports/reset-password');

    } catch (\Exception $e) {

        error_log("Verify OTP Error: " . $e->getMessage());
        return $this->redirect('/event-reports/forgot-password?error=Server error');
    }
}

    /* =====================================
       RESET PASSWORD
    ===================================== */
    public function resetPassword()
{
    // 1️⃣ Check OTP verified session
    if (
        !isset($_SESSION['reset_user_id']) ||
        !isset($_SESSION['otp_verified']) ||
        $_SESSION['otp_verified'] !== true
    ) {
        return $this->redirect('/event-reports/forgot-password?error=Unauthorized access');
    }

    // 2️⃣ Validate password
    $validation = \Validation\UserValidation::validateResetPassword($_POST);

    if (!$validation['status']) {
        return $this->redirectWithErrors(
            '/event-reports/reset-password',
            $validation['errors']
        );
    }

    $newPassword = $validation['data']['password'];
    $userId      = $_SESSION['reset_user_id'];

    try {

        // 3️⃣ Hash password securely
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // 4️⃣ Update password in database
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");
        $stmt->execute([$hashedPassword, $userId]);

        // 5️⃣ Clear reset session completely
        unset($_SESSION['reset_user_id']);
    unset($_SESSION['otp_verified']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_recovery_email']);
    session_regenerate_id(true);

        return $this->redirect('/event-reports/login?success=Password reset successful');

    } catch (\Exception $e) {

        error_log("Reset Password Error: " . $e->getMessage());
        return $this->redirect('/event-reports/reset-password?error=Server error');
    }
}
    /* =====================================
       FILE UPLOAD HELPER
    ===================================== */
    private function handleUpload($fieldName, $allowed, $maxSize, $folder, $publicId = null)
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['size'] > $maxSize) return null;

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) return null;

    try {

        $options = [
            'folder' => $folder,
            'resource_type' => 'image',
        ];

        // ✅ If public_id exists → overwrite
        if ($publicId) {
            $options['public_id'] = $publicId;
            $options['overwrite'] = true;
        }

        $upload = (new UploadApi())->upload($file['tmp_name'], $options);

        return [
            'url' => $upload['secure_url'],
            'public_id' => $upload['public_id']
        ];

    } catch (\Exception $e) {

    error_log(
        "Cloudinary Upload Error | File: " . __FILE__ .
        " | Line: " . __LINE__ .
        " | Message: " . $e->getMessage()
    );

    return null;
}
}

    private function constantTimeResponse($startTime, $redirectUrl)
{
    $minExecutionTime = 10; // seconds

    $executionTime = microtime(true) - $startTime;
    $remainingTime = $minExecutionTime - $executionTime;

    if ($remainingTime > 0) {
        $microseconds = round($remainingTime * 1000000);
        usleep($microseconds);
    }

    return $this->redirect($redirectUrl);
}
}
