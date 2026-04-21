<?php
require_once __DIR__ . '/../../init/session.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /event-reports/views/auth/login.php");
    exit;
}

// Role check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'coordinator') {
    header("Location: /event-reports/views/auth/login.php");
    exit();
}

require_once __DIR__ . '/../../views/layouts/header.php';
require_once __DIR__ . '/../../init/_dbconnect.php';
require_once __DIR__ . '/../../helpers/csrf_helper.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Edit mode
$checklist_id = $_GET['id'] ?? null;
$edit_mode = !empty($checklist_id);
$checklist_data = null;
$guests_data = [];
$incharges_data = [];

if ($edit_mode) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM checklists WHERE id = ? AND created_by = ?");
        $stmt->execute([$checklist_id, $user_id]);
        $checklist_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$checklist_data) {
            header("Location: /event-reports/dashboard?error=Checklist not found");
            exit;
        }

        // Guests
        $guest_stmt = $pdo->prepare("SELECT * FROM checklist_guests WHERE checklist_id = ?");
        $guest_stmt->execute([$checklist_id]);
        $guests_data = $guest_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Incharges
        $incharge_stmt = $pdo->prepare("SELECT * FROM program_incharge WHERE checklist_id = ?");
        $incharge_stmt->execute([$checklist_id]);
        $incharges_data = $incharge_stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        header("Location: /event-reports/dashboard?error=Error loading checklist");
        exit;
    }
}

$form_action = $edit_mode ? '/event-reports/documents/checklist/update' : '/event-reports/documents/checklist';
?>

