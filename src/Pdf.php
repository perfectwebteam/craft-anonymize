<?php

namespace perfectwebteam\anonymize;

/**
 * A plain-text PDF, written by hand: Helvetica 9pt on A4, one line per entry,
 * wrapped and paginated. No library: a placeholder only needs a file that
 * every PDF reader, and Tika, opens. Taken over from SPAR's Pdf.
 */
final class Pdf
{
    /** What fits at 9pt Helvetica on A4 with a 40pt margin. */
    private const LINES_PER_PAGE = 55;

    /** Characters per line, at the same size. */
    private const LINE_WIDTH = 95;

    /**
     * @param list<string> $lines ASCII text; longer lines are wrapped
     * @param string $title the document's title
     * @return string the PDF file contents
     */
    public static function build(array $lines, string $title): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            foreach (explode("\n", wordwrap($line, self::LINE_WIDTH, "\n", true)) as $part) {
                $wrapped[] = $part;
            }
        }

        $chunks = array_chunk($wrapped, self::LINES_PER_PAGE) ?: [[]];
        $n = count($chunks);

        // Object numbering: 1 catalog, 2 pages, 3 font, 4..3+n content streams,
        // 4+n..3+2n page objects, 4+2n info.
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count ' . $n . ' /Kids ['
                . implode(' ', array_map(static fn(int $k) => (4 + $n + $k) . ' 0 R', range(0, $n - 1)))
                . '] >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        foreach ($chunks as $k => $chunk) {
            $stream = "BT\n/F1 9 Tf\n11 TL\n40 800 Td\n";
            foreach ($chunk as $line) {
                $stream .= '(' . self::string($line) . ") Tj T*\n";
            }
            $stream .= 'ET';
            $objects[4 + $k] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[4 + $n + $k] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
                . ' /Resources << /Font << /F1 3 0 R >> >> /Contents ' . (4 + $k) . ' 0 R >>';
        }

        $infoNum = 4 + 2 * $n;
        $objects[$infoNum] = '<< /Title (' . self::string($title) . ') /Producer (craft-anonymize) >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        for ($num = 1; $num <= $infoNum; $num++) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $objects[$num] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = $infoNum + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($num = 1; $num <= $infoNum; $num++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }

        return $pdf . "trailer\n<< /Size {$size} /Root 1 0 R /Info {$infoNum} 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    /** $value as the inside of a PDF literal string: `(` `)` and `\` escaped, line breaks dropped. */
    private static function string(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $value);
    }
}
