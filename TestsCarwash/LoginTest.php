<?php

namespace Tests\Feature\Auth;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function muestra_formulario_login()
    {
        $this->get('/login')->assertOk()->assertViewIs('auth.login');
    }

    /** @test */
    public function login_admin_redirige_a_dashboard_admin()
    {
        $admin = Usuario::factory()->admin()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $this->post('/login', [
            'email'    => $admin->email,
            'password' => 'Secret123!',
            'remember' => true,
        ])
        ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    /** @test */
    public function login_empleado_redirige_a_dashboard_empleado()
    {
        $empleado = Usuario::factory()->empleado()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $this->post('/login', [
            'email'    => $empleado->email,
            'password' => 'Secret123!',
        ])
        ->assertRedirect(route('empleado.dashboard'));

        $this->assertAuthenticatedAs($empleado);
    }

    /** @test */
    public function login_cliente_redirige_a_dashboard_cliente()
    {
        $cliente = Usuario::factory()->create([
            'rol'      => Usuario::ROL_CLIENTE,
            'password' => Hash::make('Secret123!'),
        ]);

        $this->post('/login', [
            'email'    => $cliente->email,
            'password' => 'Secret123!',
        ])
        ->assertRedirect(route('cliente.dashboard'));

        $this->assertAuthenticatedAs($cliente);
    }

    /** @test */
    public function rechaza_login_con_credenciales_invalidas()
    {
        $user = Usuario::factory()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $this->from('/login')->post('/login', [
            'email'    => $user->email,
            'password' => 'BadPass!',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** @test */
    public function usuario_inactivo_no_puede_iniciar_sesion()
    {
        $user = Usuario::factory()->inactivo()->create([
            'password' => Hash::make('Secret123!'),
        ]);

        $this->from('/login')->post('/login', [
            'email'    => $user->email,
            'password' => 'Secret123!',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email', 'Tu cuenta está desactivada.');

        $this->assertGuest();
    }
}
