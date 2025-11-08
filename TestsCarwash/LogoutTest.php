<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cierra_sesion_y_redirige_a_inicio()
    {
        $user = Usuario::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $this->be($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
