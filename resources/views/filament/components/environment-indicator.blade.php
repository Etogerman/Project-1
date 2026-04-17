@php
    use App\Support\AppVersion;

    $centered ??= false;
    $environment = app()->environment();
    $normalizedVersion = AppVersion::resolve();
    $version = AppVersion::displayFromVersion($normalizedVersion);
    $style = $centered
        ? 'position: fixed; left: 50%; top: 2rem; transform: translate(-50%, -50%); z-index: 60; pointer-events: none;'
        : null;

    if ($environment !== 'staging' && blank($version)) {
        return;
    }
@endphp

<div
    data-role="environment-indicator"
    @if (filled($normalizedVersion))
        data-app-version="{{ $normalizedVersion }}"
    @endif
    @class([
        'inline-flex items-center gap-2',
    ])
    @if (filled($style))
        style="{{ $style }}"
    @endif
>
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
