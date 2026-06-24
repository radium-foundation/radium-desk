<?php

namespace App\Services;

class RemarkMentionFormatter
{
    public function format(string $body): string
    {
        $escaped = e($body);

        return preg_replace(
            '/@([\p{L}\p{M}\'.]+)/u',
            '<span class="remark-mention">@$1</span>',
            $escaped,
        ) ?? $escaped;
    }
}
