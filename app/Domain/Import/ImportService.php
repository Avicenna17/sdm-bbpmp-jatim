<?php

namespace App\Domain\Import;

use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\PersonnelSnapshot;
use App\Models\PositionRequirementSnapshot;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportService
{
    public function __construct(private WorkbookParser $parser) {}

    public function revalidate(User $user, ImportBatch $batch): ImportBatch
    {
        Gate::forUser($user)->authorize('import.view');
        Gate::forUser($user)->authorize('import.create');
        $disk = Storage::disk($batch->disk);
        if (! $disk->exists($batch->path)) {
            throw ValidationException::withMessages(['import' => 'File sumber tersimpan tidak ditemukan. Silakan upload ulang workbook.']);
        }
        $path = $disk->path($batch->path);
        if (! hash_equals($batch->sha256, hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['import' => 'Checksum file tersimpan berubah. Silakan upload ulang workbook asli.']);
        }

        return $this->preview($user, $batch->period, $batch->source_type,
            new UploadedFile($path, $batch->original_filename, null, null, true));
    }

    public function preview(User $user, ReportingPeriod $period, string $source, UploadedFile $file): ImportBatch
    {
        Gate::forUser($user)->authorize('import.create');
        Validator::make(['file' => $file, 'source' => $source], ['file' => 'required|file|mimes:xls,xlsx|max:15360', 'source' => 'required|in:PERSONNEL_DUK,POSITION_REQUIREMENT'])->validate();
        $checksum = hash_file('sha256', $file->getRealPath());
        // Only the CURRENT committed batch can be a no-op. An older file may restore a previous version.
        $current = $period->batches()->where('source_type', $source)->where('status', 'committed')->latest('committed_at')->latest('id')->first();
        if ($current?->sha256 === $checksum && ($current->summary['source_policy'] ?? null) === 'duk-only-v1') {
            return $current;
        }
        $path = $file->store('imports/'.$period->id, 'local');
        $batch = ImportBatch::create(['reporting_period_id' => $period->id, 'uploaded_by' => $user->id, 'source_type' => $source, 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255), 'sha256' => $checksum, 'path' => $path, 'disk' => 'local', 'status' => 'parsing', 'base_revision' => $period->fresh()->revision]);
        try {
            $parsed = $this->parser->parse(Storage::disk('local')->path($path), $source);
            $parsed['summary']['source_policy'] = 'duk-only-v1';
            foreach ($parsed['issues'] as $issue) {
                $batch->issues()->create($issue);
            }
            $errorIssues = collect($parsed['issues'])->where('severity', 'ERROR');
            $warningIssues = collect($parsed['issues'])->where('severity', 'WARNING');
            $locationKey = fn ($issue) => ($issue['source_sheet'] ?? '').':'.($issue['source_row'] ?? 0);
            $errors = $errorIssues->unique($locationKey)->count();
            $warnings = $warningIssues->unique($locationKey)->count();
            $summary = $this->diff($period, $source, $parsed['rows']) + ($parsed['summary'] ?? []) + [
                'primary_warning_rows' => $warningIssues->where('source_sheet', 'DUK PEGAWAI')->unique($locationKey)->count(),
                'supplemental_warning_rows' => $warningIssues->where('source_sheet', 'P3K - PPNPN')->unique($locationKey)->count(),
                'info_count' => collect($parsed['issues'])->where('severity', 'INFO')->count(),
            ];
            Storage::disk('local')->put($this->stagingPath($batch), json_encode($parsed['rows'], JSON_THROW_ON_ERROR));
            $batch->update(['status' => $errors ? 'failed' : 'validated', 'total_rows' => count($parsed['rows']), 'valid_rows' => max(0, count($parsed['rows']) - $errors), 'warning_rows' => $warnings, 'error_rows' => $errors, 'summary' => $summary]);
        } catch (\Throwable $e) {
            $batch->issues()->create(['severity' => 'ERROR', 'code' => 'PARSE_FAILED', 'message' => get_class($e) === \RuntimeException::class ? $e->getMessage() : 'Workbook gagal dibaca. Periksa format dan gunakan template.']);
            $batch->update(['status' => 'failed', 'error_rows' => 1]);
        }

        return $batch->fresh();
    }

    public function stagingPath(ImportBatch $batch): string
    {
        return 'imports/'.$batch->reporting_period_id.'/preview-'.$batch->id.'.json';
    }

    public function rows(ImportBatch $batch): array
    {
        return json_decode(Storage::disk('local')->get($this->stagingPath($batch)) ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    }

    private function comparable(array $row): array
    {
        foreach (['id', 'person_id', 'person_key', 'normalized_name', 'reporting_period_id', 'import_batch_id', 'created_at', 'updated_at', 'raw_payload', 'source_row_no', 'source_sequence'] as $f) {
            unset($row[$f]);
        }
        if (isset($row['projections'])) {
            $row['projections'] = collect($row['projections'])->map(fn ($v) => array_intersect_key($v, array_flip(['metric_type', 'projection_year', 'value'])))->sortBy(fn ($v) => $v['metric_type'].$v['projection_year'])->values()->all();
        }

        return array_filter($row, fn ($v) => $v !== null);
    }

    private function diff(ReportingPeriod $period, string $source, array $incoming): array
    {
        $old = $source === WorkbookParser::PERSONNEL ? $period->personnel()->with('person')->get()->mapWithKeys(fn ($r) => [$r->person->person_key => $this->comparable($r->attributesToArray())])->all() : $period->positions()->with('projections')->get()->mapWithKeys(fn ($r) => [$r->position_key => $this->comparable($r->toArray())])->all();
        $result = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0];
        $keys = [];
        foreach ($incoming as $row) {
            $key = $row[$source === WorkbookParser::PERSONNEL ? 'person_key' : 'position_key'];
            $keys[] = $key;
            $result[! isset($old[$key]) ? 'new' : ($old[$key] == $this->comparable($row) ? 'unchanged' : 'changed')]++;
        }
        $result['removed'] = count(array_diff(array_keys($old), $keys));

        return $result;
    }

    public function commit(User $user, ImportBatch $batch): void
    {
        Gate::forUser($user)->authorize('import.commit');
        try {
            DB::transaction(function () use ($batch, $user) {
                $period = ReportingPeriod::lockForUpdate()->findOrFail($batch->reporting_period_id);
                $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
                if ($batch->status === 'committed') {
                    return;
                }
                if ($batch->status !== 'validated' || $batch->error_rows > 0) {
                    throw ValidationException::withMessages(['import' => 'Import mengandung error atau belum tervalidasi.']);
                }
                if ($batch->source_type === WorkbookParser::PERSONNEL && ($batch->summary['source_policy'] ?? null) !== 'duk-only-v1') {
                    throw ValidationException::withMessages(['import' => 'Hasil pemeriksaan ini memakai aturan lama. Pilih Periksa ulang file, lalu simpan hasil pemeriksaan terbaru.']);
                }
                if ($period->revision !== $batch->base_revision) {
                    throw ValidationException::withMessages(['import' => 'Data periode berubah sejak preview. Upload ulang untuk melihat perbandingan terbaru.']);
                }
                if ($period->status === 'published') {
                    Gate::forUser($user)->authorize('period.revise');
                }
                if (! hash_equals($batch->sha256, hash_file('sha256', Storage::disk($batch->disk)->path($batch->path)))) {
                    throw ValidationException::withMessages(['import' => 'Checksum file berubah. Upload ulang.']);
                }
                $rows = $this->rows($batch);
                if (! $rows) {
                    throw ValidationException::withMessages(['import' => 'Snapshot kosong ditolak.']);
                }
                if ($batch->source_type === WorkbookParser::PERSONNEL) {
                    $period->personnel()->delete();
                    foreach ($rows as $row) {
                        $person = Person::updateOrCreate(['person_key' => $row['person_key']], ['nip' => $row['nip_at_period'], 'canonical_name' => $row['name_at_period'], 'normalized_name' => $row['normalized_name']]);
                        unset($row['person_key'],$row['normalized_name']);
                        PersonnelSnapshot::create($row + ['person_id' => $person->id, 'reporting_period_id' => $period->id, 'import_batch_id' => $batch->id]);
                    }
                } else {
                    $period->positions()->delete();
                    foreach ($rows as $row) {
                        $projections = $row['projections'];
                        unset($row['projections']);
                        $snapshot = PositionRequirementSnapshot::create($row + ['reporting_period_id' => $period->id, 'import_batch_id' => $batch->id]);
                        $snapshot->projections()->createMany($projections);
                    }
                }
                $batch->update(['status' => 'committed', 'committed_at' => now()]);
                $ready = $period->personnel()->exists() && $period->positions()->exists();
                $period->update(['revision' => $period->revision + 1, 'status' => $period->status === 'published' ? 'published' : ($ready ? 'ready' : 'draft')] + ($period->status === 'published' ? ['published_at' => now(), 'published_by' => $user->id] : []));
            });
        } catch (QueryException) {
            // Do not propagate SQL bindings containing personnel data into production logs.
            throw ValidationException::withMessages(['import' => 'Commit gagal di database. Snapshot sebelumnya tetap utuh. Periksa data sumber dan koneksi database.']);
        }
    }
}
