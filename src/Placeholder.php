<?php

namespace perfectwebteam\anonymize;

use RuntimeException;
use ZipArchive;

/**
 * Stand-in file contents (`anonymize/placeholders`): a file of the same type
 * as the asset's, so it keeps its extension, kind and mime type, and every
 * viewer, Tika and a download keep working; only what is in it is made up.
 * A type it cannot write (heic, tif, …) gets a text file under its own
 * extension.
 */
final class Placeholder
{
    /** The text every placeholder carries. */
    public const TEXT = 'Geanonimiseerd';

    /** Images keep their size, up to this. */
    private const MAX_IMAGE_SIDE = 2000;

    /**
     * @param string $extension the file's extension, any case
     * @param string $label a second line of text, such as the asset's (made-up) title
     * @param int|null $width for images: the size the asset has, kept where known
     */
    public static function forExtension(string $extension, string $label, ?int $width = null, ?int $height = null): string
    {
        $extension = strtolower($extension);
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) && function_exists('imagecreatetruecolor')) {
            return self::image($extension, $label, $width, $height);
        }

        return match ($extension) {
            'svg' => self::svg($label, $width, $height),
            'pdf' => Pdf::build([self::TEXT . ' document', $label], self::TEXT),
            'csv' => self::TEXT . ';' . $label . "\r\n",
            'xml' => '<?xml version="1.0" encoding="utf-8"?>' . "\n<document>" . htmlspecialchars(self::TEXT . ' ' . $label, ENT_XML1) . "</document>\n",
            'json' => json_encode(['text' => self::TEXT, 'label' => $label]) . "\n",
            'docx' => self::docx($label),
            'xlsx' => self::xlsx($label),
            default => self::TEXT . " bestand\n{$label}\n",
        };
    }

    /** A light grey image with the text in the middle, in the original format and (capped) size. */
    private static function image(string $extension, string $label, ?int $width, ?int $height): string
    {
        [$width, $height] = self::size($width, $height);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 0xE5, 0xE7, 0xEB));
        $ink = imagecolorallocate($image, 0x4B, 0x55, 0x63);
        $font = 5;
        $lines = [self::TEXT, $label];
        $lineHeight = imagefontheight($font) + 4;
        $top = (int)(($height - count($lines) * $lineHeight) / 2);
        foreach ($lines as $i => $line) {
            $left = (int)(($width - strlen($line) * imagefontwidth($font)) / 2);
            imagestring($image, $font, max(0, $left), max(0, $top + $i * $lineHeight), $line, $ink);
        }

        ob_start();
        match ($extension) {
            'png' => imagepng($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            'bmp' => imagebmp($image),
            default => imagejpeg($image, null, 80),
        };

        return (string)ob_get_clean();
    }

    private static function svg(string $label, ?int $width, ?int $height): string
    {
        [$width, $height] = self::size($width, $height);

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">'
            . '<rect width="100%" height="100%" fill="#e5e7eb"/>'
            . '<text x="50%" y="50%" text-anchor="middle" fill="#4b5563" font-family="sans-serif">'
            . htmlspecialchars(self::TEXT . ' ' . $label, ENT_XML1) . "</text></svg>\n";
    }

    /** @return array{0: int, 1: int} */
    private static function size(?int $width, ?int $height): array
    {
        return [max(1, min(self::MAX_IMAGE_SIDE, $width ?: 800)), max(1, min(self::MAX_IMAGE_SIDE, $height ?: 600))];
    }

    /** The smallest document Word opens: three parts, one paragraph per line. */
    private static function docx(string $label): string
    {
        $paragraphs = '';
        foreach ([self::TEXT . ' document', $label] as $line) {
            $paragraphs .= '<w:p><w:r><w:t>' . htmlspecialchars($line, ENT_XML1) . '</w:t></w:r></w:p>';
        }

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                . $paragraphs . '</w:body></w:document>',
        ]);
    }

    /** The smallest workbook Excel opens: one sheet, the text in A1 and the label in A2. */
    private static function xlsx(string $label): string
    {
        $cell = fn(string $ref, string $text) => '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($text, ENT_XML1) . '</t></is></c>';

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Blad1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                . '<row r="1">' . $cell('A1', self::TEXT) . '</row><row r="2">' . $cell('A2', $label) . '</row>'
                . '</sheetData></worksheet>',
        ]);
    }

    /** @param array<string, string> $files name => contents */
    private static function zip(array $files): string
    {
        if (!class_exists(ZipArchive::class)) {
            return self::TEXT . "\n";
        }
        $file = tempnam(sys_get_temp_dir(), 'anon');
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Kan geen placeholder in zip-vorm maken');
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        try {
            return (string)file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }
}
