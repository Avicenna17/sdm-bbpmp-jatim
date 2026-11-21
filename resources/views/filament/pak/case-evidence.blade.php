<div class="space-y-3">
    @forelse (($getState() ?? []) as $document)
        <div class="rounded border p-3">
            <p>{{ $document['filename'] }}</p>
            <p>{{ $document['mime'] }} · {{ $document['size'] }}</p>
            <p>{{ $document['uploader'] }} · {{ $document['time'] }}</p>
            @if ($document['url'])
                <a class="text-primary-600 underline" href="{{ $document['url'] }}">Unduh dokumen</a>
            @endif
        </div>
    @empty
        <p>Belum ada dokumen pendukung kasus.</p>
    @endforelse
</div>
