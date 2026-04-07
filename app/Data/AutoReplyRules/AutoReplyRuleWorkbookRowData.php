<?php

namespace App\Data\AutoReplyRules;

readonly class AutoReplyRuleWorkbookRowData
{
    /**
     * @param  list<int>  $channelIds
     * @param  list<int>  $requiredTagIds
     * @param  list<int>  $excludedTagIds
     * @param  list<int>  $assignTagIds
     * @param  list<int>  $removeTagIds
     */
    public function __construct(
        public int $rowNumber,
        public ?int $id,
        public ?string $name,
        public ?int $autoReplyCategoryId,
        public bool $isActive,
        public int $priority,
        public string $matchScope,
        public ?string $keyword,
        public ?string $contactPhoneCondition,
        public string $replyText,
        public ?string $buttonKind,
        public ?string $buttonText,
        public ?string $buttonUrl,
        public array $channelIds,
        public array $requiredTagIds,
        public array $excludedTagIds,
        public array $assignTagIds,
        public array $removeTagIds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'id' => $this->id,
            'name' => $this->name,
            'auto_reply_category_id' => $this->autoReplyCategoryId,
            'is_active' => $this->isActive,
            'priority' => $this->priority,
            'match_scope' => $this->matchScope,
            'keyword' => $this->keyword,
            'contact_phone_condition' => $this->contactPhoneCondition,
            'reply_text' => $this->replyText,
            'button_kind' => $this->buttonKind,
            'button_text' => $this->buttonText,
            'button_url' => $this->buttonUrl,
            'channel_ids' => $this->channelIds,
            'required_tag_ids' => $this->requiredTagIds,
            'excluded_tag_ids' => $this->excludedTagIds,
            'assign_tag_ids' => $this->assignTagIds,
            'remove_tag_ids' => $this->removeTagIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rowNumber: (int) ($data['row_number'] ?? 0),
            id: isset($data['id']) ? (int) $data['id'] : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            autoReplyCategoryId: isset($data['auto_reply_category_id']) ? (int) $data['auto_reply_category_id'] : null,
            isActive: (bool) ($data['is_active'] ?? false),
            priority: (int) ($data['priority'] ?? 10),
            matchScope: (string) ($data['match_scope'] ?? ''),
            keyword: isset($data['keyword']) ? (string) $data['keyword'] : null,
            contactPhoneCondition: isset($data['contact_phone_condition']) ? (string) $data['contact_phone_condition'] : null,
            replyText: (string) ($data['reply_text'] ?? ''),
            buttonKind: isset($data['button_kind']) ? (string) $data['button_kind'] : null,
            buttonText: isset($data['button_text']) ? (string) $data['button_text'] : null,
            buttonUrl: isset($data['button_url']) ? (string) $data['button_url'] : null,
            channelIds: array_values(array_map('intval', $data['channel_ids'] ?? [])),
            requiredTagIds: array_values(array_map('intval', $data['required_tag_ids'] ?? [])),
            excludedTagIds: array_values(array_map('intval', $data['excluded_tag_ids'] ?? [])),
            assignTagIds: array_values(array_map('intval', $data['assign_tag_ids'] ?? [])),
            removeTagIds: array_values(array_map('intval', $data['remove_tag_ids'] ?? [])),
        );
    }
}