<div class="container mb-5 mt-5">
    <div class="header mb-2 text-center">
        <h1 style="color:white;">Checklist for Program</h1>
    </div>

    <?php require_once __DIR__ . '/../../views/partials/flash.php'; ?>

    <style>
        /* Force all form elements to be clickable */
        .form-control, .form-check-input, textarea, input[type="text"], input[type="email"], input[type="date"], input[type="time"], input[type="file"] {
            cursor: text !important;
            pointer-events: auto !important;
            z-index: 10 !important;
            position: relative !important;
        }
        
        .form-check-input {
            cursor: pointer !important;
            pointer-events: auto !important;
            z-index: 10 !important;
        }
        
        .form-check-label {
            cursor: pointer !important;
            pointer-events: auto !important;
            user-select: none;
        }
        
        /* Make buttons clickable */
        .btn {
            cursor: pointer !important;
            pointer-events: auto !important;
            z-index: 10 !important;
        }
        
        /* Ensure table inputs are clickable */
        .table .form-control {
            cursor: text !important;
            pointer-events: auto !important;
            min-height: 38px;
            padding: 6px 12px;
        }
        
        /* Remove any blocking overlays */
        .container, .card, .card-body, .table, .form-group, .mb-3, .mb-2, .mt-2, .mt-3, .mt-4, .mt-5 {
            pointer-events: auto !important;
            z-index: 1 !important;
        }
        
        /* Ensure the entire form is clickable */
        form {
            pointer-events: auto !important;
        }
    </style>

    <form action="<?php echo $form_action; ?>" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
        <?php if ($edit_mode): ?>
            <input type="hidden" name="id" value="<?= htmlspecialchars($checklist_id) ?>">
        <?php endif; ?>
        
        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <!-- BASIC DETAILS -->
        <div class="mb-3">
            <label for="programme_name" class="form-label">Programme Name</label>
            <input type="text" class="form-control" name="programme_name" id="programme_name" value="<?= htmlspecialchars($checklist_data['programme_name'] ?? '') ?>" required>
            <div class="invalid-feedback">Programme Name is required.</div>
        </div>

        <div class="mb-3">
            <label for="programme_date" class="form-label">Programme Date</label>
            <input type="date" id="programme_date" class="form-control" name="programme_date" value="<?= htmlspecialchars($checklist_data['programme_date'] ?? '') ?>" required>
            <div class="invalid-feedback">Programme Date is required.</div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" id="multi_day" name="multi_day" class="form-check-input" <?= !empty($checklist_data['multi_day']) ? 'checked' : '' ?>>
            <label for="multi_day" class="form-check-label">Is this programme for more than one day?</label>
        </div>

        <div id="programme_dates" style="display: <?= !empty($checklist_data['multi_day']) ? 'block' : 'none' ?>;">
            <div class="mb-3">
                <label for="programme_start_date" class="form-label">Programme Start Date</label>
                <input type="date" class="form-control" id="programme_start_date" name="programme_start_date" value="<?= htmlspecialchars($checklist_data['programme_start_date'] ?? '') ?>">
                <div class="invalid-feedback">Start Date is required for multi-day programmes.</div>
            </div>

            <div class="mb-3">
                <label for="programme_end_date" class="form-label">Programme End Date</label>
                <input type="date" class="form-control" id="programme_end_date" name="programme_end_date" value="<?= htmlspecialchars($checklist_data['programme_end_date'] ?? '') ?>">
                <div class="invalid-feedback">End Date is required for multi-day programmes.</div>
            </div>
        </div>
                <hr>

        <!-- DEPARTMENT -->
        <label class="mt-2 mb-2">Department</label><br>
        <?php
        $stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC");
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($departments as $dept):
        ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="department[]" value="<?= htmlspecialchars($dept['id']) ?>" id="dept_<?= $dept['id'] ?>">
                <label class="form-check-label" for="dept_<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></label>
            </div>
        <?php endforeach; ?>

        <!-- COORDINATOR -->
        <div class="mb-3">
            <label for="coordinator" class="form-label">Programme Coordinator</label>
            <input class="form-control" name="coordinator" id="coordinator" value="<?= htmlspecialchars($username ?? '') ?>" readonly>
        </div>

        <!-- INVITATION -->
        <label>Invitation to related faculty/students/dept</label><br>
        <?php foreach (['F.E','S.E','T.E','B.E'] as $inv): ?>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="invitation[]" value="<?= $inv ?>" id="inv_<?= $inv ?>">
                <label class="form-check-label" for="inv_<?= $inv ?>"><?= $inv ?></label>
            </div>
        <?php endforeach; ?>
        <br><br>
        <!-- COMMUNICATION -->
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="communication" id="communication" <?= !empty($checklist_data['communication']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="communication">Communication Pre-Visit</label>
        </div>
        <textarea class="form-control mb-3" name="communication_details" placeholder="Details"><?= htmlspecialchars($checklist_data['communication_details'] ?? '') ?></textarea>

        <hr>

        <!-- GUEST TABLE -->
<h3>Guest Details Entry</h3>
<table id="guestTable" class="table table-bordered">
    <thead>
        <tr>
            <th>Name</th>
            <th>Company</th>
            <th>Designation</th>
            <th>Email</th>
            <th>Bio</th>
            <th>Contact</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($guests_data)): ?>
            <?php foreach ($guests_data as $guest): ?>
                <tr>
                    <td><input type="text" class="form-control" name="guest_name[]" value="<?= htmlspecialchars($guest['guest_name']) ?>" required></td>
                    <td><input type="text" class="form-control" name="company_name[]" value="<?= htmlspecialchars($guest['company_name']) ?>" required></td>
                    <td><input type="text" class="form-control" name="designation[]" value="<?= htmlspecialchars($guest['designation']) ?>" required></td>
                    <td><input type="email" class="form-control" name="guest_email[]" value="<?= htmlspecialchars($guest['guest_email']) ?>"></td>
                    <td><input type="file" class="form-control" name="bio_image[]"></td>
                    <td><input type="text" class="form-control guest-contact" name="contact_no[]" value="<?= htmlspecialchars($guest['contact_no']) ?>" maxlength="10" pattern="\d{10}" required></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteGuestRow(this)">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td><input type="text" class="form-control" name="guest_name[]" ></td>
                <td><input type="text" class="form-control" name="company_name[]" ></td>
                <td><input type="text" class="form-control" name="designation[]" ></td>
                <td><input type="email" class="form-control" name="guest_email[]"></td>
                <td><input type="file" class="form-control" name="bio_image[]"></td>
                <td><input type="text" class="form-control guest-contact" name="contact_no[]" maxlength="10" pattern="\d{10}" ></td>
                <td class="text-center">
                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteGuestRow(this)">Delete</button>
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<button type="button" class="btn btn-secondary mb-3" onclick="addGuestRow()">Add Row</button>

                <hr>

        <!-- SAMPLE CHECKLIST ITEMS -->
        <?php
        $items = [
            'transportation', 'invitation_letter', 'welcome_banner', 'gifts', 'bouquets',
            'shawls', 'cleanliness', 'water_bottles', 'snacks', 'tea_coffee', 'itinerary',
            'white_board_welcome', 'cleanliness_seminar_hall', 'mike_speaker', 'decoration',
            'projector', 'genset', 'candle_oil_garland_flowers', 'saraswati_pooja',
            'saraswati_geet', 'name_plates', 'note_pad', 'pen', 'water_bottle_on_dias',
            'itinerary_dias', 'photo_frame', 'video_shooting', 'photo_shooting', 'social_media',
            'impression_book', 'post_communication', 'college_database', 'thanks_letter', 'others'
        ];
        foreach ($items as $item):
        ?>
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" name="<?= $item ?>" id="<?= $item ?>">
                <label class="form-check-label" for="<?= $item ?>"><?= ucwords(str_replace('_',' ',$item)) ?></label>
            </div>
            <textarea class="form-control mb-2" name="<?= $item ?>_details" placeholder="Details"><?= htmlspecialchars($checklist_data[$item.'_details'] ?? '') ?></textarea>
        <?php endforeach; ?>

        <hr>

        <!-- PROGRAM INCHARGE -->
