<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProposalSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SessionStudentImportService
{
    public function __construct(
        private ProgramScopeService $programScope,
    ) {
    }

    /**
     * @return array{
     *     created_count: int,
     *     updated_count: int,
     *     error_count: int,
     *     created: array<int, array{name: string, email: string, sap_id: string}>,
     *     updated: array<int, array{name: string, email: string, sap_id: string}>,
     *     errors: array<int, array{row: int, message: string, sap_id: ?string, name: ?string, email: ?string}>
     * }
     */
    public function import(ProposalSession $session, UploadedFile $file, User $actor): array
    {
        $this->assertCanImport($session, $actor);

        $session->loadMissing('program.department');
        $program = $session->program;

        if (! $program) {
            throw ValidationException::withMessages([
                'session' => ['This session is not linked to a program.'],
            ]);
        }

        $rows = $this->readRows($file);

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file is empty.'],
            ]);
        }

        $headerRow = array_shift($rows);
        $columnMap = $this->mapHeaders($headerRow);

        foreach (['sap_id', 'name', 'email', 'password'] as $requiredColumn) {
            if (! array_key_exists($requiredColumn, $columnMap)) {
                throw ValidationException::withMessages([
                    'file' => ['The sheet must include SAP ID, Name, Email, and Password columns.'],
                ]);
            }
        }

        $created = [];
        $updated = [];
        $skipped = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $payload = [
                'registration_no' => $this->cellValue($row, $columnMap['sap_id']),
                'name' => $this->cellValue($row, $columnMap['name']),
                'email' => strtolower($this->cellValue($row, $columnMap['email'])),
                'password' => $this->cellValue($row, $columnMap['password']),
                'phone' => array_key_exists('phone', $columnMap)
                    ? $this->cellValue($row, $columnMap['phone'])
                    : null,
                'father_name' => array_key_exists('father_name', $columnMap)
                    ? $this->cellValue($row, $columnMap['father_name'])
                    : null,
            ];

            $alreadyCleared = $this->findClearedStudent($payload['email'], $payload['registration_no']);

            if ($alreadyCleared) {
                $skipped[] = [
                    'name' => $alreadyCleared->name,
                    'email' => $alreadyCleared->email,
                    'sap_id' => $alreadyCleared->registration_no,
                ];

                continue;
            }

            $validator = Validator::make($payload, [
                'registration_no' => ['required', 'string', 'max:50'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
                'phone' => ['nullable', 'string', 'max:20'],
                'father_name' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validator->fails()) {
                $errors[] = $this->errorRow($rowNumber, $payload, implode(' ', $validator->errors()->all()));

                continue;
            }

            try {
                $result = DB::transaction(function () use ($payload, $program, $session) {
                    return $this->upsertStudent($payload, $program, $session);
                });
            } catch (ValidationException $e) {
                $errors[] = $this->errorRow(
                    $rowNumber,
                    $payload,
                    collect($e->errors())->flatten()->first() ?? 'Unable to import this student.'
                );

                continue;
            }

            $entry = [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'sap_id' => $payload['registration_no'],
            ];

            if ($result === 'created') {
                $created[] = $entry;
            } else {
                $updated[] = $entry;
            }
        }

        return [
            'created_count' => count($created),
            'updated_count' => count($updated),
            'skipped_count' => count($skipped),
            'error_count' => count($errors),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function templateResponse(ProposalSession $session): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Students');
        $sheet->fromArray([
            ['SAP ID', 'Name', 'Email', 'Password', 'Phone', 'Father Name'],
            ['SP26-001', 'Example Student', 'student.example@fyp.com', 'Student@123', '03001234567', 'Example Father'],
        ]);

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = strtolower($session->code).'-students-import-template.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function assertCanImport(ProposalSession $session, User $actor): void
    {
        if (! \App\Support\FypProposal::canManageProposalSessions($actor)) {
            throw ValidationException::withMessages(['session' => ['Unauthorized to import students for this session.']]);
        }

        if (! $this->programScope->canAccessProgram($actor, (int) $session->program_id)) {
            throw ValidationException::withMessages(['session' => ['Unauthorized to import students for this session.']]);
        }

        if ($session->status === 'archived') {
            throw ValidationException::withMessages([
                'session' => ['Students cannot be imported into an archived session.'],
            ]);
        }

        if (! $session->is_submission_open) {
            throw ValidationException::withMessages([
                'session' => ['Open the session before uploading the student list.'],
            ]);
        }
    }

    protected function upsertStudent(array $payload, Program $program, ProposalSession $session): string
    {
        $existingByEmail = User::query()->where('email', $payload['email'])->first();
        $existingByReg = User::query()->where('registration_no', $payload['registration_no'])->first();

        if ($existingByEmail && $existingByReg && $existingByEmail->id !== $existingByReg->id) {
            throw ValidationException::withMessages([
                'email' => ["{$payload['email']} and SAP ID {$payload['registration_no']} belong to different accounts."],
            ]);
        }

        $existing = $existingByEmail ?? $existingByReg;
        $sessionCode = strtoupper(trim($session->code));

        if ($existing) {
            if (! $existing->hasRole('student')) {
                throw ValidationException::withMessages([
                    'email' => ["{$payload['email']} already belongs to a non-student account."],
                ]);
            }

            if ($existing->program_id && (int) $existing->program_id !== (int) $program->id) {
                throw ValidationException::withMessages([
                    'email' => ["{$payload['email']} belongs to a different program and cannot be moved by session import."],
                ]);
            }

            if ($this->hasClearedProposalPhase($existing)) {
                return 'skipped';
            }

            $existing->update([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'registration_no' => $payload['registration_no'],
                'password' => Hash::make($payload['password']),
                'phone' => $payload['phone'] ?: $existing->phone,
                'father_name' => $payload['father_name'] ?: $existing->father_name,
                'status' => 'active',
                'program_id' => $program->id,
                'department_id' => $program->department_id,
                'program' => $program->name,
                'department' => $program->department?->name,
                'session' => $sessionCode,
                'is_proposal_enrolled' => true,
            ]);

            $this->programScope->assignUserProgram($existing, $program);

            return 'updated';
        }

        $user = User::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'registration_no' => $payload['registration_no'],
            'password' => Hash::make($payload['password']),
            'phone' => $payload['phone'] ?: null,
            'father_name' => $payload['father_name'] ?: null,
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'program' => $program->name,
            'department' => $program->department?->name,
            'session' => $sessionCode,
            'is_proposal_enrolled' => true,
        ]);

        $user->assignRole('student');
        $this->programScope->assignUserProgram($user, $program);

        return 'created';
    }

    /**
     * A student who already has a project that has moved past the proposal
     * phase (i.e. the proposal was approved) is left untouched by re-imports:
     * no password reset, no program/session overwrite. Only students who
     * haven't cleared the proposal phase yet (no project, or a project still
     * sitting in the proposal phase) get created/updated by the import.
     */
    protected function hasClearedProposalPhase(User $user): bool
    {
        $project = $user->studentProject();

        return $project !== null && $project->current_phase !== 'proposal';
    }

    /**
     * Looked up ahead of full row validation so an already-cleared student's
     * row can be skipped without demanding a fresh password for them.
     */
    protected function findClearedStudent(string $email, string $registrationNo): ?User
    {
        $existing = ($email !== '' ? User::query()->where('email', $email)->first() : null)
            ?? ($registrationNo !== '' ? User::query()->where('registration_no', $registrationNo)->first() : null);

        if (! $existing || ! $existing->hasRole('student')) {
            return null;
        }

        return $this->hasClearedProposalPhase($existing) ? $existing : null;
    }

    protected function errorRow(int $rowNumber, array $payload, string $message): array
    {
        return [
            'row' => $rowNumber,
            'message' => $message,
            'sap_id' => $payload['registration_no'] ?: null,
            'name' => $payload['name'] ?: null,
            'email' => $payload['email'] ?: null,
        ];
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
            'name', 'student_name', 'full_name', 'student' => 'name',
            'email', 'email_address', 'e_mail' => 'email',
            'password', 'pass', 'login_password', 'student_password' => 'password',
            'phone', 'mobile', 'contact', 'phone_number' => 'phone',
            'father_name', 'father', 'guardian_name' => 'father_name',
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
