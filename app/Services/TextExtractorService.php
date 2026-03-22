<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class TextExtractorService
{
    public function __construct(protected KimiService $kimiService) {}

    /**
     * Extract text from an uploaded file based on its MIME type.
     */
    public function extract(string $filePath, string $mimeType): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match (true) {
            str_contains($mimeType, 'pdf') => $this->extractFromPdf($filePath),
            str_starts_with($mimeType, 'image/') => $this->extractFromImage($filePath),
            str_contains($mimeType, 'wordprocessingml') || str_contains($mimeType, 'msword') || $extension === 'docx' || $extension === 'doc' => $this->extractFromWord($filePath),
            str_contains($mimeType, 'spreadsheetml') || str_contains($mimeType, 'ms-excel') || $extension === 'xlsx' || $extension === 'xls' => $this->extractFromExcel($filePath),
            str_contains($mimeType, 'presentationml') || str_contains($mimeType, 'ms-powerpoint') || $extension === 'pptx' || $extension === 'ppt' => $this->extractFromPowerPoint($filePath),
            str_contains($mimeType, 'text/') || str_contains($mimeType, 'json') || in_array($extension, ['txt', 'md', 'csv', 'json', 'log', 'xml', 'yaml', 'yml']) => $this->extractFromText($filePath),
            default => throw new \RuntimeException("Unsupported file type: {$mimeType} (extension: .{$extension})"),
        };
    }

    protected function extractFromPdf(string $filePath): string
    {
        // 1. Try smalot/pdfparser for text-based PDFs
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();

            $cleanText = preg_replace('/[\x00-\x1F\x7F]+/', '', trim($text));
            if (! empty($cleanText) && mb_strlen($cleanText) > 20) {
                return $text;
            }
        } catch (\Exception $e) {
            Log::info('PdfParser failed, falling back to KIMI', [
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);
        }

        // 2. For scanned/image PDFs, use KIMI vision OCR
        return $this->ocrPdfViaKimi($filePath);
    }

    protected function ocrPdfViaKimi(string $filePath): string
    {
        if (! config('ai.kimi_api_key')) {
            throw new \RuntimeException('Cannot OCR scanned PDF: KIMI API key is not configured.');
        }

        $fileSize = filesize($filePath);
        $maxSize = 20 * 1024 * 1024; // 20MB limit for base64

        if ($fileSize > $maxSize) {
            throw new \RuntimeException('PDF too large for OCR processing ('.round($fileSize / 1048576, 1).'MB).');
        }

        // Upload the PDF to KIMI's file API, then extract content
        try {
            $fileId = $this->kimiService->uploadFile($filePath, 'application/pdf');
            $text = $this->kimiService->extractTextFromFile($fileId);

            if (! empty(trim($text))) {
                return $text;
            }
        } catch (\Exception $e) {
            Log::warning('KIMI file extraction failed', [
                'file' => basename($filePath),
                'error' => $e->getMessage(),
            ]);
        }

        throw new \RuntimeException('No text could be extracted from the PDF document.');
    }

    protected function extractFromImage(string $filePath): string
    {
        return $this->kimiService->analyzeImage($filePath);
    }

    protected function extractFromWord(string $filePath): string
    {
        $phpWord = WordIOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractWordElement($element)."\n";
            }
        }

        return trim($text);
    }

    protected function extractWordElement(mixed $element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $text .= $element->getText().' ';
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractWordElement($child);
            }
        }

        if ($element instanceof Table) {
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellText = '';
                    foreach ($cell->getElements() as $cellElement) {
                        $cellText .= $this->extractWordElement($cellElement);
                    }
                    $cells[] = trim($cellText);
                }
                $text .= implode(' | ', $cells)."\n";
            }
        }

        return $text;
    }

    protected function extractFromExcel(string $filePath): string
    {
        $spreadsheet = SpreadsheetIOFactory::load($filePath);
        $text = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetTitle = $sheet->getTitle();
            $text .= "## {$sheetTitle}\n";

            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);

                foreach ($cellIterator as $cell) {
                    $value = $cell->getFormattedValue();
                    if ($value !== '' && $value !== null) {
                        $cells[] = $value;
                    }
                }

                if (! empty($cells)) {
                    $text .= implode(' | ', $cells)."\n";
                }
            }

            $text .= "\n";
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return trim($text);
    }

    protected function extractFromPowerPoint(string $filePath): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Could not open PowerPoint file.');
        }

        $text = '';
        $slideIndex = 1;

        while (($xmlContent = $zip->getFromName("ppt/slides/slide{$slideIndex}.xml")) !== false) {
            $text .= "## Slide {$slideIndex}\n";

            $slideText = $this->extractAllTextFromSlideXml($xmlContent);

            $notesXml = $zip->getFromName("ppt/notesSlides/notesSlide{$slideIndex}.xml");
            $notesText = '';
            if ($notesXml !== false) {
                $notesText = $this->extractAllTextFromSlideXml($notesXml);
                $notesText = preg_replace('/^\d+$/m', '', $notesText);
                $notesText = trim($notesText);
            }

            if (mb_strlen(trim($slideText)) < 30) {
                $ocrText = $this->ocrSlideImage($zip, $slideIndex);
                if ($ocrText !== '') {
                    $slideText = $ocrText;
                    usleep(500000);
                }
            }

            if (trim($slideText) !== '') {
                $text .= $slideText."\n";
            }

            if ($notesText !== '') {
                $text .= '[Speaker Notes] '.$notesText."\n";
            }

            $text .= "\n";
            $slideIndex++;
        }

        $zip->close();

        return trim($text);
    }

    protected function ocrSlideImage(\ZipArchive $zip, int $slideIndex): string
    {
        $relsXml = $zip->getFromName("ppt/slides/_rels/slide{$slideIndex}.xml.rels");
        if (! $relsXml) {
            return '';
        }

        $imagePath = null;
        if (preg_match_all('/Relationship\s[^>]+>/s', $relsXml, $relMatches)) {
            foreach ($relMatches[0] as $rel) {
                if (str_contains($rel, 'relationships/image') && preg_match('/Target="\.\.\/media\/([^"]+)"/', $rel, $m)) {
                    $imagePath = 'ppt/media/'.$m[1];
                    break;
                }
            }
        }

        if (! $imagePath) {
            return '';
        }

        $imageData = $zip->getFromName($imagePath);
        if ($imageData === false) {
            return '';
        }

        $ext = pathinfo($imagePath, PATHINFO_EXTENSION) ?: 'jpg';
        $tmpFile = tempnam(sys_get_temp_dir(), 'pptx_slide_').'.'.strtolower($ext);

        try {
            file_put_contents($tmpFile, $imageData);

            return trim($this->kimiService->extractTextFromImage($tmpFile));
        } catch (\Exception $e) {
            Log::warning("KIMI OCR failed for slide {$slideIndex}", ['error' => $e->getMessage()]);

            return '';
        } finally {
            @unlink($tmpFile);
            $base = preg_replace('/\.[^.]+$/', '', $tmpFile);
            if ($base !== $tmpFile) {
                @unlink($base);
            }
        }
    }

    protected function extractAllTextFromSlideXml(string $xmlContent): string
    {
        $lines = [];
        $paragraphs = preg_split('/<[^>]*:p[\s>]/', $xmlContent);

        foreach ($paragraphs as $para) {
            $endPos = strpos($para, ':p>');
            if ($endPos !== false) {
                $para = substr($para, 0, $endPos);
            }

            if (preg_match_all('/<[^>]*:t[^>]*>([^<]*)<\/[^>]*:t>/s', $para, $matches)) {
                $line = implode('', $matches[1]);
                $line = html_entity_decode(trim($line), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        if (empty($lines)) {
            if (preg_match_all('/<[^>]*:t[^>]*>([^<]+)<\/[^>]*:t>/s', $xmlContent, $matches)) {
                foreach ($matches[1] as $text) {
                    $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function extractFromText(string $filePath): string
    {
        return file_get_contents($filePath);
    }
}
