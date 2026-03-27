@php
    $environment = app()->environment();

    if ($environment === 'production') {
        return;
    }

    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    $palette = match ($environment) {
        'staging' => [
            'badge' => 'bg-amber-100 text-amber-900 ring-amber-300',
            'dot' => 'bg-amber-500',
            'meta' => 'text-amber-900/80',
        ],
        'local' => [
            'badge' => 'bg-sky-100 text-sky-900 ring-sky-300',
            'dot' => 'bg-sky-500',
            'meta' => 'text-sky-900/80',
        ],
        default => [
            'badge' => 'bg-gray-100 text-gray-900 ring-gray-300',
            'dot' => 'bg-gray-500',
            'meta' => 'text-gray-900/80',
        ],
    };
@endphp

<div class="hidden md:flex items-center gap-2 rounded-full px-3 py-1.5 ring-1 {{ $palette['badge'] }}">
    <span class="h-2.5 w-2.5 rounded-full {{ $palette['dot'] }}"></span>
    <span class="text-xs font-semibold uppercase tracking-[0.18em]">
        {{ $environment }}
    </span>

    @if (filled($host))
        <span class="text-xs {{ $palette['meta'] }}">
            {{ $host }}
        </span>
    @endif
</div>
