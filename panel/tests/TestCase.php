<?php

namespace Tests;

use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function agent(): FakeAgentClient
    {
        return $this->app->make(AgentClient::class);
    }

    /** Log in as an admin who already passed the two-factor check. */
    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= User::factory()->admin()->create();
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }
}
