<?php require_once __DIR__ . '/layouts/header.php'; ?>

<style>
/* ================= CAROUSEL IMAGE (PRO LEVEL UI) ================= */

.carousel-image-wrapper {
    position: relative;
    width: 100%;
    height: 400px;
    overflow: hidden;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
}

/* Blurred background */
.carousel-bg {
    position: absolute;
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: blur(20px);
    transform: scale(1.1);
    opacity: 0.6;
}

/* Main image */
.carousel-img {
    position: relative;
    max-height: 100%;
    max-width: 100%;
    object-fit: contain;
    margin: auto;
    display: block;
    z-index: 2;
}
</style>

<div class="page-bg">
    <div class="overlay row">

        <!-- ================= CAROUSEL ================= -->
        <?php if (!empty($upcomingEvents)): ?>
            <div class="container mb-4">
                <h1 class="text-center text-white mb-3">Upcoming Events</h1>

                <div id="upcomingEventsCarousel" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-inner">

                        <?php foreach ($upcomingEvents as $index => $event): ?>
                            <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">

                                <?php if (!empty($event['image_path'])): ?>

                                    <div class="carousel-image-wrapper">
                                        <!-- Blurred Background -->
                                        <img src="<?= htmlspecialchars($event['image_path']) ?>" class="carousel-bg">

                                        <!-- Main Image -->
                                        <img src="<?= htmlspecialchars($event['image_path']) ?>" class="carousel-img">
                                    </div>

                                <?php else: ?>

                                    <div style="height:400px; background:#333; color:white; display:flex; align-items:center; justify-content:center;">
                                        <?= htmlspecialchars($event['event_name']) ?>
                                    </div>

                                <?php endif; ?>

                                <div class="carousel-caption d-none d-md-block">
                                    <h5><?= htmlspecialchars($event['event_name']) ?></h5>
                                    <p>
                                        <?= date('F j, Y', strtotime($event['start_date'])) ?>
                                        <?php if ($event['start_date'] !== $event['end_date']): ?>
                                            to <?= date('F j, Y', strtotime($event['end_date'])) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                            </div>
                        <?php endforeach; ?>

                    </div>

                    <!-- Controls -->
                    <button class="carousel-control-prev" type="button"
                            data-bs-target="#upcomingEventsCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon"></span>
                    </button>

                    <button class="carousel-control-next" type="button"
                            data-bs-target="#upcomingEventsCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon"></span>
                    </button>

                </div>
            </div>
        <?php endif; ?>


        <!-- ================= HEADING ================= -->
        <h2 class="page-title">Event Report</h2>

        <!-- ================= CONTENT ================= -->
        <div class="container">
            <div class="content-card">

                <!-- Search -->
                <div class="search-bar">
                    <input type="text" id="searchYear" class="form-control"
                        placeholder="Search by Year (e.g. 2024)">

                    <select id="searchMonth" class="form-control">
                        <option value="">All Months</option>
                        <option value="01">January</option>
                        <option value="02">February</option>
                        <option value="03">March</option>
                        <option value="04">April</option>
                        <option value="05">May</option>
                        <option value="06">June</option>
                        <option value="07">July</option>
                        <option value="08">August</option>
                        <option value="09">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>

                <!-- Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Table -->
                <table id="eventsTable" class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Event Name</th>
                            <th>Date</th>
                            <th width="150">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($eventReports)): ?>
                            <?php foreach ($eventReports as $row): ?>

                                <?php
                                $date = $row['multi_day']
                                    ? $row['programme_start_date']
                                    : $row['programme_date'];
                                ?>

                                <tr data-date="<?= htmlspecialchars($date) ?>">
                                    <td><?= htmlspecialchars($row['programme_name']) ?></td>
                                    <td><?= htmlspecialchars($date) ?></td>
                                    <td>
                                        <a href="<?= Url::to("/documents/view/event-report/{$row['id']}") ?>"
                                           class="btn btn-sm btn-primary">
                                            View Report
                                        </a>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center">No Event Reports Found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            </div>
        </div>

    </div>
</div>

<script>
/* ================= FILTER EVENTS ================= */
const yearInput  = document.getElementById("searchYear");
const monthSelect = document.getElementById("searchMonth");
const rows       = document.querySelectorAll("#eventsTable tbody tr");

function filterEvents() {
    const year  = yearInput.value.trim();
    const month = monthSelect.value;

    rows.forEach(row => {
        const date = row.dataset.date;

        if (!date) {
            row.style.display = "none";
            return;
        }

        const rowYear  = date.substring(0, 4);
        const rowMonth = date.substring(5, 7);

        const yearMatch  = year === "" || rowYear.includes(year);
        const monthMatch = month === "" || rowMonth === month;

        row.style.display = (yearMatch && monthMatch) ? "" : "none";
    });
}

yearInput.addEventListener("input", filterEvents);
monthSelect.addEventListener("change", filterEvents);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
