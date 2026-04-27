<?php
/**
 * Download Event Report as PDF using TCPDF
 * - Header image on EVERY page
 * - Cloudinary image URLs supported
 * - Layout mirrors view_eventreport.php exactly
 * - Photos: EXACTLY 2 per page, single column, one below the other, properly fitted
 */

// -------------------- SESSION --------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------- DB --------------------
$conn = new mysqli(
    getenv('DB_HOST'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    getenv('DB_NAME'),
    getenv('DB_PORT') ?: 3306
);
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

// -------------------- CHECKLIST ID --------------------
$checklist_id = $_GET['id'] ?? null;
if (!$checklist_id) {
    die("Checklist ID Missing");
}

// -------------------- HELPERS --------------------

function normalizePath($path) {
    $path = str_replace('/event-reports/public/', '', $path);
    return ltrim($path, '/');
}

function buildImagePath($image_value) {
    if (empty($image_value)) return '';
    if (filter_var($image_value, FILTER_VALIDATE_URL) && stripos($image_value, 'https://res.cloudinary.com') === 0) {
        return $image_value;
    }
    return 'https://event-reports-production.up.railway.app/' . ltrim($image_value, '/');
}

function fetchImageToTemp($url) {
    if (empty($url)) return '';

    $ext = 'jpg';
    if (preg_match('/\.(png|gif|webp|jpeg|jpg)(\?|$)/i', $url, $m)) {
        $ext = strtolower($m[1]);
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'er_img_') . '.' . $ext;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $data     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data && $httpCode === 200) {
        file_put_contents($tmpFile, $data);
        return $tmpFile;
    }

    @unlink($tmpFile);
    return '';
}

