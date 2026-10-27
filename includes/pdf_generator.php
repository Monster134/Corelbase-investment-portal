<?php
/**
 * CORELBASE PRIVATE LIMITED — PURE PHP NATIVE PDF 1.4 GENERATOR ENGINE
 * -----------------------------------------------------------------------------
 * Generates multi-object, vector-styled PDF documents with embedded JPEG assets
 * (Company Logo, Official Company Seal, Director 1 & 2 Signatures, Verification QR)
 * without requiring external binaries or Composer libraries.
 *
 * Supports:
 * 1. Official Digital Share Certificate (`generateShareCertificatePDF`) — Landscape A4
 * 2. Official Application Acknowledgement (`generateAcknowledgementPDF`) — Portrait A4
 * 3. Administrative Investment & Application Summary Report (`generateAdminReportPDF`)
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/qr_generator.php';

class CorelbasePDF
{
    private float $pageW;
    private float $pageH;
    private array $pages = [];
    private int $currentPage = -1;
    private array $images = [];

    public function __construct(string $orientation = 'P')
    {
        if (strtoupper($orientation) === 'L') {
            $this->pageW = 841.89; // A4 Landscape width in pt
            $this->pageH = 595.28; // A4 Landscape height in pt
        } else {
            $this->pageW = 595.28; // A4 Portrait width in pt
            $this->pageH = 841.89; // A4 Portrait height in pt
        }
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->currentPage++;
        $this->pages[$this->currentPage] = '';
    }

    private function writeCmd(string $cmd): void
    {
        $this->pages[$this->currentPage] .= $cmd . "\n";
    }

    private function pdfY(float $topY): float
    {
        return round($this->pageH - $topY, 2);
    }

    private function escapeText(string $text): string
    {
        // Replace ₹ with INR/Rs. for standard Type1 Helvetica/Times PDF compatibility
        $text = str_replace(['₹', '—', '–', '•', '✓'], ['INR ', '-', '-', '*', '[OK]'], $text);
        $text = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text) ?: $text;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->writeCmd(sprintf('%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255));
    }

    public function setStrokeColor(int $r, int $g, int $b): void
    {
        $this->writeCmd(sprintf('%.3f %.3f %.3f RG', $r / 255, $g / 255, $b / 255));
    }

    public function setLineWidth(float $width): void
    {
        $this->writeCmd(sprintf('%.2f w', $width));
    }

    public function rect(float $x, float $topY, float $w, float $h, string $style = 'S'): void
    {
        $y = $this->pdfY($topY + $h);
        $op = match (strtoupper($style)) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };
        $this->writeCmd(sprintf('%.2f %.2f %.2f %.2f re %s', $x, $y, $w, $h, $op));
    }

    public function line(float $x1, float $topY1, float $x2, float $topY2): void
    {
        $this->writeCmd(sprintf('%.2f %.2f m %.2f %.2f l S', $x1, $this->pdfY($topY1), $x2, $this->pdfY($topY2)));
    }

    /**
     * Draw text at ($x, $topY).
     * Fonts: F1 = Helvetica, F2 = Helvetica-Bold, F3 = Times-Roman, F4 = Times-Bold, F5 = Courier
     */
    public function text(float $x, float $topY, string $text, string $font = 'F1', float $size = 10, string $align = 'L', float $boxWidth = 0): void
    {
        $clean = str_replace(['₹', '—', '–', '•', '✓'], ['INR ', '-', '-', '*', '[OK]'], $text);
        if ($boxWidth > 0 && $align !== 'L') {
            $approxCharWidth = $size * (in_array($font, ['F2', 'F4'], true) ? 0.54 : 0.50);
            $textW = strlen($clean) * $approxCharWidth;
            if ($align === 'C') {
                $x += max(0, ($boxWidth - $textW) / 2);
            } elseif ($align === 'R') {
                $x += max(0, $boxWidth - $textW);
            }
        }
        $escaped = $this->escapeText($text);
        $y = $this->pdfY($topY);
        $this->writeCmd(sprintf('BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET', $font, $size, $x, $y, $escaped));
    }

    /**
     * Embed a JPEG or PNG image into the PDF at ($x, $topY, $w, $h).
     */
    public function image(string $filePath, float $x, float $topY, float $w, float $h): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        // Convert PNG/WEBP to JPEG in memory/temp if needed for PDF DCTDecode
        $jpegPath = $this->ensureJpegAsset($filePath);
        if (!$jpegPath || !file_exists($jpegPath)) {
            return;
        }

        if (!isset($this->images[$jpegPath])) {
            $info = @getimagesize($jpegPath);
            if (!$info || $info[2] !== IMAGETYPE_JPEG) {
                return;
            }
            $data = @file_get_contents($jpegPath);
            if ($data === false) {
                return;
            }
            $idx = count($this->images) + 1;
            $this->images[$jpegPath] = [
                'name'     => 'Im' . $idx,
                'width'    => $info[0],
                'height'   => $info[1],
                'channels' => ($info['channels'] ?? 3),
                'bpc'      => ($info['bits'] ?? 8),
                'data'     => $data,
            ];
        }

        $img = $this->images[$jpegPath];
        $y = $this->pdfY($topY + $h);
        $this->writeCmd(sprintf('q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q', $w, $h, $x, $y, $img['name']));
    }

    private function ensureJpegAsset(string $filePath): ?string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return $filePath;
        }
        // Check if pre-converted .jpg exists alongside .png
        $siblingJpg = preg_replace('/\.(png|webp)$/i', '.jpg', $filePath);
        if ($siblingJpg && file_exists($siblingJpg)) {
            return $siblingJpg;
        }
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $raw = @file_get_contents($filePath);
        if (!$raw) {
            return null;
        }
        $src = @imagecreatefromstring($raw);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $w, $h, $white);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        $tmpJpg = sys_get_temp_dir() . '/cb_asset_' . md5($filePath . filemtime($filePath)) . '.jpg';
        imagejpeg($dst, $tmpJpg, 92);
        imagedestroy($src);
        imagedestroy($dst);
        return $tmpJpg;
    }

    /**
     * Compile and return raw PDF 1.4 binary string.
     */
    public function output(): string
    {
        $objects = [];
        $addObj = function (string $content) use (&$objects): int {
            $id = count($objects) + 1;
            $objects[$id] = $content;
            return $id;
        };

        // 1: Catalog placeholder, 2: Pages placeholder
        $catalogId = $addObj('');
        $pagesId = $addObj('');

        // 3..7: Standard Type1 Fonts
        $f1 = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $f3 = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>');
        $f4 = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>');
        $f5 = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>');

        // Image XObjects
        $xObjRefs = '';
        foreach ($this->images as $img) {
            $cs = ($img['channels'] === 1) ? '/DeviceGray' : '/DeviceRGB';
            $len = strlen($img['data']);
            $imgObjId = $addObj(
                "<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} " .
                "/ColorSpace {$cs} /BitsPerComponent {$img['bpc']} /Filter /DCTDecode /Length {$len} >>\n" .
                "stream\n" . $img['data'] . "\nendstream"
            );
            $xObjRefs .= "/{$img['name']} {$imgObjId} 0 R ";
        }

        $resources = "<< /Font << /F1 {$f1} 0 R /F2 {$f2} 0 R /F3 {$f3} 0 R /F4 {$f4} 0 R /F5 {$f5} 0 R >> /XObject << {$xObjRefs} >> >>";

        $pageIds = [];
        foreach ($this->pages as $stream) {
            $len = strlen($stream);
            $contentId = $addObj("<< /Length {$len} >>\nstream\n{$stream}endstream");
            $pageIds[] = $addObj(sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Resources %s /Contents %d 0 R >>",
                $pagesId,
                $this->pageW,
                $this->pageH,
                $resources,
                $contentId
            ));
        }

        $kids = implode(' ', array_map(fn($id) => "{$id} 0 R", $pageIds));
        $count = count($pageIds);
        $objects[$pagesId] = "<< /Type /Pages /Kids [{$kids}] /Count {$count} >>";
        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $totalObjs = count($objects) + 1;
        $pdf .= "xref\n0 {$totalObjs}\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$totalObjs} /Root {$catalogId} 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
        return $pdf;
    }
}

