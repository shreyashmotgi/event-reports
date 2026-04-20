<?php
/**
 * ChecklistController
 * Handles creation and update of event checklists (including guests & incharges)
 */
use Ramsey\Uuid\Uuid;
require_once __DIR__ . '/../config/cloudinaryHelper.php';
use Cloudinary\Api\Upload\UploadApi;
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Flash.php';


class ChecklistController extends BaseController
{

    private array $errors = []; 
    
    public function __construct($pdo)
    {
        parent::__construct($pdo);
        
    }

    /* =====================================
       HELPERS
    ===================================== */


private function validateProgrammeName($value)
{
    return preg_match('/^[a-zA-Z0-9\s\-\&\.\(\)]+$/', $value);
}

private function validateDate($date)
{
    return (bool) DateTime::createFromFormat('Y-m-d', $date);
}

private function validateName($value)
{
    return preg_match('/^[a-zA-Z\s\.]+$/', $value);
}

private function validateCompany($value)
{
    return preg_match('/^[a-zA-Z0-9\s\.\&\-,()\/]+$/u', $value);
}

private function validateDesignation($value)
{
    return preg_match('/^[a-zA-Z0-9\s\.\-\/,&()]+$/u', $value);
}
    
private function validatePhone($value)
{
    return preg_match('/^[0-9]{10}$/', $value);
}

private function validateEmail($value)
{
    if (empty($value)) {
        return true; // Allow empty emails
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

    private function clean($value)
{
    return is_string($value) ? trim($value) : $value;
}
    private function checkbox($name)
    {
        return isset($_POST[$name]) ? 1 : 0;
    }

    private function text($name)
    {
        return isset($_POST[$name]) && $_POST[$name] !== '' ? trim($_POST[$name]) : null;
    }

    private function jsonField($name)
    {
        return isset($_POST[$name]) && is_array($_POST[$name]) ? json_encode($_POST[$name]) : null;
    }

    private function uploadApplicationLetterToCloudinary($existingPublicId = null)
{
    if (
        !isset($_FILES['application_letter']) ||
        $_FILES['application_letter']['error'] !== UPLOAD_ERR_OK
    ) {
        return null;
    }

    $tmp = $_FILES['application_letter']['tmp_name'];

    try {

        $options = [
            'folder' => 'checklists/application_letters',
            'resource_type' => 'auto' // IMPORTANT for PDF/DOC
        ];

        if ($existingPublicId) {
            $options['public_id'] = $existingPublicId;
            $options['overwrite'] = true;
            $options['invalidate'] = true;
        }

        $upload = (new UploadApi())->upload($tmp, $options);

        return [
            'url' => $upload['secure_url'],
            'public_id' => $upload['public_id']
        ];

    } catch (Exception $e) {
        error_log("Application Letter Upload Error: " . $e->getMessage());
        return null;
    }
}

    private function uploadGuestBioToCloudinary($index, $existingPublicId = null)
{
    if (
        !isset($_FILES['bio_image']['name'][$index]) ||
        $_FILES['bio_image']['error'][$index] !== UPLOAD_ERR_OK
    ) {
        return null;
    }

    $tmp = $_FILES['bio_image']['tmp_name'][$index];

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = mime_content_type($tmp);

    if (!in_array($mime, $allowed)) {
        return null;
    }

    if ($_FILES['bio_image']['size'][$index] > 2 * 1024 * 1024) {
        return null;
    }

    try {

        $options = [
            'folder' => 'checklists/guests',
            'resource_type' => 'image'
        ];

        // 🔥 Overwrite if exists
        if ($existingPublicId) {
            $options['public_id'] = $existingPublicId;
            $options['overwrite'] = true;
            $options['invalidate'] = true;
        }

        $upload = (new UploadApi())->upload($tmp, $options);

        return [
            'url' => $upload['secure_url'],
            'public_id' => $upload['public_id']
        ];

    } catch (Exception $e) {
        error_log("Guest Bio Upload Error: " . $e->getMessage());
        return null;
    }
}

    /* =====================================
       CREATE NEW CHECKLIST
    ===================================== */
    public function create()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->redirect('/documents/checklist?error=invalid_method');
    }

    if (!isset($_SESSION['user_id'])) {
        $this->redirect('/login?error=unauthorized');
    }

    $this->errors = [];

    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'Coordinator';

    // Fetch user's department ID (UUID string)
    $stmt = $this->pdo->prepare("SELECT department_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $userDeptId = $stmt->fetchColumn() ?: null;

    /* ===============================
       FETCH & CLEAN INPUT
    =============================== */
    $programme_name       = $this->clean($_POST['programme_name'] ?? '');
    $programme_date       = !empty($_POST['programme_date']) ? $_POST['programme_date'] : null;
$programme_start_date = !empty($_POST['programme_start_date']) ? $_POST['programme_start_date'] : null;
$programme_end_date   = !empty($_POST['programme_end_date']) ? $_POST['programme_end_date'] : null;

    /* ===============================
       PROGRAMME NAME VALIDATION
    =============================== */

    if (empty($programme_name)) {
        $this->errors[] = "Programme name is required.";
    } elseif (!$this->validateProgrammeName($programme_name)) {
        $this->errors[] = "Programme name contains invalid characters.";
    }

    /* ===============================
       MULTI DAY VALIDATION
    =============================== */
    $multi_day = isset($_POST['multi_day']) ? 1 : 0;

if ($multi_day === 1) {

    if (empty($programme_start_date) || empty($programme_end_date)) {
        $this->errors[] = "Start date and End date are required for multi-day programme.";
    }

    if (!empty($programme_start_date) && !$this->validateDate($programme_start_date)) {
        $this->errors[] = "Invalid start date format.";
    }

    if (!empty($programme_end_date) && !$this->validateDate($programme_end_date)) {
        $this->errors[] = "Invalid end date format.";
    }

    if (!empty($programme_start_date) && !empty($programme_end_date)) {
        if ($programme_start_date > $programme_end_date) {
            $this->errors[] = "End date must be after start date.";
        }
    }

} else {

    if (empty($programme_date)) {
        $this->errors[] = "Programme date is required.";
    } elseif (!$this->validateDate($programme_date)) {
        $this->errors[] = "Invalid programme date format.";
    }

}

// Ensure correct NULL handling for database
if ($multi_day === 1) {
    $programme_date = null; // single-day date not needed
} else {
    $programme_start_date = null;
    $programme_end_date   = null;
}

    /* ===============================
       GUEST VALIDATION
    =============================== */

    if (!empty($_POST['guest_name'])) {

        foreach ($_POST['guest_name'] as $index => $name) {

            $guestName   = $this->clean($name);
            $company     = $this->clean($_POST['company_name'][$index] ?? '');
            $designation = $this->clean($_POST['designation'][$index] ?? '');
            $contact     = $this->clean($_POST['contact_no'][$index] ?? '');
            $email       = $this->clean($_POST['guest_email'][$index] ?? '');

            if (!empty($guestName) && !$this->validateName($guestName)) {
                $this->errors[] = "Guest name contains invalid characters.";
            }

            if (!empty($company) && !$this->validateCompany($company)) {
                $this->errors[] = "Company name contains invalid characters.";
            }

            if (!empty($designation) && !$this->validateDesignation($designation)) {
                $this->errors[] = "Designation contains invalid characters.";
            }

            if (!empty($contact) && !$this->validatePhone($contact)) {
                $this->errors[] = "Guest contact number must be exactly 10 digits.";
            }

            if (!empty($email) && !$this->validateEmail($email)) {
                $this->errors[] = "Guest email address is invalid. Please enter a valid email format (e.g., name@domain.com).";
            }
        }
    }

    /* ===============================
       INCHARGE VALIDATION
    =============================== */

    if (!empty($_POST['incharge_name'])) {

        foreach ($_POST['incharge_name'] as $index => $name) {

            $inchargeName = $this->clean($name);
            $task         = $this->clean($_POST['task'][$index] ?? '');

            if (!empty($inchargeName) && !$this->validateName($inchargeName)) {
                $this->errors[] = "Incharge name contains invalid characters.";
            }

            if (!empty($task) && strlen($task) > 1000) {
                $this->errors[] = "Task is too long (max 1000 characters).";
            }
        }
    }

    /* ===============================
       STOP IF VALIDATION FAILS
    =============================== */

    if (!empty($this->errors)) {
        $_SESSION['errors'] = $this->errors;
        $this->redirect('/event-reports/documents/checklist');
    }

    /* ===============================
       CONTINUE ORIGINAL INSERT
    =============================== */

    $department_json = $this->jsonField('department');
    $invitation_json = $this->jsonField('invitation');
    $communication   = $this->checkbox('communication');
   $upload = $this->uploadApplicationLetterToCloudinary();
    $application_letter = $upload['url'] ?? null;
    $application_letter_public_id = $upload['public_id'] ?? null;
    $id = Uuid::uuid4()->toString();
    $sql = "INSERT INTO checklists SET
        id = ?,
        programme_name = ?,
        programme_date = ?,
        multi_day = ?,
        programme_start_date = ?,
        programme_end_date = ?,
        department = ?,
        userdept_id = ?,
        coordinator = ?,
        invitation = ?,
        communication = ?,
        communication_details = ?,
        
        transportation = ?, transportation_details = ?,
        invitation_letter = ?, invitation_letter_details = ?,
        welcome_banner = ?, welcome_banner_details = ?,
        gifts = ?, gifts_details = ?,
        bouquets = ?, bouquets_details = ?,
        shawls = ?, shawls_details = ?,
        
        cleanliness = ?, cleanliness_details = ?,
        water_bottles = ?, water_bottles_details = ?,
        snacks = ?, snacks_details = ?,
        tea_coffee = ?, tea_coffee_details = ?,
        itinerary = ?, itinerary_details = ?,
        white_board_welcome = ?, white_board_welcome_details = ?,
        
        cleanliness_seminar_hall = ?, cleanliness_seminar_hall_details = ?,
        mike_speaker = ?, mike_speaker_details = ?,
        decoration = ?, decoration_details = ?,
        projector = ?, projector_details = ?,
        genset = ?, genset_details = ?,
        candle_oil_garland_flowers = ?, candle_oil_garland_flowers_details = ?,
        saraswati_pooja = ?, saraswati_pooja_details = ?,
        saraswati_geet = ?, saraswati_geet_details = ?,
        
        name_plates = ?, name_plates_details = ?,
        note_pad = ?, note_pad_details = ?,
        pen = ?, pen_details = ?,
        water_bottle_on_dias = ?, water_bottle_on_dias_details = ?,
        itinerary_dias = ?, itinerary_dias_details = ?,
        photo_frame = ?, photo_frame_details = ?,
        video_shooting = ?, video_shooting_details = ?,
        photo_shooting = ?, photo_shooting_details = ?,
        social_media = ?, social_media_details = ?,
        impression_book = ?, impression_book_details = ?,
        post_communication = ?, post_communication_details = ?,
        college_database = ?, college_database_details = ?,
        thanks_letter = ?, thanks_letter_details = ?,
        others = ?, others_details = ?,
        
        application_letter = ?,
        created_by = ?,
        application_letter_public_id = ?
    ";

    $stmt = $this->pdo->prepare($sql);

    $params = [
        $id,
        $programme_name,
        $programme_date,
        $multi_day,
        $programme_start_date,
        $programme_end_date,
        $department_json,
        $userDeptId,
        $this->text('coordinator'),
        $invitation_json,
        $communication,
        $this->text('communication_details'),

        $this->checkbox('transportation'), $this->text('transportation_details'),
        $this->checkbox('invitation_letter'), $this->text('invitation_letter_details'),
        $this->checkbox('welcome_banner'), $this->text('welcome_banner_details'),
        $this->checkbox('gifts'), $this->text('gifts_details'),
        $this->checkbox('bouquets'), $this->text('bouquets_details'),
        $this->checkbox('shawls'), $this->text('shawls_details'),

        $this->checkbox('cleanliness'), $this->text('cleanliness_details'),
        $this->checkbox('water_bottles'), $this->text('water_bottles_details'),
        $this->checkbox('snacks'), $this->text('snacks_details'),
        $this->checkbox('tea_coffee'), $this->text('tea_coffee_details'),
        $this->checkbox('itinerary'), $this->text('itinerary_details'),
        $this->checkbox('white_board_welcome'), $this->text('white_board_welcome_details'),

        $this->checkbox('cleanliness_seminar_hall'), $this->text('cleanliness_seminar_hall_details'),
        $this->checkbox('mike_speaker'), $this->text('mike_speaker_details'),
        $this->checkbox('decoration'), $this->text('decoration_details'),
        $this->checkbox('projector'), $this->text('projector_details'),
        $this->checkbox('genset'), $this->text('genset_details'),
        $this->checkbox('candle_oil_garland_flowers'), $this->text('candle_oil_garland_flowers_details'),
        $this->checkbox('saraswati_pooja'), $this->text('saraswati_pooja_details'),
        $this->checkbox('saraswati_geet'), $this->text('saraswati_geet_details'),

        $this->checkbox('name_plates'), $this->text('name_plates_details'),
        $this->checkbox('note_pad'), $this->text('note_pad_details'),
        $this->checkbox('pen'), $this->text('pen_details'),
        $this->checkbox('water_bottle_on_dias'), $this->text('water_bottle_on_dias_details'),
        $this->checkbox('itinerary_dias'), $this->text('itinerary_dias_details'),
        $this->checkbox('photo_frame'), $this->text('photo_frame_details'),
        $this->checkbox('video_shooting'), $this->text('video_shooting_details'),
        $this->checkbox('photo_shooting'), $this->text('photo_shooting_details'),
        $this->checkbox('social_media'), $this->text('social_media_details'),
        $this->checkbox('impression_book'), $this->text('impression_book_details'),
        $this->checkbox('post_communication'), $this->text('post_communication_details'),
        $this->checkbox('college_database'), $this->text('college_database_details'),
        $this->checkbox('thanks_letter'), $this->text('thanks_letter_details'),
        $this->checkbox('others'), $this->text('others_details'),

        $application_letter,  
        $user_id,
        $application_letter_public_id
    ];

    try {
    $stmt->execute($params);

    } catch (PDOException $e) {

        error_log("Checklist Create Error: " . $e->getMessage());

        $this->flash->error("Database error occurred while creating checklist.");
        $this->redirect('/event-reports/documents/checklist');
        return;
    }

    $this->saveGuests($id);
    $this->saveIncharges($id);

    $_SESSION['success'] = "Checklist created successfully.";
$this->redirect('/event-reports/dashboard');
}

    private function getChecklistDepartment($checklist_id)
{
    $stmt = $this->pdo->prepare("SELECT department FROM checklists WHERE id = ?");
    $stmt->execute([$checklist_id]);
    return $stmt->fetchColumn();
}

    /* =====================================
       UPDATE EXISTING CHECKLIST
    ===================================== */
   public function update()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->redirect('/documents/checklist?error=invalid_method');
    }

