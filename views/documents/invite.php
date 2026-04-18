<?php require_once __DIR__ . '/../../views/layouts/header.php'; ?>
<?php require_once __DIR__ . '/../../views/includes/sidebar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row">
        <?php include __DIR__ . '/../includes/quick_doc_action.php'; ?>
        
        <div class="container mt-4">
            <div class="header mt-4">
                <h2 class="text-center">Letter of Invitation</h2>
            </div>

                <div class="card-body p-4">

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul>
                                <?php foreach ($errors as $e): ?>
                                    <li><?= htmlspecialchars($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>

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

                    <form method="POST" action="<?= Url::to("/documents/invitation/$checklist_id?page=$page") ?>" id="inviteForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                        <!-- Date -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>" >
                        </div>

                        <!-- Recipient (display + hidden) -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">To</label>
                            <div class="border p-3 bg-light rounded">
                                <strong><?= htmlspecialchars($guestName) ?></strong><br>
                                <?= htmlspecialchars($companyDesignation) ?><br>
                                <?= htmlspecialchars($companyName) ?>
                            </div>
                            <input type="hidden" name="recipient" value="<?= htmlspecialchars($guestName . ' - ' . $companyName . ' - ' . $companyDesignation) ?>">
                        </div>

                        <!-- Subject -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" value="<?= htmlspecialchars($subject) ?>" >
                        </div>

                        <!-- Respected -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Respected <span class="text-danger">*</span></label>
                            <input type="text" name="respected" class="form-control" value="<?= htmlspecialchars($respected) ?>" >
                        </div>

                        <!-- Body -->
                        

                        <div class="mb-4">
                            <label class="form-label fw-bold">Body <span class="text-danger">*</span></label>
                            <textarea name="body" rows="10" class="form-control ckeditor-field" required><?= htmlspecialchars($body ?? '') ?></textarea>
                            
                        </div>

                        <!-- Readonly Info -->
                        <div class="row g-3 mb-4">
                            <?php 
                            // Check if HOD name should be shown (only if it's not the default 'N/A' from multiple departments)
                            $show_hod = (!empty($hod_name) && $hod_name !== 'N/A');
                            ?>
                            
                            <?php if ($show_hod): ?>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">HOD Name</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($hod_name) ?>" readonly>
                                </div>
                            <?php endif; ?>
                            
                            <div class="<?php echo $show_hod ? 'col-md-6' : 'col-md-12'; ?>">
                                <label class="form-label fw-bold">Coordinator</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($coordinator_name) ?>" readonly>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="d-flex gap-3 ">
                            <?php if ($existingInvitation): ?>
                                <button type="submit" class="btn btn-warning btn-lg px-5">Update Invitation</button>
                                <a href="<?= Url::to("/documents/view/invitation/$checklist_id?page=$page") ?>" class="btn btn-info btn-lg px-5">
                                    View Invitation
                                </a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary btn-lg px-5">Save Invitation</button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- Pagination -->
                    <?php if ($totalGuests > 1): ?>
                        <div class="mt-5 ">
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $totalGuests; $i++): ?>
                                        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= Url::to("/documents/invitation/$checklist_id?page=$i") ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/../../views/includes/footer.php'; ?>
