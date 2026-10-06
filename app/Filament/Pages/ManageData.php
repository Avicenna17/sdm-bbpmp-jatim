<?php

namespace App\Filament\Pages;

use App\Domain\Import\ImportService;
use App\Domain\ReportingPeriod\PublishPeriodService;
use App\Models\ImportBatch;
use App\Models\ImportTemplateVersion;
use App\Models\ReportingPeriod;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class ManageData extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Periode dan Import Data';

    protected static ?string $title = 'Periode dan Import Data';

    protected static ?string $navigationGroup = 'Pengelolaan Data';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.manage-data';

    public ?int $periodId = null;

    public string $month = '';

    public string $source = 'PERSONNEL_DUK';

    public ?int $templateVersionId = null;

    public array $templateMapping = [];

    public string $ignoredColumns = '';

    public function updatedSource(): void
    {
        $this->templateVersionId = null;
        $this->templateMapping = [];
        $this->ignoredColumns = '';
    }

    public function updatedTemplateVersionId(): void
    {
        $this->templateMapping = [];
        $this->ignoredColumns = '';
    }

    /** @var UploadedFile|null */
    public $file = null;

    #[Locked]
    public ?int $batchId = null;

    public static function canAccess(): bool
    {
        return Gate::allows('period.view');
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
        $this->periodId = ReportingPeriod::orderByDesc('period_month')->value('id');
        if (request()->filled('batch')) {
            $this->inspect(request()->integer('batch'));
        }
    }

    public function updatedPeriodId(): void
    {
        $this->batchId = null;
        $this->resetValidation();
    }

    public function createPeriod(): void
    {
        Gate::authorize('period.create');
        $this->validate(['month' => 'required|date_format:Y-m']);
        $this->periodId = ReportingPeriod::firstOrCreate(['period_month' => $this->month.'-01'])->id;
        $this->batchId = null;
        Notification::make()->title('Periode siap digunakan')->success()->send();
    }

    public function preview(): void
    {
        Gate::authorize('import.create');
        $this->validate(['periodId' => 'required|exists:reporting_periods,id', 'file' => 'required|file|mimes:xls,xlsx|max:15360', 'source' => 'required|in:PERSONNEL_DUK,POSITION_REQUIREMENT']);
        $batch = app(ImportService::class)->preview($this->authenticatedUser(), ReportingPeriod::findOrFail($this->periodId), $this->source, $this->file, $this->templateVersionId, $this->templateMapping, array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->ignoredColumns)))));
        $this->batchId = $batch->id;
        $this->file = null;
        Notification::make()->title($batch->status === 'committed' ? 'File ini sudah disimpan; tidak ada perubahan.' : 'Pemeriksaan selesai. Tinjau hasilnya sebelum menyimpan.')->send();
    }

    public function inspect(int $id): void
    {
        Gate::authorize('import.view');
        $batch = ImportBatch::findOrFail($id);
        $this->periodId = $batch->reporting_period_id;
        $this->batchId = $id;
    }

    public function saveImport(): void
    {
        Gate::authorize('import.commit');
        $this->resetValidation();
        try {
            app(ImportService::class)->commit($this->authenticatedUser(), ImportBatch::findOrFail($this->batchId));
        } catch (ValidationException $exception) {
            Notification::make()->title('Data belum tersimpan')->body(collect($exception->errors())->flatten()->first())->danger()->persistent()->send();
            throw $exception;
        }
        Notification::make()->title('Data berhasil disimpan')->body('Hasil import sudah tersedia pada periode yang dipilih.')->success()->send();
    }

    public function revalidate(): void
    {
        Gate::authorize('import.create');
        $this->resetValidation();
        $batch = app(ImportService::class)->revalidate($this->authenticatedUser(), ImportBatch::findOrFail($this->batchId));
        $this->batchId = $batch->id;
        $this->periodId = $batch->reporting_period_id;
        Notification::make()->title('Pemeriksaan selesai. Tinjau hasilnya, lalu pilih Simpan data.')->send();
    }

    public function publish(): void
    {
        Gate::authorize('period.publish');
        app(PublishPeriodService::class)->publish($this->authenticatedUser(), ReportingPeriod::findOrFail($this->periodId));
        Notification::make()->title('Periode dipublikasikan')->success()->send();
    }

    protected function getViewData(): array
    {
        $period = $this->periodId ? ReportingPeriod::find($this->periodId) : null;
        $batch = $this->batchId && Gate::allows('import.view') ? ImportBatch::with('issues')->find($this->batchId) : null;
        $preview = $batch && $batch->status === 'validated' ? array_slice(app(ImportService::class)->rows($batch), 0, 25) : [];

        $templateVersions = ImportTemplateVersion::whereHas('template', fn ($q) => $q->where('source', $this->source))->whereNotNull('activated_at')->orderByDesc('number')->get();
        $selectedTemplate = $templateVersions->firstWhere('id', $this->templateVersionId);

        return ['templateVersions' => $templateVersions, 'selectedTemplate' => $selectedTemplate, 'periods' => ReportingPeriod::orderByDesc('period_month')->get(), 'period' => $period, 'batch' => $batch, 'previewRows' => $preview, 'history' => Gate::allows('import.view') ? ImportBatch::with('uploader')->when($period, fn ($q) => $q->where('reporting_period_id', $period->id))->latest()->limit(30)->get() : collect()];
    }
}
