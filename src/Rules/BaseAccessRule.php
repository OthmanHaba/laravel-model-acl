<?php

declare(strict_types=1);

namespace YourVendor\LaravelModelAcl\Rules;

use YourVendor\LaravelModelAcl\Contracts\AccessRuleContract;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class BaseAccessRule implements AccessRuleContract
{
    protected Authenticatable $user;
    protected int $priority = 0;
    protected bool $isDenyRule = false;

    public function __construct(
        ?Authenticatable $_user = null,
        ?int $_priority = null,
        ?bool $_is_deny_rule = null
    ) {
        if ($_user) {
            $this->user = $_user;
        }
        if ($_priority !== null) {
            $this->priority = $_priority;
        }
        if ($_is_deny_rule !== null) {
            $this->isDenyRule = $_is_deny_rule;
        }
    }

    /**
     * Get the priority/weight of this rule (higher = executed first)
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Determine if this is a deny rule (negative rule)
     *
     * @return bool
     */
    public function isDenyRule(): bool
    {
        return $this->isDenyRule;
    }
}
