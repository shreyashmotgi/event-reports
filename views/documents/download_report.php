<?php
/**
 * Download Event Report as PDF using TCPDF
 * - Header image on EVERY page
 * - Layout mirrors view_eventreport.php exactly
 * - Guest details table, all text sections, photos with captions, signatures
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

    // 4. Guests (full details matching view page)
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

    // 8. HOD
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
    // If multiple departments exist, hide HOD (same logic as view page: $hod_name !== 'N/A')
    // Here: $hod_name is empty string when multi-dept → matches view page's check
    if (count($dept_ids) !== 1) {
        $hod_name = '';
        $hod_sign = '';
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

// ==================== TCPDF CUSTOM CLASS ====================
// Overrides Header/Footer so the institute header image appears on EVERY page

require_once __DIR__ . '/../../tcpdf/tcpdf.php';

class EventReportPDF extends TCPDF {

    public string $headerImagePath = '';
    public float  $headerImageHeight = 0; // actual rendered height in mm

    public function Header(): void {
        if (!empty($this->headerImagePath) && file_exists($this->headerImagePath)) {
            // Draw header image spanning full width with left/right margins
            $pageW  = $this->getPageWidth();
            $margin = $this->getMargins();
            $imgW   = $pageW - $margin['left'] - $margin['right'];

            // getimagesize to auto-calc proportional height
            [$iw, $ih] = @getimagesize($this->headerImagePath) ?: [1, 1];
            $imgH = ($ih / $iw) * $imgW;
            $this->headerImageHeight = $imgH;

            $this->Image(
                $this->headerImagePath,
                $margin['left'],
                8,          // 8 mm from top of page
                $imgW,
                $imgH,
                '',
                '',
                'T',
                false,
                300
            );

            // Push cursor below the header image
            $this->SetY(8 + $imgH + 4);
        } else {
            $this->headerImageHeight = 0;
            $this->SetY(15);
        }

        // Thin separator line below header
        $this->SetLineWidth(0.3);
        $this->SetDrawColor(180, 180, 180);
        $margins = $this->getMargins();
        $y = $this->GetY();
        $this->Line($margins['left'], $y, $this->getPageWidth() - $margins['right'], $y);
        $this->Ln(3);
    }

    public function Footer(): void {
        // Minimal footer – just a page number
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(130, 130, 130);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

// ==================== INIT PDF ====================
$pdf = new EventReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Event Management System');
$pdf->SetAuthor('Keystone School of Engineering');
$pdf->SetTitle('Event Report - ' . $programme_name);
$pdf->SetSubject('Event Report');

// Enable built-in header/footer
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// Margins: left, top, right
// Top margin is larger to accommodate the header image (will be adjusted after first page render)
$pdf->SetMargins(18, 55, 18); // top=55 is a safe default; header image adjusts dynamically
$pdf->SetAutoPageBreak(true, 18);

// Assign header image path
$headerPath = '';
if (!empty($header_image)) {
    $headerPath = absolutePath($header_image);
    if (!file_exists($headerPath)) $headerPath = '';
}
$pdf->headerImagePath = $headerPath;

// ==================== HELPER FUNCTIONS ====================

/**
 * Draw a label-value pair: bold label, normal value on same line (or wrapped)
 */
function pdfLabelValue(EventReportPDF $pdf, string $label, string $value): void {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(52, 7, $label . ':', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 7, $value, 0, 'L');
    $pdf->Ln(1);
}

/**
 * Draw a section heading + body text (mirrors view page bold title + paragraph)
 */
function pdfSection(EventReportPDF $pdf, string $title, string $html): void {
    $text = trim(strip_tags(html_entity_decode($html)));
    if ($text === '') return;

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, $title . ':', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->MultiCell(0, 6, $text, 0, 'L');
    $pdf->Ln(8);
}

// ==================== PAGE 1 – EVENT DETAILS ====================
$pdf->AddPage();

// ── Title ──────────────────────────────────────────────────────
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'EVENT REPORT', 0, 1, 'C');
$pdf->Ln(6);

// ── Basic Details ──────────────────────────────────────────────
pdfLabelValue($pdf, 'Name of Event', $programme_name);
pdfLabelValue($pdf, 'Day & Date',    $event_date);
pdfLabelValue($pdf, 'Time',          $event_time);
pdfLabelValue($pdf, 'Venue',         $event_venue);
$pdf->Ln(6);

