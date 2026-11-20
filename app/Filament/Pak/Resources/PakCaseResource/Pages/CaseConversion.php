<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Domain\Pak\Rules\ConversionSaveability;
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
        $this->resetErrorBag();
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

        $canCalculate = $this->canCalculate();
        if (! $canCalculate) {
            $this->clearPreview();
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
