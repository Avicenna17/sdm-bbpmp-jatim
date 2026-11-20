<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Domain\Pak\Rules\ConversionSaveability;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Filament\Pak\Resources\PakCaseResource;
use App\Filament\Pak\Support\ConversionPresenter;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CaseConversion extends Page
{
    protected static string $resource = PakCaseResource::class;

    protected static string $view = 'filament.pak.case-conversion';

    protected static ?string $title = 'Konversi tersimpan';

    #[Locked]
    public int $caseId;

    #[Url(as: 'run')]
    public ?int $runId = null;

    #[Locked]
    public ?int $displayedRunId = null;

    #[Locked]
    public array $assessmentDisplay = [];

    #[Locked]
    public ?int $assessedCaseId = null;

    #[Locked]
    public ?int $assessedRunId = null;

    #[Locked]
    public ?string $reviewExpectedRunStatus = null;

    #[Locked]
    public ?int $reviewExpectedCaseLockVersion = null;

    #[Locked]
    public ?int $reviewExpectedAssessmentSequence = null;

    #[Locked]
    public ?string $reviewExpectedAssessmentFingerprint = null;

    public string $reviewReason = '';

    public function updatedRunId(): void
    {
        $this->clearAssessment();
        // Do not permit an action in the same request to use the previously displayed run.
        $this->displayedRunId = null;
    }

    private function clearAssessment(bool $clearReason = true): void
    {
        $this->assessmentDisplay = [];
        $this->assessedCaseId = $this->assessedRunId = null;
        $this->reviewExpectedRunStatus = $this->reviewExpectedAssessmentFingerprint = null;
        $this->reviewExpectedCaseLockVersion = $this->reviewExpectedAssessmentSequence = null;
        if ($clearReason) {
            $this->reviewReason = '';
        }
        $this->resetErrorBag('assessment');
        $this->resetErrorBag('review');
    }

    private function authorizeAssessmentAction(): void
    {
        if (! $this->canCalculate()) {
            $this->clearAssessment();
            abort(403);
        }
    }

    public function assessConversion(): void
    {
        $this->authorizeAssessmentAction();
        $this->clearAssessment();
        if ($this->displayedRunId === null) {
            $this->addError('assessment', 'Pilih run tersimpan sebelum menjalankan asesmen.');

            return;
        }
        // Pin the rendered identity, including a page originally opened using the latest alias.
        $this->runId = $this->displayedRunId;
        try {
            $assessment = app(ConversionAssessmentService::class)->assess(auth()->user(), ConversionAssessmentRequest::from([
                'pak_case_id' => $this->caseId, 'run_id' => $this->displayedRunId,
            ]));
            $this->assessmentDisplay = app(ConversionPresenter::class)->assessmentProjection($assessment);
            $case = app(ConversionRunQuery::class)->caseFor(auth()->user(), $this->caseId);
            if ($assessment->is_current_active && $case->status === 'DRAFT' && $case->finalized_at === null
                && ($assessment->observed_run_status === 'REQUIRES_REVIEW'
                    || ($assessment->observed_run_status === 'PREVIEW' && in_array($assessment->comparison, ['CHANGED', 'UNASSESSABLE'], true)))) {
                $this->assessedCaseId = $assessment->pak_case_id;
                $this->assessedRunId = $assessment->run_id;
                $this->reviewExpectedRunStatus = $assessment->observed_run_status;
                $this->reviewExpectedCaseLockVersion = $assessment->observed_case_lock_version;
                $this->reviewExpectedAssessmentSequence = $assessment->assessment_sequence;
                $this->reviewExpectedAssessmentFingerprint = $assessment->assessment_fingerprint;
            }
        } catch (ValidationException $error) {
            $this->assessmentValidation($error, 'assessment');
        } catch (HttpExceptionInterface $error) {
            $this->clearAssessment();
            throw $error;
        } catch (ConversionRuleException) {
            $this->addError('assessment', 'Asesmen belum dapat dilakukan karena konteks aturan tidak tersedia. Periksa konteks sebelum mencoba kembali.');
        } catch (Throwable) {
            $this->clearAssessment();
            $this->addError('assessment', 'Asesmen tidak dapat dijalankan. Silakan coba kembali secara eksplisit.');
        }
    }

    public function reviewConversion(): void
    {
        $this->authorizeAssessmentAction();
        $this->resetErrorBag('review');
        if ($this->assessedCaseId !== $this->caseId || $this->assessedRunId === null
            || $this->assessedRunId !== $this->displayedRunId || $this->reviewExpectedRunStatus === null
            || $this->reviewExpectedCaseLockVersion === null || $this->reviewExpectedAssessmentSequence === null
            || $this->reviewExpectedAssessmentFingerprint === null) {
            $this->clearAssessment(false);
            $this->addError('review', 'Jalankan asesmen baru pada run yang ditampilkan sebelum mencatat tinjauan.');

            return;
        }
        $this->runId = $this->displayedRunId;
        try {
            $run = app(ConversionReviewService::class)->assessReview(auth()->user(), ConversionReviewRequest::from([
                'pak_case_id' => $this->assessedCaseId,
                'run_id' => $this->assessedRunId,
                'expected_run_status' => $this->reviewExpectedRunStatus,
                'expected_case_lock_version' => $this->reviewExpectedCaseLockVersion,
                'expected_assessment_sequence' => $this->reviewExpectedAssessmentSequence,
                'expected_assessment_fingerprint' => $this->reviewExpectedAssessmentFingerprint,
                'reason' => $this->reviewReason,
            ]));
            $this->clearAssessment();
            $this->redirect(PakCaseResource::getUrl('conversion', ['record' => $this->caseId, 'run' => $run->id]));
        } catch (ValidationException $error) {
            $this->clearAssessment(false);
            $this->assessmentValidation($error, 'review');
        } catch (HttpExceptionInterface $error) {
            $this->clearAssessment();
            throw $error;
        } catch (ConversionRuleException) {
            $this->clearAssessment(false);
            $this->addError('review', 'Tinjauan belum dapat dicatat karena konteks aturan tidak tersedia. Jalankan asesmen baru.');
        } catch (Throwable) {
            $this->clearAssessment(false);
            $this->addError('review', 'Pencatatan tinjauan tidak dapat dikonfirmasi. Periksa run tersimpan dan jalankan asesmen baru sebelum tindakan berikutnya.');
        }
    }

    private function assessmentValidation(ValidationException $error, string $action): void
    {
        $messages = [
            'case' => 'Konteks case berubah atau tidak mengizinkan tinjauan.',
            'run' => 'Run tidak tersedia atau tidak lagi mengizinkan tinjauan.',
            'snapshot' => 'Rencana dependensi berubah.',
            'expected_run_status' => 'Status run berubah.',
            'expected_case_lock_version' => 'Versi case berubah.',
            'expected_assessment_sequence' => 'Urutan asesmen berubah.',
            'expected_assessment_fingerprint' => 'Keadaan yang dinilai telah berubah.',
            'review' => 'Run ini belum memenuhi ketentuan tinjauan.',
            'review_assessment' => 'Metadata tinjauan tidak dapat digunakan.',
            'reason' => 'Alasan tinjauan wajib diisi.',
        ];
        foreach (array_keys($error->errors()) as $field) {
            $this->addError($action, ($messages[$field] ?? 'Asesmen atau tinjauan belum dapat dilakukan.').' Jalankan asesmen baru sebelum mencatat tinjauan.');
        }
    }

    public string $requestedStart = '';

    public string $requestedEnd = '';

    #[Locked]
    public array $previewDisplay = [];

    #[Locked]
    public ?string $frozenRequestedStart = null;

    #[Locked]
    public ?string $frozenRequestedEnd = null;

    #[Locked]
    public ?int $expectedCaseLockVersion = null;

    #[Locked]
    public ?string $expectedDependencyFingerprint = null;

    #[Locked]
    public ?string $expectedSnapshotFingerprint = null;

    public function updatedRequestedStart(): void
    {
        $this->clearPreview();
    }

    public function updatedRequestedEnd(): void
    {
        $this->clearPreview();
    }

    private function clearPreview(): void
    {
        $this->previewDisplay = [];
        $this->frozenRequestedStart = $this->frozenRequestedEnd = null;
        $this->expectedCaseLockVersion = null;
        $this->expectedDependencyFingerprint = $this->expectedSnapshotFingerprint = null;
        foreach (['conversion', 'case', 'snapshot', 'requested_start', 'requested_end', 'requested_range'] as $field) {
            $this->resetErrorBag($field);
        }
    }

    private function canCalculate(): bool
    {
        foreach (ConversionService::PERMISSIONS as $permission) {
            if (! Access::allows(auth()->user(), $permission)) {
                return false;
            }
        }

        return true;
    }

    public function previewConversion(): void
    {
        $this->clearAssessment();
        abort_unless($this->canCalculate(), 403);
        $this->clearPreview();
        try {
            $preview = app(ConversionService::class)->preview(auth()->user(), new ConversionPreviewRequest(
                $this->caseId, $this->requestedStart, $this->requestedEnd,
            ));
            $status = null;
            try {
                $status = app(ConversionSaveability::class)->statusFor($preview)->value;
            } catch (ValidationException) {
                // A valid preview can be informative without being eligible for persistence.
            }
            $this->previewDisplay = app(ConversionPresenter::class)->previewProjection($preview, $status);
            if ($status !== null) {
                $this->frozenRequestedStart = $preview->request->requested_start;
                $this->frozenRequestedEnd = $preview->request->requested_end;
                $this->expectedCaseLockVersion = $preview->expected_case_version;
                $this->expectedDependencyFingerprint = $preview->dependency_fingerprint;
                $this->expectedSnapshotFingerprint = $preview->snapshot_fingerprint;
            }
        } catch (ValidationException $error) {
            $this->safeValidation($error);
        } catch (ConversionRuleException) {
            $this->addError('conversion', 'Aturan belum dapat digunakan. Periksa konteks aturan sebelum pratinjau kembali.');
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            $this->clearPreview();
            $this->addError('conversion', 'Pratinjau tidak dapat dijalankan. Silakan coba kembali secara eksplisit.');
        }
    }

    public function saveInitialConversion(): void
    {
        abort_unless($this->canCalculate(), 403);
        if ($this->expectedCaseLockVersion === null || $this->expectedDependencyFingerprint === null
            || $this->expectedSnapshotFingerprint === null || $this->frozenRequestedStart !== $this->requestedStart
            || $this->frozenRequestedEnd !== $this->requestedEnd) {
            $this->clearPreview();
            $this->addError('conversion', 'Jalankan pratinjau baru sebelum menyimpan.');

            return;
        }
        try {
            $request = ConversionSaveRequest::from([
                'pak_case_id' => $this->caseId,
                'requested_start' => $this->frozenRequestedStart,
                'requested_end' => $this->frozenRequestedEnd,
                'expected_case_lock_version' => $this->expectedCaseLockVersion,
                'expected_dependency_fingerprint' => $this->expectedDependencyFingerprint,
                'expected_snapshot_fingerprint' => $this->expectedSnapshotFingerprint,
            ]);
            $run = app(ConversionService::class)->saveInitial(auth()->user(), $request);
            $this->clearPreview();
            $this->clearAssessment();
            $this->redirect(PakCaseResource::getUrl('conversion', ['record' => $this->caseId, 'run' => $run->id]));
        } catch (ValidationException $error) {
            $this->clearPreview();
            $this->safeValidation($error);
        } catch (ConversionRuleException) {
            $this->clearPreview();
            $this->addError('conversion', 'Aturan berubah atau belum dapat digunakan. Jalankan pratinjau baru setelah pemeriksaan.');
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            $this->clearPreview();
            $this->addError('conversion', 'Penyimpanan tidak dapat dikonfirmasi. Periksa riwayat tersimpan sebelum tindakan berikutnya.');
        }
    }

    private function safeValidation(ValidationException $error): void
    {
        // Field whitelist only: never forward arbitrary exception payloads to the browser.
        $messages = [
            'case' => 'Case berubah, terkunci, atau sudah memiliki riwayat konversi. Periksa riwayat dan jalankan pratinjau baru bila sesuai.',
            'snapshot' => 'Sumber atau hasil perhitungan berubah. Jalankan pratinjau baru sebelum menyimpan.',
            'requested_start' => 'Tanggal awal tidak valid.',
            'requested_end' => 'Tanggal akhir tidak valid atau mendahului tanggal awal.',
            'requested_range' => 'Rentang permintaan harus berada di dalam periode case.',
        ];
        foreach (array_keys($error->errors()) as $field) {
            $this->addError(isset($messages[$field]) ? $field : 'conversion', $messages[$field] ?? 'Konversi belum dapat disimpan. Periksa konteks dan jalankan pratinjau baru.');
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ConversionRunQuery::allows(auth()->user());
    }

    public function mount(int|string $record): void
    {
        $case = app(ConversionRunQuery::class)->caseFor(auth()->user(), (int) $record);
        $this->caseId = $case->id;
    }

    protected function getViewData(): array
    {
        $query = app(ConversionRunQuery::class);
        $actor = auth()->user();
        $revisions = $query->revisions($actor, $this->caseId);
        $selected = $this->runId ?? $revisions->first()?->id;
        $url = fn (int $id) => PakCaseResource::getUrl('conversion', ['record' => $this->caseId, 'run' => $id]);
        $run = $selected === null ? null : $query->find($actor, $this->caseId, $selected);

        if ($this->displayedRunId !== $run?->id) {
            $this->clearAssessment();
            $this->displayedRunId = $run?->id;
        }

        $canCalculate = $this->canCalculate();
        if (! $canCalculate) {
            $this->clearPreview();
            $this->clearAssessment();
        }
        $case = $query->caseFor($actor, $this->caseId);

        return [
            'canCalculate' => $canCalculate,
            'canInitialSave' => $canCalculate && $revisions->isEmpty() && $case->status === 'DRAFT' && $case->finalized_at === null,
            'caseUrl' => PakCaseResource::getUrl('view', ['record' => $this->caseId]),
            'revisions' => $revisions->map(fn ($row) => ['label' => 'Revisi '.$row->revision_number.' · '.$row->status->value, 'url' => $url($row->id), 'selected' => $row->id === $selected])->all(),
            'saved' => $run === null ? null : app(ConversionPresenter::class)->present($actor, $run, $url),
        ];
    }
}
