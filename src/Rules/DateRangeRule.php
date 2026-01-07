<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Carbon\Carbon;

/**
 * Rule to restrict access based on date range
 */
class DateRangeRule extends BaseAccessRule
{
    protected ?Carbon $from;
    protected ?Carbon $to;
    protected string $dateColumn;

    public function __construct(
        ?string $from = null,
        ?string $to = null,
        ?string $date_column = 'created_at',
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);

        $this->from = $from ? Carbon::parse($from)->startOfDay() : null;
        $this->to = $to ? Carbon::parse($to)->endOfDay() : null;
        $this->dateColumn = $date_column ?? 'created_at';
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        if (!$this->from && !$this->to) {
            return true; // No restriction if not configured
        }

        $modelDate = data_get($model, $this->dateColumn);

        if (!$modelDate instanceof Carbon && !$modelDate instanceof \DateTime) {
            $modelDate = Carbon::parse($modelDate);
        }

        if ($this->from && $this->to) {
            return $modelDate->between($this->from, $this->to);
        }

        if ($this->from) {
            return $modelDate->greaterThanOrEqualTo($this->from);
        }

        if ($this->to) {
            return $modelDate->lessThanOrEqualTo($this->to);
        }

        return true;
    }

    public function scope($query, Authenticatable $user)
    {
        if ($this->from && $this->to) {
            $query->whereBetween($this->dateColumn, [$this->from, $this->to]);
        } elseif ($this->from) {
            $query->where($this->dateColumn, '>=', $this->from);
        } elseif ($this->to) {
            $query->where($this->dateColumn, '<=', $this->to);
        }

        return $query;
    }
}
