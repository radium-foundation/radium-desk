<?php

namespace App\Enums;

enum CommunicationActionTriggerSource: string
{
    case Manual = 'manual';
    case Customer360 = 'customer360';
    case Workspace = 'workspace';
    case Automation = 'automation';
    case Ira = 'ira';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Customer360 => 'Customer 360',
            self::Workspace => 'Workspace',
            self::Automation => 'Automation',
            self::Ira => 'IRA',
            self::Api => 'API',
        };
    }

    public static function fromWorkspaceContext(WorkspaceContext $workspaceContext): self
    {
        return match ($workspaceContext) {
            WorkspaceContext::Customer => self::Customer360,
            WorkspaceContext::Api => self::Api,
            WorkspaceContext::Ai => self::Ira,
            default => self::Workspace,
        };
    }
}
