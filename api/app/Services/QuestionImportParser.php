<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Throwable;
use ZipArchive;

/**
 * Import multiple-choice questions from Excel, Word, Documents, or PDF
 * into a single exam subject/category.
 */
class QuestionImportParser
{
    public const FILE_TYPES = ['excel', 'word', 'documents', 'pdf'];

    /**
     * @return list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>
     */
    public function parse(UploadedFile $file, string $fileType): array
    {
        return $this->parseWithReport($file, $fileType)['questions'];
    }

    /**
     * @return array{questions: list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>, errors: list<array{row: int, message: string}>}
     */
    public function parseWithReport(UploadedFile $file, string $fileType): array
    {
        $fileType = strtolower(trim($fileType));
        if (! in_array($fileType, self::FILE_TYPES, true)) {
            throw new InvalidArgumentException('Choose a file type: Excel, Word, Documents, or PDF.');
        }

        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new InvalidArgumentException('Unable to read uploaded file.');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $this->assertExtensionMatchesType($extension, $fileType);

        $errors = [];
        $questions = match ($fileType) {
            'excel' => $this->parseSpreadsheet($path, $extension, $errors),
            'word' => $this->parseWord($path, $extension),
            'documents' => $extension === 'csv'
                ? $this->parseSpreadsheet($path, $extension, $errors)
                : $this->parseDocument($path, $extension),
            'pdf' => $this->parsePdf($path),
        };

        if ($questions === [] && $errors === []) {
            throw new InvalidArgumentException(
                'No questions found. For Excel use columns: stem, option_a, option_b, option_c, option_d, correct_answer. For Word/PDF/Documents use numbered items with A–D choices.'
            );
        }

        return ['questions' => $questions, 'errors' => $errors];
    }

    private function assertExtensionMatchesType(string $extension, string $fileType): void
    {
        $allowed = match ($fileType) {
            'excel' => ['xlsx', 'xls', 'csv'],
            'word' => ['docx'],
            'documents' => ['txt', 'csv', 'rtf', 'doc'],
            'pdf' => ['pdf'],
        };

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            $label = match ($fileType) {
                'excel' => '.xlsx, .xls, or .csv',
                'word' => '.docx',
                'documents' => '.txt, .csv, or .rtf',
                'pdf' => '.pdf',
            };
            throw new InvalidArgumentException("For {$fileType}, please upload {$label}.");
        }

