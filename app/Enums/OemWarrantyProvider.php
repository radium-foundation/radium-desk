<?php

namespace App\Enums;

enum OemWarrantyProvider: string
{
    case Mantratec = 'mantratec';
    case Morpho = 'morpho';

    public function label(): string
    {
        return match ($this) {
            self::Mantratec => 'MantraTec',
            self::Morpho => 'Morpho / IDEMIA',
        };
    }

    public function portalUrl(): string
    {
        return match ($this) {
            self::Mantratec => 'https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx',
            self::Morpho => 'https://rdserviceonline.com/pages/172iserialvalidatenumber',
        };
    }
}