    if (!isset($_SESSION['user_id'])) {
        $this->redirect('/login?error=unauthorized');
    }

    $checklist_id = $_POST['id'] ?? null;
    if (!$checklist_id) {
        $this->redirect('/event-reports/dashboard?error=invalid_id');
    }

    $this->errors = [];

    /* ===============================
       FETCH & CLEAN INPUT
    =============================== */

    $programme_name       = $this->clean($_POST['programme_name'] ?? '');
    $programme_date       = !empty($_POST['programme_date']) ? $_POST['programme_date'] : null;
    $programme_start_date = !empty($_POST['programme_start_date']) ? $_POST['programme_start_date'] : null;
    $programme_end_date   = !empty($_POST['programme_end_date']) ? $_POST['programme_end_date'] : null;

    $multi_day = isset($_POST['multi_day']) ? 1 : 0;

   
    /* ===============================
       PROGRAMME NAME VALIDATION
    =============================== */

    if (empty($programme_name)) {
        $this->errors[] = "Programme name is required.";
    } elseif (!$this->validateProgrammeName($programme_name)) {
        $this->errors[] = "Programme name contains invalid characters.";
    }

    /* ===============================
       MULTI DAY VALIDATION
    =============================== */

    if ($multi_day === 1) {

        if (empty($programme_start_date) || empty($programme_end_date)) {
            $this->errors[] = "Start date and End date are required for multi-day programme.";
        }

        if (!empty($programme_start_date) && !$this->validateDate($programme_start_date)) {
            $this->errors[] = "Invalid start date format.";
        }

        if (!empty($programme_end_date) && !$this->validateDate($programme_end_date)) {
            $this->errors[] = "Invalid end date format.";
        }

        if (!empty($programme_start_date) && !empty($programme_end_date)) {
            if ($programme_start_date > $programme_end_date) {
                $this->errors[] = "End date must be after start date.";
            }
        }

    } else {

        if (empty($programme_date)) {
            $this->errors[] = "Programme date is required.";
        } elseif (!$this->validateDate($programme_date)) {
            $this->errors[] = "Invalid programme date format.";
        }

    }

