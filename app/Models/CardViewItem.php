<?php

namespace App\Models;

use App\Services\Contacts\ContactCardViewBlockRegistry;
use App\Services\Dialogs\DialogCardViewBlockRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CardViewItem extends Model
{
    public const TYPE_FIELD = 'field';

    public const TYPE_BLOCK = 'block';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'card_view_section_id',
        'item_key',
        'item_type',
        'field_dictionary_field_id',
        'sort_order',
        'is_visible',
        'is_system',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (CardViewItem $item): void {
            $item->guardKnownBlockKey();
            $item->guardUniqueItemWithinView();
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CardViewSection::class, 'card_view_section_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(FieldDictionaryField::class, 'field_dictionary_field_id');
    }

    private function guardKnownBlockKey(): void
    {
        if ($this->item_type !== self::TYPE_BLOCK) {
            return;
        }

        $view = $this->resolveParentView();

        if (! $view instanceof CardView) {
            return;
        }

        if ($view->context !== CardView::CONTEXT_CARD) {
            return;
        }

        $registry = match ($view->entity) {
            CardView::ENTITY_CONTACT => app(ContactCardViewBlockRegistry::class),
            CardView::ENTITY_DIALOG => app(DialogCardViewBlockRegistry::class),
            default => null,
        };

        if ($registry === null || $registry->contains((string) $this->item_key)) {
            return;
        }

        throw ValidationException::withMessages([
            'item_key' => 'Неизвестный блок вида карточки.',
        ]);
    }

    private function guardUniqueItemWithinView(): void
    {
        $itemKey = (string) $this->item_key;

        if ($itemKey === '') {
            return;
        }

        $view = $this->resolveParentView();

        if (! $view instanceof CardView) {
            return;
        }

        $exists = self::query()
            ->where('item_key', $itemKey)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->whereHas('section.tab.view', fn ($query) => $query->whereKey($view->getKey()))
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'item_key' => 'Этот элемент уже есть в виде карточки.',
        ]);
    }

    private function resolveParentView(): ?CardView
    {
        $section = $this->section()
            ->with('tab.view')
            ->first();

        return $section?->tab?->view;
    }
}
