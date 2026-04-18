<?php
// -------------------- SESSION --------------------
require_once __DIR__ . '/../layouts/header.php';


// -------------------- PATH HELPER --------------------
function getAssetPath($path) {
    // Remove common project path prefixes to get relative path
    $path = str_replace([
        '/event-reports/public/', 
        'event-reports/public/',
        '/events-reports/public/',
        'events-reports/public/',
        '/event-reports/',
        'event-reports/',
        '/events-reports/',
        'events-reports/'
    ], '', $path);
    return ltrim($path, '/');
}
?>

<link rel="stylesheet" href="/event-reports/public/css/view.css">

<div class="container" id="pdf-content">
<div class="card mt-5">
<div class="card-body">

<!-- HEADER -->
<div class="img-logo text-center">
<img src="<?php echo htmlspecialchars($header_image); ?>">
</div>

<h3 class="text-center mt-3">EVENT REPORT</h3><br>

<p>Name of Event: <strong><?php echo $programme_name; ?></strong></p>
<p>Day & Date:<strong> <?php echo $event_date; ?></strong></p>
<p>Time:<strong> <?php echo $event_time; ?></strong></p>
<p>Venue: <strong><?php echo htmlspecialchars($event_venue); ?></strong></p>
<br>
<?php if (!empty($guests)): ?>

<h4 class="mt-4 mb-3">Guest Details</h4>
                    <div class="event-report-wrapper">
    <table class="event-report-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Company / Organization</th>
                <th>Designation</th>
                <th>Email</th>
            </tr>
        </thead>
        <tbody>
            
                <?php foreach ($guests as $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($g['guest_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($g['company_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($g['designation'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($g['guest_email'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
           
            
        </tbody>
    </table>
</div>
<?php endif; ?>

<br><br>
<p><strong class="section-title">Description:</strong><br><?= htmlspecialchars_decode($description); ?></p><br>
<p><strong class="section-title">Activities and Highlights:</strong><br><?= htmlspecialchars_decode($activities); ?></p><br>
<p><strong class="section-title">Significance:</strong><br><?= htmlspecialchars_decode($significance); ?></p><br>
<p><strong class="section-title">Conclusion:</strong><br><?= htmlspecialchars_decode($conclusion); ?></p><br>
<p><strong class="section-title">Faculties' Responses & Participation:</strong><br><?= htmlspecialchars_decode($faculties_participation); ?></p><br>


<?php if (!empty($photos)): ?>
<h4 class="text-center mt-4">Photos</h4>
    <div class="photos-gallery text-center">
        <?php foreach ($photos as $i => $photo_url): ?>
        <div class="image-container mb-4">
            <img 
                src="<?php echo htmlspecialchars($photo_url); ?>" 
                alt="<?php echo htmlspecialchars($captions[$i] ?? 'Event photo'); ?>"
                class="img-fluid event-report-img"
                loading="lazy"
            >
            <?php if (!empty($captions[$i])): ?>
            <div class="caption mt-2">
                <strong><?php echo htmlspecialchars($captions[$i]); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="text-center text-muted"></p>
<?php endif; ?>

<br>

<!-- SIGNATURES -->
<div class="signature">

<div>
<?php if($coordinator_sign): ?>
<img src="<?php echo htmlspecialchars($coordinator_sign); ?>" width="150">
<?php endif; ?>
<strong><?php echo $coordinator_name; ?></strong>
Coordinator
</div>

<!-- HOD - Only show if HOD name is not default 'N/A' (meaning exactly one department exists) -->
<?php if (!empty($hod_name) && $hod_name !== 'N/A'): ?>
<div>
<?php if($hod_sign): ?>
<img src="<?php echo htmlspecialchars($hod_sign); ?>" width="150">
<?php endif; ?>
<strong><?php echo $hod_name; ?></strong>
HOD
</div>
<?php endif; ?>

<div>
<?php if($principal_sign): ?>
<img src="<?php echo htmlspecialchars($principal_sign); ?>" width="150">
<?php endif; ?>
<strong><?php echo $principal_name; ?></strong>
Principal
</div>

</div>

<br>
<div class="img-logo text-center">
    <img src="/public/images/view_footer.png" alt="Footer Image">
</div>

<!-- BUTTONS -->
<?php if(!empty($canAccessButtons)): ?>
<div>
    <a href="<?= Url::to('/documents/event-report/' . $checklist_id) ?>" class="btn btn-secondary">Edit Event Report</a>
    <a href="<?= Url::to('/documents/download/' . $checklist_id) ?>" class="btn btn-primary"> Download PDF</a>
</div>
<?php endif; ?>

</div>
</div>
</div>

