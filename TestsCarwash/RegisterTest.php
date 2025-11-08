<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Auth\Events\Registered;
use Tests\TestCase;
use App\Events\UsuarioCreado;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function muestra_formulario_registro()
    {
        $this->get('/register')->assertOk()->assertViewIs('auth.register');
    }

    /** @test */
    public function registra_cliente_valido_y_redirige_a_dashboard_cliente()
    {
        Event::fake([Registered::class, UsuarioCreado::class]);
        Cache::put('dashboard_stats', ['dummy' => true]); 

        $payload = [
            'nombre'                  => 'Juan Pérez',
            'email'                   => 'juan@example.com',
            'telefono'                => '78901234',
            'password'                => 'Password1!',
            'password_confirmation'   => 'Password1!',
        ];

        $this->post('/register', $payload)
            ->assertRedirect(route('cliente.dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('usuarios', [
            'email'  => 'juan@example.com',
            'rol'    => Usuario::ROL_CLIENTE,
            'estado' => true,
        ]);

        Event::assertDispatched(Registered::class);
        Event::assertDispatched(UsuarioCreado::class);

        
        $this->assertFalse(Cache::has('dashboard_stats'));
    }

    /** @test */
    public function valida_unicidad_de_email_y_telefono()
    {
        Usuario::factory()->create([
            'email' => 'ya@existe.com',
            'telefono' => '70000000',
        ]);

        $payload = [
            'nombre' => 'X',
            'email'  => 'ya@existe.com',
            'telefono' => '70000000',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ];

        $this->from('/register')->post('/register', $payload)
             ->assertRedirect('/register')
             ->assertSessionHasErrors(['email', 'telefono']);
    }

    /** @test */
    public function valida_telefono_exactamente_8_digitos()
    {
        $payload = [
            'nombre' => 'X',
            'email'  => 'x@example.com',
            'telefono' => '12345',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ];

        $this->from('/register')->post('/register', $payload)
             ->assertRedirect('/register')
             ->assertSessionHasErrors(['telefono']);
    }

    /** @test */
    public function valida_politica_de_password()
    {
        // Falla por no cumplir reglas (sin mayúscula/minúscula/número/símbolo)
        $payload = [
            'nombre' => 'X',
            'email'  => 'x@example.com',
            'telefono' => '70000001',
            'password' => 'password', 
            'password_confirmation' => 'password',
        ];

        $this->from('/register')->post('/register', $payload)
             ->assertRedirect('/register')
             ->assertSessionHasErrors(['password']);
    }
}
