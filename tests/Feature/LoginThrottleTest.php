<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('login:'.'nobody@example.com|127.0.0.1');
        RateLimiter::clear('login:'.'127.0.0.1');

        parent::tearDown();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $credentials = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/login', $credentials);
            $response->assertStatus(422);
        }

        $this->postJson('/login', $credentials)->assertStatus(429);
    }

    public function test_throttle_clears_after_window(): void
    {
        RateLimiter::hit('login:'.'nobody@example.com|127.0.0.1', 60);
        RateLimiter::clear('login:'.'nobody@example.com|127.0.0.1');

        $this->postJson('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->assertStatus(422);
    }
}
