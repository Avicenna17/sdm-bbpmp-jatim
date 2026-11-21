<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Domain\Pak\Authorization\PakEvidenceAuthorization as EvidenceAccess;
use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Domain\Pak\Queries\PakEvidenceQuery;
use App\Domain\Pak\Services\PakEvidenceService;
use App\Filament\Pak\Resources\PakCaseResource;
use App\Filament\Pak\Support\EvidencePresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ViewCase extends ViewRecord
{
    protected static string $resource = PakCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('conversion')->label('Konversi')
            ->visible(fn () => ConversionRunQuery::allows(auth()->user()))
            ->url(fn () => PakCaseResource::getUrl('conversion', ['record' => $this->record->id])),
            Action::make('uploadEvidence')->label('Tambah dokumen pendukung')
                ->visible(fn () => EvidenceAccess::allows(auth()->user(), true) && $this->record->status === 'DRAFT' && $this->record->finalized_at === null)
                ->form([FileUpload::make('evidence')->label('Dokumen PDF/JPEG/PNG')->required()
                    ->disk('local')->storeFiles(false)->previewable(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)])
                ->action(function (array $data): void {
                    EvidenceAccess::authorize(auth()->user(), true);
                    try {
                        app(PakEvidenceService::class)->upload(auth()->user(), (int) $this->record->id, $data['evidence']);
                        $this->record->refresh();
                        Notification::make()->success()->title('Dokumen pendukung ditambahkan')->send();
                    } catch (HttpExceptionInterface $error) {
                        throw $error;
                    } catch (ValidationException) {
                        throw ValidationException::withMessages(['mountedActionsData.0.evidence' => 'Dokumen atau kasus tidak valid. Gunakan PDF/JPEG/PNG yang sesuai ekstensi, maksimum 10 MiB, pada draf kasus.']);
                    } catch (Throwable) {
                        Notification::make()->danger()->title('Upload tidak dapat dikonfirmasi')->body('Periksa dokumen tersimpan sebelum mencoba lagi.')->send();
                    }
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $fields = ['name' => 'Nama snapshot', 'nip' => 'NIP snapshot', 'rank_name' => 'Pangkat', 'grade_code' => 'Golongan', 'rank_tmt' => 'TMT pangkat', 'position_name' => 'Jabatan utama', 'position_tmt' => 'TMT jabatan', 'functional_position_name' => 'JF', 'functional_level_name' => 'Jenjang', 'institution_name' => 'Instansi historis', 'work_unit' => 'Unit historis'];

        return $infolist->schema([
            Section::make('Dokumen pendukung kasus')->visible(fn () => EvidenceAccess::allows(auth()->user()))
                ->schema([ViewEntry::make('case_evidence')->hiddenLabel()->view('filament.pak.case-evidence')
                    ->state(function (): array {
                        if (! EvidenceAccess::allows(auth()->user())) {
                            return [];
                        }

                        return app(PakEvidenceQuery::class)->forCase(auth()->user(), (int) $this->record->id)->get()
                            ->map(fn ($evidence) => app(EvidencePresenter::class)->present($evidence))->all();
                    })]),
            Section::make('Draft pemeriksaan')->schema([TextEntry::make('purpose_type')->label('Tujuan'), TextEntry::make('period_start')->date(), TextEntry::make('period_end')->date(), TextEntry::make('employee_data_as_of')->label('Tanggal acuan')->date(), TextEntry::make('status')->badge(), TextEntry::make('notes')->label('Catatan')])->columns(2),
            Section::make('Snapshot tersimpan')->schema(array_map(fn ($key, $label) => TextEntry::make('employeeSnapshot.'.$key)->label($label)->placeholder('Belum tersedia'), array_keys($fields), array_values($fields)))->columns(2),
            TextEntry::make('employeeSnapshot.snapshot_payload.identity_source')->label('Asal identitas'),
            TextEntry::make('employeeSnapshot.snapshot_payload.missing_fields')->label('Data belum tersedia')->listWithLineBreaks(),
        ]);
    }
}
