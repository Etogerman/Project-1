<label
    class="ac-color-picker__option"
    for="{{ $id }}-{{ $color['value'] }}"
    title="{{ $color['name'] }} {{ $color['hex'] }}"
    x-on:click.prevent="chooseRadio({{ \Illuminate\Support\Js::from($color['value']) }}, $el.querySelector('input'))"
>
    <input
        @checked($state === $color['value'])
        @disabled($isDisabled)
        aria-label="{{ $color['name'] }} {{ $color['hex'] }}"
        id="{{ $id }}-{{ $color['value'] }}"
        name="{{ $id }}"
        type="radio"
        value="{{ $color['value'] }}"
    />

    <span
        class="ac-color-picker__swatch-button {{ $isCompact ? 'ac-color-picker__swatch-button--compact' : '' }}"
        data-color-picker-swatch
        style="--ac-color: {{ $color['hex'] }}; --ac-color-border: {{ $color['border'] }};"
    ></span>
</label>
