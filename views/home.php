<?php require_once __DIR__ . '/layouts/header.php'; ?>
<br><br>
<!-- ================= CAROUSEL (Full Width, outside overlay) ================= -->
<?php if (!empty($upcomingEvents)): ?>
    <div id="upcomingEventsCarousel" class="carousel slide" data-bs-ride="carousel">
        <div class="carousel-inner">
            
            <?php foreach ($upcomingEvents as $index => $event): ?>
                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                    <?php if (!empty($event['image_path'])): ?>
                        <img src="<?= htmlspecialchars($event['image_path']) ?>"
                             class="carousel-banner-img"
                             alt="<?= htmlspecialchars($event['event_name']) ?>">
                    <?php else: ?>
                        <div class="carousel-no-image">
                            <?= htmlspecialchars($event['event_name']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="carousel-caption">
                        <h5><?= htmlspecialchars($event['event_name']) ?></h5>
                        <p>
                            <?= date('F j, Y', strtotime($event['start_date'])) ?>
                            <?php if ($event['start_date'] !== $event['end_date']): ?>
                                &nbsp;to&nbsp; <?= date('F j, Y', strtotime($event['end_date'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button class="carousel-control-prev" type="button"
                data-bs-target="#upcomingEventsCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon"></span>
        </button>
        <button class="carousel-control-next" type="button"
                data-bs-target="#upcomingEventsCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon"></span>
        </button>

        <!-- Slide Indicators -->
        <div class="carousel-indicators">
            <?php foreach ($upcomingEvents as $index => $event): ?>
                <button type="button"
                        data-bs-target="#upcomingEventsCarousel"
                        data-bs-slide-to="<?= $index ?>"
                        class="<?= $index === 0 ? 'active' : '' ?>"
                        aria-label="Slide <?= $index + 1 ?>">
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Upcoming Events Label below the banner -->
    <div class="upcoming-label">
        <span>📅 Upcoming Events</span>
    </div>
<?php endif; ?>


<!-- ================= MAIN PAGE CONTENT ================= -->
<div class="page-bg">
    <div class="overlay row">

        <!-- HEADING -->
        <h2 class="page-title">Event Report</h2>

        <!-- CONTENT -->
        <div class="container">
            <div class="content-card">

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

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

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

<style>
/* ── Carousel Banner ─────────────────────────────────────── */
#upcomingEventsCarousel {
    width: 100%;
    position: relative;
    background: #000;
    margin: 0;
    padding: 0;
    overflow: hidden;
}

/* The image fills full width and auto-adjusts height based on its aspect ratio */
.carousel-banner-img {
    display: block;
    width: 100%;
    height: auto;               /* natural height — portrait stays tall, landscape stays wide */
    max-height: 90vh;           /* safety cap so very tall images don't push content off screen */
    object-fit: contain;        /* no cropping, no distortion — shows full image */
    background: #000;
    margin: 0 auto;
}

/* Fallback when no image uploaded */
.carousel-no-image {
    width: 100%;
    min-height: 300px;
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: 1px;
    text-align: center;
    padding: 2rem;
}

/* Caption overlay at the bottom of each slide */
.carousel-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, transparent 100%);
    padding: 2rem 1.5rem 1.2rem;
    text-align: center;
    border-radius: 0;
}

.carousel-caption h5 {
    font-size: clamp(1rem, 2.5vw, 1.6rem);
    font-weight: 700;
    color: #fff;
    margin-bottom: 0.3rem;
    text-shadow: 0 2px 6px rgba(0,0,0,0.8);
}

.carousel-caption p {
    font-size: clamp(0.8rem, 1.5vw, 1rem);
    color: #f0f0f0;
    margin: 0;
    text-shadow: 0 1px 4px rgba(0,0,0,0.7);
}

/* Prev / Next controls */
.carousel-control-prev,
.carousel-control-next {
    width: 5%;
}

/* Dot indicators */
.carousel-indicators button {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    opacity: 0.6;
}
.carousel-indicators .active {
    opacity: 1;
}

/* Label strip below the carousel */
.upcoming-label {
    width: 100%;
    background: #1a1a2e;
    color: #fff;
    text-align: center;
    padding: 10px 0;
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 1px;
}
</style>

<script>
const yearInput   = document.getElementById("searchYear");
const monthSelect = document.getElementById("searchMonth");
const rows        = document.querySelectorAll("#eventsTable tbody tr");

function filterEvents() {
    const year  = yearInput.value.trim();
    const month = monthSelect.value;

    rows.forEach(row => {
        const date = row.dataset.date;
        if (!date) { row.style.display = "none"; return; }

        const rowYear  = date.substring(0, 4);
        const rowMonth = date.substring(5, 7);

        const yearMatch  = year  === "" || rowYear.includes(year);
        const monthMatch = month === "" || rowMonth === month;

        row.style.display = (yearMatch && monthMatch) ? "" : "none";
    });
}

yearInput.addEventListener("input", filterEvents);
monthSelect.addEventListener("change", filterEvents);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