// -------------------- FETCH DATA --------------------
try {
    // Event Report
    $er_stmt = $conn->prepare("SELECT * FROM event_report WHERE checklist_id = ?");
    $er_stmt->bind_param("s", $checklist_id);
    $er_stmt->execute();
    $event = $er_stmt->get_result()->fetch_assoc();
    if (!$event) die("No Event Report Found");

    // Checklist
    $chk_stmt = $conn->prepare("
        SELECT programme_name, programme_date, multi_day,
               programme_start_date, programme_end_date,
               department, created_by
        FROM checklists WHERE id = ?
    ");
    $chk_stmt->bind_param("s", $checklist_id);
    $chk_stmt->execute();
    $checklist = $chk_stmt->get_result()->fetch_assoc();

    // Notice
    $notice_stmt = $conn->prepare("SELECT event_time, event_venue FROM notice WHERE checklist_id = ?");
    $notice_stmt->bind_param("s", $checklist_id);
    $notice_stmt->execute();
    $notice = $notice_stmt->get_result()->fetch_assoc();

    // Guests
    $guest_stmt = $conn->prepare("
        SELECT guest_name, company_name, designation, guest_email
        FROM checklist_guests WHERE checklist_id = ?
    ");
    $guest_stmt->bind_param("s", $checklist_id);
    $guest_stmt->execute();
    $guest_res = $guest_stmt->get_result();
    $guests    = [];
    while ($g = $guest_res->fetch_assoc()) {
        $guests[] = $g;
    }

    // Department & Header Image
    $deptArray    = json_decode($checklist['department'] ?? '[]', true) ?? [];
    $header_image = '';
    $dept_id      = null;

    $default_stmt = $conn->prepare("SELECT image FROM default_header LIMIT 1");
    $default_stmt->execute();
    $default_row  = $default_stmt->get_result()->fetch_assoc();
    $header_image = $default_row['image'] ?? '';

    if (is_array($deptArray) && count($deptArray) === 1) {
        $dept_id   = $deptArray[0];
        $dept_stmt = $conn->prepare("SELECT header_image FROM departments WHERE id = ?");
        $dept_stmt->bind_param("s", $dept_id);
        $dept_stmt->execute();
        $dept_row = $dept_stmt->get_result()->fetch_assoc();
        if (!empty($dept_row['header_image'])) {
            $header_image = $dept_row['header_image'];
        }
    }

    // Event info
    $programme_name = htmlspecialchars($checklist['programme_name']);
    if (!empty($checklist['multi_day'])) {
        $event_date = date('d-m-Y', strtotime($checklist['programme_start_date']))
                    . ' to '
                    . date('d-m-Y', strtotime($checklist['programme_end_date']));
    } else {
        $event_date = date('d-m-Y', strtotime($checklist['programme_date']));
    }
    $event_time  = !empty($notice['event_time']) ? date('h:i A', strtotime($notice['event_time'])) : 'N/A';
    $event_venue = $notice['event_venue'] ?? 'N/A';

    $photos   = json_decode($event['photos']   ?? '[]', true) ?? [];
    $captions = json_decode($event['captions'] ?? '[]', true) ?? [];

    // Coordinator
    $pc_stmt = $conn->prepare("SELECT username, sign_image FROM users WHERE id = ?");
    $pc_stmt->bind_param("s", $checklist['created_by']);
    $pc_stmt->execute();
    $pc               = $pc_stmt->get_result()->fetch_assoc();
    $coordinator_name = $pc['username']   ?? 'Coordinator';
    $coordinator_sign = $pc['sign_image'] ?? '';

    // HOD
    $hod_name = '';
    $hod_sign = '';
    if (is_array($deptArray) && count($deptArray) === 1) {
        $hod_stmt = $conn->prepare("
            SELECT username, sign_image FROM users
            WHERE role = 'hod' AND department_id = ? LIMIT 1
        ");
        $hod_stmt->bind_param("s", $dept_id);
        $hod_stmt->execute();
        $hod = $hod_stmt->get_result()->fetch_assoc();
        if ($hod) {
            $hod_name = $hod['username'];
            $hod_sign = $hod['sign_image'] ?? '';
        }
    }

    // Principal
    $pr_stmt = $conn->prepare("SELECT username, sign_image FROM users WHERE role = 'principal' LIMIT 1");
    $pr_stmt->execute();
    $principal      = $pr_stmt->get_result()->fetch_assoc();
    $principal_name = $principal['username']   ?? 'Principal';
    $principal_sign = $principal['sign_image'] ?? '';

} catch (Exception $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

// ==================== PRE-DOWNLOAD HEADER IMAGE ====================
$HEADER_IMG_LEFT  = 15;
$HEADER_IMG_TOP   = 8;
$HEADER_IMG_WIDTH = 180;

$PAGE_MARGIN_LEFT   = 18;
$PAGE_MARGIN_RIGHT  = 18;
$PAGE_MARGIN_BOTTOM = 18;

// A4 page height = 297mm
$PAGE_HEIGHT = 297;

$usableW = 210 - $PAGE_MARGIN_LEFT - $PAGE_MARGIN_RIGHT; // 174 mm

$headerUrl     = buildImagePath($header_image);
$headerTmpPath = '';
$headerImgH    = 32;

if (!empty($headerUrl)) {
    $headerTmpPath = fetchImageToTemp($headerUrl);
    if ($headerTmpPath && file_exists($headerTmpPath)) {
        $sz = @getimagesize($headerTmpPath);
        if ($sz && $sz[0] > 0) {
            $headerImgH = round(($sz[1] / $sz[0]) * $HEADER_IMG_WIDTH, 2);
        }
    }
}

$PAGE_MARGIN_TOP = $HEADER_IMG_TOP + $headerImgH + 5;

$tempFiles = [];
if ($headerTmpPath) $tempFiles[] = $headerTmpPath;

// ==================== PHOTO LAYOUT CONSTANTS ====================
// Calculate FIXED image dimensions so exactly 2 photos always fit per page.
// We do this before TCPDF is even loaded so the numbers are clean.

$PHOTO_PAGE_USABLE_H = $PAGE_HEIGHT - $PAGE_MARGIN_TOP - $PAGE_MARGIN_BOTTOM;
// Reserve: "Photos" title on first page = 18mm, gap between photos = 10mm, caption per photo = 8mm
$PHOTO_TITLE_H  = 18;  // mm — heading + spacing, only on first photo page
$PHOTO_GAP      = 10;  // mm — vertical gap between photo 1 and photo 2
$PHOTO_CAP_H    = 8;   // mm — caption space below each image

// On first page: 2 images + 1 gap + 2 captions + title
// imgH = (usable - title - gap - 2*caption) / 2
$PHOTO_IMG_H_FIRST = ($PHOTO_PAGE_USABLE_H - $PHOTO_TITLE_H - $PHOTO_GAP - 2 * $PHOTO_CAP_H) / 2;

// On subsequent pages: 2 images + 1 gap + 2 captions
$PHOTO_IMG_H_REST  = ($PHOTO_PAGE_USABLE_H - $PHOTO_GAP - 2 * $PHOTO_CAP_H) / 2;

// Image width = full usable width
$PHOTO_IMG_W = $usableW; // 174 mm

// ==================== CUSTOM TCPDF CLASS ====================
require_once __DIR__ . '/../../tcpdf/tcpdf.php';

class EventReportPDF extends TCPDF {

    public string $hdrImgPath = '';
    public float  $hdrImgLeft = 15;
    public float  $hdrImgTop  = 8;
    public float  $hdrImgW    = 180;
    public float  $hdrImgH    = 32;

    public function Header(): void {
        if (!empty($this->hdrImgPath) && file_exists($this->hdrImgPath)) {
            $this->Image(
                $this->hdrImgPath,
                $this->hdrImgLeft,
                $this->hdrImgTop,
                $this->hdrImgW,
                $this->hdrImgH,
                '', '', 'T', false, 300,
                '', false, false, 0, false, false, false
            );
        }

        $lineY = $this->hdrImgTop + $this->hdrImgH + 2;
        $this->SetLineWidth(0.4);
        $this->SetDrawColor(150, 150, 150);
        $this->Line(
            $this->hdrImgLeft,
            $lineY,
            $this->getPageWidth() - $this->hdrImgLeft,
            $lineY
        );
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
    }

    public function Footer(): void {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

// ==================== INIT PDF ====================
$pdf = new EventReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Event Management System');
$pdf->SetAuthor('Keystone School of Engineering');
$pdf->SetTitle('Event Report - ' . $programme_name);
$pdf->SetSubject('Event Report');

$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);
$pdf->SetMargins($PAGE_MARGIN_LEFT, $PAGE_MARGIN_TOP, $PAGE_MARGIN_RIGHT);
$pdf->SetAutoPageBreak(true, $PAGE_MARGIN_BOTTOM);
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
$pdf->setJPEGQuality(90);

$pdf->hdrImgPath = $headerTmpPath ?: '';
$pdf->hdrImgLeft = $HEADER_IMG_LEFT;
$pdf->hdrImgTop  = $HEADER_IMG_TOP;
$pdf->hdrImgW    = $HEADER_IMG_WIDTH;
$pdf->hdrImgH    = $headerImgH;

// ==================== HELPER FUNCTIONS ====================

function pdfLabel(EventReportPDF $pdf, string $label, string $value): void {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(45, 7, $label . ':', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 7, $value, 0, 'L');
    $pdf->Ln(1);
}

function pdfSection(EventReportPDF $pdf, string $title, string $html): void {
    $text = trim(strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8')));
    if ($text === '') return;
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, $title . ':', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->writeHTMLCell(0, 6, '', '', $html, 0, 1, false, true, 'L');
    $pdf->Ln(5);
}

// ==================== PAGE 1 — EVENT DETAILS ====================
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'EVENT REPORT', 0, 1, 'C');
$pdf->Ln(6);

pdfLabel($pdf, 'Name of Event', $programme_name);
pdfLabel($pdf, 'Day & Date',    $event_date);
pdfLabel($pdf, 'Time',          $event_time);
pdfLabel($pdf, 'Venue',         htmlspecialchars($event_venue));
$pdf->Ln(5);

// ── Guest Details Table ─────────────────────────────────────────
if (!empty($guests)) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Guest Details', 0, 1, 'L');
    $pdf->Ln(2);

    $colW    = [42, 48, 42, 42];
    $headers = ['Name', 'Company / Organization', 'Designation', 'Email'];

    $pdf->SetFillColor(45, 62, 80);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetLineWidth(0.3);
    foreach ($headers as $i => $h) {
        $pdf->Cell($colW[$i], 8, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('helvetica', '', 10);

    foreach ($guests as $rowIdx => $g) {
        $row = [
            $g['guest_name']   ?? '',
            $g['company_name'] ?? '—',
            $g['designation']  ?? '—',
            $g['guest_email']  ?? '—',
        ];

        $rowH = 7;
        foreach ($row as $ci => $cellText) {
            $lines = $pdf->getNumLines($cellText, $colW[$ci] - 2);
            $h     = max(7, $lines * 6);
            if ($h > $rowH) $rowH = $h;
        }

        if ($pdf->GetY() + $rowH > ($pdf->getPageHeight() - $PAGE_MARGIN_BOTTOM)) {
            $pdf->AddPage();
        }

        $pdf->SetFillColor(
            $rowIdx % 2 === 0 ? 240 : 255,
            $rowIdx % 2 === 0 ? 244 : 255,
            $rowIdx % 2 === 0 ? 248 : 255
        );

        foreach ($row as $ci => $cellText) {
            $pdf->MultiCell($colW[$ci], $rowH, $cellText, 1, 'L', true, 0, '', '', true, 0, false, true, $rowH, 'M');
        }
        $pdf->Ln();
    }

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Ln(5);
}

// ── Text Sections ───────────────────────────────────────────────
$sections = [
    'Description'                          => $event['description']            ?? '',
    'Activities and Highlights'            => $event['activities']              ?? '',
    'Significance'                         => $event['significance']            ?? '',
    'Conclusion'                           => $event['conclusion']              ?? '',
    "Faculties' Responses & Participation" => $event['faculties_participation'] ?? '',
];
foreach ($sections as $title => $html) {
    pdfSection($pdf, $title, $html);
}

// ==================== PHOTOS — exactly 2 per page, stacked ====================
if (!empty($photos)) {

    // Pre-download ALL photos first so we only draw pages for photos that exist
    $photoItems = [];
    foreach ($photos as $idx => $photo_db_value) {
        $photo_url = buildImagePath($photo_db_value);
        if (empty($photo_url)) continue;

        $tmpPhoto = fetchImageToTemp($photo_url);
        if (!$tmpPhoto || !file_exists($tmpPhoto)) continue;

        $tempFiles[]  = $tmpPhoto;
        $photoItems[] = [
            'path'    => $tmpPhoto,
            'caption' => trim($captions[$idx] ?? ''),
        ];
    }

    if (!empty($photoItems)) {

        // Split into pages of exactly 2
        $pages          = array_chunk($photoItems, 2);
        $isFirstPhotoPage = true;

        foreach ($pages as $pagePhotos) {

            $pdf->AddPage();
            // Disable auto page break inside photo pages — we control Y manually
            $pdf->SetAutoPageBreak(false, 0);

            // ── "Photos" title — only on very first photo page ──
            if ($isFirstPhotoPage) {
                $pdf->SetFont('helvetica', 'B', 14);
                $pdf->Cell(0, 10, 'Photos', 0, 1, 'C');
                $pdf->Ln(4);
                $isFirstPhotoPage = false;
                $imgH = $PHOTO_IMG_H_FIRST;
            } else {
                $imgH = $PHOTO_IMG_H_REST;
            }

            // Starting Y for the first photo on this page
            $startY = $pdf->GetY();

            foreach ($pagePhotos as $pos => $item) {
                // pos 0 = top photo, pos 1 = bottom photo
                $y = $startY + $pos * ($imgH + $PHOTO_CAP_H + $PHOTO_GAP);

                // Draw image — full width, fixed height
                $pdf->Image(
                    $item['path'],
                    $PAGE_MARGIN_LEFT,
                    $y,
                    $PHOTO_IMG_W,
                    $imgH,
                    '', '', 'T', false, 150
                );

                // Caption centred below the image
                if ($item['caption'] !== '') {
                    $pdf->SetFont('helvetica', 'I', 10);
                    $pdf->SetXY($PAGE_MARGIN_LEFT, $y + $imgH + 1);
                    $pdf->MultiCell($PHOTO_IMG_W, 6, $item['caption'], 0, 'C');
                }
            }
        }

        // Re-enable auto page break for the signatures page
        $pdf->SetAutoPageBreak(true, $PAGE_MARGIN_BOTTOM);
    }

} else {
    $pdf->SetFont('helvetica', 'I', 11);
    $pdf->Cell(0, 10, 'No photos available.', 0, 1, 'C');
    $pdf->Ln(5);
}

// ==================== SIGNATURES — FINAL PAGE ====================
$pdf->AddPage();
$pdf->SetAutoPageBreak(false);

$pdf->Ln(20);

$signatories = [];
$signatories[] = [
    'name'  => $coordinator_name,
    'title' => 'Coordinator',
    'url'   => buildImagePath($coordinator_sign),
];
if (!empty($hod_name) && $hod_name !== 'N/A') {
    $signatories[] = [
        'name'  => $hod_name,
        'title' => 'HOD',
        'url'   => buildImagePath($hod_sign),
    ];
}
$signatories[] = [
    'name'  => $principal_name,
    'title' => 'Principal',
    'url'   => buildImagePath($principal_sign),
];

foreach ($signatories as &$s) {
    $s['path'] = '';
    if (!empty($s['url'])) {
        $tmp = fetchImageToTemp($s['url']);
        if ($tmp && file_exists($tmp)) {
            $s['path']   = $tmp;
            $tempFiles[] = $tmp;
        }
    }
}
unset($s);

$count   = count($signatories);
$sigColW = $usableW / $count;
$sigSize = 35;
$baseY   = $pdf->GetY();

foreach ($signatories as $i => $s) {
    $x = $PAGE_MARGIN_LEFT + $i * $sigColW;

    if (!empty($s['path']) && file_exists($s['path'])) {
        $imgX = $x + ($sigColW - $sigSize) / 2;
        $pdf->Image($s['path'], $imgX, $baseY, $sigSize, 0, '', '', 'T', false, 300);
    }

    $lineY = $baseY + $sigSize + 3;
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(40, 40, 40);
    $pdf->Line($x + 4, $lineY, $x + $sigColW - 4, $lineY);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($x, $lineY + 3);
    $pdf->Cell($sigColW, 7, $s['name'], 0, 0, 'C');

    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetXY($x, $lineY + 11);
    $pdf->Cell($sigColW, 6, $s['title'], 0, 0, 'C');
}

// Institute footer
$pdf->SetY(-22);
$pdf->SetLineWidth(0.3);
$pdf->SetDrawColor(100, 100, 100);
$pdf->Line($PAGE_MARGIN_LEFT, $pdf->GetY(), 210 - $PAGE_MARGIN_RIGHT, $pdf->GetY());
$pdf->Ln(3);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(60, 60, 60);
$pdf->Cell(0, 5, 'Keystone School of Engineering, Near Handewadi Chowk, Urali Devachi, Shewalewadi, Pune - 412308', 0, 1, 'C');
$pdf->Cell(0, 5, 'www.keystoneschoolofengineering.com', 0, 1, 'C');

// ==================== OUTPUT ====================
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Event_Report_' . $checklist_id . '.pdf"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = $pdf->Output('Event_Report_' . $checklist_id . '.pdf', 'S');

foreach ($tempFiles as $tmp) {
    if ($tmp && file_exists($tmp)) @unlink($tmp);
}

echo $output;
exit();
