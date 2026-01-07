<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Rule that checks if a model's attribute matches user's attribute
 * Example: ticket->department_id === user->department_id
 */
class AttributeRule extends BaseAccessRule
{
    protected ?string $modelAttribute;
    protected ?string $userAttribute;
    protected mixed $staticValue;
    protected ?string $operator;

    public function __construct(
        ?string $model_attribute = null,
        ?string $user_attribute = null,
        mixed $static_value = null,
        ?string $operator = '=',
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);

        $this->modelAttribute = $model_attribute;
        $this->userAttribute = $user_attribute;
        $this->staticValue = $static_value;
        $this->operator = $operator ?? '=';
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        if (!$this->modelAttribute) {
            return true; // No restriction if not configured
        }

        $modelValue = data_get($model, $this->modelAttribute);

        // Compare with user attribute
        if ($this->userAttribute) {
            $userValue = data_get($user, $this->userAttribute);
            return $this->compare($modelValue, $userValue, $this->operator);
        }

        // Compare with static value
        if ($this->staticValue !== null) {
            return $this->compare($modelValue, $this->staticValue, $this->operator);
        }

        return true;
    }

    public function scope($query, Authenticatable $user)
    {
        if (!$this->modelAttribute) {
            return $query;
        }

        if ($this->userAttribute) {
            $userValue = data_get($user, $this->userAttribute);
            return $this->applyOperator($query, $this->modelAttribute, $userValue, $this->operator);
        }

        if ($this->staticValue !== null) {
            return $this->applyOperator($query, $this->modelAttribute, $this->staticValue, $this->operator);
        }

        return $query;
    }

    protected function compare(mixed $a, mixed $b, string $operator): bool
    {
        return match ($operator) {
            '=' => $a == $b,
            '!=' => $a != $b,
            '>' => $a > $b,
            '>=' => $a >= $b,
            '<' => $a < $b,
            '<=' => $a <= $b,
            'in' => is_array($b) && in_array($a, $b),
            'not_in' => is_array($b) && !in_array($a, $b),
            default => $a == $b,
        };
    }

    protected function applyOperator($query, string $column, mixed $value, string $operator)
    {
        return match ($operator) {
            '=' => $query->where($column, '=', $value),
            '!=' => $query->where($column, '!=', $value),
            '>' => $query->where($column, '>', $value),
            '>=' => $query->where($column, '>=', $value),
            '<' => $query->where($column, '<', $value),
            '<=' => $query->where($column, '<=', $value),
            'in' => $query->whereIn($column, (array) $value),
            'not_in' => $query->whereNotIn($column, (array) $value),
            default => $query->where($column, '=', $value),
        };
    }
}
