<?php

namespace App\Filament\Pak\Resources\PakCaseResource\Pages;

use App\Domain\Pak\Services\PakCaseService;
use App\Filament\Pak\Resources\PakCaseResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditCase extends EditRecord
{
    protected static string $resource = PakCaseResource::class;

    #[Locked]
    public int $expectedVersion;

    #[Locked]
    public string $previewHash = '';

    #[Locked]
    public array $previewValues = [];

    #[Locked]
    public array $beforeSnapshot = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->expectedVersion = $this->record->lock_version;
        $this->beforeSnapshot = $this->record->employeeSnapshot->toArray();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $payload = $this->record->employeeSnapshot->snapshot_payload;
        // Historical DUK ID may have been replaced; user explicitly selects a new reference.
        $data['organization'] = $payload['organization'] ?? [];
        $data['duk_snapshot_id'] = null;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('preview')->label('Pratinjau snapshot baru')->action(function () {
            $snapshot = app(PakCaseService::class)->preview(auth()->user(), $this->form->getState());
            $this->previewHash = $snapshot->fingerprint();
            $this->previewValues = $snapshot->values;
            Notification::make()->title('Bandingkan snapshot di bawah sebelum menyimpan.')->success()->send();
        })];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $this->previewHash) {
            throw ValidationException::withMessages(['snapshot' => 'Jalankan pratinjau dan tinjau perubahan sebelum menyimpan.']);
        }
        $case = app(PakCaseService::class)->refresh(auth()->user(), $record, $data, $this->expectedVersion, $this->previewHash, $data['reason'] ?? '');
        $this->expectedVersion = $case->lock_version;
        $this->beforeSnapshot = $case->employeeSnapshot->fresh()->toArray();
        $this->previewHash = '';
        $this->previewValues = [];

        return $case;
    }
}
