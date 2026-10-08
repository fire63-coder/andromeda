<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_see_the_platform_home_page(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertSee('Apprenez le SQL en écrivant du SQL.')
            ->assertSee('Expert & Certifié')
            ->assertDontSee('Congratulations John');
    }

    #[Test]
    public function signed_in_users_go_straight_to_their_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
    }
}
