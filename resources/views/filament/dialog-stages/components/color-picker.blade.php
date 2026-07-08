@php
    $fieldWrapperView = $getFieldWrapperView();
    $id = $getId();
    $isDisabled = $isDisabled();
    $statePath = $getStatePath();
    $wireModelAttribute = $applyStateBindingModifiers('wire:model');
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <style>
        .ac-dialog-stage-color-picker {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
            gap: .5rem;
        }

        .ac-dialog-stage-color-option {
            display: block;
        }

        .ac-dialog-stage-color-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .ac-dialog-stage-color-card {
            display: flex;
            min-height: 2.75rem;
            align-items: center;
            gap: .625rem;
            border: 1px solid rgba(148, 163, 184, .55);
            border-radius: .75rem;
            background: rgba(255, 255, 255, .78);
            color: rgb(15, 23, 42);
            cursor: pointer;
            font-size: .875rem;
            font-weight: 600;
            padding: .625rem .75rem;
            transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
        }

        .dark .ac-dialog-stage-color-card {
            background: rgba(15, 23, 42, .56);
            border-color: rgba(71, 85, 105, .9);
            color: rgb(226, 232, 240);
        }

        .ac-dialog-stage-color-card:hover {
            border-color: var(--ac-stage-color-border);
        }

        .ac-dialog-stage-color-option input:checked + .ac-dialog-stage-color-card {
            background: var(--ac-stage-color-soft);
            border-color: var(--ac-stage-color-border);
            box-shadow: 0 0 0 2px var(--ac-stage-color-soft);
        }

        .ac-dialog-stage-color-option input:focus-visible + .ac-dialog-stage-color-card {
            outline: 2px solid var(--ac-stage-color-border);
            outline-offset: 2px;
        }

        .ac-dialog-stage-color-option input:disabled + .ac-dialog-stage-color-card {
            cursor: not-allowed;
            opacity: .6;
        }

        .ac-dialog-stage-color-swatch {
            width: 1.125rem;
            height: 1.125rem;
            border: 1px solid rgba(15, 23, 42, .18);
            border-radius: 9999px;
            background: var(--ac-stage-color);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .34);
            flex: 0 0 auto;
        }
    </style>

    <div class="ac-dialog-stage-color-picker" role="radiogroup" aria-label="{{ $getLabel() }}">
        @foreach ($options as $value => $label)
            @php
                $color = $palette[$value] ?? $palette[\App\Models\DialogStage::COLOR_GRAY];
            @endphp

            <label class="ac-dialog-stage-color-option" for="{{ $id }}-{{ $value }}">
                <input
                    @if ($loop->first && $isAutofocused()) autofocus @endif
                    @checked($getState() === $value)
                    @disabled($isDisabled)
                    id="{{ $id }}-{{ $value }}"
                    name="{{ $id }}"
                    type="radio"
                    value="{{ $value }}"
                    {{ $wireModelAttribute }}="{{ $statePath }}"
                />

                <span
                    class="ac-dialog-stage-color-card"
                    style="--ac-stage-color: {{ $color['background'] }}; --ac-stage-color-soft: {{ $color['soft'] }}; --ac-stage-color-border: {{ $color['border'] }};"
                >
                    <span class="ac-dialog-stage-color-swatch" aria-hidden="true"></span>
                    <span>{{ $label }}</span>
                </span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
