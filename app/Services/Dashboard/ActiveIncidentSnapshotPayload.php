<?php

namespace App\Services\Dashboard;

use App\Models\BusinessHold;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SupportAppointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Serializable active-incident snapshot for cross-request cache stores.
 *
 * Stores plain arrays only (no Eloquent instances). DashboardSnapshotStore
 * rehydrates models in-memory per request so KPI/row consumers stay unchanged.
 */
final class ActiveIncidentSnapshotPayload
{
    public const VERSION = 1;

    /**
     * @var array<string, class-string<Model>>
     */
    private const MODEL_ALIASES = [
        'incident' => Incident::class,
        'order' => Order::class,
        'user' => User::class,
        'device_model' => DeviceModel::class,
        'refund_request' => RefundRequest::class,
        'waiting_state' => IncidentWaitingState::class,
        'business_hold' => BusinessHold::class,
        'support_appointment' => SupportAppointment::class,
        'role' => Role::class,
        'pivot' => Pivot::class,
    ];

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return array{v: int, incidents: list<array<string, mixed>>}
     */
    public function encode(Collection $incidents): array
    {
        $rows = [];

        foreach ($incidents as $incident) {
            if (! $incident instanceof Incident) {
                continue;
            }

            $encoded = $this->encodeModel($incident);

            if ($encoded !== null) {
                $rows[] = $encoded;
            }
        }

        return [
            'v' => self::VERSION,
            'incidents' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return EloquentCollection<int, Incident>|null
     */
    public function decode(array $payload): ?EloquentCollection
    {
        if (($payload['v'] ?? null) !== self::VERSION) {
            return null;
        }

        if (! isset($payload['incidents']) || ! is_array($payload['incidents'])) {
            return null;
        }

        $incidents = [];

        foreach ($payload['incidents'] as $row) {
            if (! is_array($row)) {
                return null;
            }

            $model = $this->decodeModel($row);

            if (! $model instanceof Incident) {
                return null;
            }

            $incidents[] = $model;
        }

        return new EloquentCollection($incidents);
    }

    public function isValidPayload(mixed $payload): bool
    {
        return is_array($payload)
            && ($payload['v'] ?? null) === self::VERSION
            && isset($payload['incidents'])
            && is_array($payload['incidents']);
    }

    /**
     * @return array{type: string, alias: string, attributes: array<string, mixed>, relations: array<string, mixed>}|null
     */
    private function encodeModel(Model $model): ?array
    {
        $alias = $this->aliasForClass($model::class);

        if ($alias === null) {
            return null;
        }

        $relations = [];

        foreach ($model->getRelations() as $name => $related) {
            $relations[$name] = $this->encodeRelation($related);
        }

        return [
            'type' => 'model',
            'alias' => $alias,
            'attributes' => $model->getAttributes(),
            'relations' => $relations,
        ];
    }

    private function encodeRelation(mixed $related): mixed
    {
        if ($related === null) {
            return ['type' => 'null'];
        }

        if ($related instanceof Model) {
            return $this->encodeModel($related) ?? ['type' => 'null'];
        }

        if ($related instanceof Collection) {
            $items = [];

            foreach ($related as $item) {
                if (! $item instanceof Model) {
                    continue;
                }

                $encoded = $this->encodeModel($item);

                if ($encoded !== null) {
                    $items[] = $encoded;
                }
            }

            return [
                'type' => 'collection',
                'items' => $items,
            ];
        }

        return ['type' => 'null'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function decodeModel(array $row): ?Model
    {
        if (($row['type'] ?? null) !== 'model') {
            return null;
        }

        $alias = $row['alias'] ?? null;

        if (! is_string($alias) || ! isset(self::MODEL_ALIASES[$alias])) {
            return null;
        }

        $class = self::MODEL_ALIASES[$alias];
        $attributes = $row['attributes'] ?? [];

        if (! is_array($attributes)) {
            return null;
        }

        /** @var Model $model */
        $model = (new $class)->newFromBuilder($attributes);

        $relations = $row['relations'] ?? [];

        if (! is_array($relations)) {
            return $model;
        }

        foreach ($relations as $name => $relationPayload) {
            if (! is_string($name)) {
                continue;
            }

            $model->setRelation($name, $this->decodeRelation($relationPayload));
        }

        return $model;
    }

    private function decodeRelation(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return null;
        }

        return match ($payload['type'] ?? null) {
            'null' => null,
            'model' => $this->decodeModel($payload),
            'collection' => $this->decodeCollection($payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return EloquentCollection<int, Model>
     */
    private function decodeCollection(array $payload): EloquentCollection
    {
        $items = [];

        foreach ($payload['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $model = $this->decodeModel($item);

            if ($model !== null) {
                $items[] = $model;
            }
        }

        return new EloquentCollection($items);
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function aliasForClass(string $class): ?string
    {
        foreach (self::MODEL_ALIASES as $alias => $mapped) {
            if ($class === $mapped || is_subclass_of($class, $mapped)) {
                return $alias;
            }
        }

        return null;
    }
}
