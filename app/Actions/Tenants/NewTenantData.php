<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Enums\TenantType;

final readonly class NewTenantData
{
    public function __construct(
        public string $name,
        public string $slug,
        public TenantType $type,
        public ?string $contactEmail,
        public ?string $phone,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
    ) {}
}
