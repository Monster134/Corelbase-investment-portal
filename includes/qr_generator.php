<?php
/**
 * CORELBASE PRIVATE LIMITED — PURE PHP ISO/IEC 18004 QR CODE GENERATOR
 * -----------------------------------------------------------------------------
 * Self-contained QR Code encoder (Byte mode, Reed-Solomon ECC Level L/M,
 * Versions 1 to 6, up to 134 bytes — ideal for verification URLs like
 * https://YOUR-DOMAIN/verify.php?id=CB-SC-2026-000001).
 *
 * Requires NO external Composer packages or outbound internet API calls.
 * Outputs:
 * - Clean inline SVG (`QRGenerator::renderSvg($text, $size)`)
 * - JPEG/PNG image file via PHP GD (`QRGenerator::saveJpeg($text, $filepath, $size)`)
 */

declare(strict_types=1);

class QRGenerator
{
    /**
     * Capacity & ECC table for Byte Mode, Error Correction Level L (01)
     * Format: version => [total_codewords, ec_codewords_per_block, num_blocks, max_bytes, alignment_centers]
     */
    private const VERSION_TABLE = [
        1 => [26,  7,  1, 17,  []],
        2 => [44,  10, 1, 32,  [6, 18]],
        3 => [70,  15, 1, 53,  [6, 22]],
        4 => [100, 20, 1, 78,  [6, 26]],
        5 => [134, 26, 1, 106, [6, 30]],
        6 => [172, 18, 2, 134, [6, 34]],
    ];

    // Precomputed Format Information strings for ECC Level L (01) and Mask 0..7
    private const FORMAT_BITS_L = [
        0 => 0x77C4,
        1 => 0x72F3,
        2 => 0x7DAA,
        3 => 0x789D,
        4 => 0x662F,
        5 => 0x6318,
        6 => 0x6C41,
        7 => 0x6976,
    ];