/**
 * High-level Document Generator for Corelbase Portal
 */
class PDFGenerator
{
    /**
     * Generate Official Digital Share Certificate PDF (Section 18).
     * Saves PDF to /certificates/CB-SC-2026-XXXXXX.pdf and returns relative path.
     */
    public static function generateShareCertificatePDF(array $certData, ?array $settings = null): string
    {
        $settings = $settings ?? getAllSettings(null, false);
        $pdf = new CorelbasePDF('L'); // Landscape A4 (841.89 x 595.28)

        // Outer Deep Charcoal / Black Frame + Red Double Border
        $pdf->setFillColor(12, 13, 18);
        $pdf->rect(0, 0, 841.89, 595.28, 'F');

        // Inner Cream/White Luxury Certificate Canvas
        $pdf->setFillColor(252, 252, 253);
        $pdf->rect(22, 22, 797.89, 551.28, 'F');

        // Outer Red Border
        $pdf->setStrokeColor(210, 25, 55);
        $pdf->setLineWidth(2.4);
        $pdf->rect(28, 28, 785.89, 539.28, 'S');

        // Inner Charcoal Hairline Border
        $pdf->setStrokeColor(25, 28, 38);
        $pdf->setLineWidth(0.8);
        $pdf->rect(34, 34, 773.89, 527.28, 'S');

        // Top Red Header Ribbon Accent
        $pdf->setFillColor(15, 17, 24);
        $pdf->rect(34, 34, 773.89, 86, 'F');
        $pdf->setFillColor(220, 38, 38);
        $pdf->rect(34, 120, 773.89, 4, 'F');

        // Embed Company Logo
        $logoRel = $settings['company_logo'] ?: 'assets/brand/logo.jpg';
        $logoPath = CB_ROOT_PATH . '/' . preg_replace('/\.png$/i', '.jpg', $logoRel);
        if (!file_exists($logoPath)) {
            $logoPath = CB_ROOT_PATH . '/' . $logoRel;
        }
        $pdf->image($logoPath, 52, 45, 62, 62);

        // Company Name & Statutory Details in Header
        $companyName = strtoupper($settings['company_name'] ?? 'CORELBASE PRIVATE LIMITED');
        $cin = $settings['company_cin'] ?? 'U62099UP2026PTC250888';
        $regOffice = $settings['company_registered_office'] ?? 'HOUSE NO 173, BHARROH, GORAKHPUR, UTTAR PRADESH - 273408';

        $pdf->setFillColor(255, 255, 255);
        $pdf->text(0, 62, $companyName, 'F2', 20, 'C', 841.89);
        $pdf->setFillColor(244, 63, 94);
        $pdf->text(0, 81, "CIN: {$cin}  |  GSTIN: " . ($settings['company_gstin'] ?? '09AAOCC1033C1Z1'), 'F2', 9.5, 'C', 841.89);
        $pdf->setFillColor(200, 205, 215);
        $pdf->text(0, 96, "Registered Office: {$regOffice}", 'F1', 8, 'C', 841.89);

        // Certificate Status Badge (Top Right)
        $status = strtoupper((string)($certData['status'] ?? 'VALID'));
        $pdf->setFillColor(220, 38, 38);
        $pdf->rect(695, 52, 92, 24, 'F');
        $pdf->setFillColor(255, 255, 255);
        $pdf->text(695, 68, "STATUS: {$status}", 'F2', 8.5, 'C', 92);

        // Title Banner
        $pdf->setFillColor(18, 20, 28);
        $pdf->text(0, 152, 'SHARE ALLOTMENT CERTIFICATE', 'F4', 19, 'C', 841.89);
        $pdf->setFillColor(100, 108, 125);
        $pdf->text(0, 169, '(Issued pursuant to Board Approval & Recorded Share Allotment)', 'F1', 9, 'C', 841.89);

        // Metadata Strip (Certificate No | Folio No | Investor ID | Allotment Date)
        $pdf->setFillColor(244, 245, 248);
        $pdf->setStrokeColor(220, 224, 232);
        $pdf->setLineWidth(0.7);
        $pdf->rect(54, 182, 733.89, 34, 'FD');

        $certNo = (string)$certData['certificate_number'];
        $folioNo = (string)$certData['folio_number'];
        $investorId = (string)$certData['investor_id'];
        $allotmentDate = date('d M Y', strtotime((string)$certData['allotment_date']));

        $pdf->setFillColor(110, 115, 130);
        $pdf->text(68, 195, 'CERTIFICATE NUMBER', 'F2', 7.5);
        $pdf->text(260, 195, 'REGISTER FOLIO NO.', 'F2', 7.5);
        $pdf->text(450, 195, 'INVESTOR ID', 'F2', 7.5);
        $pdf->text(630, 195, 'ALLOTMENT DATE', 'F2', 7.5);

        $pdf->setFillColor(15, 18, 26);
        $pdf->text(68, 209, $certNo, 'F2', 10.5);
        $pdf->text(260, 209, $folioNo, 'F2', 10.5);
        $pdf->text(450, 209, $investorId, 'F2', 10.5);
        $pdf->text(630, 209, $allotmentDate, 'F2', 10.5);

        // Main Certification Body
        $investorName = strtoupper((string)$certData['investor_name']);
        $pdf->setFillColor(70, 75, 90);
        $pdf->text(0, 240, 'THIS IS TO CERTIFY THAT THE PERSON NAMED HEREUNDER IS THE REGISTERED HOLDER OF SHARES IN CORELBASE PRIVATE LIMITED:', 'F2', 8.5, 'C', 841.89);

        $pdf->setFillColor(190, 24, 45);
        $pdf->text(0, 266, $investorName, 'F4', 20, 'C', 841.89);
        $pdf->setStrokeColor(220, 38, 38);
        $pdf->setLineWidth(0.8);
        $pdf->line(220, 274, 621.89, 274);

        // Share Allocation Specification Grid (2 Rows x 4 Columns)
        $gridX = 54;
        $gridY = 290;
        $gridW = 733.89;
        $colW = $gridW / 4;

        $pdf->setFillColor(248, 249, 251);
        $pdf->setStrokeColor(215, 220, 228);
        $pdf->rect($gridX, $gridY, $gridW, 88, 'FD');
        $pdf->line($gridX, $gridY + 44, $gridX + $gridW, $gridY + 44);
        for ($i = 1; $i <= 3; $i++) {
            $pdf->line($gridX + ($colW * $i), $gridY, $gridX + ($colW * $i), $gridY + 88);
        }

        $sharesCount = number_format((int)$certData['shares_count']);
        $shareClass = (string)($certData['share_class'] ?? 'Equity Shares');
        $faceVal = 'INR ' . number_format((float)$certData['face_value'], 2);
        $paidUpVal = 'INR ' . number_format((float)$certData['paid_up_value'], 2);
        $issuePrice = 'INR ' . number_format((float)$certData['issue_price'], 2);
        $premium = 'INR ' . number_format(max(0, (float)$certData['issue_price'] - (float)$certData['face_value']), 2);
        $ownershipPct = rtrim(rtrim(number_format((float)$certData['ownership_percentage'], 6), '0'), '.') . '%';
        $totalAmount = 'INR ' . number_format((int)$certData['shares_count'] * (float)$certData['issue_price'], 2);

        $cells = [
            // Row 1
            [0, 0, 'NUMBER OF SHARES ALLOTTED', "{$sharesCount} Shares"],
            [1, 0, 'CLASS OF SHARES', $shareClass],
            [2, 0, 'FACE VALUE PER SHARE', $faceVal],
            [3, 0, 'PAID-UP VALUE PER SHARE', $paidUpVal],
            // Row 2
            [0, 1, 'ISSUE PRICE PER SHARE', $issuePrice],
            [1, 1, 'PREMIUM PER SHARE', $premium],
            [2, 1, 'TOTAL ALLOTMENT CONSIDERATION', $totalAmount],
            [3, 1, 'INDICATIVE OWNERSHIP %', $ownershipPct],
        ];

        foreach ($cells as [$col, $row, $label, $val]) {
            $cx = $gridX + ($col * $colW) + 12;
            $cy = $gridY + ($row * 44);
            $pdf->setFillColor(110, 115, 130);
            $pdf->text($cx, $cy + 16, $label, 'F2', 7.2);
            $pdf->setFillColor(15, 18, 26);
            $pdf->text($cx, $cy + 33, $val, 'F2', 11);
        }

        // Bottom Section: QR Verification (Left), Official Seal (Center-Left), Director 1 & 2 Signatures (Right)
        $qrUrl = (string)$certData['qr_verification_url'];
        $tmpQrJpg = sys_get_temp_dir() . '/cb_qr_' . preg_replace('/[^A-Za-z0-9]/', '_', $certNo) . '.jpg';
        QRGenerator::saveJpeg($qrUrl, $tmpQrJpg, 280, 2);
        if (file_exists($tmpQrJpg)) {
            $pdf->image($tmpQrJpg, 58, 396, 86, 86);
        }
        $pdf->setFillColor(60, 65, 80);
        $pdf->text(58, 493, 'SCAN TO VERIFY', 'F2', 7.5, 'C', 86);
        $pdf->text(58, 503, $certNo, 'F5', 7, 'C', 86);

        // Company Seal
        $sealRel = $settings['company_seal'] ?: 'assets/brand/seal.jpg';
        $sealPath = CB_ROOT_PATH . '/' . preg_replace('/\.png$/i', '.jpg', $sealRel);
        if (!file_exists($sealPath)) {
            $sealPath = CB_ROOT_PATH . '/' . $sealRel;
        }
        $pdf->image($sealPath, 195, 392, 92, 92);
        $pdf->setFillColor(90, 95, 110);
        $pdf->text(195, 496, 'COMMON SEAL', 'F2', 7.5, 'C', 92);

        // Director 1 Signature (Anurag Singh)
        $sig1Rel = $settings['director1_signature'] ?: 'assets/brand/director1_sign.jpg';
        $sig1Path = CB_ROOT_PATH . '/' . preg_replace('/\.png$/i', '.jpg', $sig1Rel);
        if (!file_exists($sig1Path)) {
            $sig1Path = CB_ROOT_PATH . '/' . $sig1Rel;
        }
        $pdf->image($sig1Path, 370, 405, 135, 52);
        $pdf->setStrokeColor(60, 65, 80);
        $pdf->setLineWidth(0.7);
        $pdf->line(355, 465, 520, 465);
        $pdf->setFillColor(15, 18, 26);
        $pdf->text(355, 479, strtoupper($settings['director1_name'] ?? 'ANURAG SINGH'), 'F2', 9.5, 'C', 165);
        $pdf->setFillColor(100, 105, 120);
        $pdf->text(355, 491, $settings['director1_designation'] ?? 'Director / Authorized Signatory', 'F1', 8, 'C', 165);

        // Director 2 Signature (Manabendra Singha)
        $sig2Rel = $settings['director2_signature'] ?: 'assets/brand/director2_sign.jpg';
        $sig2Path = CB_ROOT_PATH . '/' . preg_replace('/\.png$/i', '.jpg', $sig2Rel);
        if (!file_exists($sig2Path)) {
            $sig2Path = CB_ROOT_PATH . '/' . $sig2Rel;
        }
        $pdf->image($sig2Path, 585, 405, 145, 52);
        $pdf->line(575, 465, 745, 465);
        $pdf->setFillColor(15, 18, 26);
        $pdf->text(575, 479, strtoupper($settings['director2_name'] ?? 'MANABENDRA SINGHA'), 'F2', 9.5, 'C', 170);
        $pdf->setFillColor(100, 105, 120);
        $pdf->text(575, 491, $settings['director2_designation'] ?? 'Director / Authorized Signatory', 'F1', 8, 'C', 170);

        // Statutory Footer Notice
        $pdf->setFillColor(242, 244, 247);
        $pdf->rect(36, 514, 769.89, 44, 'F');
        $pdf->setFillColor(90, 95, 110);
        $pdf->text(0, 528, 'IMPORTANT NOTICE: This digital certificate represents the administrative record of share allotment approved by Corelbase Private Limited.', 'F2', 7.2, 'C', 841.89);
        $pdf->text(0, 539, 'This PDF alone does not constitute a negotiable public market instrument. Verify authenticity online at: ' . $qrUrl, 'F1', 7.0, 'C', 841.89);
        $pdf->text(0, 550, 'Corelbase Private Limited is a Private Limited Company registered under the Companies Act, 2013. Shares are subject to Articles of Association.', 'F1', 6.8, 'C', 841.89);

        if (!is_dir(CB_CERTIFICATES_PATH)) {
            @mkdir(CB_CERTIFICATES_PATH, 0750, true);
        }
        $relativePath = 'certificates/' . $certNo . '.pdf';
        $fullPath = CB_ROOT_PATH . '/' . $relativePath;
        file_put_contents($fullPath, $pdf->output());
        return $relativePath;
    }

