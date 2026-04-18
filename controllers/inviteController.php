<?php

/**
 * InviteController
 * Handles viewing, creating, updating invitation letters for checklist guests
 * + Final read-only view after submission
 */
use Ramsey\Uuid\Uuid;
require_once __DIR__ . '/../core/BaseController.php';

class InviteController extends BaseController
{
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /* =====================================
       MANAGE INVITATION FORM (GET + POST)
       - Shows form per guest with pagination
       - Creates/updates invitation
    ===================================== */
    public function manage($checklist_id = null)
    {
        // Auth check
        if (!isset($_SESSION['user_id'])) {
            $this->redirect('/login');
        }

        // Get checklist_id from parameter or GET
        if ($checklist_id === null) {
            $checklist_id = $_GET['checklist_id'] ?? null;
        }
        
        if (
            empty($checklist_id) ||
            !preg_match('/^[0-9a-f-]{36}$/i', $checklist_id)
        ) {
            throw new Exception('Invalid checklist ID');
        }

        $page = $_GET['page'] ?? 1;
        if (
            empty($page) ||
            !is_numeric($page) ||
            $page < 1
        ) {
            $page = 1;
        }
        if ($page < 1) $page = 1;

        // Fetch checklist + coordinator
        $stmt = $this->pdo->prepare("
            SELECT c.*, u.username as coordinator_name
            FROM checklists c
            LEFT JOIN users u ON c.created_by = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$checklist_id]);
        $checklist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$checklist) {
            $this->redirect('/dashboard&error=checklist_not_found');
        }

        // Fetch all guests
        $stmtGuests = $this->pdo->prepare("
            SELECT id, guest_name, company_name, designation
            FROM checklist_guests 
            WHERE checklist_id = ?
            ORDER BY id ASC
        ");
        $stmtGuests->execute([$checklist_id]);
        $guests = $stmtGuests->fetchAll(PDO::FETCH_ASSOC);

        $totalGuests = count($guests);

        if ($totalGuests == 0) {
            

         $_SESSION['errors'] = ["No guests found for this checklist. Please add guests first."];


    $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
    return;
}

        if ($page > $totalGuests) $page = $totalGuests;

        $guest = $guests[$page - 1];
        $guest_id = $guest['id'];
        $guestName = htmlspecialchars($guest['guest_name'] ?? '');
        $companyName = htmlspecialchars($guest['company_name'] ?? 'N/A');
        $companyDesignation = htmlspecialchars($guest['designation'] ?? 'N/A');

        $coordinator_name = htmlspecialchars($checklist['coordinator_name'] ?? 'N/A');

        // Fetch HOD - only if exactly one department exists
        $hod_name = 'N/A';
        
        // Get department data to check count
        $stmtDept = $this->pdo->prepare("SELECT department FROM checklists WHERE id = ?");
        $stmtDept->execute([$checklist_id]);
        $deptRow = $stmtDept->fetch(PDO::FETCH_ASSOC);
        
        $deptArray = json_decode($deptRow['department'] ?? '[]', true);
        $dept_id = 0;
        
        // Only show HOD if exactly one department exists
        if (is_array($deptArray) && count($deptArray) === 1) {
            $dept_id = $deptArray[0]; // Keep as UUID string
            $stmtHod = $this->pdo->prepare("
                SELECT u.username AS hod_name
                FROM users u
                WHERE u.role = 'hod' AND u.department_id = ?
                LIMIT 1
            ");
            $stmtHod->execute([$dept_id]);
            $hodRow = $stmtHod->fetch(PDO::FETCH_ASSOC);
            $hod_name = htmlspecialchars($hodRow['hod_name'] ?? 'N/A');
        }

        // Check existing invitation
        $stmtInvite = $this->pdo->prepare("
            SELECT * FROM invite 
            WHERE checklist_id = ? AND guest_id = ?
        ");
        $stmtInvite->execute([$checklist_id, $guest_id]);
        $existingInvitation = $stmtInvite->fetch(PDO::FETCH_ASSOC);

        // Prefill
        $date = $existingInvitation['invite_date'] ?? '';
        $subject = $existingInvitation['subject'] ?? '';
        $respected = $existingInvitation['respected'] ?? '';
        $body = $existingInvitation['body'] ?? '';
        $recipient = "$guestName - $companyName - $companyDesignation";

        $errors = [];
        $success = $_GET['success'] ?? '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // CSRF validation
            if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                    $this->redirect("/documents/invitation/$checklist_id?error=csrf_invalid");
                exit;
            }

            $date       = trim($_POST['date'] ?? '');
            $recipient  = trim($_POST['recipient'] ?? '');
            $subject    = trim($_POST['subject'] ?? '');
            $respected  = trim($_POST['respected'] ?? '');
            $body       = trim($_POST['body'] ?? '');

            if (empty($date) || empty($subject) || empty($respected) || empty($body)) {
                $errors[] = "All fields are required!";
            } else {
                try {
                    if ($existingInvitation) {
                        $stmtUpdate = $this->pdo->prepare("
                            UPDATE invite 
                            SET invite_date = ?, recipient = ?, subject = ?, respected = ?, body = ?
                            WHERE checklist_id = ? AND guest_id = ?
                        ");
                        $stmtUpdate->execute([$date, $recipient, $subject, $respected, $body, $checklist_id, $guest_id]);
                        $success = "Invitation updated successfully!";
                    } else {
                        $id = Uuid::uuid4()->toString();
                        $stmtInsert = $this->pdo->prepare("
                            INSERT INTO invite 
                            (id, checklist_id, guest_id, invite_date, recipient, subject, respected, body)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtInsert->execute([$id, $checklist_id, $guest_id, $date, $recipient, $subject, $respected, $body]);
                        $success = "Invitation saved successfully!";
                    }

                    // Refresh data
                    $stmtInvite->execute([$checklist_id, $guest_id]);
                    $existingInvitation = $stmtInvite->fetch(PDO::FETCH_ASSOC);

                    $date = $existingInvitation['invite_date'] ?? '';
                    $subject = $existingInvitation['subject'] ?? '';
                    $respected = $existingInvitation['respected'] ?? '';
                    $body = $existingInvitation['body'] ?? '';
                } catch (PDOException $e) {
                    error_log("Invitation save failed: " . $e->getMessage());
                    $errors[] = "Failed to save invitation. Please try again.";
                }
            }
        }

        $this->render('documents/invite', [
            'checklist_id'       => $checklist_id,
            'page'               => $page,
            'totalGuests'        => $totalGuests,
            'guest'              => $guest,
            'guestName'          => $guestName,
            'companyName'        => $companyName,
            'companyDesignation' => $companyDesignation,
            'coordinator_name'   => $coordinator_name,
            'hod_name'           => $hod_name,
            'existingInvitation' => $existingInvitation,
            'date'               => $date,
            'subject'            => $subject,
            'respected'          => $respected,
            'body'               => $body,
            'recipient'          => $recipient,
            'success'            => $success,
            'errors'             => $errors
        ]);
    }

    /* =====================================
       VIEW FINAL INVITATION (after submit)
    ===================================== */
    public function view($checklist_id = null)
    {
        // Auth check
        if (!isset($_SESSION['user_id'])) {
            $this->redirect('/login');
        }

        // Get checklist_id from parameter or GET
        if ($checklist_id === null) {
            $checklist_id = $_GET['checklist_id'] ?? null;
            if (
                empty($checklist_id) ||
                !preg_match('/^[0-9a-f-]{36}$/i', $checklist_id)
            ) {
                throw new Exception('Invalid checklist ID');
            }
        } else {
            if (
                empty($checklist_id) ||
                !preg_match('/^[0-9a-f-]{36}$/i', $checklist_id)
            ) {
                throw new Exception('Invalid checklist ID');
            }
        }
        if (!$checklist_id) {
            $this->redirect('/dashboard?error=checklist_id_missing');
        }

        // Get page parameter (for pagination support)
        $page = $_GET['page'] ?? 1;
        if (
            empty($page) ||
            !is_numeric($page) ||
            $page < 1
        ) {
            $page = 1;
        }
        if ($page < 1) $page = 1;

        // Fetch all guests to support pagination
        $stmtGuests = $this->pdo->prepare("
            SELECT id, guest_name, company_name, designation
            FROM checklist_guests 
            WHERE checklist_id = ?
            ORDER BY id ASC
        ");
        $stmtGuests->execute([$checklist_id]);
        $guests = $stmtGuests->fetchAll(PDO::FETCH_ASSOC);

        $totalGuests = count($guests);

        if ($totalGuests == 0) {
            $this->redirect("/event-reports/documents/view/checklist/$checklist_id");
            return;
        }

        if ($page > $totalGuests) $page = $totalGuests;

        // Get the specific guest for this page
        $guest = $guests[$page - 1];
        $guest_id = $guest['id'];

        // Fetch invitation for this specific guest
        $stmt = $this->pdo->prepare("
            SELECT i.*, ch.department
            FROM invite i
            JOIN checklists ch ON i.checklist_id = ch.id
            WHERE i.checklist_id = ? AND i.guest_id = ?
            LIMIT 1
        ");
        $stmt->execute([$checklist_id, $guest_id]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invitation) {
            // If no invitation for this guest, redirect to manage page for this guest
            $this->redirect('/documents/invitation/' . $checklist_id . '?page=' . $page . '&error=no_invitation');
        }

        // Handle department header logic
        $deptArray = json_decode($invitation['department'] ?? '[]', true) ?? [];
        $header_image = '';

        // Default header
        $stmtDefault = $this->pdo->query("SELECT image FROM default_header LIMIT 1");
        $defaultRow = $stmtDefault->fetch(PDO::FETCH_ASSOC);
        $header_image = $defaultRow['image'] ?? '';

        // If exactly one department
        if (is_array($deptArray) && count($deptArray) === 1 && !empty($deptArray[0])) {
            $dept_id = $deptArray[0]; // Keep as UUID string
            $stmtDept = $this->pdo->prepare("SELECT header_image FROM departments WHERE id = ?");
            $stmtDept->execute([$dept_id]);
            $deptRow = $stmtDept->fetch(PDO::FETCH_ASSOC);
            if (!empty($deptRow['header_image'])) {
                $header_image = $deptRow['header_image'];
            }
        }

        // Date formatting
        $date = !empty($invitation['invite_date'])
            ? date('d-m-Y', strtotime($invitation['invite_date']))
            : 'N/A';

        // Guest info fallback (try both schemas)
        $guestName = "Guest Name";
        $companyName = "Company Name";
        $companyDesignation = "Designation";

        if (!empty($invitation['recipient'])) {
            $recipientParts = explode(' - ', $invitation['recipient']);
            $guestName = htmlspecialchars($recipientParts[0] ?? 'Guest Name');
            $companyName = htmlspecialchars($recipientParts[1] ?? 'N/A');
            $companyDesignation = htmlspecialchars($recipientParts[2] ?? '');
        } else if (!empty($invitation['guest_id'])) {
            $stmtGuest = $this->pdo->prepare("
                SELECT guest_name, company_name, designation 
                FROM checklist_guests 
                WHERE id = ?
            ");
            $stmtGuest->execute([$invitation['guest_id']]);
            $guestData = $stmtGuest->fetch(PDO::FETCH_ASSOC);
            if ($guestData) {
                $guestName = htmlspecialchars($guestData['guest_name']);
                $companyName = htmlspecialchars($guestData['company_name'] ?? 'N/A');
                $companyDesignation = htmlspecialchars($guestData['designation'] ?? '');
            }
        }

        // Coordinator + signature
        $stmtCoord = $this->pdo->prepare("
            SELECT u.username AS name, u.sign_image 
            FROM checklists c
            LEFT JOIN users u ON c.created_by = u.id
            WHERE c.id = ?
        ");
        $stmtCoord->execute([$checklist_id]);
        $coord = $stmtCoord->fetch(PDO::FETCH_ASSOC);

        $coordinator_name = htmlspecialchars($coord['name'] ?? 'Programme Coordinator');
        $coordinator_sign = $coord['sign_image'] ?? '';

        // HOD + signature - only if exactly one department exists
        $hod_name = 'N/A';
        $hod_sign = '';
        
        // Check if exactly one department exists (same logic as manage() method)
        if (is_array($deptArray) && count($deptArray) === 1) {
            $dept_id = $deptArray[0]; // Keep as UUID string
            $stmtHod = $this->pdo->prepare("
                SELECT username AS name, sign_image 
                FROM users 
                WHERE role = 'hod' AND department_id = ? 
                LIMIT 1
            ");
            $stmtHod->execute([$dept_id]);
            $hod = $stmtHod->fetch(PDO::FETCH_ASSOC);
            $hod_name = htmlspecialchars($hod['name'] ?? 'N/A');
            $hod_sign = $hod['sign_image'] ?? '';
        }

        // Principal + signature
        $stmtPrincipal = $this->pdo->prepare("
            SELECT username AS name, sign_image 
            FROM users 
            WHERE role = 'principal' 
            LIMIT 1
        ");
        $stmtPrincipal->execute();
        $principal = $stmtPrincipal->fetch(PDO::FETCH_ASSOC);

        $principal_name = htmlspecialchars($principal['name'] ?? 'Principal');
        $principal_sign = $principal['sign_image'] ?? '';

        // Pass all data to view
        $this->render('documents/view_invitation', [
            'header_image'       => $header_image,
            'date'               => $date,
            'guestName'          => $guestName,
            'companyName'        => $companyName,
            'companyDesignation' => $companyDesignation,
            'subject'            => htmlspecialchars($invitation['subject'] ?? ''),
            'respected'          => htmlspecialchars($invitation['respected'] ?? ''),
            'body' => htmlspecialchars_decode($invitation['body'] ?? ''),
            'coordinator_name'   => $coordinator_name,
            'coordinator_sign'   => $coordinator_sign,
            'hod_name'           => $hod_name,
            'hod_sign'           => $hod_sign,
            'principal_name'     => $principal_name,
            'principal_sign'     => $principal_sign,
            'checklist_id'       => $checklist_id,
            'page'               => $page,
            'totalGuests'        => $totalGuests,
            'guest'              => $guest
        ]);
    }
}
