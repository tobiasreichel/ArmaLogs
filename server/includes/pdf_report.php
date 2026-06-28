<?php
declare(strict_types=1);

require_once __DIR__ . '/fpdf/fpdf.php';

function markdown_to_pdf(array $report): string {
    $title = $report['title'] ?? 'ArmaLogs Report';
    $markdown = $report['markdown'] ?? ($report['summary'] ?? '');
    $scope = '';
    if (!empty($report['is_multi_friend'])) {
        $scope = 'multi-friend';
    } elseif (!empty($report['is_multi_session'])) {
        $scope = 'multi-session';
    } elseif (!empty($report['friend_name']) || !empty($report['session_id'])) {
        $scope = ($report['friend_name'] ?? 'unknown') . ' / ' . ($report['session_id'] ?? 'unknown');
    }
    $model = $report['model'] ?? '';
    $created = $report['created_at'] ?? '';

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 15);

    // Title
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, utf8_to_latin1($title), 0, 1);

    // Meta
    $pdf->SetFont('Arial', '', 10);
    if ($scope !== '') {
        $pdf->Cell(0, 6, utf8_to_latin1('Scope: ' . $scope), 0, 1);
    }
    if ($created !== '') {
        $pdf->Cell(0, 6, utf8_to_latin1('Created: ' . $created), 0, 1);
    }
    if ($model !== '') {
        $pdf->Cell(0, 6, utf8_to_latin1('Model: ' . $model), 0, 1);
    }
    $pdf->Ln(4);

    // Body
    $lines = explode("\n", $markdown);
    $pdf->SetFont('Arial', '', 11);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            $pdf->Ln(2);
            continue;
        }

        // Headings
        if (str_starts_with($line, '## ')) {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Ln(2);
            $pdf->MultiCell(0, 7, utf8_to_latin1(ltrim(substr($line, 3))), 0, 'L');
            $pdf->SetFont('Arial', '', 11);
            continue;
        }
        if (str_starts_with($line, '### ')) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Ln(2);
            $pdf->MultiCell(0, 6, utf8_to_latin1(ltrim(substr($line, 4))), 0, 'L');
            $pdf->SetFont('Arial', '', 11);
            continue;
        }
        if (str_starts_with($line, '# ')) {
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Ln(2);
            $pdf->MultiCell(0, 8, utf8_to_latin1(ltrim(substr($line, 2))), 0, 'L');
            $pdf->SetFont('Arial', '', 11);
            continue;
        }

        // Horizontal rule
        if (preg_match('/^-{3,}$/', $line)) {
            $pdf->Ln(2);
            $pdf->Cell(0, 1, '', 'T', 1);
            $pdf->Ln(2);
            continue;
        }

        // Bullet list
        if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
            $pdf->Cell(5); // indent
            $pdf->Cell(4, 6, chr(149), 0, 0, 'L');
            $pdf->MultiCell(0, 6, utf8_to_latin1(render_inline($m[1])), 0, 'L');
            continue;
        }

        // Numbered list
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
            $pdf->Cell(5); // indent
            $pdf->MultiCell(0, 6, utf8_to_latin1(render_inline($m[1])), 0, 'L');
            continue;
        }

        // Bold-only detection for impact scores / labels
        $pdf->MultiCell(0, 6, utf8_to_latin1(render_inline($line)), 0, 'L');
    }

    return $pdf->Output('S');
}

function render_inline(string $text): string {
    // Strip markdown bold markers for plain-text PDF rendering
    return preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
}

function utf8_to_latin1(string $text): string {
    $text = str_replace(['…', '’', '‘', '“', '”', '—', '–'], ['...', "'", "'", '"', '"', '-', '-'], $text);
    $out = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    return $out === false ? $text : $out;
}
