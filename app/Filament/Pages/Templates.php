<?php

namespace App\Filament\Pages;

use App\Domain\Import\WorkbookParser;
use App\Domain\Templates\TemplateService;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateVersion;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class Templates extends Page
{
    use WithFileUploads;

    /** @var UploadedFile|null */
    public $sampleFile = null;

    public array $sampleResult = [];

    #[Locked]
    public array $sampleRows = [];

    #[Locked]
    public ?array $sampleBackup = null;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Template Import';

    protected static ?string $title = 'Template Import';

    protected static ?string $navigationGroup = 'Pengelolaan Data';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.templates';

    #[Locked]
    public ?int $templateId = null;

    #[Locked]
    public ?int $draftId = null;

    public array $definition = [];

    #[Locked]
    public array $pendingKeys = [];

    public bool $showAddColumn = false;

    public string $newType = 'text';

    public string $newKind = 'extra';

    public string $newField = '';

    public string $newMetric = 'RETIREMENT';

    public int $newYear = 2030;

    public static function canAccess(): bool
    {
        return Gate::allows('template.view');
    }

    public function mount(): void
    {
        Gate::authorize('template.view');
        $this->newYear = (int) now()->year;
        app(TemplateService::class)->ensureTemplates();
        $this->open(ImportTemplate::orderBy('id')->firstOrFail()->id);
    }

    public function open(int $id): void
    {
        Gate::authorize('template.view');
        $t = ImportTemplate::findOrFail($id);
        $this->templateId = $id;
        $this->draftId = null;
        $this->definition = $t->activeVersion?->definition ?? app(TemplateService::class)->defaults($t->source);
        foreach ($this->definition['columns'] as &$column) {
            $column['color'] = '#FFFFFF';
        }
        $this->definition = app(TemplateService::class)->withRequiredValues(ImportTemplate::findOrFail($this->templateId)->source, $this->definition);
        $this->pendingKeys = [];
        $this->showAddColumn = false;
        $this->sampleResult = [];
        $this->sampleRows = [];
        $this->sampleBackup = null;
        $this->resetValidation();
    }

    public function loadVersion(int $id): void
    {
        Gate::authorize('template.view');
        $v = ImportTemplateVersion::where('import_template_id', $this->templateId)->findOrFail($id);
        $this->draftId = $v->activated_at ? null : $v->id;
        $this->definition = $v->definition;
        $this->definition = app(TemplateService::class)->withRequiredValues(ImportTemplate::findOrFail($this->templateId)->source, $this->definition);
        $this->pendingKeys = [];
        $this->showAddColumn = false;
        $this->sampleResult = [];
        $this->sampleRows = [];
        $this->sampleBackup = null;
        $this->resetValidation();
        $this->dispatch('template-version-loaded');
    }

    public function updatedNewKind(): void
    {
        $this->newType = $this->newKind === 'year' ? 'number' : 'text';
    }

    public function addColumn(): void
    {
        Gate::authorize('template.manage');
        $this->validate(['newKind' => 'in:extra,standard,year', 'newType' => 'in:text,number,date,choice']);
        $service = app(TemplateService::class);
        $source = ImportTemplate::findOrFail($this->templateId)->source;
        $key = match ($this->newKind) {
            'standard' => $this->newField,'year' => 'projection:'.$this->newMetric.':'.$this->newYear,default => 'extra:'.Str::uuid()
        };
        if ($this->newKind === 'year' && ($source !== 'POSITION_REQUIREMENT' || ! in_array($this->newMetric, ['RETIREMENT', 'REQUIREMENT']) || $this->newYear < 1900 || $this->newYear > 2199)) {
            $this->addError('template', 'Pilih jenis dan tahun yang valid.');

            return;
        }
        if ($this->newKind === 'standard' && ! isset($service->fields($source)[$key])) {
            $this->addError('template', 'Pilih kegunaan kolom.');

            return;
        }
        if (in_array($key, array_column($this->definition['columns'], 'key'))) {
            $this->addError('template', 'Kolom dengan kegunaan tersebut sudah tersedia.');

            return;
        }
        $label = $service->fields($source)[$key] ?? ($this->newKind === 'year' ? ($this->newMetric === 'RETIREMENT' ? 'Pensiun ' : 'Proyeksi Kebutuhan ').$this->newYear : 'Kolom tambahan');
        $this->definition['columns'][] = ['key' => $key, 'label' => $label, 'type' => $this->newKind === 'extra' ? $this->newType : ($this->newKind === 'year' ? 'number' : 'text'), 'required' => in_array($key, $service->required($source)), 'width' => 24, 'color' => '#FFFFFF', 'bold' => true, 'align' => 'center', 'choices' => ''];
        $this->pendingKeys[] = $key;
        $this->showAddColumn = false;
    }

    public function startColumn(): void
    {
        $this->newKind = 'extra';
        $this->newType = 'text';
        $this->addColumn();
    }

    public function changePurpose(int $index, string $purpose): void
    {
        Gate::authorize('template.manage');
        $column = $this->definition['columns'][$index] ?? null;
        if (! $column || ! in_array($column['key'], $this->pendingKeys)) {
            return;
        }
        $service = app(TemplateService::class);
        $source = ImportTemplate::findOrFail($this->templateId)->source;
        $key = $purpose === 'extra' ? 'extra:'.Str::uuid() : $purpose;
        $year = $source === 'POSITION_REQUIREMENT' && preg_match('/^projection:(RETIREMENT|REQUIREMENT):(19|20|21)\d{2}$/', $key);
        if ($purpose !== 'extra' && ! isset($service->fields($source)[$key]) && ! $year) {
            $this->addError('template', 'Pilih kegunaan atau tahun yang valid.');
            return;
        }
        if ($key !== $column['key'] && in_array($key, array_column($this->definition['columns'], 'key'))) {
            $this->addError('template', 'Kegunaan dan tahun tersebut sudah tersedia.');
            return;
        }
        $this->pendingKeys = array_values(array_diff($this->pendingKeys, [$column['key']]));
        $this->pendingKeys[] = $key;
        $column['key'] = $key;
        $column['type'] = $year ? 'number' : 'text';
        $column['required'] = in_array($key, $service->required($source));
        $column['label'] = $service->fields($source)[$key] ?? ($year ? str_replace(['projection:', 'RETIREMENT:', 'REQUIREMENT:'], ['', 'Pensiun ', 'Proyeksi Kebutuhan '], $key) : 'Kolom tambahan');
        $this->definition['columns'][$index] = $column;
        $this->resetValidation();
    }

    public function removeColumn(int $index): void
    {
        Gate::authorize('template.manage');
        $required = app(TemplateService::class)->required(ImportTemplate::findOrFail($this->templateId)->source);
        if (in_array($this->definition['columns'][$index]['key'] ?? '', $required)) {
            $this->addError('template', 'Kolom wajib tidak dapat dihapus.');

            return;
        }
        $this->pendingKeys = array_values(array_diff($this->pendingKeys, [$this->definition['columns'][$index]['key']]));
        unset($this->definition['columns'][$index]);
        $this->definition['columns'] = array_values($this->definition['columns']);
    }

    public function applyAppearanceToAll(int $index): void
    {
        Gate::authorize('template.manage');
        if (! isset($this->definition['columns'][$index])) {
            return;
        }
        $appearance = array_intersect_key($this->definition['columns'][$index], array_flip(['color', 'width', 'bold', 'align']));
        $this->validate([
            'definition.columns.'.$index.'.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'definition.columns.'.$index.'.width' => 'required|numeric|min:8|max:80',
            'definition.columns.'.$index.'.bold' => 'required|boolean',
            'definition.columns.'.$index.'.align' => 'required|in:left,center,right',
        ]);
        foreach ($this->definition['columns'] as &$column) {
            $column = array_replace($column, $appearance);
        }
        Notification::make()->title('Tampilan diterapkan ke semua kolom draft')
            ->body('Warna, lebar, ketebalan, dan posisi teks sudah disamakan. Simpan draft untuk menyimpan perubahan.')
            ->success()->send();
    }

    public function moveColumn(int $index, int $direction): void
    {
        Gate::authorize('template.manage');
        if (! in_array($direction, [-1, 1]) || ! isset($this->definition['columns'][$index],$this->definition['columns'][$index + $direction])) {
            return;
        }
        $other = $this->definition['columns'][$index + $direction];
        $this->definition['columns'][$index + $direction] = $this->definition['columns'][$index];
        $this->definition['columns'][$index] = $other;
    }

    public function check(): void
    {
        Gate::authorize('template.manage');
        app(TemplateService::class)->validate(ImportTemplate::findOrFail($this->templateId)->source, $this->definition);
        Notification::make()->title('Template lolos pemeriksaan struktur')->body('Tinjau preview. Simpan draft sebelum mengaktifkan versi ini.')->success()->send();
    }

    public function save(): void
    {
        $v = app(TemplateService::class)->save(ImportTemplate::findOrFail($this->templateId), $this->definition, $this->draftId, (int) Auth::id());
        $this->draftId = $v->id;
        $this->definition = $v->definition;
        $this->pendingKeys = [];
        $this->sampleBackup = null;
        Notification::make()->title('Draft disimpan. Template aktif belum berubah.')->success()->send();
    }

    public function testSample(): void
    {
        Gate::authorize('template.manage');
        try {
            $this->validate(['sampleFile' => 'required|file|mimes:xls,xlsx|max:15360']);
            $template = ImportTemplate::findOrFail($this->templateId);
            $result = app(\App\Domain\Templates\TemplateSampleLoader::class)->load(
                $this->sampleFile->getRealPath(), $template->source, $this->draftId ?? $template->active_version_id,
            );
            $this->sampleBackup ??= ['definition' => $this->definition, 'draftId' => $this->draftId, 'pendingKeys' => $this->pendingKeys, 'rows' => $this->sampleRows];
            $this->definition = $result['definition'];
            $this->sampleRows = $result['samples'];
            $this->pendingKeys = [];
            $this->sampleResult = ['rows' => $result['rows'], 'errors' => []];
            $this->dispatch('template-sample-loaded');
            Notification::make()->title('File lolos pemeriksaan struktur')
                ->body('Format file dimuat ke pengaturan draft. Tinjau preview, sesuaikan bila perlu, lalu simpan draft atau pilih Batal.')
                ->success()->send();
        } catch (\Throwable $e) {
            $message = $e instanceof \Illuminate\Validation\ValidationException
                ? implode(' ', $e->validator->errors()->all())
                : ($e instanceof \RuntimeException ? $e->getMessage() : 'File tidak dapat dibaca. Gunakan file XLS/XLSX sesuai template yang dipilih.');
            $this->sampleResult = ['rows' => 0, 'errors' => [$message]];
            Notification::make()->title('File belum sesuai')->body($message.' Pengaturan draft tidak diubah.')->danger()->send();
        }
        $this->sampleFile = null;
    }

    public function cancelSample(): void
    {
        Gate::authorize('template.manage');
        if (! $this->sampleBackup) {
            return;
        }
        $this->definition = $this->sampleBackup['definition'];
        $this->draftId = $this->sampleBackup['draftId'];
        $this->pendingKeys = $this->sampleBackup['pendingKeys'];
        $this->sampleRows = $this->sampleBackup['rows'];
        $this->sampleBackup = null;
        $this->sampleResult = [];
        $this->resetValidation();
        Notification::make()->title('Hasil uji dibatalkan. Draft sebelumnya dipulihkan.')->success()->send();
    }

    public function activate(int $id): void
    {
        $v = ImportTemplateVersion::where('import_template_id', $this->templateId)->findOrFail($id);
        if ($this->draftId === $v->id && $this->definition != $v->definition) {
            $this->addError('template', 'Simpan perubahan draft terlebih dahulu sebelum aktivasi.');

            return;
        }
        app(TemplateService::class)->activate($v);
        $this->open($this->templateId);
        Notification::make()->title('Versi '.$v->number.' diaktifkan')->success()->send();
    }

    protected function getViewData(): array
    {
        $template = ImportTemplate::findOrFail($this->templateId);

        $activeDefinition = $template->activeVersion?->definition ?? app(TemplateService::class)->defaults($template->source);

        return ['activeDefinition' => $activeDefinition, 'templates' => ImportTemplate::with('activeVersion')->get(), 'template' => $template, 'versions' => $template->versions()->latest('number')->get(), 'fields' => app(TemplateService::class)->fields($template->source), 'required' => app(TemplateService::class)->required($template->source)];
    }
}
