<?php

namespace App\Services\RadiumBoxRead;

use App\Enums\RadiumBoxReadIdentifierType;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadCommercialOrder;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadCustomer;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadHistoryEvent;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadInvoice;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadLine;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadLookupResult;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadOrderRecord;
use App\Services\RadiumBoxRead\Data\RadiumBoxReadRdOrder;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RadiumBoxReadRepository
{
    /**
     * @var array<string, list<string>>
     */
    public const ALLOWED_COLUMNS = [
        'orders' => [
            'id', 'ordercode', 'ordertype', 'rdservice_order_id', 'invoicecode',
            'userid', 'gst_no', 'payment_status', 'status', 'orderdate', 'branch', 'created_at',
        ],
        'order_rdservice' => [
            'id', 'rdorderid', 'userid', 'gst_no', 'product_name', 'serial_no',
            'status', 'website', 'amc_service_name', 'rd_service_name', 'created_at',
        ],
        'invoice' => [
            'id', 'orderid', 'invoice_number', 'branch', 'service_type', 'created_at',
        ],
        'order_details' => [
            'id', 'orderid', 'product_name', 'productid', 'invoicecode', 'created_at',
        ],
        'order_history' => [
            'id', 'orderid', 'updated_by', 'status', 'description', 'created_at',
        ],
        'users' => [
            'id', 'name', 'phone', 'email', 'gst_no', 'company_name',
        ],
    ];

    /** @var array<string, bool> */
    private static array $writeGuards = [];

    public static function resetWriteGuardsForTests(): void
    {
        self::$writeGuards = [];
    }

    public function lookup(
        RadiumBoxReadIdentifierType $type,
        string $identifier,
        int $page,
        int $perPage,
    ): RadiumBoxReadLookupResult {
        $this->assertReady();
        $this->installWriteGuard();

        $keys = $this->resolveKeys($type, $identifier);
        $total = $keys->count();
        $pageKeys = $keys->forPage($page, $perPage)->values();

        $records = $pageKeys
            ->map(fn (array $key): RadiumBoxReadOrderRecord => $this->hydrate($key, $type))
            ->all();

        return new RadiumBoxReadLookupResult(
            records: $records,
            total: $total,
            page: $page,
            perPage: $perPage,
            identifierType: $type->value,
            identifier: $identifier,
            identifierColumn: $type->describes(),
        );
    }

    public function findCommercial(int $commercialId): ?RadiumBoxReadOrderRecord
    {
        $result = $this->lookup(
            RadiumBoxReadIdentifierType::CommercialId,
            (string) $commercialId,
            page: 1,
            perPage: 1,
        );

        return $result->records[0] ?? null;
    }

    /**
     * @return Collection<int, array{commercial_id: ?int, rd_id: ?int}>
     */
    private function resolveKeys(RadiumBoxReadIdentifierType $type, string $identifier): Collection
    {
        return match ($type) {
            RadiumBoxReadIdentifierType::CommercialId => $this->keysFromCommercialIds(
                $this->select('orders', ['id', 'rdservice_order_id'], function ($query) use ($identifier) {
                    return $query->where('id', (int) $identifier)->orderBy('id');
                }),
            ),
            RadiumBoxReadIdentifierType::Ordercode => $this->keysFromCommercialIds(
                $this->select('orders', ['id', 'rdservice_order_id'], function ($query) use ($identifier) {
                    return $query->where('ordercode', $identifier)->orderBy('id');
                }),
            ),
            RadiumBoxReadIdentifierType::RdserviceOrderId => $this->keysFromCommercialIds(
                $this->select('orders', ['id', 'rdservice_order_id'], function ($query) use ($identifier) {
                    return $query->where('rdservice_order_id', $identifier)->orderBy('id');
                }),
            ),
            RadiumBoxReadIdentifierType::RdId => $this->keysFromRdIds(
                $this->select('order_rdservice', ['id'], function ($query) use ($identifier) {
                    return $query->where('id', (int) $identifier)->orderBy('id');
                }),
            ),
            RadiumBoxReadIdentifierType::Rdorderid => $this->keysFromRdIds(
                $this->select('order_rdservice', ['id'], function ($query) use ($identifier) {
                    return $query->where('rdorderid', $identifier)->orderBy('id');
                }),
            ),
        };
    }

    /**
     * @param  Collection<int, object>  $orders
     * @return Collection<int, array{commercial_id: int, rd_id: ?int}>
     */
    private function keysFromCommercialIds(Collection $orders): Collection
    {
        return $orders
            ->map(fn (object $order): array => [
                'commercial_id' => (int) $order->id,
                'rd_id' => $this->nullableInt($order->rdservice_order_id ?? null),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, object>  $rdOrders
     * @return Collection<int, array{commercial_id: ?int, rd_id: int}>
     */
    private function keysFromRdIds(Collection $rdOrders): Collection
    {
        $keys = collect();

        foreach ($rdOrders as $rdOrder) {
            $rdId = (int) $rdOrder->id;
            $commercials = $this->select('orders', ['id', 'rdservice_order_id'], function ($query) use ($rdId) {
                return $query->where('rdservice_order_id', (string) $rdId)->orderBy('id');
            });

            if ($commercials->isEmpty()) {
                $keys->push(['commercial_id' => null, 'rd_id' => $rdId]);

                continue;
            }

            foreach ($commercials as $order) {
                $keys->push([
                    'commercial_id' => (int) $order->id,
                    'rd_id' => $rdId,
                ]);
            }
        }

        return $keys
            ->sortBy(fn (array $key): string => sprintf('%d-%d', $key['rd_id'], $key['commercial_id'] ?? 0))
            ->values();
    }

    /**
     * @param  array{commercial_id: ?int, rd_id: ?int}  $key
     */
    private function hydrate(array $key, RadiumBoxReadIdentifierType $type): RadiumBoxReadOrderRecord
    {
        $commercial = $key['commercial_id'] !== null
            ? $this->mapCommercial($this->select('orders', self::ALLOWED_COLUMNS['orders'], function ($query) use ($key) {
                return $query->where('id', $key['commercial_id']);
            })->first())
            : null;

        $rdId = $key['rd_id'] ?? $this->nullableInt($commercial?->rdserviceOrderId);
        $rdOrder = $rdId !== null
            ? $this->mapRdOrder($this->select('order_rdservice', self::ALLOWED_COLUMNS['order_rdservice'], function ($query) use ($rdId) {
                return $query->where('id', $rdId);
            })->first())
            : null;

        $userId = $commercial?->userid ?? ($rdOrder?->userid !== null ? (string) $rdOrder->userid : null);
        $customer = $userId !== null
            ? $this->mapCustomer($this->select('users', self::ALLOWED_COLUMNS['users'], function ($query) use ($userId) {
                return $query->where('id', (int) $userId);
            })->first())
            : null;

        $invoices = [];
        $lines = [];
        $history = [];

        if ($commercial !== null) {
            $orderId = (string) $commercial->id;
            $invoices = $this->select('invoice', self::ALLOWED_COLUMNS['invoice'], function ($query) use ($orderId) {
                return $query->where('orderid', $orderId)->orderBy('id');
            })->map(fn (object $row): RadiumBoxReadInvoice => $this->mapInvoice($row))->all();

            $lines = $this->select('order_details', self::ALLOWED_COLUMNS['order_details'], function ($query) use ($orderId) {
                return $query->where('orderid', $orderId)->orderBy('id');
            })->map(fn (object $row): RadiumBoxReadLine => $this->mapLine($row))->all();

            $history = $this->select('order_history', self::ALLOWED_COLUMNS['order_history'], function ($query) use ($commercial) {
                return $query->where('orderid', $commercial->id)->orderBy('id');
            })->map(fn (object $row): RadiumBoxReadHistoryEvent => $this->mapHistory($row))->all();
        }

        return new RadiumBoxReadOrderRecord(
            commercial: $commercial,
            rdOrder: $rdOrder,
            customer: $customer,
            invoices: $invoices,
            lines: $lines,
            history: $history,
            matchedIdentifierType: $type->value,
            matchedIdentifierColumn: $type->describes(),
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  callable(Builder): Builder  $configure
     * @return Collection<int, object>
     */
    private function select(string $table, array $columns, callable $configure): Collection
    {
        $allowed = self::ALLOWED_COLUMNS[$table] ?? null;
        if ($allowed === null) {
            throw new InvalidArgumentException('Table is not on the RadiumBox read allowlist.');
        }

        foreach ($columns as $column) {
            if (! in_array($column, $allowed, true)) {
                throw new InvalidArgumentException('Column is not on the RadiumBox read allowlist.');
            }
        }

        $query = $configure(
            DB::connection($this->connectionName())->table($table)->select($columns)
        );

        return $query->get();
    }

    private function mapCommercial(?object $row): ?RadiumBoxReadCommercialOrder
    {
        if ($row === null) {
            return null;
        }

        return new RadiumBoxReadCommercialOrder(
            id: (int) $row->id,
            ordercode: $this->nullableString($row->ordercode ?? null),
            ordertype: $this->nullableString($row->ordertype ?? null),
            rdserviceOrderId: $this->nullableString($row->rdservice_order_id ?? null),
            invoicecode: $this->nullableString($row->invoicecode ?? null),
            userid: $this->nullableString($row->userid ?? null),
            gstNo: $this->nullableString($row->gst_no ?? null),
            paymentStatus: $this->nullableString($row->payment_status ?? null),
            status: $this->nullableString($row->status ?? null),
            orderdate: $this->nullableString($row->orderdate ?? null),
            branch: $this->nullableString($row->branch ?? null),
            createdAt: $this->nullableString($row->created_at ?? null),
        );
    }

    private function mapRdOrder(?object $row): ?RadiumBoxReadRdOrder
    {
        if ($row === null) {
            return null;
        }

        return new RadiumBoxReadRdOrder(
            id: (int) $row->id,
            rdorderid: $this->nullableString($row->rdorderid ?? null),
            userid: $this->nullableInt($row->userid ?? null),
            gstNo: $this->nullableString($row->gst_no ?? null),
            productName: $this->nullableString($row->product_name ?? null),
            serialNo: $this->nullableString($row->serial_no ?? null),
            status: $this->nullableString($row->status ?? null),
            website: $this->nullableString($row->website ?? null),
            amcServiceName: $this->nullableString($row->amc_service_name ?? null),
            rdServiceName: $this->nullableString($row->rd_service_name ?? null),
            createdAt: $this->nullableString($row->created_at ?? null),
        );
    }

    private function mapCustomer(?object $row): ?RadiumBoxReadCustomer
    {
        if ($row === null) {
            return null;
        }

        return new RadiumBoxReadCustomer(
            id: (int) $row->id,
            name: $this->nullableString($row->name ?? null),
            phone: $this->nullableString($row->phone ?? null),
            email: $this->nullableString($row->email ?? null),
            gstNo: $this->nullableString($row->gst_no ?? null),
            companyName: $this->nullableString($row->company_name ?? null),
        );
    }

    private function mapInvoice(object $row): RadiumBoxReadInvoice
    {
        return new RadiumBoxReadInvoice(
            id: (int) $row->id,
            orderid: $this->nullableString($row->orderid ?? null),
            invoiceNumber: $this->nullableString($row->invoice_number ?? null),
            branch: $this->nullableString($row->branch ?? null),
            serviceType: $this->nullableString($row->service_type ?? null),
            createdAt: $this->nullableString($row->created_at ?? null),
        );
    }

    private function mapLine(object $row): RadiumBoxReadLine
    {
        return new RadiumBoxReadLine(
            id: (int) $row->id,
            orderid: $this->nullableString($row->orderid ?? null),
            productName: $this->nullableString($row->product_name ?? null),
            productid: $this->nullableString($row->productid ?? null),
            invoicecode: $this->nullableString($row->invoicecode ?? null),
            createdAt: $this->nullableString($row->created_at ?? null),
        );
    }

    private function mapHistory(object $row): RadiumBoxReadHistoryEvent
    {
        return new RadiumBoxReadHistoryEvent(
            id: (int) $row->id,
            orderid: (int) $row->orderid,
            updatedBy: $this->nullableString($row->updated_by ?? null),
            status: $this->nullableString($row->status ?? null),
            description: $this->nullableString($row->description ?? null),
            createdAt: $this->nullableString($row->created_at ?? null),
        );
    }

    public function assertReady(): void
    {
        if (! (bool) config('radiumbox_read.enabled')) {
            throw new RadiumBoxReadDisabledException;
        }

        $connection = $this->connectionName();
        $connections = config('database.connections', []);
        if (! is_array($connections) || ! array_key_exists($connection, $connections)) {
            throw new RadiumBoxReadMisconfiguredException('named connection is missing.');
        }

        $config = $connections[$connection];
        $driver = (string) ($config['driver'] ?? '');
        $database = trim((string) ($config['database'] ?? ''));

        if ($database === '') {
            throw new RadiumBoxReadMisconfiguredException('database name is empty.');
        }

        if ($driver !== 'sqlite') {
            $host = trim((string) ($config['host'] ?? ''));
            $username = trim((string) ($config['username'] ?? ''));
            if ($host === '' || $username === '') {
                throw new RadiumBoxReadMisconfiguredException('host or username is empty.');
            }
        }
    }

    private function connectionName(): string
    {
        $name = (string) config('radiumbox_read.connection', 'radiumbox_read');
        $default = (string) config('database.default');

        if ($name === '' || $name === $default) {
            throw new RadiumBoxReadMisconfiguredException('connection must be a dedicated name, not the default Desk connection.');
        }

        $readDatabase = (string) (config('database.connections.'.$name.'.database') ?? '');
        $defaultDatabase = (string) (config('database.connections.'.$default.'.database') ?? '');
        if ($readDatabase !== '' && $defaultDatabase !== '' && $readDatabase === $defaultDatabase && $this->connectionDriver($name) !== 'sqlite') {
            throw new RadiumBoxReadMisconfiguredException('read database must not be the Desk database.');
        }

        return $name;
    }

    private function connectionDriver(string $name): string
    {
        return (string) (config('database.connections.'.$name.'.driver') ?? '');
    }

    private function installWriteGuard(): void
    {
        $name = $this->connectionName();
        if (isset(self::$writeGuards[$name])) {
            return;
        }

        DB::connection($name)->beforeExecuting(function (string $sql, mixed $_bindings, Connection $connection) use ($name): void {
            if ($connection->getName() !== $name) {
                return;
            }

            if (preg_match('/^\s*(select|pragma)\b/i', $sql) === 1) {
                return;
            }

            throw new RadiumBoxReadWriteAttemptException($sql);
        });

        self::$writeGuards[$name] = true;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