// ── Guest Details Table (only if guests exist) ─────────────────
if (!empty($guests)) {
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 9, 'Guest Details', 0, 1, 'L');
    $pdf->Ln(2);

    // Table header
    $colWidths = [44, 48, 44, 38]; // Name | Company | Designation | Email
    $headers   = ['Name', 'Company / Organization', 'Designation', 'Email'];

    $pdf->SetFillColor(52, 73, 94);    // dark blue-grey
    $pdf->SetTextColor(255, 255, 255); // white text
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetLineWidth(0.3);

    foreach ($headers as $i => $h) {
        $pdf->Cell($colWidths[$i], 8, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Table rows
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->SetFont('helvetica', '', 10);

    $fill = false;
    foreach ($guests as $g) {
        $row = [
            $g['guest_name']  ?? '',
            $g['company_name'] ?? '—',
            $g['designation'] ?? '—',
            $g['guest_email'] ?? '—',
        ];

        // Calculate row height based on tallest cell
        $maxH = 7;
        foreach ($row as $ci => $cell) {
            $lines = $pdf->getNumLines($cell, $colWidths[$ci]);
            $cellH = $lines * 6;
            if ($cellH > $maxH) $maxH = $cellH;
        }

        foreach ($row as $ci => $cell) {
            $pdf->MultiCell($colWidths[$ci], $maxH, $cell, 1, 'L', $fill, 0);
        }
        $pdf->Ln();
        $fill = !$fill;
    }

    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(8);
}

// ── Text Sections ───────────────────────────────────────────────
$sections = [
    'Description'                          => $event['description']          ?? '',
    'Activities and Highlights'            => $event['activities']            ?? '',
    'Significance'                         => $event['significance']          ?? '',
    'Conclusion'                           => $event['conclusion']            ?? '',
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
    $pdf->Ln(6);

    $pageW    = $pdf->getPageWidth();
    $margins  = $pdf->getMargins();
    $usableW  = $pageW - $margins['left'] - $margins['right'];

    // Two-column layout (like a gallery)
    $colCount = 2;
    $imgW     = ($usableW - 8) / $colCount; // 8 mm gap between columns
    $imgH     = $imgW * 0.65;               // ~4:2.6 aspect ratio

    $col      = 0;
    $rowStartY = $pdf->GetY();

    foreach ($photos as $idx => $rel_path) {
        $img_path = absolutePath($rel_path);
        if (!file_exists($img_path)) continue;

        $caption = trim($captions[$idx] ?? '');

        // Estimate block height: image + caption lines
        $captionLines = $caption ? $pdf->getNumLines($caption, $imgW) : 0;
        $blockH       = $imgH + 5 + ($captionLines * 5) + 6; // image + gap + caption + bottom gap

        // Start new row if needed
        if ($col === 0) {
            $rowStartY = $pdf->GetY();
            // Check page break for a full row
            if ($rowStartY + $blockH > ($pdf->getPageHeight() - $pdf->getBreakMargin())) {
                $pdf->AddPage();
                $rowStartY = $pdf->GetY();
            }
        }

        // X position for this column
        $x = $margins['left'] + $col * ($imgW + 8);
        $y = $rowStartY;

        // Draw image
        $pdf->Image($img_path, $x, $y, $imgW, $imgH, '', '', 'T', false, 150);

        // Caption below image
        if ($caption) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetXY($x, $y + $imgH + 2);
            $pdf->MultiCell($imgW, 5, $caption, 0, 'C');
        }

        $col++;

        if ($col >= $colCount) {
            // Move cursor below this row
            $pdf->SetY($rowStartY + $blockH);
            $col = 0;
        }
    }

    // If last row had only one image, move cursor down
    if ($col !== 0) {
        $pdf->SetY($rowStartY + $imgH + 14);
    }
}

// ==================== SIGNATURES – DEDICATED FINAL PAGE ====================
$pdf->AddPage();
$pdf->SetAutoPageBreak(false);

// "Approved By" heading
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Approved By', 0, 1, 'C');
$pdf->Ln(6);

// ── Build list of signatories (matches view page: coordinator, HOD if applicable, principal)
$signatories = [];

$signatories[] = [
    'name'  => $coordinator_name,
    'title' => 'Coordinator',
    'sign'  => $coordinator_sign,
];

// HOD only if single department (same condition as view page)
if (!empty($hod_name)) {
    $signatories[] = [
        'name'  => $hod_name,
        'title' => 'HOD',
        'sign'  => $hod_sign,
    ];
}

$signatories[] = [
    'name'  => $principal_name,
    'title' => 'Principal',
    'sign'  => $principal_sign,
];

// ── Layout calculations ──────────────────────────────────────────
$pageW     = $pdf->getPageWidth();
$margins   = $pdf->getMargins();
$usableW   = $pageW - $margins['left'] - $margins['right'];
$count     = count($signatories);
$colW      = $usableW / $count;

$sigImgSize = 35; // mm – signature image width
$baseY      = $pdf->GetY() + 15;  // start Y for signature images

foreach ($signatories as $i => $s) {
    $x = $margins['left'] + $i * $colW;

    // Signature image (centered in column)
    $signPath = !empty($s['sign']) ? absolutePath($s['sign']) : '';
    if ($signPath && file_exists($signPath)) {
        $imgX = $x + ($colW - $sigImgSize) / 2;
        $pdf->Image($signPath, $imgX, $baseY, $sigImgSize, 0, '', '', 'T', false, 300);
    }

    // Signature line
    $lineY = $baseY + $sigImgSize + 2;
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(60, 60, 60);
    $pdf->Line($x + 6, $lineY, $x + $colW - 6, $lineY);

    // Name
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($x, $lineY + 3);
    $pdf->Cell($colW, 7, $s['name'], 0, 0, 'C');

    // Title
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetXY($x, $lineY + 11);
    $pdf->Cell($colW, 6, $s['title'], 0, 0, 'C');
}

// ── Institute Footer (bottom of last page) ──────────────────────
$pdf->SetY(-22);
$pdf->SetDrawColor(100, 100, 100);
$pdf->SetLineWidth(0.3);
$pdf->Line($margins['left'], $pdf->GetY(), $pageW - $margins['right'], $pdf->GetY());
$pdf->Ln(3);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(80, 80, 80);
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
