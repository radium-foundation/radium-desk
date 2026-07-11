<?php

namespace App\Support\Customer360;

final class RequestCorrectSerialMenuPresenter
{
    /**
     * @param  array{requested?: bool, requested_at_label?: string|null}  $correctSerialRequestState
     * @return array{
     *     visible: bool,
     *     type: 'trigger'|'status'|'hidden',
     *     label: string,
     *     enabled: bool,
     *     status: 'available'|'pending'|'re-request'|'hidden',
     *     requested_at_label: string|null,
     * }
     */
    public static function resolve(bool $canRequestCorrectSerial, array $correctSerialRequestState): array
    {
        $requested = (bool) ($correctSerialRequestState['requested'] ?? false);
        $requestedAtLabel = filled($correctSerialRequestState['requested_at_label'] ?? null)
            ? (string) $correctSerialRequestState['requested_at_label']
            : null;

        if ($canRequestCorrectSerial && $requested) {
            return [
                'visible' => true,
                'type' => 'trigger',
                'label' => 'Re-request Serial',
                'enabled' => true,
                'status' => 're-request',
                'requested_at_label' => $requestedAtLabel,
            ];
        }

        if ($canRequestCorrectSerial) {
            return [
                'visible' => true,
                'type' => 'trigger',
                'label' => 'Request Serial',
                'enabled' => true,
                'status' => 'available',
                'requested_at_label' => null,
            ];
        }

        if ($requested) {
            return [
                'visible' => true,
                'type' => 'status',
                'label' => 'Serial Requested',
                'enabled' => false,
                'status' => 'pending',
                'requested_at_label' => $requestedAtLabel,
            ];
        }

        return [
            'visible' => false,
            'type' => 'hidden',
            'label' => '',
            'enabled' => false,
            'status' => 'hidden',
            'requested_at_label' => null,
        ];
    }
}
