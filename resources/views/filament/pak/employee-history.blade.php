<div class="space-y-4">
<h3 class="font-semibold">{{ $person->canonical_name }}</h3>
<p>Instansi current/default: {{ $person->employeeProfile?->institution_name ?? 'Belum diisi' }}.
Unit current/default: {{ $person->employeeProfile?->work_unit_name ?? 'Belum diisi' }}.</p>
@foreach (['Pangkat' => $person->rankHistories, 'Jabatan utama/definitif' => $person->positionHistories] as $title => $rows)
<h4 class="font-semibold">{{ $title }}</h4>
@forelse ($rows->sortByDesc('tmt_start') as $row)
<div class="border rounded p-2">
<p>{{ $row->rank_name ?? $row->position_name }} {{ $row->grade_code }} — {{ $row->tmt_start->format('d-m-Y') }} s.d. {{ $row->tmt_end?->format('d-m-Y') ?? 'belum diketahui' }}</p>
<p>Sumber: {{ $row->source_ref }} · {{ $row->superseded_at ? 'Versi dikoreksi (dipertahankan)' : 'Terverifikasi' }}</p>
</div>
@empty <p>Belum ada histori terverifikasi.</p> @endforelse
@endforeach
</div>