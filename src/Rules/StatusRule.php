<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Rule to restrict access based on model status
 */
class StatusRule extends BaseAccessRule
{
    protected ?array $allowedStatuses;
    protected string $statusColumn;

    public function __construct(
        ?array $statuses = null,
        ?string $status_column = 'status',
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);

        $this->allowedStatuses = $statuses;
        $this->statusColumn = $status_column ?? 'status';
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        if (!$this->allowedStatuses || empty($this->allowedStatuses)) {
            return true; // No restriction if not configured
        }

        $modelStatus = data_get($model, $this->statusColumn);

        // Handle enum values
        if (is_object($modelStatus) && method_exists($modelStatus, 'value')) {
            $modelStatus = $modelStatus->value;
        }

        return in_array($modelStatus, $this->allowedStatuses);
    }

    public function scope($query, Authenticatable $user)
    {
        if ($this->allowedStatuses && !empty($this->allowedStatuses)) {
            $query->whereIn($this->statusColumn, $this->allowedStatuses);
        }

        return $query;
    }
}
