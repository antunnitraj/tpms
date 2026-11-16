@php
    $media = $getRecord();
    $mime = (string) $media->mime_type;
    $icon = match (true) {
        str_starts_with($mime, 'image/') => 'heroicon-o-photo',
        $mime === 'application/pdf' => 'heroicon-o-document-text',
        default => 'heroicon-o-document',
    };
@endphp

{{-- A preview for pictures (and PDFs when a thumbnail could be made), a file-type icon for the rest. --}}
@if (str_starts_with($mime, 'image/') || $media->hasGeneratedConversion('thumb'))
    <img src="{{ $media->getUrl('thumb') }}" alt="" loading="lazy"
         class="size-12 shrink-0 rounded-lg bg-gray-100 object-cover ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
@else
    <span class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
        <x-filament::icon :icon="$icon" class="size-6" />
    </span>
@endif
