@php
    use App\Support\AppVersion;

    $centered ??= false;
    $environment = app()->environment();
    $version = AppVersion::display();

    if ($environment !== 'staging' && blank($version)) {
        return;
    }
@endphp

<div @class([
    'inline-flex items-center gap-2',
    'pointer-events-none fixed left-1/2 top-8 z-40 -translate-x-1/2 -translate-y-1/2' => $centered,
])>
    @if ($environment === 'staging')
        <span class="inline-flex items-center rounded-md border border-black bg-amber-300 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.22em] text-black shadow-sm">
            staging
        </span>
    @endif

    @if (filled($version))
        <span class="inline-flex items-center rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-700 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
            {{ $version }}
        </span>
    @endif
</div>
