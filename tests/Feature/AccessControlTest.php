<?php

namespace OthmanHaba\LaravelModelAcl\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use OthmanHaba\LaravelModelAcl\Events\AccessDenied;
use OthmanHaba\LaravelModelAcl\Events\AccessGranted;
use OthmanHaba\LaravelModelAcl\Http\Middleware\AccessControlMiddleware;
use OthmanHaba\LaravelModelAcl\Models\AccessRule;
use OthmanHaba\LaravelModelAcl\Rules\StatusRule;
use OthmanHaba\LaravelModelAcl\Services\AccessControlService;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestTicket;
use OthmanHaba\LaravelModelAcl\Tests\Fixtures\Models\TestUser;
use OthmanHaba\LaravelModelAcl\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccessControlTest extends TestCase
{
    private function user(): TestUser
    {
        return TestUser::create(['name' => 'A', 'email' => 'a@example.test']);
    }

    private function service(): AccessControlService
    {
        return app(AccessControlService::class);
    }

    private function allowRule(array $statuses): AccessRule
    {
        return AccessRule::create([
            'name' => 'allow',
            'key' => 'view_ticket',
            'rule_class' => StatusRule::class,
            'ruleable_type' => TestTicket::class,
            'settings' => ['statuses' => $statuses],
            'priority' => 10,
            'is_deny_rule' => false,
            'active' => true,
        ]);
    }

    private function denyRule(array $statuses): AccessRule
    {
        return AccessRule::create([
            'name' => 'deny',
            'key' => 'view_ticket',
            'rule_class' => StatusRule::class,
            'ruleable_type' => TestTicket::class,
            'settings' => ['statuses' => $statuses],
            'priority' => 100,
            'is_deny_rule' => true,
            'active' => true,
        ]);
    }

    public function test_no_rules_denies(): void
    {
        $user = $this->user();
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'open']);

        $this->assertFalse($this->service()->can($user, 'view', $ticket));
    }

    public function test_allow_rule_grants(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open']));
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'open']);

        $this->assertTrue($this->service()->can($user, 'view', $ticket));
    }

    public function test_deny_rule_overrides_allow(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open', 'archived']));
        $user->assignAccessRule($this->denyRule(['archived']));
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'archived']);

        $this->assertFalse($this->service()->can($user, 'view', $ticket));
    }

    public function test_filter_query_excludes_denied_records(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open', 'archived']));
        $user->assignAccessRule($this->denyRule(['archived']));

        $open = TestTicket::create(['title' => 'open', 'status' => 'open']);
        TestTicket::create(['title' => 'archived', 'status' => 'archived']);

        $ids = $this->service()
            ->filterQuery($user, 'view', TestTicket::query())
            ->pluck('id')
            ->all();

        $this->assertSame([$open->id], $ids);
    }

    public function test_filter_query_no_rules_returns_nothing(): void
    {
        $user = $this->user();
        TestTicket::create(['title' => 'T', 'status' => 'open']);

        $count = $this->service()
            ->filterQuery($user, 'view', TestTicket::query())
            ->count();

        $this->assertSame(0, $count);
    }

    public function test_deny_rule_blocks_via_gate(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open', 'archived']));
        $user->assignAccessRule($this->denyRule(['archived']));
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'archived']);

        $this->assertFalse($user->can('view', $ticket));
    }

    public function test_allow_rule_grants_via_gate(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open']));
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'open']);

        $this->assertTrue($user->can('view', $ticket));
    }

    public function test_cache_is_flushed_when_rule_revoked(): void
    {
        config(['access-control.cache.enabled' => true]);

        $user = $this->user();
        $rule = $this->allowRule(['open']);
        $user->assignAccessRule($rule);
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'open']);

        // First call populates the cache.
        $this->assertTrue($this->service()->can($user, 'view', $ticket));

        // Revoking must invalidate the cache, not serve a stale grant.
        $user->removeAccessRule($rule);
        $this->assertFalse($this->service()->can($user, 'view', $ticket));
    }

    public function test_events_are_dispatched(): void
    {
        Event::fake([AccessGranted::class, AccessDenied::class]);

        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open']));

        $granted = TestTicket::create(['title' => 'g', 'status' => 'open']);
        $this->service()->can($user, 'view', $granted);
        Event::assertDispatched(AccessGranted::class);

        $denied = TestTicket::create(['title' => 'd', 'status' => 'closed']);
        $this->service()->can($user, 'view', $denied);
        Event::assertDispatched(AccessDenied::class);
    }

    private function fakeRequest(TestTicket $ticket, TestUser $user): Request
    {
        $request = Request::create('/tickets/' . $ticket->id);
        $route = new Route('GET', '/tickets/{ticket}', fn() => null);
        $route->bind($request);
        $route->setParameter('ticket', $ticket);
        $request->setRouteResolver(fn() => $route);
        $request->setUserResolver(fn() => $user);

        return $request;
    }

    public function test_middleware_blocks_denied_access(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open']));
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'closed']);

        $this->expectException(HttpException::class);

        (new AccessControlMiddleware())->handle(
            $this->fakeRequest($ticket, $user),
            fn() => 'ok',
            'view',
            'ticket'
        );
    }

    public function test_middleware_allows_granted_access(): void
    {
        $user = $this->user();
        $user->assignAccessRule($this->allowRule(['open']));
        $ticket = TestTicket::create(['title' => 'T', 'status' => 'open']);

        $result = (new AccessControlMiddleware())->handle(
            $this->fakeRequest($ticket, $user),
            fn() => 'ok',
            'view',
            'ticket'
        );

        $this->assertSame('ok', $result);
    }

    public function test_invalid_rule_class_is_rejected(): void
    {
        $user = $this->user();
        AccessRule::create([
            'name' => 'bad',
            'key' => 'view_ticket',
            'rule_class' => \stdClass::class,
            'ruleable_type' => TestTicket::class,
            'settings' => [],
            'priority' => 10,
            'is_deny_rule' => false,
            'active' => true,
        ])->assignments()->create([
            'assignable_type' => TestUser::class,
            'assignable_id' => $user->id,
        ]);

        $ticket = TestTicket::create(['title' => 'T', 'status' => 'open']);

        $this->expectException(\RuntimeException::class);
        $this->service()->can($user, 'view', $ticket);
    }
}
