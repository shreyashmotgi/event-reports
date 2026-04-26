<?php
/**
 * Download Event Report as PDF using TCPDF
 * - Header image on EVERY page
 * - Cloudinary image URLs supported
 * - Layout mirrors view_eventreport.php exactly
 * - Photos: one per page, full-width, centred vertically
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

/**
 * Return full Cloudinary URL as-is, or build absolute URL for relative paths.
 */
function buildImagePath($image_value) {
    if (empty($image_value)) return '';
    if (filter_var($image_value, FILTER_VALIDATE_URL) && stripos($image_value, 'https://res.cloudinary.com') === 0) {
        return $image_value;
    }
    return 'https://event-reports-production.up.railway.app/' . ltrim($image_value, '/');
}

/**
 * Download a remote image URL into a temp file and return its local path.
 * TCPDF renders remote images much more reliably when given a local file.
 * Returns '' if the download fails.
 */
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

    // Guests — all 4 columns matching view page
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

    // HOD — only if single department (mirrors view page)
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
$HEADER_IMG_LEFT  = 15;   // mm from left edge
$HEADER_IMG_TOP   = 8;    // mm from top edge
$HEADER_IMG_WIDTH = 180;  // mm (210 - 15 - 15)

$PAGE_MARGIN_LEFT   = 18;
$PAGE_MARGIN_RIGHT  = 18;
$PAGE_MARGIN_BOTTOM = 18;

$usableW = 210 - $PAGE_MARGIN_LEFT - $PAGE_MARGIN_RIGHT; // 174 mm

$headerUrl     = buildImagePath($header_image);
$headerTmpPath = '';
$headerImgH    = 32; // safe default mm height

if (!empty($headerUrl)) {
    $headerTmpPath = fetchImageToTemp($headerUrl);
    if ($headerTmpPath && file_exists($headerTmpPath)) {
        $sz = @getimagesize($headerTmpPath);
        if ($sz && $sz[0] > 0) {
            $headerImgH = round(($sz[1] / $sz[0]) * $HEADER_IMG_WIDTH, 2);
        }
    }
}

// Top margin = image top offset + image height + separator line gap
$PAGE_MARGIN_TOP = $HEADER_IMG_TOP + $headerImgH + 5;

// Track all temp files to clean up at the end
$tempFiles = [];
if ($headerTmpPath) $tempFiles[] = $headerTmpPath;

// ==================== CUSTOM TCPDF CLASS ====================
require_once __DIR__ . '/../../tcpdf/tcpdf.php';

class EventReportPDF extends TCPDF {

    public string $hdrImgPath = '';
    public float  $hdrImgLeft = 15;
    public float  $hdrImgTop  = 8;
    public float  $hdrImgW    = 180;
    public float  $hdrImgH    = 32;

    /**
     * Called automatically by TCPDF at the top of EVERY page.
     */
    public function Header(): void {
        if (!empty($this->hdrImgPath) && file_exists($this->hdrImgPath)) {
            $this->Image(
                $this->hdrImgPath,
                $this->hdrImgLeft,
                $this->hdrImgTop,
                $this->hdrImgW,
                $this->hdrImgH,
                '',
                '',
                'T',
                false,
                300,
                '',
                false,
                false,
                0,
                false,
                false,
                false
            );
        }

        // Single thin separator line — drawn just below the header image
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

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'EVENT REPORT', 0, 1, 'C');
$pdf->Ln(6);

// Basic event info
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

        $pdf->SetFillColor($rowIdx % 2 === 0 ? 240 : 255, $rowIdx % 2 === 0 ? 244 : 255, $rowIdx % 2 === 0 ? 248 : 255);

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

// ==================== PHOTOS ====================
if (!empty($photos)) {

    // ── Single-column gallery — one image per page, full-width ──
    $imgW     = $usableW;        // full usable width ~174 mm
    $imgH     = $imgW * 0.65;    // ~113 mm tall (landscape feel)
    $capSpace = 14;              // space below image reserved for caption

    foreach ($photos as $idx => $photo_db_value) {
        $photo_url = buildImagePath($photo_db_value);
        if (empty($photo_url)) continue;

        $tmpPhoto = fetchImageToTemp($photo_url);
        if (!$tmpPhoto || !file_exists($tmpPhoto)) continue;
        $tempFiles[] = $tmpPhoto;

        // Each photo gets its own dedicated page
        $pdf->AddPage();

        $pageH       = $pdf->getPageHeight();
        $usablePageH = $pageH - $PAGE_MARGIN_TOP - $PAGE_MARGIN_BOTTOM;
        $blockH      = $imgH + $capSpace;

        // Centre the image block vertically within the usable area
        $centredY = $PAGE_MARGIN_TOP + ($usablePageH - $blockH) / 2;
        if ($centredY < $PAGE_MARGIN_TOP) $centredY = $PAGE_MARGIN_TOP;

        // Draw image — high DPI for maximum sharpness
        $pdf->Image($tmpPhoto, $PAGE_MARGIN_LEFT, $centredY, $imgW, $imgH, '', '', 'T', false, 200);

        // Caption centred beneath the image
        $caption = trim($captions[$idx] ?? '');
        if ($caption !== '') {
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->SetXY($PAGE_MARGIN_LEFT, $centredY + $imgH + 3);
            $pdf->MultiCell($imgW, 6, $caption, 0, 'C');
        }
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

// Institute footer — pinned to bottom of last page
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

// Clean up all temp files
foreach ($tempFiles as $tmp) {
    if ($tmp && file_exists($tmp)) @unlink($tmp);
}

echo $output;
exit();
