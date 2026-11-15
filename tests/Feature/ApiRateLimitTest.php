<?php

namespace Tests\Feature;

use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_requests_within_the_limit_are_allowed()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.15']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->getJson('/api/leads')->assertStatus(200);
        }
    }

    public function test_read_requests_over_the_limit_return_too_many_requests()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($attempt = 0; $attempt < RouteServiceProvider::READ_PER_MINUTE; $attempt++) {
            $this->getJson('/api/leads')->assertStatus(200);
        }

        $this->getJson('/api/leads')->assertStatus(429);
    }

    public function test_write_requests_over_the_limit_return_too_many_requests()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);
        $this->withHeader('X-Api-Key', 'test-key');

        for ($attempt = 0; $attempt < RouteServiceProvider::WRITE_PER_MINUTE; $attempt++) {
            $this->deleteJson('/api/leads/999999')->assertStatus(404);
        }

        $this->deleteJson('/api/leads/999999')->assertStatus(429);
    }

    public function test_post_requests_use_the_write_limit_and_do_not_block_reads()
    {
        Http::fake([
            '*/sources' => Http::response([
                'data' => [
                    ['code' => 'WEBSITE', 'label' => 'Site'],
                ],
            ]),
        ]);

        $this->assertWriteAllowanceThenTooManyRequests(
            '203.0.113.20',
            fn () => $this->postJson('/api/leads', []),
            422
        );
    }

    public function test_put_requests_use_the_write_limit_and_do_not_block_reads()
    {
        $this->assertWriteAllowanceThenTooManyRequests(
            '203.0.113.21',
            fn () => $this->putJson('/api/leads/999999', []),
            404
        );
    }

    public function test_write_limit_does_not_consume_the_read_limit()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12']);
        $this->withHeader('X-Api-Key', 'test-key');

        for ($attempt = 0; $attempt < RouteServiceProvider::WRITE_PER_MINUTE; $attempt++) {
            $this->deleteJson('/api/leads/999999')->assertStatus(404);
        }

        $this->deleteJson('/api/leads/999999')->assertStatus(429);
        $this->getJson('/api/leads')->assertStatus(200);
    }

    public function test_read_limit_is_isolated_by_ip()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.13']);

        for ($attempt = 0; $attempt < RouteServiceProvider::READ_PER_MINUTE; $attempt++) {
            $this->getJson('/api/leads')->assertStatus(200);
        }

        $this->getJson('/api/leads')->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.14'])
            ->getJson('/api/leads')
            ->assertStatus(200);
    }

    public function test_write_requests_still_require_the_api_key()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.16']);

        $this->deleteJson('/api/leads/1')->assertStatus(401);

        $this->withHeader('X-Api-Key', 'wrong-key')
            ->deleteJson('/api/leads/1')
            ->assertStatus(401);
    }

    public function test_unauthenticated_writes_over_the_limit_return_too_many_requests()
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.22']);

        for ($attempt = 0; $attempt < RouteServiceProvider::WRITE_PER_MINUTE; $attempt++) {
            $this->deleteJson('/api/leads/1')->assertStatus(401);
        }

        $this->deleteJson('/api/leads/1')->assertStatus(429);
    }

    private function assertWriteAllowanceThenTooManyRequests(string $ip, callable $request, int $allowedStatus): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $this->withHeader('X-Api-Key', 'test-key');

        for ($attempt = 0; $attempt < RouteServiceProvider::WRITE_PER_MINUTE; $attempt++) {
            $request()->assertStatus($allowedStatus);
        }

        $request()->assertStatus(429);
        $this->getJson('/api/leads')->assertStatus(200);
    }
}
