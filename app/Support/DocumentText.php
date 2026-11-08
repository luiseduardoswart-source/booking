<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use finfo;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Extracts the plain text from an uploaded document (PDF, Word .docx or .txt),
 * for example an existing rental contract. Formatting is lost, paragraphs
 * are kept.
 *
 * The file type follows from the content, not from the name. A scanned PDF
 * contains no text; that needs text recognition (OCR), which we do not do.
 */
final class DocumentText
{
    /** Largest document we accept. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Largest unpacked Word text; more is a zip bomb, not a contract. */
    public const MAX_WORD_XML = 8 * 1024 * 1024;

    /** Largest unpacked PDF stream, for the same reason. */
    private const MAX_PDF_STREAM = 32 * 1024 * 1024;

    private const WORD = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** What the upload reports to the browser; the real check is done by fromPath(). */
    public const MIME_TYPES = ['application/pdf', self::WORD, 'application/zip', 'text/plain'];

    /**
     * @throws DocumentNotReadable
     */
    public static function fromPath(string $path): string
    {
        if (! is_file($path)) {
            throw new DocumentNotReadable(__('Please upload a file first.'));
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new DocumentNotReadable(__('The file is larger than :mb MB.', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        $text = match ((new finfo(FILEINFO_MIME_TYPE))->file($path)) {
            'application/pdf' => self::fromPdf($path),
            self::WORD, 'application/zip' => self::fromWord($path),
            'text/plain' => self::fromTextFile($path),
            'application/msword', 'application/CDFV2' => throw new DocumentNotReadable(
                __('This is an old Word file (.doc). Save it in Word as .docx or PDF and upload it again.')
            ),
            default => throw new DocumentNotReadable(
                __('This file type is not supported. Upload a PDF, a Word file (.docx) or a text file (.txt).')
            ),
        };

        $text = self::clean($text);

        if ($text === '') {
            throw new DocumentNotReadable(
                __('This file contains no readable text. Is it a scanned PDF? Then upload the original Word file or paste the text into the field.')
            );
        }

        return $text;
    }

    private static function fromPdf(string $path): string
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::MAX_PDF_STREAM);

        try {
            return (new Parser([], $config))->parseFile($path)->getText();
        } catch (Throwable) {
            throw new DocumentNotReadable(
                __('This PDF cannot be read. Is it password-protected or damaged?')
            );
        }
    }

    /**
     * Reads word/document.xml: one line per paragraph, tabs and hard line breaks
     * are kept. Deleted text from "track changes" (w:delText)
     * is not counted.
     */
    private static function fromWord(string $path): string
    {
        $noWord = new DocumentNotReadable(__('This file is not a valid Word file (.docx).'));

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw $noWord;
        }

        // Read one byte more than allowed: the size stated in a zip
        // can be a lie, what we actually unpack cannot.
        $xml = $zip->getFromName('word/document.xml', self::MAX_WORD_XML + 1);
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            throw $noWord;
        }

        if (strlen($xml) > self::MAX_WORD_XML) {
            throw new DocumentNotReadable(__('This Word file contains too much text to import.'));
        }

        // A real Word document has no DOCTYPE; with a DOCTYPE, entities
        // can blow up the text or point to external resources.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $noWord;
        }

        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw $noWord;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);

        $lines = [];

        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            $line = '';

            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph) as $part) {
                $line .= match ($part->localName) {
                    't' => $part->textContent,
                    'tab' => "\t",
                    default => "\n",
                };
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** Notepad sometimes saves as UTF-16 or Windows-1252; everything becomes UTF-8. */
    private static function fromTextFile(string $path): string
    {
        $text = (string) file_get_contents($path);

        return match (true) {
            str_starts_with($text, "\xEF\xBB\xBF") => substr($text, 3),
            str_starts_with($text, "\xFF\xFE") => mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($text, "\xFE\xFF") => mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE'),
            mb_check_encoding($text, 'UTF-8') => $text,
            default => mb_convert_encoding($text, 'UTF-8', 'Windows-1252'),
        };
    }

    /**
     * Uniform line endings, no invisible control characters, no trailing spaces
     * on a line and at most one empty line in a row.
     */
    private static function clean(string $text): string
    {
        // A PDF can yield invalid characters; without this preg_replace returns null.
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+$/mu', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
