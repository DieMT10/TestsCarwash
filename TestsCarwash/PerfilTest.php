<?php

namespace Tests\Feature\Auth\Admin;

use Tests\TestCase;
use App\Models\Usuario; 
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

   protected function rutaEditar(): string           { return '/perfil'; }
   protected function rutaActualizarPerfil(): string { return '/perfil/actualizar'; }
   protected function rutaUpdateAjax(): string       { return '/perfil/actualizar-ajax'; }

   protected function rutaConfig(): string           { return '/configuracion'; }
   protected function rutaUpdateEmail(): string      { return '/configuracion/actualizar-email'; }
   protected function rutaUpdatePassword(): string   { return '/configuracion/actualizar-password'; }


    
    protected function user(array $attrs = []): Usuario
    {
        return Usuario::factory()->create(array_merge([
            'password' => Hash::make('secret123'),
            
        ], $attrs));
    }


    /** @test */
    public function invitado_redirige_a_login_en_edit_y_config(): void
    {
        $this->get($this->rutaEditar())->assertRedirect('/login');
        $this->get($this->rutaConfig())->assertRedirect('/login');
    }

    /** @test */
    public function autenticado_ve_vistas_de_edicion_y_config(): void
    {
        $u = $this->user();

        $this->actingAs($u)->get($this->rutaEditar())->assertStatus(200);
        $this->actingAs($u)->get($this->rutaConfig())->assertStatus(200);
    }


    /** @test */
    public function actualiza_perfil_valido_y_guarda_en_bd(): void
    {
        $u = $this->user(['nombre' => 'Original', 'telefono' => '']);

        $payload = [
            'nombre'   => 'Nuevo Nombre',
            'telefono' => '77778888', 
        ];

        
        $this->actingAs($u)
            ->post($this->rutaActualizarPerfil(), $payload)
            ->assertStatus(302);

        $this->assertDatabaseHas('usuarios', [
            'id'      => $u->id,
            'nombre'  => 'Nuevo Nombre',
            'telefono'=> '77778888',
        ]);
    }

    /** @test */
    public function valida_perfil_requiere_nombre_y_telefono_valido_si_lo_envias(): void
    {
        $u = $this->user();

        
        $this->actingAs($u)
            ->post($this->rutaActualizarPerfil(), [
                'nombre' => '',
                'telefono' => '123456789012345678901', 
            ])
            ->assertSessionHasErrors(['nombre', 'telefono']);
    }

   

    /** @test */
    public function actualiza_email_valido_y_unico_en_usuarios(): void
    {
        $u = $this->user(['email' => 'old@example.com']);

        $this->actingAs($u)
            ->post($this->rutaUpdateEmail(), ['email' => 'nuevo@example.com'])
            ->assertStatus(302);

        $this->assertDatabaseHas('usuarios', [
            'id'    => $u->id,
            'email' => 'nuevo@example.com',
        ]);
    }

    /** @test */
    public function update_email_falla_por_formato_o_no_unico(): void
    {
        $u = $this->user(['email' => 'propietario@example.com']);
        $otro = $this->user(['email' => 'ocupado@example.com']);

        // formato inválido
        $this->actingAs($u)
            ->post($this->rutaUpdateEmail(), ['email' => 'noemail'])
            ->assertSessionHasErrors(['email']);

        // ya usado por otro
        $this->actingAs($u)
            ->post($this->rutaUpdateEmail(), ['email' => 'ocupado@example.com'])
            ->assertSessionHasErrors(['email']);
    }

    

    /** @test */
    public function cambia_password_con_current_password_valida_y_regex(): void
    {
        $u = $this->user();

        $payload = [
            'current_password'      => 'secret123',         
            'password'              => 'NuevaClave1',       
            'password_confirmation' => 'NuevaClave1',
        ];

        $this->actingAs($u)
            ->post($this->rutaUpdatePassword(), $payload)
            ->assertStatus(302);

        $u->refresh();
        $this->assertTrue(Hash::check('NuevaClave1', $u->password));
    }

    /** @test */
    public function cambiar_password_falla_por_actual_incorrecta_no_confirmada_o_regex(): void
    {
        $u = $this->user();

        // actual incorrecta
        $this->actingAs($u)
            ->post($this->rutaUpdatePassword(), [
                'current_password'      => 'incorrecta',
                'password'              => 'Valida123',
                'password_confirmation' => 'Valida123',
            ])
            ->assertSessionHasErrors(['current_password']);

        // sin confirmación
        $this->actingAs($u)
            ->post($this->rutaUpdatePassword(), [
                'current_password'      => 'secret123',
                'password'              => 'Valida123',
            ])
            ->assertSessionHasErrors(['password']);

        // no cumple
        $this->actingAs($u)
            ->post($this->rutaUpdatePassword(), [
                'current_password'      => 'secret123',
                'password'              => 'soloMinusculas', 
                'password_confirmation' => 'soloMinusculas',
            ])
            ->assertSessionHasErrors(['password']);
    }

    /** @test */
    public function update_ajax_responde_json_success_y_persiste(): void
    {
        $u = $this->user(['telefono' => '']);

        $this->actingAs($u)
            ->postJson($this->rutaUpdateAjax(), [
                'nombre'   => 'Nombre Ajax',
                'telefono' => '12345678', 
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.nombre', 'Nombre Ajax');

        $this->assertDatabaseHas('usuarios', [
            'id'      => $u->id,
            'nombre'  => 'Nombre Ajax',
            'telefono'=> '12345678',
        ]);
    }

   /** @test */
public function update_ajax_valida_nombre_requerido_y_telefono_digits8_y_unico(): void
{
    $u = $this->user(['telefono' => '77778888']);

    
    $this->actingAs($u)
        ->postJson($this->rutaUpdateAjax(), [
            'nombre'   => '',
            'telefono' => '11112222',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message','errors'])
        ->assertJsonPath('errors.nombre', fn ($arr) => is_array($arr) && count($arr) > 0);

    
    $this->actingAs($u)
        ->postJson($this->rutaUpdateAjax(), [
            'nombre'   => 'Nombre OK',
            'telefono' => '1234', 
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message','errors'])
        ->assertJsonPath('errors.telefono', fn ($arr) => is_array($arr) && count($arr) > 0);

    
    $otro = $this->user(['telefono' => '12345678']); 
    $this->actingAs($u)
        ->postJson($this->rutaUpdateAjax(), [
            'nombre'   => 'Nombre OK',
            'telefono' => '12345678', 
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message','errors'])
        ->assertJsonPath('errors.telefono', fn ($arr) => is_array($arr) && count($arr) > 0);
}

}
