<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Throwable;

class ApplicantImportParser
{
    /** Only these columns are imported; all others are ignored. */
    private const ALLOWED_KEYS = [
        'applicant_name',
        'program_desire',
        'application_date',
        'time',
        'gmail',
    ];

    private const ALIASES = [
        'applicant_name' => 'applicant_name',
        'name' => 'applicant_name',
        'program_desire' => 'program_desire',
        'desired_program' => 'program_desire',
        'program' => 'program_desire',
        'application_date' => 'application_date',
        'examination_date' => 'application_date',
        'exam_date' => 'application_date',
        'time' => 'time',
        'preferred_exam_time' => 'time',
        'exam_time' => 'time',
        'examination_time' => 'time',
        'desire_time' => 'time',
        'desiretime' => 'time',
        'desired_time' => 'time',
        'preferred_time' => 'time',
        'gmail' => 'gmail',
        'email' => 'gmail',
        'g_mail' => 'gmail',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $path = $file->getRealPath();

        if ($path === false || $path === '') {
            throw new InvalidArgumentException('Unable to read uploaded file.');
        }

        $matrix = match ($extension) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            'xls' => $this->readXls($path),
            default => throw new InvalidArgumentException(
                'Unsupported file type. Please upload a .csv, .xlsx, or .xls file.'
            ),
        };

        return $this->matrixToAssocRows($matrix);
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
        $xlsx = SimpleXLSX::parse($path);
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
        $xls = SimpleXLS::parse($path);
        if ($xls === false) {
            throw new InvalidArgumentException(
                'Unable to read Excel (.xls) file: '.(SimpleXLS::parseError() ?: 'unknown error')
            );
        }

        return $xls->rows();
    }

    /**
     * @param  list<list<mixed>>  $matrix
     * @return list<array<string, mixed>>
     */
    private function matrixToAssocRows(array $matrix): array
    {
        if ($matrix === []) {
            throw new InvalidArgumentException('File is empty.');
        }

        $header = array_shift($matrix);
        if (! is_array($header) || count(array_filter($header, fn ($v) => trim((string) $v) !== '')) === 0) {
            throw new InvalidArgumentException('Header row is missing.');
        }

        $normalized = array_map(function ($col) {
            $col = strtolower(trim((string) $col));
            $col = preg_replace('/[^a-z0-9]+/', '_', $col) ?? $col;

            return trim($col, '_');
        }, $header);

        $rows = [];
        foreach ($matrix as $data) {
            if (! is_array($data)) {
                continue;
            }

            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $assoc = [];
            foreach ($normalized as $i => $key) {
                $mappedKey = self::ALIASES[$key] ?? null;
                if ($mappedKey === null || ! in_array($mappedKey, self::ALLOWED_KEYS, true)) {
                    continue;
                }
                $value = $data[$i] ?? null;
                $assoc[$mappedKey] = $this->stringifyCell($value);
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    private function stringifyCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value) || is_int($value)) {
            // Excel often stores dates as serial numbers; leave numeric times/dates as string for Carbon later.
            if (is_float($value) && floor($value) != $value) {
                return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
            }

            return (string) $value;
        }

        try {
            return trim((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
