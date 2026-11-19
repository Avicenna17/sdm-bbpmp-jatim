<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Filament\Pak\Resources\PakCaseResource;
use App\Filament\Pak\Support\ConversionPresenter;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class CaseConversion extends Page
{
    protected static string $resource = PakCaseResource::class;

    protected static string $view = 'filament.pak.case-conversion';

    protected static ?string $title = 'Konversi tersimpan';

    #[Locked]
    public int $caseId;

    #[Url(as: 'run')]
    public ?int $runId = null;

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

        return [
            'caseUrl' => PakCaseResource::getUrl('view', ['record' => $this->caseId]),
            'revisions' => $revisions->map(fn ($row) => ['label' => 'Revisi '.$row->revision_number.' · '.$row->status->value, 'url' => $url($row->id), 'selected' => $row->id === $selected])->all(),
            'saved' => $run === null ? null : app(ConversionPresenter::class)->present($actor, $run, $url),
        ];
    }
}
