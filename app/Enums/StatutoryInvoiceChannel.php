<?php

namespace App\Enums;

enum StatutoryInvoiceChannel: string
{
    case DeskPos = 'desk_pos';
    case RdServiceIn = 'rdservice_in';
    case RadiumBoxCom = 'radiumbox_com';
    case RdServiceNet = 'rdservice_net';
    case RadiumSignCom = 'radiumsign_com';
    case DeskService = 'desk_service';
    case DeskInventory = 'desk_inventory';
    case Future = 'future';

    public function label(): string
    {
        return match ($this) {
            self::DeskPos => 'Desk POS',
            self::RdServiceIn => 'rdservice.in',
            self::RadiumBoxCom => 'radiumbox.com',
            self::RdServiceNet => 'rdservice.net',
            self::RadiumSignCom => 'radiumsign.com',
            self::DeskService => 'Desk service POS',
            self::DeskInventory => 'Desk inventory',
            self::Future => 'Future channel',
        };
    }
}
