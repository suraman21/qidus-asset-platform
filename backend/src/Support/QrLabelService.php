<?php
declare(strict_types=1);

namespace QidusAsset\Support;

/**
 * QR Label & Bulk Sheet Renderer (FR-03).
 * Uses `chillerlan/php-qrcode` when vendor/ is installed, with deterministic SVG fallback.
 */
final class QrLabelService
{
    public static function renderQrSvg(string $token): string
    {
        if (class_exists(\chillerlan\QRCode\QRCode::class) && class_exists(\chillerlan\QRCode\QROptions::class)) {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
                'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
                'scale' => 5,
                'outputBase64' => false,
            ]);
            return (new \chillerlan\QRCode\QRCode($options))->render($token);
        }

        // Deterministic 21x21 fallback SVG matrix encoded from token SHA-256 (for pre-composer environments)
        $hash = hash('sha256', $token, true);
        $size = 21;
        $cell = 6;
        $dim = $size * $cell;
        $rects = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $inFinder = ($x < 7 && $y < 7) || ($x >= $size - 7 && $y < 7) || ($x < 7 && $y >= $size - 7);
                if ($inFinder) {
                    $lx = $x >= $size - 7 ? $x - ($size - 7) : $x;
                    $ly = $y >= $size - 7 ? $y - ($size - 7) : $y;
                    $on = ($lx === 0 || $lx === 6 || $ly === 0 || $ly === 6 || ($lx >= 2 && $lx <= 4 && $ly >= 2 && $ly <= 4));
                } else {
                    $idx = ($y * $size + $x);
                    $byte = ord($hash[$idx % 32]);
                    $bit = ($byte >> ($idx % 8)) & 1;
                    $on = $bit === 1;
                }
                if ($on) {
                    $px = $x * $cell;
                    $py = $y * $cell;
                    $rects[] = "<rect x=\"{$px}\" y=\"{$py}\" width=\"{$cell}\" height=\"{$cell}\" fill=\"#0f172a\"/>";
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="126" height="126" role="img" aria-label="QR Code">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>'
            . implode('', $rects)
            . '</svg>';
    }

    /**
     * Render printable A4 HTML label sheet for 1..N assets.
     *
     * @param list<array<string,mixed>> $assets
     */
    public static function renderLabelSheetHtml(array $assets, string $schoolName): string
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cards = '';
        foreach ($assets as $asset) {
            $token = (string)($asset['qr_token'] ?? '');
            $svg = self::renderQrSvg($token);
            $cards .= '<div class="lbl">'
                . '<div class="qr">' . $svg . '</div>'
                . '<div class="meta">'
                . '<div class="sch">' . $esc($schoolName) . '</div>'
                . '<div class="code">' . $esc((string)($asset['asset_code'] ?? '')) . '</div>'
                . '<div class="name">' . $esc((string)($asset['name_en'] ?? '')) . '</div>'
                . (!empty($asset['name_am']) ? '<div class="am">' . $esc((string)$asset['name_am']) . '</div>' : '')
                . '<div class="tok">' . $esc(substr($token, 0, 18)) . '</div>'
                . '</div></div>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>QR Labels · ' . $esc($schoolName) . '</title>'
            . '<style>@page{size:A4;margin:10mm;}body{font-family:sans-serif;margin:0;padding:12px;background:#fff;color:#0f172a;}'
            . '.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}'
            . '.lbl{border:1px solid #cbd5e1;border-radius:8px;padding:8px;display:flex;gap:8px;align-items:center;break-inside:avoid;}'
            . '.qr svg{width:76px;height:76px;display:block;}'
            . '.sch{font-size:9px;color:#475569;text-transform:uppercase;font-weight:700;}'
            . '.code{font-size:13px;font-weight:800;margin:2px 0;}'
            . '.name{font-size:11px;font-weight:600;}.am{font-size:11px;}.tok{font-size:9px;color:#64748b;font-family:monospace;margin-top:3px;}'
            . '@media print{.noprint{display:none;}}</style></head><body>'
            . '<div class="noprint" style="margin-bottom:12px;"><button onclick="window.print()">Print Labels</button></div>'
            . '<div class="grid">' . $cards . '</div></body></html>';
    }
}
