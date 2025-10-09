<?php
namespace ESolution\Inventory\DTO;

class DocumentData {
    public function __construct(
        public string $type,
        public string $date,
        public ?string $ref = null,
        public ?string $external_id = null,
        public array $lines = [],
        public array $meta = [],
    ){}

    public static function make(array $data): self {
        return new self(
            type: $data['type'],
            date: $data['date'],
            ref: $data['ref'] ?? null,
            external_id: $data['external_id'] ?? null,
            lines: $data['lines'] ?? [],
            meta: $data['meta'] ?? []
        );
    }
}