    /* ===============================
       ENSURE CORRECT NULL HANDLING
    =============================== */

    if ($multi_day === 1) {
        $programme_date = null;
    } else {
        $programme_start_date = null;
        $programme_end_date   = null;
    }

    

    /* ===============================
       STOP IF VALIDATION FAILS
    =============================== */

    if (!empty($this->errors)) {
        $_SESSION['errors'] = $this->errors;
        $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
    }

    /* ===============================
       CONTINUE UPDATE
    =============================== */
    
    $stmtUser = $this->pdo->prepare("
        SELECT role 
        FROM users 
        WHERE id = ?
    ");

    $stmtUser->execute([$_SESSION['user_id']]);
    $userRole = $stmtUser->fetchColumn();

    $department_json = null;

    if ($userRole === 'coordinator' || $userRole === 'principal') {
        $department_json = $this->jsonField('department');
    } else {
        // Get current department from database
        $current_department = $this->getChecklistDepartment($checklist_id);
        // Get submitted department from form
        $submitted_department = $this->jsonField('department');
        
        // Only show error if HOD is actually trying to change the department
        if ($current_department !== $submitted_department) {
            $_SESSION['errors'][] = "HOD cannot update department.";
        }
        
        // Always use the current department (don't allow changes)
        $department_json = $current_department;
    }
    


    $invitation_json = $this->jsonField('invitation');
    $communication   = $this->checkbox('communication');

            /* ===============================
        FETCH EXISTING PUBLIC ID
        =============================== */

        $stmtOld = $this->pdo->prepare("
            SELECT application_letter_public_id 
            FROM checklists 
            WHERE id = ?
        ");
        $stmtOld->execute([$checklist_id]);
        $existingPublicId = $stmtOld->fetchColumn();

        /* ===============================
        Upload to Cloudinary
        =============================== */

        $upload = $this->uploadApplicationLetterToCloudinary($existingPublicId);

        $new_application_letter = $upload['url'] ?? null;
        $new_public_id = $upload['public_id'] ?? $existingPublicId;

        /* ===============================
        Dynamic SQL
        =============================== */

        $application_sql = $new_application_letter 
            ? ", application_letter = ?, application_letter_public_id = ?" 
            : "";


    $sql = "
        UPDATE checklists SET
            programme_name = ?,
            programme_date = ?,
            multi_day = ?,
            programme_start_date = ?,
            programme_end_date = ?,
            department = ?,
            
            invitation = ?,
            communication = ?,
            communication_details = ?,
            
            transportation = ?, transportation_details = ?,
            invitation_letter = ?, invitation_letter_details = ?,
            welcome_banner = ?, welcome_banner_details = ?,
            gifts = ?, gifts_details = ?,
            bouquets = ?, bouquets_details = ?,
            shawls = ?, shawls_details = ?,
            
            cleanliness = ?, cleanliness_details = ?,
            water_bottles = ?, water_bottles_details = ?,
            snacks = ?, snacks_details = ?,
            tea_coffee = ?, tea_coffee_details = ?,
            itinerary = ?, itinerary_details = ?,
            white_board_welcome = ?, white_board_welcome_details = ?,
            
            cleanliness_seminar_hall = ?, cleanliness_seminar_hall_details = ?,
            mike_speaker = ?, mike_speaker_details = ?,
            decoration = ?, decoration_details = ?,
            projector = ?, projector_details = ?,
            genset = ?, genset_details = ?,
            candle_oil_garland_flowers = ?, candle_oil_garland_flowers_details = ?,
            saraswati_pooja = ?, saraswati_pooja_details = ?,
            saraswati_geet = ?, saraswati_geet_details = ?,
            
            name_plates = ?, name_plates_details = ?,
            note_pad = ?, note_pad_details = ?,
            pen = ?, pen_details = ?,
            water_bottle_on_dias = ?, water_bottle_on_dias_details = ?,
            itinerary_dias = ?, itinerary_dias_details = ?,
            photo_frame = ?, photo_frame_details = ?,
            video_shooting = ?, video_shooting_details = ?,
            photo_shooting = ?, photo_shooting_details = ?,
            social_media = ?, social_media_details = ?,
            impression_book = ?, impression_book_details = ?,
            post_communication = ?, post_communication_details = ?,
            college_database = ?, college_database_details = ?,
            thanks_letter = ?, thanks_letter_details = ?,
            others = ?, others_details = ?
            $application_sql
        WHERE id = ?
    ";

    $stmt = $this->pdo->prepare($sql);

    $params = [
        $programme_name,
        $programme_date,
        $multi_day,
        $programme_start_date,
        $programme_end_date,
        $department_json,
        
        $invitation_json,
        $communication,
        $this->text('communication_details'),

        $this->checkbox('transportation'), $this->text('transportation_details'),
        $this->checkbox('invitation_letter'), $this->text('invitation_letter_details'),
        $this->checkbox('welcome_banner'), $this->text('welcome_banner_details'),
        $this->checkbox('gifts'), $this->text('gifts_details'),
        $this->checkbox('bouquets'), $this->text('bouquets_details'),
        $this->checkbox('shawls'), $this->text('shawls_details'),

        $this->checkbox('cleanliness'), $this->text('cleanliness_details'),
        $this->checkbox('water_bottles'), $this->text('water_bottles_details'),
        $this->checkbox('snacks'), $this->text('snacks_details'),
        $this->checkbox('tea_coffee'), $this->text('tea_coffee_details'),
        $this->checkbox('itinerary'), $this->text('itinerary_details'),
        $this->checkbox('white_board_welcome'), $this->text('white_board_welcome_details'),

        $this->checkbox('cleanliness_seminar_hall'), $this->text('cleanliness_seminar_hall_details'),
        $this->checkbox('mike_speaker'), $this->text('mike_speaker_details'),
        $this->checkbox('decoration'), $this->text('decoration_details'),
        $this->checkbox('projector'), $this->text('projector_details'),
        $this->checkbox('genset'), $this->text('genset_details'),
        $this->checkbox('candle_oil_garland_flowers'), $this->text('candle_oil_garland_flowers_details'),
        $this->checkbox('saraswati_pooja'), $this->text('saraswati_pooja_details'),
        $this->checkbox('saraswati_geet'), $this->text('saraswati_geet_details'),

        $this->checkbox('name_plates'), $this->text('name_plates_details'),
        $this->checkbox('note_pad'), $this->text('note_pad_details'),
        $this->checkbox('pen'), $this->text('pen_details'),
        $this->checkbox('water_bottle_on_dias'), $this->text('water_bottle_on_dias_details'),
        $this->checkbox('itinerary_dias'), $this->text('itinerary_dias_details'),
        $this->checkbox('photo_frame'), $this->text('photo_frame_details'),
        $this->checkbox('video_shooting'), $this->text('video_shooting_details'),
        $this->checkbox('photo_shooting'), $this->text('photo_shooting_details'),
        $this->checkbox('social_media'), $this->text('social_media_details'),
        $this->checkbox('impression_book'), $this->text('impression_book_details'),
        $this->checkbox('post_communication'), $this->text('post_communication_details'),
        $this->checkbox('college_database'), $this->text('college_database_details'),
        $this->checkbox('thanks_letter'), $this->text('thanks_letter_details'),
        $this->checkbox('others'), $this->text('others_details')
    ];

    if ($new_application_letter) {
        $params[] = $new_application_letter;
        $params[] = $new_public_id;
    }
$params[] = $checklist_id;

    try {

    $stmt->execute($params);

} catch (PDOException $e) {

    error_log("Checklist Update Error: " . $e->getMessage());

    // $_SESSION['errors'] = ["Database error occurred while updating checklist."];
  $_SESSION['errors'] = ["Checklist Update Error: " . $e->getMessage()];

    $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
    return;
}
    

// Save guests
$this->saveGuests($checklist_id);

// 🔥 CHECK HERE
if (!empty($this->errors)) {
    $_SESSION['errors'] = $this->errors;
    $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
    return;
}

// Save incharges
$this->saveIncharges($checklist_id);

// 🔥 CHECK AGAIN (optional but good practice)
if (!empty($this->errors)) {
    $_SESSION['errors'] = $this->errors;
    $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
    return;
}

$_SESSION['success'] = "Checklist updated successfully.";
$this->redirect("/event-reports/documents/view/checklist/$checklist_id");
}

    /* =====================================
       SAVE GUESTS (used by both create & update)
    ===================================== */
    private function saveGuests($checklist_id)
{
     if (empty($_POST['guest_name']) || !is_array($_POST['guest_name'])) {
        $_POST['guest_name'] = [];
    }
    

    /* ===============================
       VALIDATION FIRST
    =============================== */

    foreach ($_POST['guest_name'] as $index => $name) {

        $guestName   = $this->clean($name);
        $company     = $this->clean($_POST['company_name'][$index] ?? '');
        $designation = $this->clean($_POST['designation'][$index] ?? '');
        $contact     = $this->clean($_POST['contact_no'][$index] ?? '');
        $email       = $this->clean($_POST['guest_email'][$index] ?? '');

        if (!empty($guestName) && !$this->validateName($guestName)) {
            $this->errors[] = "Guest name contains invalid characters.";
        }

        if (!empty($company) && !$this->validateCompany($company)) {
            $this->errors[] = "Company name contains invalid characters.";
        }

        if (!empty($designation) && !$this->validateDesignation($designation)) {
            $this->errors[] = "Designation contains invalid characters.";
        }

        if (!empty($contact) && !$this->validatePhone($contact)) {
            $this->errors[] = "Guest contact number must be exactly 10 digits.";
        }

        if (!empty($email) && !$this->validateEmail($email)) {
            $this->errors[] = "Guest email address is invalid.";
        }
    }

    // 🔥 STOP if validation fails
    if (!empty($this->errors)) {
        return;
    }

   $postedIds = isset($_POST['guest_id'])
    ? array_filter(array_map('trim', $_POST['guest_id']))
    : [];
/* ===============================
   DELETE REMOVED GUESTS FIRST
=============================== */

$existingStmt = $this->pdo->prepare("
    SELECT id FROM checklist_guests WHERE checklist_id = ?
");
$existingStmt->execute([$checklist_id]);

$existingIds = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
$existingIds = array_map('trim', $existingIds); 

$toDelete = array_diff($existingIds, $postedIds);

if (!empty($toDelete)) {
    $placeholders = implode(',', array_fill(0, count($toDelete), '?'));

    $delStmt = $this->pdo->prepare("
        DELETE FROM checklist_guests 
        WHERE id IN ($placeholders)
    ");

    $delStmt->execute(array_values($toDelete));
}

/* ===============================
   STOP IF NO GUESTS LEFT
=============================== */

if (empty($_POST['guest_name']) || !is_array($_POST['guest_name'])) {
    return;
}
   /* ===============================
   INSERT / UPDATE
================================ */

try {

    foreach ($_POST['guest_name'] as $i => $name) {

        $guestName   = $this->clean($name);
        if (empty($guestName)) continue;

        $company     = $this->clean($_POST['company_name'][$i] ?? '');
        $designation = $this->clean($_POST['designation'][$i] ?? '');
        $contact     = $this->clean($_POST['contact_no'][$i] ?? '');
        $email       = $this->clean($_POST['guest_email'][$i] ?? '');

        $guest_id = $_POST['guest_id'][$i] ?? null;

/* ------------------------------------
   FETCH EXISTING PUBLIC ID (if update)
------------------------------------ */

        $existingPublicId = null;

        if (!empty($guest_id)) {
            $stmtPublic = $this->pdo->prepare("
                SELECT bio_public_id FROM checklist_guests 
                WHERE id = ? AND checklist_id = ?
            ");
            $stmtPublic->execute([$guest_id, $checklist_id]);
            $existingPublicId = $stmtPublic->fetchColumn();
        }

        /* ------------------------------------
        Upload to Cloudinary
        ------------------------------------ */

        $upload = $this->uploadGuestBioToCloudinary($i, $existingPublicId);

        $bio_image = $upload['url'] ?? null;
        $bio_public_id = $upload['public_id'] ?? $existingPublicId;

        if (!empty($guest_id)) {

            if ($bio_image) {

                $sql = "UPDATE checklist_guests SET 
                            guest_name = ?, company_name = ?, designation = ?, 
                            contact_no = ?, guest_email = ?, bio_image = ?, bio_public_id = ?
                        WHERE id = ? AND checklist_id = ?";

                $stmt = $this->pdo->prepare($sql);
                            $stmt->execute([
                    $guestName,
                    $company ?: null,
                    $designation ?: null,
                    $contact ?: null,
                    $email ?: null,
                    $bio_image,
                    $bio_public_id,
                    $guest_id,
                    $checklist_id
                ]);

            } else {

                $sql = "UPDATE checklist_guests SET 
                            guest_name = ?, company_name = ?, designation = ?, 
                            contact_no = ?, guest_email = ?
                        WHERE id = ? AND checklist_id = ?";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $guestName,
                    $company ?: null,
                    $designation ?: null,
                    $contact ?: null,
                    $email ?: null,
                    $guest_id,
                    $checklist_id
                ]);
            }

        } else {
            $id = Uuid::uuid4()->toString();
            $sql = "INSERT INTO checklist_guests 
            (id, checklist_id, guest_name, company_name, designation, contact_no, guest_email, bio_image, bio_public_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $id,
                $checklist_id,
                $guestName,
                $company ?: null,
                $designation ?: null,
                $contact ?: null,
                $email ?: null,
                $bio_image,
                $bio_public_id
            ]);
        }
    }

} catch (PDOException $e) {

    error_log("Checklist Guest Error: " . $e->getMessage());

    // Extract only MySQL message
    $errorMessage = $e->getMessage();

    if (strpos($errorMessage, 'SQLSTATE') !== false) {
        $parts = explode(':', $errorMessage, 3);
        $errorMessage = $parts[2] ?? $errorMessage;
    }

    $this->flash->error(trim($errorMessage));

    

   
    $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
    return;
}}

    /* =====================================
       SAVE INCHARGES (simple replace for now)
    ===================================== */
   private function saveIncharges($checklist_id)
{
    try {

        $this->pdo->beginTransaction();

        /* ------------------------------------
           1️⃣ DELETE SELECTED INCHARGES BY ID
        ------------------------------------ */

        if (!empty($_POST['delete_incharge_ids'])) {

            $deleteStmt = $this->pdo->prepare("
                DELETE FROM program_incharge WHERE id = ?
            ");

            foreach ($_POST['delete_incharge_ids'] as $deleteId) {
                $deleteStmt->execute([$deleteId]);
            }
        }


        /* ------------------------------------
           2️⃣ INSERT OR UPDATE INCHARGES
        ------------------------------------ */

        if (!empty($_POST['incharge_name'])) {

            foreach ($_POST['incharge_name'] as $i => $name) {

                $name = trim($name);
                if (empty($name)) continue;

                $task = $_POST['task'][$i] ?? null;
                $id   = $_POST['incharge_id'][$i] ?? null;

                // 🔹 If ID exists → UPDATE
                if (!empty($id)) {

                    $updateStmt = $this->pdo->prepare("
                        UPDATE program_incharge
                        SET incharge_name = ?, task = ?
                        WHERE id = ? AND checklist_id = ?
                    ");

                    $updateStmt->execute([
                        $name,
                        $task ?: null,
                        $id,
                        $checklist_id
                    ]);

                } 
                // 🔹 If no ID → INSERT new
                else {
                    $id = Uuid::uuid4()->toString();
                    $insertStmt = $this->pdo->prepare("
                        INSERT INTO program_incharge 
                        (id, checklist_id, incharge_name, task)
                        VALUES (?, ?, ?, ?)
                    ");

                    $insertStmt->execute([
                        $id,
                        $checklist_id,
                        $name,
                        $task ?: null
                    ]);
                }
            }
        }

        $this->pdo->commit();

    } catch (PDOException $e) {

        $this->pdo->rollBack();

        error_log("Program Incharge Error: " . $e->getMessage());

        $this->flash->error("Error saving incharge details.");

        $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
        exit;
    }
}
    /* =====================================
       VIEW CHECKLIST
    ===================================== */
    public function view($id)
    {
        if (!isset($_SESSION['user_id'])) {
            $this->redirect('/login?error=unauthorized');
        }

       $checklist_id = $id;

        // Fetch checklist data with coordinator name
        $stmt = $this->pdo->prepare("
            SELECT c.*, u.username as coordinator_name
            FROM checklists c
            LEFT JOIN users u ON c.created_by = u.id
            WHERE c.id=?
        ");
        $stmt->execute([$checklist_id]);
        $checklist = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$checklist) {
            $this->redirect('/dashboard&error=checklist_not_found');
        }

        // Process department and invitation data
        $department = !empty($checklist['department']) ? json_decode($checklist['department'], true) : [];
        $invitation = !empty($checklist['invitation']) ? json_decode($checklist['invitation'], true) : [];

        // Fetch guests
        $gst = $this->pdo->prepare("SELECT * FROM checklist_guests WHERE checklist_id=?");
        $gst->execute([$checklist_id]);
        $guests = $gst->fetchAll(PDO::FETCH_ASSOC);

        // Fetch program incharges
        $pin = $this->pdo->prepare("SELECT * FROM program_incharge WHERE checklist_id=?");
        $pin->execute([$checklist_id]);
        $incharges = $pin->fetchAll(PDO::FETCH_ASSOC);

        // Fetch departments for the checkbox list
        $deps = $this->pdo->query("SELECT id, name FROM departments");
        $departments = $deps->fetchAll(PDO::FETCH_ASSOC);

        // Return all data as an associative array
        return [
            'checklist' => $checklist,
            'department' => $department,
            'invitation' => $invitation,
            'guests' => $guests,
            'incharges' => $incharges,
            'departments' => $departments
        ];
    }

    /* =====================================
   RENDER CREATE CHECKLIST FORM ONLY
===================================== */
    public function createForm()
    {
        if (!isset($_SESSION['user_id'])) {
            $this->redirect('/login?error=unauthorized');
        }

        // Empty data for create mode
        $checklist   = null;
        $department  = [];
        $invitation  = [];
        $guests      = [];
        $incharges   = [];

        // Fetch departments for dropdown
        $stmt = $this->pdo->query("SELECT id, name FROM departments");
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        require __DIR__ . '/../views/documents/checklist.php';
    }

    /**
 * DELETE CHECKLIST
 * Deletes a checklist and all associated guests & incharges
 */
/**
 * DELETE CHECKLIST
 * Deletes checklist + guests + incharges + related documents (invite, notice, appreciation, event_report)
 * Also removes Cloudinary files where applicable
 */
public function delete()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->redirect('/event-reports/dashboard?error=invalid_method');
    }

    if (!isset($_SESSION['user_id'])) {
        $this->redirect('/login?error=unauthorized');
    }

    $checklist_id = $_POST['checklist_id'] ?? null;

    if (!$checklist_id || !Uuid::isValid($checklist_id)) {
        $_SESSION['errors'] = ["Invalid checklist identifier."];
        $this->redirect('/event-reports/dashboard');
    }

    try {

        $this->pdo->beginTransaction();

        // ────────────────────────────────────────────────
        // 1. Collect Cloudinary public IDs
        // ────────────────────────────────────────────────
        $cloudinaryFiles = [];

        // Application letter
        $stmt = $this->pdo->prepare("
            SELECT application_letter_public_id 
            FROM checklists 
            WHERE id = ?
        ");
        $stmt->execute([$checklist_id]);

        if ($pubId = $stmt->fetchColumn()) {
            $cloudinaryFiles[] = [
                'public_id' => $pubId,
                'resource_type' => 'auto'
            ];
        }

        // Guest bio images
        $stmt = $this->pdo->prepare("
            SELECT bio_public_id 
            FROM checklist_guests 
            WHERE checklist_id = ?
            AND bio_public_id IS NOT NULL
        ");
        $stmt->execute([$checklist_id]);

        while ($pubId = $stmt->fetchColumn()) {
            $cloudinaryFiles[] = [
                'public_id' => $pubId,
                'resource_type' => 'image'
            ];
        }

        // Event report photos
        $stmt = $this->pdo->prepare("
            SELECT photos_public_ids 
            FROM event_report 
            WHERE checklist_id = ?
        ");
        $stmt->execute([$checklist_id]);

        if ($jsonIds = $stmt->fetchColumn()) {
            $ids = json_decode($jsonIds, true) ?: [];

            foreach ($ids as $id) {
                $cloudinaryFiles[] = [
                    'public_id' => $id,
                    'resource_type' => 'image'
                ];
            }
        }

        // ────────────────────────────────────────────────
        // 2. Delete child records FIRST
        // ────────────────────────────────────────────────

        $this->pdo->prepare("DELETE FROM invite WHERE checklist_id=?")
            ->execute([$checklist_id]);

        $this->pdo->prepare("DELETE FROM notice WHERE checklist_id=?")
            ->execute([$checklist_id]);

        $this->pdo->prepare("DELETE FROM appreciation WHERE checklist_id=?")
            ->execute([$checklist_id]);

        $this->pdo->prepare("DELETE FROM event_report WHERE checklist_id=?")
            ->execute([$checklist_id]);

        $this->pdo->prepare("DELETE FROM checklist_guests WHERE checklist_id=?")
            ->execute([$checklist_id]);

        $this->pdo->prepare("DELETE FROM program_incharge WHERE checklist_id=?")
            ->execute([$checklist_id]);

        // ────────────────────────────────────────────────
        // 3. Delete checklist itself
        // ────────────────────────────────────────────────

        $this->pdo->prepare("DELETE FROM checklists WHERE id=?")
            ->execute([$checklist_id]);

        // ────────────────────────────────────────────────
        // 4. Delete Cloudinary files (non-blocking)
        // ────────────────────────────────────────────────

        if (!empty($cloudinaryFiles)) {

            try {

                $uploadApi = new \Cloudinary\Api\Upload\UploadApi();

                foreach ($cloudinaryFiles as $file) {

                    $uploadApi->destroy(
                        $file['public_id'],
                        [
                            'resource_type' => $file['resource_type']
                        ]
                    );
                }

            } catch (\Exception $e) {
                error_log("Cloudinary delete failed: " . $e->getMessage());
            }
        }

        $this->pdo->commit();

        $_SESSION['success'] = "Checklist and all related documents deleted successfully.";
        $this->redirect('/event-reports/dashboard');

    } catch (PDOException $e) {

        $this->pdo->rollBack();

        error_log("Checklist deletion failed: " . $e->getMessage());

        $_SESSION['errors'] = [
            "Could not delete checklist. Please try again later."
        ];

        $this->redirect('/event-reports/dashboard');
    }
}
}
