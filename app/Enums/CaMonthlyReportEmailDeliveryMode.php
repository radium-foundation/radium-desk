<?php

namespace App\Enums;

enum CaMonthlyReportEmailDeliveryMode: string
{
    case Attachment = 'attachment';
    case DownloadLink = 'download_link';

    public function label(): string
    {
        return match ($this) {
            self::Attachment => 'Email attachment',
            self::DownloadLink => 'Secure download link',
        };
    }
}