    /**
     * Generate Application Acknowledgement PDF (Section 57).
     * Clearly states: "Application acknowledgement — not proof of share allotment."
     */
    public static function generateAcknowledgementPDF(array $appData, ?array $settings = null): string
    {
        $settings = $settings ?? getAllSettings(null, false);
        $pdf = new CorelbasePDF('P'); // Portrait A4 (595.28 x 841.89)

        // Top Header Band
        $pdf->setFillColor(12, 14, 20);
        $pdf->rect(0, 0, 595.28, 115, 'F');
        $pdf->setFillColor(220, 38, 38);
        $pdf->rect(0, 115, 595.28, 4, 'F');

        $logoRel = $settings['company_logo'] ?: 'assets/brand/logo.jpg';
        $logoPath = CB_ROOT_PATH . '/' . preg_replace('/\.png$/i', '.jpg', $logoRel);
        if (!file_exists($logoPath)) {
            $logoPath = CB_ROOT_PATH . '/' . $logoRel;
        }
        $pdf->image($logoPath, 40, 26, 58, 58);

        $pdf->setFillColor(255, 255, 255);
        $pdf->text(112, 48, strtoupper($settings['company_name'] ?? 'CORELBASE PRIVATE LIMITED'), 'F2', 16);
        $pdf->setFillColor(244, 63, 94);
        $pdf->text(112, 66, 'CIN: ' . ($settings['company_cin'] ?? 'U62099UP2026PTC250888') . '  |  GSTIN: ' . ($settings['company_gstin'] ?? '09AAOCC1033C1Z1'), 'F2', 8.5);
        $pdf->setFillColor(190, 195, 205);
        $pdf->text(112, 82, 'Email: ' . ($settings['company_email'] ?? 'support@corelbase.com'), 'F1', 8.5);

        // Mandatory Red Legal Banner (Section 57)
        $pdf->setFillColor(254, 242, 242);
        $pdf->setStrokeColor(220, 38, 38);
        $pdf->setLineWidth(1.2);
        $pdf->rect(40, 140, 515.28, 42, 'FD');
        $pdf->setFillColor(185, 28, 28);
        $pdf->text(40, 158, 'APPLICATION ACKNOWLEDGEMENT - NOT PROOF OF SHARE ALLOTMENT', 'F2', 11, 'C', 515.28);
        $pdf->setFillColor(120, 30, 30);
        $pdf->text(40, 173, 'Submission of an application or payment does not confer shareholder status or guarantee share allotment.', 'F1', 8, 'C', 515.28);

        // Title & Date
        $pdf->setFillColor(15, 18, 26);
        $pdf->text(40, 214, 'INVESTMENT APPLICATION RECEIPT', 'F2', 14);
        $pdf->setFillColor(100, 105, 120);
        $pdf->text(40, 230, 'Generated on: ' . date('d M Y, h:i A \I\S\T'), 'F1', 9);

        // Details Table
        $rows = [
            ['Application ID', (string)($appData['application_id'] ?? '')],
            ['Investor ID', (string)($appData['investor_id'] ?? '')],
            ['Applicant Full Name', (string)($appData['full_name'] ?? '')],
            ['Registered Email', (string)($appData['email'] ?? '')],
            ['Mobile Number', (string)($appData['mobile'] ?? '')],
            ['PAN (Masked)', (string)($appData['pan_masked'] ?? '')],
            ['Aadhaar (Masked)', (string)($appData['aadhaar_masked'] ?? '')],
            ['Investment Amount Applied', 'INR ' . number_format((float)($appData['investment_amount'] ?? 0), 2)],
            ['Indicative Shares (Subject to Approval)', rtrim(rtrim(number_format((float)($appData['indicative_shares'] ?? 0), 4), '0'), '.') . ' Shares (Indicative)'],
            ['Indicative Ownership %', rtrim(rtrim(number_format((float)($appData['indicative_ownership_pct'] ?? 0), 6), '0'), '.') . '% (Indicative)'],
            ['Payment Method', (string)($appData['payment_method'] ?? 'Bank Transfer')],
            ['UTR / Transaction Reference', (string)($appData['utr_number'] ?? '')],
            ['Payment Date', (string)($appData['payment_date'] ?? '')],
            ['Current Application Status', (string)($appData['status'] ?? 'PAYMENT UNDER VERIFICATION')],
            ['Application Timestamp', formatIST($appData['created_at'] ?? gmdate('Y-m-d H:i:s'))],
        ];

        $y = 250;
        foreach ($rows as $idx => [$label, $val]) {
            if ($idx % 2 === 0) {
                $pdf->setFillColor(248, 249, 251);
            } else {
                $pdf->setFillColor(255, 255, 255);
            }
            $pdf->setStrokeColor(226, 230, 238);
            $pdf->setLineWidth(0.5);
            $pdf->rect(40, $y, 515.28, 24, 'FD');

            $pdf->setFillColor(90, 96, 110);
            $pdf->text(52, $y + 16, $label, 'F2', 9);
            $pdf->setFillColor(15, 18, 26);
            $pdf->text(245, $y + 16, $val, 'F2', 9.5);
            $y += 24;
        }

        // Legal Disclaimer Box at Bottom
        $y += 22;
        $pdf->setFillColor(244, 245, 248);
        $pdf->setStrokeColor(210, 215, 225);
        $pdf->rect(40, $y, 515.28, 96, 'FD');

        $pdf->setFillColor(20, 24, 34);
        $pdf->text(52, $y + 18, 'IMPORTANT STATUTORY & LEGAL DISCLAIMER', 'F2', 9);
        $pdf->setFillColor(80, 85, 100);
        $disclaimerLines = [
            '1. Corelbase Private Limited is a private limited company (CIN: U62099UP2026PTC250888).',
            '2. This portal is an investment application and investor management system, NOT a stock exchange or public trading platform.',
            '3. Submitting an application or transferring funds does NOT automatically make the applicant a shareholder.',
            '4. Any share allotment remains strictly subject to payment verification, company approval, statutory compliance,',
            '   corporate documentation, and applicable laws of India.',
        ];
        $ly = $y + 34;
        foreach ($disclaimerLines as $line) {
            $pdf->text(52, $ly, $line, 'F1', 8);
            $ly += 12;
        }

        return $pdf->output();
    }

