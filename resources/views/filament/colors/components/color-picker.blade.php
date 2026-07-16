@php
    use App\Support\Colors\AbColorPalette;

    $fieldWrapperView = $getFieldWrapperView();
    $id = $getId();
    $isDisabled = $isDisabled();
    $state = $getState();
    $statePath = $getStatePath();
    $stateEntangle = $applyStateBindingModifiers("\$entangle('{$statePath}')");
    $defaultValue = AbColorPalette::DEFAULT_PRESET_KEY;
    $allColors = collect($recommendedColors)->merge($webSafeColors);
    $defaultColor = $allColors->firstWhere('value', $defaultValue) ?? $recommendedColors[0];
    $selectedColor = $allColors->firstWhere('value', $state);
    $colorOptions = $allColors
        ->mapWithKeys(fn (array $color): array => [
            (string) $color['value'] => [
                'name' => (string) $color['name'],
                'hex' => (string) $color['hex'],
            ],
        ])
        ->all();

    if ($selectedColor === null && is_string($state) && str_starts_with($state, '#')) {
        $selectedColor = [
            'value' => $state,
            'name' => 'Свой цвет',
            'hex' => $state,
            'soft' => 'rgba(148, 163, 184, .14)',
            'border' => 'rgba(148, 163, 184, .55)',
        ];
    }

    $selectedColor ??= $defaultColor;
    $isDefaultSelected = $state === null || $state === '' || $state === $defaultValue;
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <style>
        .ac-color-picker {
            position: relative;
            max-width: 28rem;
        }

        .ac-color-picker__trigger {
            display: grid;
            width: 100%;
            min-height: 2.75rem;
            grid-template-columns: minmax(0, 1fr) 7rem;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, .55);
            border-radius: .75rem;
            background: rgba(255, 255, 255, .78);
            color: rgb(15, 23, 42);
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .dark .ac-color-picker__trigger {
            background: rgba(15, 23, 42, .56);
            border-color: rgba(71, 85, 105, .9);
            color: rgb(226, 232, 240);
        }

        .ac-color-picker__trigger:hover,
        .ac-color-picker__trigger.is-open {
            border-color: rgba(59, 130, 246, .72);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, .16);
        }

        .ac-color-picker__trigger-main {
            display: flex;
            min-width: 0;
            flex-direction: column;
            justify-content: center;
            padding: .55rem .875rem;
            text-align: left;
        }

        .ac-color-picker__trigger-main strong {
            overflow: hidden;
            font-size: .9rem;
            font-weight: 700;
            line-height: 1.15;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ac-color-picker__trigger-main span {
            color: rgb(100, 116, 139);
            font-size: .7rem;
            font-weight: 700;
            line-height: 1.15;
        }

        .dark .ac-color-picker__trigger-main span {
            color: rgb(148, 163, 184);
        }

        .ac-color-picker__trigger-swatch {
            border-inline-start: 1px solid rgba(148, 163, 184, .4);
            background: var(--ac-color-picker-current);
        }

        .ac-color-picker__panel {
            position: absolute;
            z-index: 80;
            top: calc(100% + .5rem);
            left: 0;
            width: min(28rem, calc(100vw - 2rem));
            border: 1px solid rgba(148, 163, 184, .45);
            border-radius: .85rem;
            background: rgb(255, 255, 255);
            box-shadow: 0 18px 44px rgba(15, 23, 42, .18);
            padding: .75rem;
        }

        .dark .ac-color-picker__panel {
            background: rgb(15, 23, 42);
            border-color: rgba(71, 85, 105, .9);
            box-shadow: 0 18px 44px rgba(0, 0, 0, .42);
        }

        .ac-color-picker__default {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 7rem;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, .55);
            border-radius: .55rem;
            cursor: pointer;
        }

        .ac-color-picker__default input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .ac-color-picker__default-text {
            display: flex;
            min-height: 2.35rem;
            align-items: center;
            justify-content: center;
            background: rgba(248, 250, 252, .92);
            color: rgb(51, 65, 85);
            font-size: .88rem;
            font-weight: 750;
        }

        .dark .ac-color-picker__default-text {
            background: rgba(30, 41, 59, .92);
            color: rgb(226, 232, 240);
        }

        .ac-color-picker__default-swatch {
            border-inline-start: 1px solid rgba(148, 163, 184, .45);
            background: var(--ac-color-picker-default);
        }

        .ac-color-picker__section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: rgb(71, 85, 105);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: 0;
            margin-block: .75rem .45rem;
        }

        .dark .ac-color-picker__section-title {
            color: rgb(203, 213, 225);
        }

        .ac-color-picker__matrix {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: .35rem;
        }

        .ac-color-picker__matrix--dense {
            grid-template-columns: repeat(12, minmax(0, 1fr));
            max-height: 14rem;
            overflow: auto;
            padding-right: .25rem;
        }

        .ac-color-picker__option {
            display: block;
        }

        .ac-color-picker__option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .ac-color-picker__swatch-button {
            display: block;
            aspect-ratio: 1;
            border: 1px solid rgba(15, 23, 42, .22);
            border-radius: .22rem;
            background: var(--ac-color);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .28);
            cursor: pointer;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .ac-color-picker__matrix--dense .ac-color-picker__swatch-button {
            border-radius: .18rem;
        }

        .ac-color-picker__swatch-button:hover {
            border-color: rgb(15, 23, 42);
            transform: translateY(-1px);
        }

        .dark .ac-color-picker__swatch-button:hover {
            border-color: rgb(248, 250, 252);
        }

        .ac-color-picker__option input:checked + .ac-color-picker__swatch-button {
            border-color: rgb(15, 23, 42);
            box-shadow: 0 0 0 2px rgb(255, 255, 255), 0 0 0 4px var(--ac-color-border);
        }

        .dark .ac-color-picker__option input:checked + .ac-color-picker__swatch-button {
            border-color: rgb(248, 250, 252);
            box-shadow: 0 0 0 2px rgb(15, 23, 42), 0 0 0 4px var(--ac-color-border);
        }

        .ac-color-picker__option input:focus-visible + .ac-color-picker__swatch-button {
            outline: 2px solid var(--ac-color-border);
            outline-offset: 2px;
        }

        .ac-color-picker__option input:disabled + .ac-color-picker__swatch-button {
            cursor: not-allowed;
            opacity: .6;
        }

        .ac-color-picker__advanced-toggle {
            display: flex;
            width: 100%;
            min-height: 2.35rem;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(148, 163, 184, .45);
            border-radius: .55rem;
            color: rgb(51, 65, 85);
            font-size: .8rem;
            font-weight: 750;
            margin-top: .75rem;
        }

        .dark .ac-color-picker__advanced-toggle {
            color: rgb(226, 232, 240);
        }

        .ac-color-picker__custom {
            display: grid;
            gap: .35rem;
            margin-bottom: .75rem;
        }

        .ac-color-picker__custom span {
            color: rgb(71, 85, 105);
            font-size: .72rem;
            font-weight: 800;
        }

        .dark .ac-color-picker__custom span {
            color: rgb(203, 213, 225);
        }

        .ac-color-picker__custom input {
            width: 100%;
            border: 1px solid rgba(148, 163, 184, .55);
            border-radius: .55rem;
            background: rgba(248, 250, 252, .92);
            color: rgb(15, 23, 42);
            font-size: .875rem;
            padding: .55rem .65rem;
        }

        .dark .ac-color-picker__custom input {
            background: rgba(30, 41, 59, .92);
            color: rgb(226, 232, 240);
        }

        [x-cloak] {
            display: none !important;
        }
    </style>

    <div
        class="ac-color-picker"
        x-data="{
            open: false,
            advanced: false,
            defaultValue: {{ \Illuminate\Support\Js::from($defaultValue) }},
            colors: {{ \Illuminate\Support\Js::from($colorOptions) }},
            selectedValue: {{ \Illuminate\Support\Js::from((string) ($state ?: $defaultValue)) }},
            selectedName: {{ \Illuminate\Support\Js::from($isDefaultSelected ? 'По умолчанию' : (string) $selectedColor['name']) }},
            selectedHex: {{ \Illuminate\Support\Js::from((string) $selectedColor['hex']) }},
            customHexInput: {{ \Illuminate\Support\Js::from(str_starts_with((string) $state, '#') ? (string) $state : '') }},
            state: $wire.{{ $stateEntangle }},
            init() {
                this.syncFormState(this.state || this.defaultValue);
                this.applySelectedValue(this.state);

                this.$watch('state', (value) => {
                    this.applySelectedValue(value || this.defaultValue);
                });
            },
            normalizeHex(value) {
                const color = String(value || '').trim();
                const withHash = color.startsWith('#') ? color : `#${color}`;

                return /^#[0-9A-Fa-f]{6}$/.test(withHash) ? withHash.toUpperCase() : null;
            },
            applySelectedValue(value) {
                const normalizedValue = value || this.defaultValue;

                this.selectedValue = normalizedValue;

                const color = this.colors[normalizedValue] || null;

                if (color) {
                    this.selectedName = normalizedValue === this.defaultValue ? 'По умолчанию' : color.name;
                    this.selectedHex = color.hex;

                    return;
                }

                const customHex = this.normalizeHex(normalizedValue);

                if (customHex) {
                    this.selectedValue = customHex;
                    this.selectedName = 'Свой цвет';
                    this.selectedHex = customHex;
                    this.customHexInput = customHex;

                    return;
                }

                const fallback = this.colors[this.defaultValue] || null;

                if (fallback) {
                    this.selectedValue = this.defaultValue;
                    this.selectedName = 'По умолчанию';
                    this.selectedHex = fallback.hex;
                }
            },
            syncFormState(value) {
                const normalizedValue = value || this.defaultValue;

                this.state = normalizedValue;
                this.applySelectedValue(normalizedValue);
            },
            select(value, close = true) {
                this.syncFormState(value);

                if (close) {
                    this.open = false;
                }
            },
            chooseRadio(value, input) {
                if (input) {
                    input.checked = true;
                }

                this.select(value);
            },
            selectCustom(value) {
                this.customHexInput = value;

                const hex = this.normalizeHex(value);

                if (! hex) {
                    return;
                }

                this.selectedValue = hex;
                this.selectedName = 'Свой цвет';
                this.selectedHex = hex;
                this.syncFormState(hex);
            },
        }"
        x-on:keydown.escape.window="open = false"
    >
        <button
            @disabled($isDisabled)
            class="ac-color-picker__trigger"
            type="button"
            x-bind:class="{ 'is-open': open }"
            x-bind:style="'--ac-color-picker-current: ' + selectedHex"
            x-on:click="open = ! open"
        >
            <span class="ac-color-picker__trigger-main">
                <strong x-text="selectedName">{{ $isDefaultSelected ? 'По умолчанию' : $selectedColor['name'] }}</strong>
                <span x-text="selectedHex">{{ $selectedColor['hex'] }}</span>
            </span>
            <span class="ac-color-picker__trigger-swatch" aria-hidden="true"></span>
        </button>

        <div class="ac-color-picker__panel" x-show="open" x-cloak x-on:click.outside="open = false">
            <label
                class="ac-color-picker__default"
                style="--ac-color-picker-default: {{ $defaultColor['hex'] }};"
                x-on:click.prevent="chooseRadio({{ \Illuminate\Support\Js::from($defaultValue) }}, $el.querySelector('input'))"
            >
                <input
                    @checked($isDefaultSelected)
                    @disabled($isDisabled)
                    name="{{ $id }}"
                    type="radio"
                    value="{{ $defaultValue }}"
                />
                <span class="ac-color-picker__default-text">По умолчанию</span>
                <span class="ac-color-picker__default-swatch" aria-hidden="true"></span>
            </label>

            <div class="ac-color-picker__section-title">
                <span>Основные цвета</span>
                <span>{{ count($recommendedColors) }}</span>
            </div>

            <div class="ac-color-picker__matrix" role="radiogroup" aria-label="Основные цвета">
                @foreach ($recommendedColors as $color)
                    @include('filament.colors.components.partials.color-option', [
                        'color' => $color,
                        'id' => $id,
                        'isCompact' => false,
                        'isDisabled' => $isDisabled,
                        'state' => $state,
                    ])
                @endforeach
            </div>

            <button
                class="ac-color-picker__advanced-toggle"
                type="button"
                x-on:click="advanced = ! advanced"
            >
                <span x-show="! advanced">Показать расширенные цвета</span>
                <span x-show="advanced" x-cloak>Скрыть расширенные цвета</span>
            </button>

            <div x-show="advanced" x-cloak>
                <label class="ac-color-picker__custom">
                    <span>Свой HEX</span>
                    <input
                        @disabled($isDisabled)
                        maxlength="7"
                        placeholder="#1A2B3C"
                        type="text"
                        x-model="customHexInput"
                        x-on:input="selectCustom($event.target.value)"
                    />
                </label>

                <div class="ac-color-picker__section-title">
                    <span>Web-safe</span>
                    <span>{{ count($webSafeColors) }}</span>
                </div>

                <div class="ac-color-picker__matrix ac-color-picker__matrix--dense" role="radiogroup" aria-label="Web-safe цвета">
                    @foreach ($webSafeColors as $color)
                        @include('filament.colors.components.partials.color-option', [
                            'color' => $color,
                            'id' => $id,
                            'isCompact' => true,
                            'isDisabled' => $isDisabled,
                            'state' => $state,
                        ])
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-dynamic-component>
