<?php

namespace App\Services\RadiumBoxRead;

use App\Enums\RadiumBoxReadIdentifierType;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadLookupResult;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadOrderRecord;

class RadiumBoxReadService
{
    public const AUDIT_LOOKUP = 'radiumbox.read.lookup';

    public const AUDIT_SHOW = 'radiumbox.read.show';

    public function __construct(
        private readonly RadiumBoxReadRepository $repository,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function lookup(
        RadiumBoxReadIdentifierType $type,
        string $identifier,
        int $page,
        int $perPage,
        User $viewer,
    ): RadiumBoxReadLookupResult {
        $result = $this->repository->lookup($type, $identifier, $page, $perPage);

        $this->auditLogService->log(
            userId: $viewer->id,
            event: self::AUDIT_LOOKUP,
            auditable: $viewer,
            newValues: [
                'identifier_type' => $type->value,
                'identifier_column' => $type->describes(),
                'identifier' => $identifier,
                'result_count' => $result->total,
                'page' => $page,
            ],
        );

        return $result;
    }

    public function findCommercial(int $commercialId, User $viewer): ?RadiumBoxReadOrderRecord
    {
        $record = $this->repository->findCommercial($commercialId);

        $this->auditLogService->log(
            userId: $viewer->id,
            event: self::AUDIT_SHOW,
            auditable: $viewer,
            newValues: [
                'identifier_type' => RadiumBoxReadIdentifierType::CommercialId->value,
                'identifier_column' => RadiumBoxReadIdentifierType::CommercialId->describes(),
                'identifier' => (string) $commercialId,
                'found' => $record !== null,
            ],
        );

        return $record;
    }
}
