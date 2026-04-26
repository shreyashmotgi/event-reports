<?php
/**
 * Download Event Report as PDF using TCPDF
 * - Header image on EVERY page via TCPDF Header() override
 * - Layout mirrors view_eventreport.php exactly
 * - Guest table, all sections, 2-col photo gallery, signatures
 */

// -------------------- SESSION & DB --------------------
require_once __DIR__ . '/../layouts/header.php';

$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

// -------------------- INPUT VALIDATION --------------------
$checklist_id = trim($_GET['checklist_id'] ?? '');
if (empty($checklist_id)) {
    die("Invalid or missing Checklist ID");
}

// -------------------- PATH NORMALIZER --------------------
function normalizePath(string $path): string {
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

function absolutePath(string $rel): string {
    if (empty($rel)) return '';
    return $_SERVER['DOCUMENT_ROOT'] . '/' . normalizePath($rel);
}

// -------------------- FETCH ALL DATA --------------------
try {
    // 1. Event Report
    $stmt = $conn->prepare("SELECT * FROM event_report WHERE checklist_id = ?");
    $stmt->bind_param("i", $checklist_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    if (!$event) die("No event report found for this checklist.");

    // 2. Checklist
    $stmt = $conn->prepare("
        SELECT programme_name, programme_date, multi_day,
               programme_start_date, programme_end_date,
               department, created_by
        FROM checklists WHERE id = ?
    ");
    $stmt->bind_param("i", $checklist_id);
    $stmt->execute();
    $checklist = $stmt->get_result()->fetch_assoc();

    // 3. Notice
    $stmt = $conn->prepare("SELECT event_time, event_venue FROM notice WHERE checklist_id = ?");
    $stmt->bind_param("i", $checklist_id);
    $stmt->execute();
    $notice = $stmt->get_result()->fetch_assoc();

    // 4. Guests — full 4 columns matching view page
    $stmt = $conn->prepare("
        SELECT guest_name, company_name, designation, guest_email
        FROM checklist_guests WHERE checklist_id = ?
    ");
    $stmt->bind_param("i", $checklist_id);
    $stmt->execute();
    $guests_result = $stmt->get_result();
    $guests = [];
    while ($g = $guests_result->fetch_assoc()) {
        $guests[] = $g;
    }

    // 5. Department & Header Image
    $dept_ids = json_decode($checklist['department'] ?? '[]', true) ?? [];
    $header_image = '';
    $dept_id = null;

    $stmt = $conn->prepare("SELECT image FROM default_header LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $header_image = $row['image'] ?? '';

    if (count($dept_ids) === 1) {
        $dept_id = (int)$dept_ids[0];
        $stmt = $conn->prepare("SELECT header_image FROM departments WHERE id = ?");
        $stmt->bind_param("i", $dept_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['header_image'])) {
            $header_image = $row['header_image'];
        }
    }

    // 6. Format Event Details
    $programme_name = $checklist['programme_name'] ?? 'Event';

    if (!empty($checklist['multi_day'])) {
        $event_date = date('d-m-Y', strtotime($checklist['programme_start_date']))
                    . ' to '
                    . date('d-m-Y', strtotime($checklist['programme_end_date']));
    } else {
        $event_date = date('d-m-Y', strtotime($checklist['programme_date'] ?? 'now'));
    }

    $event_time  = !empty($notice['event_time'])  ? date('h:i A', strtotime($notice['event_time'])) : 'N/A';
    $event_venue = $notice['event_venue'] ?? 'N/A';

    $photos   = json_decode($event['photos']   ?? '[]', true) ?? [];
    $captions = json_decode($event['captions'] ?? '[]', true) ?? [];

    // 7. Coordinator
    $stmt = $conn->prepare("SELECT username, sign_image FROM users WHERE id = ?");
    $stmt->bind_param("i", $checklist['created_by']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $coordinator_name = $user['username']   ?? 'Coordinator';
    $coordinator_sign = $user['sign_image'] ?? '';

    // 8. HOD — only for single department (mirrors view page logic)
    $hod_name = '';
    $hod_sign = '';
    if (!empty($dept_id)) {
        $stmt = $conn->prepare("SELECT username, sign_image FROM users WHERE role = 'hod' AND department_id = ? LIMIT 1");
        $stmt->bind_param("i", $dept_id);
        $stmt->execute();
        $hod = $stmt->get_result()->fetch_assoc();
        if ($hod) {
            $hod_name = $hod['username'];
            $hod_sign = $hod['sign_image'] ?? '';
        }
    }

    // 9. Principal
    $stmt = $conn->prepare("SELECT username, sign_image FROM users WHERE role = 'principal' LIMIT 1");
    $stmt->execute();
    $principal = $stmt->get_result()->fetch_assoc();
    $principal_name = $principal['username']   ?? 'Principal';
    $principal_sign = $principal['sign_image'] ?? '';

} catch (Exception $e) {
    http_response_code(500);
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// ==================== TCPDF SETUP ====================
require_once __DIR__ . '/../../tcpdf/tcpdf.php';

// ------------------------------------------------------------------
// Pre-calculate header image dimensions so the top margin is correct
// on EVERY page before any page is added.
// A4 = 210mm wide. We use 15mm left/right for the header image.
// ------------------------------------------------------------------
$HEADER_IMG_LEFT   = 15;   // mm from left edge
$HEADER_IMG_TOP    = 8;    // mm from top edge
$HEADER_IMG_WIDTH  = 180;  // 210 - 15 - 15

$PAGE_MARGIN_LEFT   = 18;
$PAGE_MARGIN_RIGHT  = 18;
$PAGE_MARGIN_BOTTOM = 18;

$usableW = 210 - $PAGE_MARGIN_LEFT - $PAGE_MARGIN_RIGHT; // 174 mm

// Resolve header image path and measure its height
$headerAbsPath = '';
$headerImgH    = 32; // default fallback height in mm

if (!empty($header_image)) {
    $p = absolutePath($header_image);
    if (file_exists($p)) {
        $headerAbsPath = $p;
        $sz = @getimagesize($p);
        if ($sz && $sz[0] > 0) {
            $headerImgH = round(($sz[1] / $sz[0]) * $HEADER_IMG_WIDTH, 2);
        }
    }
}

// Top margin: image top offset + image height + separator gap + small breathing room
$PAGE_MARGIN_TOP = $HEADER_IMG_TOP + $headerImgH + 7;

// ------------------------------------------------------------------
// Custom TCPDF class — Header() is called automatically on every page
// ------------------------------------------------------------------
class EventReportPDF extends TCPDF {

    public string $hdrImgPath  = '';
    public float  $hdrImgLeft  = 15;
    public float  $hdrImgTop   = 8;
    public float  $hdrImgW     = 180;
    public float  $hdrImgH     = 32;

    /**
     * Called by TCPDF at the top of every page automatically.
     * We draw the header image here.
     */
    public function Header(): void {
        if (!empty($this->hdrImgPath) && file_exists($this->hdrImgPath)) {
            $this->Image(
                $this->hdrImgPath,
                $this->hdrImgLeft,
                $this->hdrImgTop,
                $this->hdrImgW,
                $this->hdrImgH,
                '',    // auto-detect format from extension
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

        // Thin grey separator line just below the header image
        $lineY = $this->hdrImgTop + $this->hdrImgH + 2;
        $this->SetLineWidth(0.4);
        $this->SetDrawColor(160, 160, 160);
        $this->Line($this->hdrImgLeft, $lineY, $this->getPageWidth() - $this->hdrImgLeft, $lineY);

        // Reset draw color to black
        $this->SetDrawColor(0, 0, 0);
    }

    public function Footer(): void {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

// ------------------------------------------------------------------
// Instantiate and configure PDF
// ------------------------------------------------------------------
$pdf = new EventReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Event Management System');
$pdf->SetAuthor('Keystone School of Engineering');
$pdf->SetTitle('Event Report - ' . $programme_name);
$pdf->SetSubject('Event Report');

// These TWO lines are critical — without them Header() never fires
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// SetHeaderMargin(0) means TCPDF won't add extra space; our $PAGE_MARGIN_TOP already accounts for it
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);

// Content margins — top is large enough for the header on every page
$pdf->SetMargins($PAGE_MARGIN_LEFT, $PAGE_MARGIN_TOP, $PAGE_MARGIN_RIGHT);
$pdf->SetAutoPageBreak(true, $PAGE_MARGIN_BOTTOM);

// Pass header image info into the class instance
$pdf->hdrImgPath = $headerAbsPath;
$pdf->hdrImgLeft = $HEADER_IMG_LEFT;
$pdf->hdrImgTop  = $HEADER_IMG_TOP;
$pdf->hdrImgW    = $HEADER_IMG_WIDTH;
$pdf->hdrImgH    = $headerImgH;

// ==================== HELPER FUNCTIONS ====================

function pdfLabelValue(EventReportPDF $pdf, string $label, string $value): void {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(50, 7, $label . ':', 0, 0, 'L');
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
    $pdf->MultiCell(0, 6, $text, 0, 'L');
    $pdf->Ln(6);
}

// ==================== PAGE 1 — EVENT DETAILS ====================
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'EVENT REPORT', 0, 1, 'C');
$pdf->Ln(4);

// Basic event info
pdfLabelValue($pdf, 'Name of Event', $programme_name);
pdfLabelValue($pdf, 'Day & Date',    $event_date);
pdfLabelValue($pdf, 'Time',          $event_time);
pdfLabelValue($pdf, 'Venue',         $event_venue);
$pdf->Ln(5);

// ── Guest Details Table ─────────────────────────────────────────
if (!empty($guests)) {
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 9, 'Guest Details', 0, 1, 'L');
    $pdf->Ln(1);

    // Columns sum to $usableW = 174 mm
    $colW    = [42, 48, 42, 42];
    $headers = ['Name', 'Company / Organization', 'Designation', 'Email'];

    // Header row — dark background, white text
    $pdf->SetFillColor(45, 62, 80);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetLineWidth(0.3);
    foreach ($headers as $i => $h) {
        $pdf->Cell($colW[$i], 8, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Data rows — alternating shading
    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('helvetica', '', 10);

    foreach ($guests as $rowIdx => $g) {
        $row = [
            $g['guest_name']   ?? '',
            $g['company_name'] ?? '—',
            $g['designation']  ?? '—',
            $g['guest_email']  ?? '—',
        ];

        // Calculate row height based on tallest cell
        $rowH = 7;
        foreach ($row as $ci => $cellText) {
            $lines = $pdf->getNumLines($cellText, $colW[$ci] - 2);
            $h     = max(7, $lines * 6);
            if ($h > $rowH) $rowH = $h;
        }

        // Page break before drawing row if needed
        if ($pdf->GetY() + $rowH > ($pdf->getPageHeight() - $PAGE_MARGIN_BOTTOM)) {
            $pdf->AddPage();
        }

        // Alternating row color
        if ($rowIdx % 2 === 0) {
            $pdf->SetFillColor(240, 244, 248);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        // Draw each cell with fixed row height so they align
        foreach ($row as $ci => $cellText) {
            $pdf->MultiCell($colW[$ci], $rowH, $cellText, 1, 'L', true, 0, '', '', true, 0, false, true, $rowH, 'M');
        }
        $pdf->Ln();
    }

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Ln(6);
}

// ── Text Sections ───────────────────────────────────────────────
$sections = [
    'Description'                          => $event['description']           ?? '',
    'Activities and Highlights'            => $event['activities']             ?? '',
    'Significance'                         => $event['significance']           ?? '',
    'Conclusion'                           => $event['conclusion']             ?? '',
    "Faculties' Responses & Participation" => $event['faculties_participation'] ?? '',
];
foreach ($sections as $title => $html) {
    pdfSection($pdf, $title, $html);
}

// ==================== PHOTOS PAGE ====================
if (!empty($photos)) {
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Photos', 0, 1, 'C');
    $pdf->Ln(4);

    $colCount  = 2;
    $gap       = 6;                                           // mm between columns
    $imgW      = ($usableW - $gap) / $colCount;              // ~84 mm
    $imgH      = $imgW * 0.72;                               // proportional height
    $capSpace  = 12;                                          // mm reserved below image for caption
    $blockH    = $imgH + $capSpace;

    $col       = 0;
    $rowStartY = $pdf->GetY();

    foreach ($photos as $idx => $rel_path) {
        $img_path = absolutePath($rel_path);
        if (!file_exists($img_path)) continue;

        // At start of each row: check if the block fits on this page
        if ($col === 0) {
            $rowStartY = $pdf->GetY();
            if ($rowStartY + $blockH > ($pdf->getPageHeight() - $PAGE_MARGIN_BOTTOM)) {
                $pdf->AddPage();
                $rowStartY = $pdf->GetY();
            }
        }

        $x = $PAGE_MARGIN_LEFT + $col * ($imgW + $gap);
        $y = $rowStartY;

        // Draw image
        $pdf->Image($img_path, $x, $y, $imgW, $imgH, '', '', 'T', false, 150);

        // Caption
        $caption = trim($captions[$idx] ?? '');
        if ($caption !== '') {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetXY($x, $y + $imgH + 1);
            $pdf->MultiCell($imgW, 5, $caption, 0, 'C');
        }

        $col++;
        if ($col >= $colCount) {
            // Move cursor below finished row
            $pdf->SetXY($PAGE_MARGIN_LEFT, $rowStartY + $blockH);
            $col = 0;
        }
    }

    // If last row only had one image, move cursor down
    if ($col !== 0) {
        $pdf->SetXY($PAGE_MARGIN_LEFT, $rowStartY + $blockH);
    }
}

// ==================== SIGNATURES — FINAL PAGE ====================
$pdf->AddPage();
$pdf->SetAutoPageBreak(false);

$pdf->Ln(8);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Approved By', 0, 1, 'C');
$pdf->Ln(10);

// Build signatories — HOD only if single department (matches view page)
$signatories = [];
$signatories[] = ['name' => $coordinator_name, 'title' => 'Coordinator', 'sign' => $coordinator_sign];
if (!empty($hod_name)) {
    $signatories[] = ['name' => $hod_name, 'title' => 'HOD', 'sign' => $hod_sign];
}
$signatories[] = ['name' => $principal_name, 'title' => 'Principal', 'sign' => $principal_sign];

$count   = count($signatories);
$sigColW = $usableW / $count;
$sigSize = 35;   // signature image width mm
$baseY   = $pdf->GetY();

foreach ($signatories as $i => $s) {
    $x = $PAGE_MARGIN_LEFT + $i * $sigColW;

    // Signature image — centered horizontally within column
    $signPath = !empty($s['sign']) ? absolutePath($s['sign']) : '';
    if ($signPath && file_exists($signPath)) {
        $imgX = $x + ($sigColW - $sigSize) / 2;
        $pdf->Image($signPath, $imgX, $baseY, $sigSize, 0, '', '', 'T', false, 300);
    }

    // Underline below signature
    $lineY = $baseY + $sigSize + 3;
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(40, 40, 40);
    $pdf->Line($x + 4, $lineY, $x + $sigColW - 4, $lineY);

    // Name
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($x, $lineY + 3);
    $pdf->Cell($sigColW, 7, $s['name'], 0, 0, 'C');

    // Role title
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

$pdf->Output('Event_Report_' . $checklist_id . '.pdf', 'D');
exit();