        if ($fileType === 'documents' && $extension === 'doc') {
            throw new InvalidArgumentException(
                'Legacy .doc is not supported. Save as .docx (Word) or .txt/.csv (Documents) and try again.'
            );
        }
    }

    /**
     * @return list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>
     */
    private function parseSpreadsheet(string $path, string $extension, ?array &$rowErrors = null): array
    {
        $matrix = match ($extension) {
            'csv' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            'xls' => $this->readXls($path),
            default => throw new InvalidArgumentException('Unsupported spreadsheet type.'),
        };

        if ($matrix === []) {
            return [];
        }

        $header = array_map(fn ($cell) => $this->normalizeHeader((string) $cell), $matrix[0]);
        $hasHeader = $this->looksLikeHeader($header);

        if ($hasHeader) {
            $rows = array_slice($matrix, 1);
            $questions = [];
            foreach ($rows as $index => $row) {
                $assoc = [];
                foreach ($header as $i => $key) {
                    if ($key === '') {
                        continue;
                    }
                    $assoc[$key] = trim((string) ($row[$i] ?? ''));
                }
                $parsed = $this->rowToQuestion($assoc);
                if ($parsed !== null) {
                    $questions[] = $parsed;
                } elseif (count(array_filter($row, static fn ($cell) => trim((string) $cell) !== '')) > 0) {
                    $rowErrors[] = ['row' => $index + 2, 'message' => 'Question stem, at least two options, and a valid answer are required.'];
                }
            }

            return $questions;
        }

        // No header: treat as stem | A | B | C | D | correct
        $questions = [];
        foreach ($matrix as $index => $row) {
            $stem = trim((string) ($row[0] ?? ''));
            if ($stem === '' || strlen($stem) < 5) {
                if (count(array_filter($row, static fn ($cell) => trim((string) $cell) !== '')) > 0) {
                    $rowErrors[] = ['row' => $index + 1, 'message' => 'Question stem must contain at least five characters.'];
                }
                continue;
            }
            $options = [];
            foreach ([1, 2, 3, 4] as $i) {
                $opt = trim((string) ($row[$i] ?? ''));
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
            if (count($options) < 2) {
                $inline = $this->parseInlineMcqBlob($stem);
                if ($inline !== null) {
                    $questions[] = [
                        'stem' => $inline['stem'],
                        'options' => $inline['options'],
                        'correct_answer' => strtoupper((string) ($row[5] ?? $inline['correct_answer'] ?? 'A')),
                        'difficulty' => 'medium',
                    ];
                }
                $rowErrors[] = ['row' => $index + 1, 'message' => 'At least two answer options are required.'];
                continue;
            }
            $correct = strtoupper(trim((string) ($row[5] ?? 'A')));
            if (! in_array($correct, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
                $correct = 'A';
            }
            $questions[] = [
                'stem' => $stem,
                'options' => $options,
                'correct_answer' => $correct,
                'difficulty' => 'medium',
            ];
        }

        return $questions;
    }

    /**
     * @param  array<string, string>  $assoc
     * @return array{stem: string, options: list<string>, correct_answer: string, difficulty: string}|null
     */
    private function rowToQuestion(array $assoc): ?array
    {
        $stem = trim((string) ($assoc['stem'] ?? $assoc['question'] ?? $assoc['item'] ?? ''));
        if (strlen($stem) < 5) {
            return null;
        }

        $options = [];
        foreach (['option_a', 'a', 'option_b', 'b', 'option_c', 'c', 'option_d', 'd', 'option_e', 'e'] as $key) {
            if (! empty($assoc[$key])) {
                $options[] = $assoc[$key];
            }
        }

        // Also support option1..option4
        if ($options === []) {
            for ($i = 1; $i <= 6; $i++) {
                if (! empty($assoc['option'.$i])) {
                    $options[] = $assoc['option'.$i];
                }
            }
        }

        // Exam worksheets often put the whole item in the stem cell:
        // "80% of 65=  A. 530% C. 53%  B.5/2% D. 5.3%"
        if (count($options) < 2) {
            $inline = $this->parseInlineMcqBlob($stem);
            if ($inline !== null) {
                $stem = $inline['stem'];
                $options = $inline['options'];
                if (($assoc['correct_answer'] ?? '') === '' && isset($inline['correct_answer'])) {
                    $assoc['correct_answer'] = $inline['correct_answer'];
                }
            }
        }

        if (count($options) < 2) {
            return null;
        }

        $correct = strtoupper(trim((string) ($assoc['correct_answer'] ?? $assoc['answer'] ?? $assoc['correct'] ?? 'A')));
        if (! in_array($correct, range('A', 'F'), true)) {
            // Accept full option text as the answer key when letters are missing.
            foreach ($options as $index => $text) {
                if (strcasecmp(trim($text), $correct) === 0) {
                    $correct = chr(65 + $index);
                    break;
                }
            }
        }
        if (! in_array($correct, range('A', 'F'), true)) {
            return null;
        }

        $difficulty = strtolower($assoc['difficulty'] ?? 'medium');
        if (! in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $difficulty = 'medium';
        }

        return [
            'stem' => $stem,
            'options' => $options,
            'correct_answer' => $correct,
            'difficulty' => $difficulty,
        ];
    }

    /**
     * Split a single-cell exam item that embeds A–D choices (often two-column layout).
     *
     * @return array{stem: string, options: list<string>, correct_answer?: string}|null
     */
    private function parseInlineMcqBlob(string $blob): ?array
    {
        $blob = trim(preg_replace('/\s+/u', ' ', $blob) ?? $blob);
        if ($blob === '') {
            return null;
        }

        // Find the first option marker (A. / A) / a.)
        if (! preg_match('/^(.*?)\s+([A-Da-d])\s*[\.\)]\s*(.+)$/u', $blob, $m)) {
            return null;
        }

        $stem = trim($m[1]);
        $optionsPart = $m[2].'. '.$m[3];

        if (strlen($stem) < 3) {
            return null;
        }

        if (! preg_match_all('/([A-Da-d])\s*[\.\)]\s*(.*?)(?=\s+[A-Da-d]\s*[\.\)]\s*|$)/u', $optionsPart, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $byLetter = [];
        foreach ($matches as $match) {
            $letter = strtoupper($match[1]);
            $text = trim($match[2]);
            if ($text !== '') {
                $byLetter[$letter] = $text;
            }
        }

        $ordered = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
            if (! empty($byLetter[$letter])) {
                $ordered[] = $byLetter[$letter];
            }
        }

        if (count($ordered) < 2) {
            return null;
        }

        return [
            'stem' => $stem,
            'options' => $ordered,
            'correct_answer' => 'A',
        ];
    }

    /**
     * @param  list<string>  $header
     */
    private function looksLikeHeader(array $header): bool
    {
        $joined = implode('|', $header);

        return str_contains($joined, 'stem')
            || str_contains($joined, 'question')
            || str_contains($joined, 'option_a')
            || str_contains($joined, 'optiona');
    }

    private function normalizeHeader(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
        $key = trim($key, '_');

        return match ($key) {
            'question', 'question_text', 'item', 'prompt' => 'stem',
            'stem' => 'stem',
            'option_a', 'optiona', 'choice_a', 'a' => 'option_a',
            'option_b', 'optionb', 'choice_b', 'b' => 'option_b',
            'option_c', 'optionc', 'choice_c', 'c' => 'option_c',
            'option_d', 'optiond', 'choice_d', 'd' => 'option_d',
            'option_e', 'optione', 'choice_e', 'e' => 'option_e',
            'correct', 'answer', 'correct_answer', 'key' => 'correct_answer',
            'difficulty', 'level' => 'difficulty',
            default => $key,
        };
    }

    /**
     * @return list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>
     */
    private function parseWord(string $path, string $extension): array
    {
        if ($extension !== 'docx') {
            throw new InvalidArgumentException('Word import supports .docx only.');
        }

        $text = $this->extractDocxText($path);

        return $this->parseMcqText($text);
    }

    /**
     * @return list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>
     */
    private function parseDocument(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            return $this->parseSpreadsheet($path, 'csv');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException('Unable to read document file.');
        }

        if ($extension === 'rtf') {
            $raw = $this->stripRtf($raw);
        }

        return $this->parseMcqText($raw);
    }

    /**
     * @return list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>
     */
    private function parsePdf(string $path): array
    {
        $text = $this->extractPdfTextFromFile($path);
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'Could not extract text from this PDF. Export as Word (.docx) or Excel (.xlsx) and import again.'
            );
        }

        return $this->parseMcqText($text);
    }

    /**
     * Prefer smalot/pdfparser (handles ToUnicode fonts used by exam PDFs).
     * Fall back to a lightweight binary scrape when the package cannot read the file.
     */
    private function extractPdfTextFromFile(string $path): string
    {
        try {
            if (class_exists(\Smalot\PdfParser\Parser::class)) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                $text = $pdf->getText();
                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        } catch (Throwable $e) {
            // Fall through to binary scrape.
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('Unable to read PDF file.');
        }

        return $this->extractPdfText($binary);
    }

    private function extractDocxText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open Word (.docx) file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || $xml === '') {
            throw new InvalidArgumentException('Word file has no readable document content.');
        }

        $xml = str_replace(['</w:p>', '</w:tr>', '</w:tbl>'], "\n", $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return preg_replace("/[ \t]+/", ' ', $text) ?? $text;
    }

    private function extractPdfText(string $binary): string
    {
        $chunks = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $binary, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $decoded, $literals)) {
                    foreach ($literals[0] as $literal) {
                        $inner = substr($literal, 1, -1);
                        $inner = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'], ["\n", "\r", "\t", '(', ')', '\\'], $inner);
                        if (preg_match('/[A-Za-z0-9]/', $inner)) {
                            $chunks[] = $inner;
                        }
                    }
                }
                if (preg_match_all('/\[\s*((?:<[^>]+>|\([^)]*\)|\s)+)\]\s*TJ/s', $decoded, $tjMatches)) {
                    foreach ($tjMatches[1] as $part) {
                        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $part, $bits)) {
                            foreach ($bits[0] as $bit) {
                                $inner = substr($bit, 1, -1);
                                $chunks[] = $inner;
                            }
                        }
                    }
                }
            }
        }

        // Fallback: raw printable runs
        if ($chunks === [] && preg_match_all('/[\x20-\x7E]{4,}/', $binary, $runs)) {
            foreach ($runs[0] as $run) {
                if (! str_starts_with($run, '/')) {
                    $chunks[] = $run;
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function stripRtf(string $rtf): string
    {
        $text = preg_replace('/\{\\\*?[^{}]+\}|\\\?[a-z]+\d* ?/i', '', $rtf) ?? $rtf;
        $text = str_replace(['\\par', '\\line'], "\n", $text);
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Parse numbered MCQ text blocks.
     *
     * @return list<array{stem: string, options: list<string>, correct_answer: string, difficulty: string}>
     */
    private function parseMcqText(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '  '], $text);
        // Soft-wrap exam PDFs: break before numbered stems (41.) / 42)) and choice letters (A. / B.)
        // Avoid bare "N." so years like 1935. are not split.
        $text = preg_replace('/\s+(?=(\d{1,3}\)\s))/u', "\n", $text) ?? $text;
        $text = preg_replace('/\s+(?=(\d{1,3}\.\)\s))/u', "\n", $text) ?? $text;
        $text = preg_replace('/\s+(?=([A-Da-d]\.\s))/u', "\n", $text) ?? $text;

        $lines = preg_split("/\n+/", $text) ?: [];
        $lines = array_values(array_filter(array_map(
            static fn ($line) => trim(preg_replace('/\s+/u', ' ', (string) $line) ?? (string) $line),
            $lines
        ), static fn ($line) => $line !== '' && ! preg_match('/^(GENERL|GENERAL|MATHEMATICS|DIRECTIONS|Select the correct)/i', $line)));

        $questions = [];
        $stem = null;
        $options = [];
        $currentOption = null;
        $pendingOrphan = null;

        $isQuestionStart = static function (string $line): bool {
            // 41.)  42)  61.  7 -
            return (bool) preg_match('/^\d{1,3}\s*(?:[.\)]+|\-)\s+\S/u', $line);
        };

        $commit = function () use (&$questions, &$stem, &$options, &$currentOption, &$pendingOrphan) {
            if ($pendingOrphan !== null && $stem !== null) {
                if (! isset($options['A']) && isset($options['B'])) {
                    $options['A'] = $pendingOrphan;
                } else {
                    $stem .= ' '.$pendingOrphan;
                }
                $pendingOrphan = null;
            }

            if ($stem === null) {
                $options = [];
                $currentOption = null;

                return;
            }

            $ordered = [];
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
                if (! empty($options[$letter])) {
                    $ordered[] = trim($options[$letter]);
                }
            }

            $cleanStem = trim($stem);
            // Drop a leading leftover number if soft-wrap failed earlier.
            $cleanStem = preg_replace('/^\d{1,3}\s*(?:[.\)]+|\-)\s*/u', '', $cleanStem) ?? $cleanStem;
            if (strlen($cleanStem) >= 5 && count($ordered) >= 2) {
                $ordered = array_map(function (string $opt): string {
                    $opt = preg_replace('/\s*-END-.*$/iu', '', $opt) ?? $opt;
                    $opt = preg_replace('/\s+TAGOLOAN\b.*$/iu', '', $opt) ?? $opt;

                    return trim($opt);
                }, $ordered);

                $questions[] = [
                    'stem' => $cleanStem,
                    'options' => $ordered,
                    'correct_answer' => 'A',
                    'difficulty' => 'medium',
                ];
            }

            $stem = null;
            $options = [];
            $currentOption = null;
            $pendingOrphan = null;
        };

        foreach ($lines as $line) {
            // Two-column option rows: "A. Diamond C. Nail" / "B. Brass D. Alloy"
            // Only treat as options when the line starts with a letter choice (not a numbered stem).
            if (
                ! $isQuestionStart($line)
                && preg_match_all('/\b([A-Da-d])\s*[.\)]\s+/u', $line, $letterHits)
                && count($letterHits[1]) >= 2
            ) {
                $parts = preg_split('/(?=\b[A-Da-d]\s*[.\)]\s+)/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    if (preg_match('/^([A-Da-d])\s*[.\)]\s*(.*)$/u', $part, $om)) {
                        $letter = strtoupper($om[1]);
                        $optText = trim($om[2]);
                        if ($optText !== '') {
                            // Strip trailing exam footers glued into the last choice.
                            $optText = preg_replace('/\s*-END-.*$/iu', '', $optText) ?? $optText;
                            $optText = trim($optText);
                            $currentOption = $letter;
                            $options[$currentOption] = $optText;
                        }
                    }
                }
                continue;
            }

            if ($isQuestionStart($line) && preg_match('/^(\d{1,3})\s*(?:[.\)]+|\-)\s*(.*)$/u', $line, $m)) {
                $rest = trim($m[2]);
                // Numbered stem that already embeds choices on the same line
                $inline = $rest !== '' ? $this->parseInlineMcqBlob($rest) : null;
                if ($inline !== null) {
                    $commit();
                    $questions[] = [
                        'stem' => $inline['stem'],
                        'options' => $inline['options'],
                        'correct_answer' => $inline['correct_answer'] ?? 'A',
                        'difficulty' => 'medium',
                    ];
                    $stem = null;
                    $options = [];
                    $currentOption = null;
                    $pendingOrphan = null;
                    continue;
                }
                $commit();
                $stem = $rest;
                continue;
            }

            if (preg_match('/^([A-Da-d])\s*[.\)]\s*(.*)$/u', $line, $m)) {
                $letter = strtoupper($m[1]);
                $optText = trim($m[2]);
                $optText = preg_replace('/\s*-END-.*$/iu', '', $optText) ?? $optText;
                $optText = trim(preg_replace('/\s+TAGOLOAN\b.*$/iu', '', $optText) ?? $optText);

                // Same-line trailing partner: "A. Salt C. Sodium Chloride"
                if (preg_match('/^(.*?)\s+([A-Da-d])\s*[.\)]\s*(.+)$/u', $optText, $pair)) {
                    $options[$letter] = trim($pair[1]);
                    $partner = trim(preg_replace('/\s*-END-.*$/iu', '', $pair[3]) ?? $pair[3]);
                    $options[strtoupper($pair[2])] = $partner;
                    $currentOption = strtoupper($pair[2]);
                    continue;
                }

                if ($letter === 'A' && $pendingOrphan !== null && $stem !== null) {
                    $stem .= ' '.$pendingOrphan;
                    $pendingOrphan = null;
                }
                if ($letter === 'B' && ! isset($options['A']) && $pendingOrphan !== null) {
                    $options['A'] = $pendingOrphan;
                    $pendingOrphan = null;
                }

                $currentOption = $letter;
                $options[$currentOption] = $optText;
                continue;
            }

            if ($currentOption !== null && isset($options[$currentOption])) {
                if (preg_match('/^(-END-|TAGOLOAN|Baluarte)/i', $line)) {
                    continue;
                }
                $options[$currentOption] .= ' '.trim($line);
            } elseif ($stem !== null && $options === [] && $currentOption === null) {
                // Continuation of a multi-line stem (common in exam PDFs).
                $stem .= ' '.trim($line);
            } elseif ($stem !== null) {
                if (preg_match('/^(-END-|TAGOLOAN|Baluarte)/i', $line)) {
                    continue;
                }
                $stem .= ' '.trim($line);
            }
        }

        $commit();

        return $questions;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException('Unable to read CSV file.');
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readXlsx(string $path): array
    {
        try {
            $xlsx = SimpleXLSX::parse($path);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Unable to read Excel (.xlsx) file: '.$e->getMessage());
        }

        if ($xlsx === false) {
            throw new InvalidArgumentException(
                'Unable to read Excel (.xlsx) file: '.(SimpleXLSX::parseError() ?: 'unknown error')
            );
        }

        return $xlsx->rows();
    }

    /**
     * @return list<list<mixed>>
     */
    private function readXls(string $path): array
    {
        try {
            $xls = SimpleXLS::parse($path);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Unable to read Excel (.xls) file: '.$e->getMessage());
        }

        if ($xls === false) {
            throw new InvalidArgumentException(
                'Unable to read Excel (.xls) file: '.(SimpleXLS::parseError() ?: 'unknown error')
            );
        }

        return $xls->rows();
    }
}
