<?php

namespace App\Services\HardwareFulfilment;

use Illuminate\Http\Request;

/**
 * Hardware dashboard row pagination. Progressive mode uses a smaller first page
 * (faster first paint) and the standard page size thereafter.
 */
final class HardwareDashboardPageSizer
{
    public function __construct(
        public readonly int $initialPerPage,
        public readonly int $standardPerPage,
        public readonly int $skeletonRowCount,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('dashboard');

        return new self(
            max(1, (int) ($config['hardware_rows_initial_per_page'] ?? 10)),
            max(1, (int) ($config['hardware_rows_per_page'] ?? 40)),
            max(1, (int) ($config['hardware_skeleton_row_count'] ?? 10)),
        );
    }

    public function progressiveEnabled(Request $request): bool
    {
        return $request->boolean('hw_progressive');
    }

    /**
     * @return array{page: int, offset: int, limit: int, per_page: int}
     */
    public function sliceFor(Request $request, int $page): array
    {
        $page = max(1, $page);

        if (! $this->progressiveEnabled($request)) {
            $perPage = $this->standardPerPage;

            return [
                'page' => $page,
                'offset' => ($page - 1) * $perPage,
                'limit' => $perPage,
                'per_page' => $perPage,
            ];
        }

        if ($page === 1) {
            return [
                'page' => 1,
                'offset' => 0,
                'limit' => $this->initialPerPage,
                'per_page' => $this->initialPerPage,
            ];
        }

        $offset = $this->initialPerPage + ($page - 2) * $this->standardPerPage;

        return [
            'page' => $page,
            'offset' => $offset,
            'limit' => $this->standardPerPage,
            'per_page' => $this->standardPerPage,
        ];
    }

    public function lastPage(int $total, Request $request): int
    {
        if ($total <= 0) {
            return 1;
        }

        if (! $this->progressiveEnabled($request)) {
            return max(1, (int) ceil($total / $this->standardPerPage));
        }

        if ($total <= $this->initialPerPage) {
            return 1;
        }

        $remaining = $total - $this->initialPerPage;

        return 1 + (int) ceil($remaining / $this->standardPerPage);
    }
}
