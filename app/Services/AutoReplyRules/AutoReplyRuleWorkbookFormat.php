<?php

namespace App\Services\AutoReplyRules;

final class AutoReplyRuleWorkbookFormat
{
    public const SHEET_RULES = 'rules';

    public const SHEET_CATEGORIES = 'categories_ref';

    public const SHEET_CHANNELS = 'channels_ref';

    public const SHEET_TAGS = 'tags_ref';

    public const SHEET_INSTRUCTIONS = 'instructions';

    public const BUTTON_KIND_NONE = 'none';

    public const BUTTON_KIND_REQUEST_PHONE = 'request_phone';

    public const BUTTON_KIND_LINK = 'link';

    /**
     * @return list<string>
     */
    public static function rulesColumns(): array
    {
        return [
            'id',
            'name',
            'category_name',
            'is_active',
            'priority',
            'match_scope',
            'keyword',
            'contact_phone_condition',
            'reply_text',
            'button_kind',
            'button_text',
            'button_url',
            'channel_ids',
            'required_tag_names',
            'excluded_tag_names',
            'assign_tag_names',
            'remove_tag_names',
        ];
    }

    /**
     * @return list<string>
     */
    public static function instructionLines(): array
    {
        return [
            'id пустой -> create, id существующий -> update, id не найден -> error',
            'category_name должен совпадать с существующей категорией или быть пустым',
            'is_active: 1/0 или true/false',
            'match_scope: exact_keyword, contains_text, exact_parameter, exact_text_or_parameter, any_inbound',
            'contact_phone_condition: пусто, has_phone, missing_phone',
            'button_kind: none, request_phone, link',
            'button_kind link требует button_text и button_url',
            'channel_ids и списки тегов задаются через ;',
            'import не удаляет правила, которых нет в файле',
        ];
    }

    /**
     * @param  list<string>  $values
     */
    public static function formatList(array $values): string
    {
        return implode(';', array_values(array_filter(array_map(
            fn (string $value): string => trim($value),
            $values,
        ), fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    public static function parseList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $text = trim((string) $value);

        if ($text === '') {
            return [];
        }

        $text = str_replace(["\r\n", "\r", "\n"], ';', $text);

        return array_values(array_unique(array_filter(array_map(
            fn (string $item): string => trim($item),
            explode(';', $text),
        ), fn (string $item): bool => $item !== '')));
    }

    public static function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== ''
            ? $normalized
            : null;
    }
}