<h3>Program Incharge</h3>
<table id="incharge_table" class="table table-bordered">
    <thead>
        <tr>
            <th>#</th>
            <th>Name</th>
            <th>Task</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($incharges_data)): ?>
            <?php foreach ($incharges_data as $index => $incharge): ?>
                <tr>
                    <!-- Row Number -->
                    <td><?= $index + 1 ?></td>

                    <!-- Hidden ID (for existing DB records) -->
                    <input type="hidden" name="incharge_id[]" value="<?= htmlspecialchars($incharge['id']) ?>">

                    <td>
                        <input type="text" 
                               name="incharge_name[]" 
                               class="form-control"
                               placeholder="Enter Incharge Name"
                               pattern="[A-Za-z\s]+"
                               title="Only letters and spaces allowed"
                               value="<?= htmlspecialchars($incharge['incharge_name']) ?>"
                               required>
                    </td>

                    <td>
                        <textarea name="task[]" 
                                  class="form-control" 
                                  placeholder="Enter Task" 
                                  style="height: 32px; overflow: hidden;"><?= htmlspecialchars($incharge['task']) ?></textarea>
                    </td>

                    <td class="text-center">
                        <button type="button" class="btn btn-danger btn-md" onclick="deleteInchargeRow(this)">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td>1</td>
                <input type="hidden" name="incharge_id[]" value="">
                <td>
                    <input type="text" name="incharge_name[]" class="form-control" placeholder="Enter Incharge Name" pattern="[A-Za-z\s]+" title="Only letters and spaces allowed" >
                </td>
                <td>
                    <textarea name="task[]" class="form-control" placeholder="Enter Task" style="height: 32px; overflow: hidden;"></textarea>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-danger btn-md" onclick="deleteInchargeRow(this)">Delete</button>
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
<button type="button" class="btn btn-secondary mb-3" onclick="addInchargeRow()">Add Incharge</button>
        <hr>
        <div class="mb-3">
            <label>Upload Application Letter</label>
            <input type="file" name="application_letter" class="form-control">
        </div>

        <button type="submit" class="btn btn-primary mb-5">Submit</button>
        
        <!-- Navigation Buttons -->
        <div class="mt-3">
            <a href="<?= Url::to('/dashboard') ?>" class="btn btn-secondary me-2">
                ← Back to Dashboard
            </a>
            <?php if ($edit_mode): ?>
                <a href="<?= Url::to("/documents/view/checklist/$checklist_id") ?>" class="btn btn-info">
                    View Checklist →
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
// Show/hide multi-day fields
document.getElementById('multi_day').addEventListener('change', function(){
    document.getElementById('programme_dates').style.display = this.checked ? 'block' : 'none';
});

// Bootstrap validation
(function () {
    'use strict'
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', function (event) {

            // Multi-day validation
            const multiDay = document.getElementById('multi_day').checked;
            if(multiDay){
                const start = document.getElementById('programme_start_date');
                const end = document.getElementById('programme_end_date');
                if(!start.value) start.classList.add('is-invalid');
                if(!end.value) end.classList.add('is-invalid');
            }

            // Guest contact validation
            const contacts = document.querySelectorAll('.guest-contact');
            contacts.forEach(input => {
                const val = input.value;
                if(!/^\d{10}$/.test(val)){
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            // Guest email validation
            const emails = document.querySelectorAll('input[name="guest_email[]"]');
            emails.forEach(input => {
                const val = input.value;
                if(val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)){
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            if (!form.checkValidity() || (multiDay && (!document.getElementById('programme_start_date').value || !document.getElementById('programme_end_date').value))) {
                event.preventDefault()
                event.stopPropagation()
            }

            form.classList.add('was-validated')
        }, false)
    })
})();
</script>

<script src="/public/js/checklistValidation.js"></script>
<?php require_once __DIR__ . '/../../views/includes/footer.php'; ?>
