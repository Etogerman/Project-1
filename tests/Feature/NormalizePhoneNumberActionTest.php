<?php

namespace Tests\Feature;

use App\Services\Contacts\NormalizePhoneNumberAction;
use Tests\TestCase;

class NormalizePhoneNumberActionTest extends TestCase
{
    public function test_it_normalizes_common_russian_formats_to_plus_seven(): void
    {
        $action = app(NormalizePhoneNumberAction::class);

        $this->assertSame('+79991234567', $action->handle('+7 999 123-45-67'));
        $this->assertSame('+79991234567', $action->handle('8 (999) 123-45-67'));
        $this->assertSame('+79991234567', $action->handle('79991234567'));
    }

    public function test_it_keeps_explicit_international_numbers_in_plus_format(): void
    {
        $this->assertSame('+491512345678', app(NormalizePhoneNumberAction::class)->handle('+49 151 2345678'));
    }

    public function test_it_returns_empty_string_for_blank_or_invalid_values(): void
    {
        $action = app(NormalizePhoneNumberAction::class);

        $this->assertSame('', $action->handle(''));
        $this->assertSame('', $action->handle('номер не указан'));
    }
}
