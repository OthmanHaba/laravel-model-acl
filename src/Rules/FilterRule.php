<?php

declare(strict_types=1);

namespace OthmanHaba\LaravelModelAcl\Rules;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restrict access with a list of column/operator/value clauses, e.g.
 * "priority > 3 AND status = open OR amount <= 100".
 *
 * Each clause after the first carries a boolean ('and' | 'or'). Clauses are
 * grouped so that AND binds tighter than OR (standard SQL precedence), and the
 * same grouping drives both passes() and scope() so per-record and per-query
 * checks always agree.
 */
class FilterRule extends BaseAccessRule
{
    /** @var array<int, array{column:string, operator:string, value:mixed, boolean?:string}> */
    protected array $clauses;

    public function __construct(
        ?array $clauses = null,
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);

        $this->clauses = array_values(array_filter(
            $clauses ?? [],
            fn ($c) => is_array($c) && ! empty($c['column']) && ! empty($c['operator']),
        ));
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        if (empty($this->clauses)) {
            return true; // No restriction if not configured
        }

        // OR across groups; AND within each group.
        foreach ($this->groups() as $group) {
            $groupPasses = true;
            foreach ($group as $clause) {
                if (! $this->clauseMatches(data_get($model, $clause['column']), $clause)) {
                    $groupPasses = false;
                    break;
                }
            }
            if ($groupPasses) {
                return true;
            }
        }

        return false;
    }

    public function scope(Builder $query, Authenticatable $user): Builder
    {
        if (empty($this->clauses)) {
            return $query;
        }

        return $query->where(function (Builder $outer) {
            foreach ($this->groups() as $group) {
                $outer->orWhere(function (Builder $q) use ($group) {
                    foreach ($group as $clause) {
                        $this->applyClause($q, $clause);
                    }
                });
            }
        });
    }

    /**
     * Split the clauses into AND-groups, starting a new group before every
     * clause joined with 'or'. Groups are later OR-ed together.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function groups(): array
    {
        $groups = [];
        $current = [];

        foreach ($this->clauses as $i => $clause) {
            $boolean = $i === 0 ? 'and' : strtolower($clause['boolean'] ?? 'and');

            if ($boolean === 'or' && $current) {
                $groups[] = $current;
                $current = [];
            }

            $current[] = $clause;
        }

        if ($current) {
            $groups[] = $current;
        }

        return $groups;
    }

    protected function clauseMatches(mixed $actual, array $clause): bool
    {
        $value = $clause['value'] ?? null;

        return match ($clause['operator']) {
            '!=' => $actual != $value,
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            'in' => in_array($actual, $this->list($value)),
            'not_in' => ! in_array($actual, $this->list($value)),
            'contains' => str_contains((string) $actual, (string) $value),
            default => $actual == $value,
        };
    }

    protected function applyClause(Builder $query, array $clause): void
    {
        $column = $clause['column'];
        $value = $clause['value'] ?? null;

        match ($clause['operator']) {
            '!=' => $query->where($column, '!=', $value),
            '>' => $query->where($column, '>', $value),
            '>=' => $query->where($column, '>=', $value),
            '<' => $query->where($column, '<', $value),
            '<=' => $query->where($column, '<=', $value),
            'in' => $query->whereIn($column, $this->list($value)),
            'not_in' => $query->whereNotIn($column, $this->list($value)),
            'contains' => $query->where($column, 'like', '%' . $value . '%'),
            default => $query->where($column, '=', $value),
        };
    }

    /** Parse a comma-separated (or already-array) value into a list. */
    protected function list(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return array_map('trim', explode(',', (string) $value));
    }
}