    /**
     * Generate Administrative Report PDF (Sections 47 & 48).
     */
    public static function generateAdminReportPDF(string $reportTitle, array $records, array $summary, ?array $settings = null): string
    {
        $settings = $settings ?? getAllSettings(null, false);
        $pdf = new CorelbasePDF('L'); // Landscape A4 for tabular clarity

        $pdf->setFillColor(12, 14, 20);
        $pdf->rect(0, 0, 841.89, 78, 'F');
        $pdf->setFillColor(220, 38, 38);
        $pdf->rect(0, 78, 841.89, 3, 'F');

        $pdf->setFillColor(255, 255, 255);
        $pdf->text(36, 34, strtoupper($settings['company_name'] ?? 'CORELBASE PRIVATE LIMITED') . ' - ADMIN REPORT', 'F2', 14);
        $pdf->setFillColor(244, 63, 94);
        $pdf->text(36, 52, strtoupper($reportTitle), 'F2', 10);
        $pdf->setFillColor(185, 190, 205);
        $pdf->text(36, 66, 'Generated: ' . date('d M Y, h:i A \I\S\T') . '  |  Total Records: ' . count($records), 'F1', 8.5);

        // Summary KPI Bar
        $pdf->setFillColor(245, 246, 250);
        $pdf->setStrokeColor(220, 224, 232);
        $pdf->rect(36, 94, 769.89, 36, 'FD');
        $pdf->setFillColor(20, 24, 34);
        $summaryText = sprintf(
            'Total Applied: INR %s   |   Verified Amount: INR %s   |   Approved/Allotted Shares: %s',
            number_format((float)($summary['total_applied'] ?? 0), 2),
            number_format((float)($summary['total_verified'] ?? 0), 2),
            number_format((int)($summary['total_shares'] ?? 0))
        );
        $pdf->text(48, 116, $summaryText, 'F2', 9.5);

        // Table Header
        $headers = [
            [36,  'APPLICATION ID'],
            [130, 'INVESTOR NAME'],
            [270, 'EMAIL'],
            [420, 'AMOUNT (INR)'],
            [510, 'UTR NUMBER'],
            [620, 'STATUS'],
            [745, 'DATE'],
        ];

        $y = 144;
        $pdf->setFillColor(24, 28, 38);
        $pdf->rect(36, $y, 769.89, 22, 'F');
        $pdf->setFillColor(255, 255, 255);
        foreach ($headers as [$hx, $hLabel]) {
            $pdf->text($hx + 4, $y + 15, $hLabel, 'F2', 7.8);
        }
        $y += 22;

        foreach ($records as $idx => $r) {
            if ($y > 535) {
                $pdf->addPage();
                $y = 40;
            }
            $pdf->setFillColor($idx % 2 === 0 ? 255 : 248, $idx % 2 === 0 ? 255 : 249, $idx % 2 === 0 ? 255 : 252);
            $pdf->setStrokeColor(230, 233, 240);
            $pdf->rect(36, $y, 769.89, 20, 'FD');

            $pdf->setFillColor(20, 24, 34);
            $pdf->text(40,  $y + 14, (string)($r['application_id'] ?? ''), 'F2', 8);
            $pdf->text(134, $y + 14, mb_substr((string)($r['full_name'] ?? ''), 0, 22), 'F1', 8);
            $pdf->text(274, $y + 14, mb_substr((string)($r['email'] ?? ''), 0, 26), 'F1', 7.8);
            $pdf->text(424, $y + 14, number_format((float)($r['investment_amount'] ?? 0), 2), 'F2', 8);
            $pdf->text(514, $y + 14, mb_substr((string)($r['utr_number'] ?? '-'), 0, 16), 'F5', 7.8);
            $pdf->text(624, $y + 14, mb_substr((string)($r['status'] ?? ''), 0, 20), 'F2', 7.5);
            $pdf->text(749, $y + 14, date('d M Y', strtotime((string)($r['created_at'] ?? 'now'))), 'F1', 7.8);
            $y += 20;
        }

        return $pdf->output();
    }
}
