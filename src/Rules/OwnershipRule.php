<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Rule to restrict access to owned records only
 */
class OwnershipRule extends BaseAccessRule
{
    protected string $ownerColumn;
    protected ?string $userIdColumn;

    public function __construct(
        ?string $owner_column = 'user_id',
        ?string $user_id_column = 'id',
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        parent::__construct($_user, $_priority, $_is_deny_rule);

        $this->ownerColumn = $owner_column ?? 'user_id';
        $this->userIdColumn = $user_id_column ?? 'id';
    }

    public function passes(Authenticatable $user, Model $model): bool
    {
        $ownerId = data_get($model, $this->ownerColumn);
        $userId = data_get($user, $this->userIdColumn);

        return $ownerId == $userId;
    }

    public function scope($query, Authenticatable $user)
    {
        $userId = data_get($user, $this->userIdColumn);

        return $query->where($this->ownerColumn, $userId);
    }
}
