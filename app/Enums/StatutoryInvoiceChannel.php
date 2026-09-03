<?php

namespace App\Enums;

enum StatutoryInvoiceChannel: string
{
    case DeskPos = 'desk_pos';
    case RdServiceIn = 'rdservice_in';
    case RadiumBoxCom = 'radiumbox_com';
    case RdServiceNet = 'rdservice_net';
    case RadiumSignCom = 'radiumsign_com';
    case Future = 'future';

    public function label(): string
    {
        return match ($this) {
            self::DeskPos => 'Desk POS',
            self::RdServiceIn => 'rdservice.in',
            self::RadiumBoxCom => 'radiumbox.com',
            self::RdServiceNet => 'rdservice.net',
            self::RadiumSignCom => 'radiumsign.com',
            self::Future => 'Future channel',
        };
    }
}
