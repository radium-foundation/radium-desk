<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadCustomer
{
    public function __construct(
        public int $id,
        public ?string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $gstNo,
        public ?string $companyName,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gst_no' => $this->gstNo,
            'company_name' => $this->companyName,
        ];
    }
}
