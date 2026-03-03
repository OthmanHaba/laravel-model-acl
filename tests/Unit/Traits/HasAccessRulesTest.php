<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Unit\Traits;

use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;

class HasAccessRulesTest extends TestCase
{
    public function test_get_access_resolution_logic_returns_config_default(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->getAccessResolutionLogic();

        $this->assertEquals('any', $result);
    }

    public function test_get_scope_grouping_strategy_returns_config_default(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->getScopeGroupingStrategy();

        $this->assertEquals('and', $result);
    }

    public function test_get_action_prefix_returns_model_basename(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->getActionPrefix();

        $this->assertEquals('testticket', $result);
    }

    public function test_should_integrate_with_policies_returns_config_default(): void
    {
        $ticket = new TestTicket();

        $result = $ticket->shouldIntegrateWithPolicies();

        $this->assertTrue($result);
    }
}
