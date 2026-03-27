@php
    $environment = app()->environment();

    if ($environment !== 'staging') {
        return;
    }
@endphp

<span class="ml-2 inline-flex items-center rounded-md border border-black bg-amber-300 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.22em] text-black shadow-sm">
    staging
</span>
