<?php

namespace App\Models\Builders;

use App\Enums\IraMemoryStatus;
use App\Models\IncomingEmailLearningRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Remaps Learning Rule column names onto ira_memories physical columns.
 *
 * Keeps IncomingEmailLearningRulesService query shapes unchanged during M1.
 *
 * @extends Builder<IncomingEmailLearningRule>
 */
class IncomingEmailLearningRuleBuilder extends Builder
{
    private const COLUMN_MAP = [
        'rule_type' => 'pattern_kind',
        'match_value' => 'pattern_value',
        'decision_type' => 'decision_kind',
        'created_by' => 'created_by_user_id',
    ];

    /**
     * @param  mixed  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            return parent::where($this->mapColumnArray($column), $operator, $value, $boolean);
        }

        if (is_string($column)) {
            $column = self::COLUMN_MAP[$column] ?? $column;
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * @param  mixed  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        if (is_array($column)) {
            return parent::orWhere($this->mapColumnArray($column), $operator, $value);
        }

        if (is_string($column)) {
            $column = self::COLUMN_MAP[$column] ?? $column;
        }

        return parent::orWhere($column, $operator, $value);
    }

    /**
     * @param  mixed  $column
     * @param  mixed  $values
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if (is_string($column)) {
            $column = self::COLUMN_MAP[$column] ?? $column;
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * @param  array<mixed, mixed>  $columns
     * @return array<mixed, mixed>
     */
    private function mapColumnArray(array $columns): array
    {
        $mapped = [];

        foreach ($columns as $key => $value) {
            if (! is_string($key)) {
                $mapped[$key] = $value;

                continue;
            }

            if ($key === 'enabled') {
                $mapped['status'] = filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    ? IraMemoryStatus::Active->value
                    : IraMemoryStatus::Disabled->value;

                continue;
            }

            $mapped[self::COLUMN_MAP[$key] ?? $key] = $value;
        }

        return $mapped;
    }
}