    /**
     * Generate a 2D boolean matrix for the given string.
     *
     * @return array<int, array<int, bool>>
     */
    public static function encodeMatrix(string $data): array
    {
        $bytes = array_values(unpack('C*', $data));
        $len = count($bytes);

        // Select smallest fitting version (1..6); truncate safely if >134 bytes
        if ($len > 134) {
            $bytes = array_slice($bytes, 0, 134);
            $len = 134;
        }
        $version = 6;
        foreach (self::VERSION_TABLE as $v => $info) {
            if ($len <= $info[3]) {
                $version = $v;
                break;
            }
        }

        [$totalCodewords, $ecPerBlock, $numBlocks, , $alignCenters] = self::VERSION_TABLE[$version];
        $dataCodewordsCount = $totalCodewords - ($ecPerBlock * $numBlocks);

        // Build bit stream: Byte Mode indicator (0100) + 8-bit character count
        $bits = '0100' . str_pad(decbin($len), 8, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        // Terminator (up to 4 zero bits)
        $maxBits = $dataCodewordsCount * 8;
        $bits .= str_repeat('0', min(4, max(0, $maxBits - strlen($bits))));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        // Pad bytes (0xEC, 0x11 alternating)
        $padToggle = true;
        while (strlen($bits) < $maxBits) {
            $bits .= $padToggle ? '11101100' : '00010001';
            $padToggle = !$padToggle;
        }

        $dataCodewords = [];
        for ($i = 0; $i < $maxBits; $i += 8) {
            $dataCodewords[] = bindec(substr($bits, $i, 8));
        }

        // Split into blocks and compute Reed-Solomon EC codewords
        $blockSize = intdiv($dataCodewordsCount, $numBlocks);
        $dataBlocks = array_chunk($dataCodewords, $blockSize);
        $ecBlocks = [];
        foreach ($dataBlocks as $block) {
            $ecBlocks[] = self::reedSolomonEncode($block, $ecPerBlock);
        }

        // Interleave data and EC codewords
        $finalCodewords = [];
        for ($i = 0; $i < $blockSize; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $finalCodewords[] = $dataBlocks[$b][$i];
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $finalCodewords[] = $ecBlocks[$b][$i];
            }
        }

        // Convert to full bit array
        $streamBits = [];
        foreach ($finalCodewords as $cw) {
            for ($bit = 7; $bit >= 0; $bit--) {
                $streamBits[] = (($cw >> $bit) & 1) === 1;
            }
        }
        // Remainder bits for versions 2..6
        $remainderBits = ($version >= 2 && $version <= 6) ? 7 : 0;
        for ($r = 0; $r < $remainderBits; $r++) {
            $streamBits[] = false;
        }

        // Initialize grid
        $size = 17 + ($version * 4);
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns + separators
        self::placeFinderPattern($matrix, $reserved, 0, 0, $size);
        self::placeFinderPattern($matrix, $reserved, $size - 7, 0, $size);
        self::placeFinderPattern($matrix, $reserved, 0, $size - 7, $size);

        // 2. Alignment patterns
        if (!empty($alignCenters)) {
            foreach ($alignCenters as $ay) {
                foreach ($alignCenters as $ax) {
                    if ($reserved[$ay][$ax]) {
                        continue;
                    }
                    self::placeAlignmentPattern($matrix, $reserved, $ax, $ay);
                }
            }
        }

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i % 2 === 0);
            if (!$reserved[6][$i]) {
                $matrix[6][$i] = $val;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $matrix[$i][6] = $val;
                $reserved[$i][6] = true;
            }
        }

        // 4. Dark module & reserve format info areas
        $matrix[(4 * $version) + 9][8] = true;
        $reserved[(4 * $version) + 9][8] = true;

        for ($i = 0; $i < 9; $i++) {
            if ($i < $size) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // 5. Place data bits in upward/downward 2-column zig-zag
        $bitIdx = 0;
        $totalBits = count($streamBits);
        $upward = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // Skip vertical timing column
            }
            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? ($size - 1 - $step) : $step;
                for ($c = 0; $c < 2; $c++) {
                    $cc = $col - $c;
                    if (!$reserved[$row][$cc]) {
                        $bit = ($bitIdx < $totalBits) ? $streamBits[$bitIdx++] : false;
                        // Apply Mask 0: (row + cc) % 2 == 0
                        if ((($row + $cc) % 2) === 0) {
                            $bit = !$bit;
                        }
                        $matrix[$row][$cc] = $bit;
                    }
                }
            }
            $upward = !$upward;
        }

        // 6. Write Format Information for Level L, Mask 0
        $formatBits = self::FORMAT_BITS_L[0];
        $fBits = [];
        for ($i = 0; $i < 15; $i++) {
            $fBits[$i] = (($formatBits >> $i) & 1) === 1;
        }

        // Around top-left finder
        $tlCoords = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        foreach ($tlCoords as $idx => [$r, $c]) {
            $matrix[$r][$c] = $fBits[$idx];
        }

        // Bottom-left & top-right format bits
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = $fBits[$i];
        }
        for ($i = 7; $i < 15; $i++) {
            $matrix[8][$size - 15 + $i] = $fBits[$i];
        }

        return $matrix;
    }

    /**
     * Render a crisp inline SVG QR code.
     */
    public static function renderSvg(string $data, int $pixelSize = 220, int $margin = 2, string $fgColor = '#0A0A0D', string $bgColor = '#FFFFFF'): string
    {
        $matrix = self::encodeMatrix($data);
        $count = count($matrix);
        $totalModules = $count + ($margin * 2);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges" role="img" aria-label="Verification QR Code">',
            $totalModules,
            $totalModules,
            $pixelSize,
            $pixelSize
        );
        $svg .= sprintf('<rect width="100%%" height="100%%" fill="%s"/>', htmlspecialchars($bgColor, ENT_QUOTES));
        $path = '';
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $path .= "M{$x},{$y}h1v1h-1z";
                }
            }
        }
        $svg .= sprintf('<path d="%s" fill="%s"/>', $path, htmlspecialchars($fgColor, ENT_QUOTES));
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Save QR code as a JPEG image file (used for embedding into native PDFs).
     */
    public static function saveJpeg(string $data, string $outputPath, int $pixelSize = 360, int $margin = 2): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        $matrix = self::encodeMatrix($data);
        $count = count($matrix);
        $totalModules = $count + ($margin * 2);
        $scale = max(4, intdiv($pixelSize, $totalModules));
        $imgDim = $totalModules * $scale;

        $im = imagecreatetruecolor($imgDim, $imgDim);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 12, 14, 20);
        imagefilledrectangle($im, 0, 0, $imgDim, $imgDim, $white);

        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $count; $c++) {
                if ($matrix[$r][$c]) {
                    $x1 = ($c + $margin) * $scale;
                    $y1 = ($r + $margin) * $scale;
                    imagefilledrectangle($im, $x1, $y1, $x1 + $scale - 1, $y1 + $scale - 1, $black);
                }
            }
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $res = imagejpeg($im, $outputPath, 95);
        imagedestroy($im);
        return $res;
    }

    private static function placeFinderPattern(array &$matrix, array &$reserved, int $rowOffset, int $colOffset, int $size): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $rowOffset + $r;
                $cc = $colOffset + $c;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                    continue;
                }
                $isDark = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                    || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $matrix[$rr][$cc] = $isDark;
                $reserved[$rr][$cc] = true;
            }
        }
    }

    private static function placeAlignmentPattern(array &$matrix, array &$reserved, int $centerCol, int $centerRow): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $rr = $centerRow + $r;
                $cc = $centerCol + $c;
                $isDark = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                $matrix[$rr][$cc] = $isDark;
                $reserved[$rr][$cc] = true;
            }
        }
    }

    /**
     * Reed-Solomon error correction over GF(256) with primitive polynomial 0x11D.
     */
    private static function reedSolomonEncode(array $data, int $ecCount): array
    {
        static $exp = null;
        static $log = null;
        if ($exp === null) {
            $exp = array_fill(0, 512, 0);
            $log = array_fill(0, 256, 0);
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
        }

        // Build generator polynomial
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $nextGen = array_fill(0, count($gen) + 1, 0);
            for ($j = 0, $gLen = count($gen); $j < $gLen; $j++) {
                $nextGen[$j] ^= $gen[$j];
                if ($gen[$j] !== 0) {
                    $nextGen[$j + 1] ^= $exp[$log[$gen[$j]] + $i];
                }
            }
            $gen = $nextGen;
        }

        $ec = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($ec);
            $ec[] = 0;
            if ($factor !== 0) {
                for ($j = 0; $j < $ecCount; $j++) {
                    if ($gen[$j + 1] !== 0) {
                        $ec[$j] ^= $exp[$log[$gen[$j + 1]] + $log[$factor]];
                    }
                }
            }
        }
        return $ec;
    }
}
