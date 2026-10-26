<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => Role::Admin->value,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.role', Role::Customer->value)
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'role' => Role::Customer->value,
        ]);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_user_can_log_in_with_the_right_password(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', Role::Customer->value)
            ->assertJsonStructure(['token']);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_an_authenticated_user_can_read_their_profile(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', Role::Admin->value);
    }

    public function test_registration_checks_the_required_fields(): void
    {
        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_registration_rejects_a_password_that_is_not_confirmed(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_login_rejects_an_unknown_email(): void
    {
        $this->postJson('/api/login', [
            'email' => 'missing@example.com',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_an_admin_can_log_in(): void
    {
        User::factory()->admin()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.role', Role::Admin->value);
    }

    public function test_auth_responses_do_not_include_the_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $response->assertJsonMissingPath('data.password');

        $this->withToken($response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonMissingPath('data.password');
    }

    public function test_a_guest_cannot_read_the_profile(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_guest_cannot_log_out(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_logging_out_revokes_the_token(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The guard keeps the user from the logout request until it is forgotten.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }
}
