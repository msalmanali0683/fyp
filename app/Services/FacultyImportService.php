<?php

namespace App\Services;

use App\Models\Program;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacultyImportService
{
    public const DEFAULT_PASSWORD = 'password';

    /**
     * @return array{
     *     created_count: int,
     *     error_count: int,
     *     created: array<int, array{name: string, email: string, sap_id: string}>,
     *     errors: array<int, array{row: int, message: string, sap_id: ?string, name: ?string, email: ?string}>
     * }
     */
    public function import(UploadedFile $file, ?int $programId = null): array
    {
        $rows = $this->readRows($file);

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file is empty.'],
            ]);
        }

        $headerRow = array_shift($rows);
        $columnMap = $this->mapHeaders($headerRow);

        foreach (['sap_id', 'name', 'email'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columnMap)) {
                throw ValidationException::withMessages([
                    'file' => ['The sheet must include SAP ID, Name, and Email columns.'],
                ]);
            }
        }

        $created = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $payload = [
                'registration_no' => $this->cellValue($row, $columnMap['sap_id']),
                'name' => $this->cellValue($row, $columnMap['name']),
                'email' => $this->cellValue($row, $columnMap['email']),
            ];

            $validator = Validator::make($payload, [
                'registration_no' => ['required', 'string', 'max:50'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ]);

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => implode(' ', $validator->errors()->all()),
                    'sap_id' => $payload['registration_no'] ?: null,
                    'name' => $payload['name'] ?: null,
                    'email' => $payload['email'] ?: null,
                ];

                continue;
            }

            $user = User::create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'registration_no' => $payload['registration_no'],
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'status' => 'active',
            ]);

            $user->assignRole('faculty');

            if ($programId) {
                $program = Program::with('department')->find($programId);
                if ($program) {
                    app(ProgramScopeService::class)->assignUserProgram($user, $program);
                }
            }

            $created[] = [
                'name' => $user->name,
                'email' => $user->email,
                'sap_id' => $user->registration_no,
            ];
        }

        return [
            'created_count' => count($created),
            'error_count' => count($errors),
            'created' => $created,
            'errors' => $errors,
        ];
    }

    public function templateResponse(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['SAP ID', 'Name', 'Email'],
            ['FAC-001', 'Dr. Example Faculty', 'faculty.example@fyp.com'],
        ]);

        foreach (range('A', 'C') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 'faculty-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function readRows(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($file->getRealPath());

        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int>
     */
    protected function mapHeaders(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = $index;
        }

        return $map;
    }

    protected function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? '';
        $header = trim($header, '_');

        return match ($header) {
            'sap_id', 'sapid', 'sap', 'registration_no', 'registration_number', 'registration' => 'sap_id',
            'name', 'faculty_name', 'full_name', 'faculty' => 'name',
            'email', 'email_address', 'e_mail' => 'email',
            default => $header,
        };
    }

    protected function cellValue(array $row, int $index): string
    {
        $value = $row[$index] ?? '';

        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return trim((string) $value);
        }

        return trim((string) ($value ?? ''));
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
