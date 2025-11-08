<?php

namespace Tests\Feature\Auth\Admin;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Usuario;
use App\Models\Gasto;

class GastosTest extends TestCase
{
    use RefreshDatabase;

    
    protected function admin(): Usuario
    {
        return Usuario::factory()->create(['rol' => 'admin']);
    }

    protected function empleado(): Usuario
    {
        return Usuario::factory()->create(['rol' => 'empleado']);
    }

    protected function cliente(): Usuario
    {
        return Usuario::factory()->create(['rol' => 'cliente']);
    }

    
    protected function payloadValido(Usuario $admin): array
    {
        return [
            'usuario_id'  => $admin->id,
            'tipo'        => 'stock', 
            'detalle'     => 'Compra shampoo',
            'monto'       => 12.50,
            'fecha_gasto' => now()->toDateString(),
        ];
    }

    /** @test */
    public function admin_puede_crear_gasto_valido(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->post('/admin/gastos', $this->payloadValido($admin))
            ->assertRedirect('/admin/gastos');

        $this->assertDatabaseHas('gastos', [
            'detalle' => 'Compra shampoo',
            'monto'   => 12.50,
            'tipo'    => 'stock',
        ]);
    }

    /** @test */
    public function valida_campos_requeridos_y_reglas(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/gastos', [])
            ->assertSessionHasErrors([
                'usuario_id',
                'tipo',
                'detalle',
                'monto',
                'fecha_gasto',
            ]);
    }

    /** @test */
    public function admin_puede_ver_listado_de_gastos(): void
    {
        $admin = $this->admin();
        Gasto::factory()->create([
            'usuario_id'  => $admin->id,
            'tipo'        => 'stock',
            'detalle'     => 'Shampoo',
            'monto'       => 10.25,
            'fecha_gasto' => now()->subDays(5),
        ]);

        $this->actingAs($admin)
            ->get('/admin/gastos')
            ->assertOk()
            ->assertSee('Shampoo');
    }

    /** @test */
    public function admin_puede_actualizar_gasto(): void
    {
        $admin = $this->admin();
        $gasto = Gasto::factory()->create([
            'usuario_id'  => $admin->id,
            'tipo'        => 'personal',
            'detalle'     => 'Guantes',
            'monto'       => 5.00,
            'fecha_gasto' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->put("/admin/gastos/{$gasto->id}", [
                'usuario_id'  => $admin->id,
                'tipo'        => 'stock',
                'detalle'     => 'Detalle actualizado',
                'monto'       => 8.75,
                'fecha_gasto' => now()->toDateString(),
            ])
            ->assertRedirect('/admin/gastos');

        $this->assertDatabaseHas('gastos', [
            'id'      => $gasto->id,
            'detalle' => 'Detalle actualizado',
            'monto'   => 8.75,
            'tipo'    => 'stock',
        ]);
    }

    /** @test */
    public function admin_puede_eliminar_gasto(): void
    {
        $admin = $this->admin();
        $gasto = Gasto::factory()->create([
            'usuario_id'  => $admin->id,
            'tipo'        => 'mantenimiento',
            'detalle'     => 'Aceite',
            'monto'       => 7.30,
            'fecha_gasto' => now()->subDays(2),
        ]);

        $this->actingAs($admin)
            ->delete("/admin/gastos/{$gasto->id}")
            ->assertRedirect('/admin/gastos');

        $this->assertDatabaseMissing('gastos', ['id' => $gasto->id]);
    }

    /** @test */
    public function no_admin_no_puede_ver_gastos(): void
    {
        $empleado = $this->empleado();
        $cliente  = $this->cliente();

        
        $this->actingAs($empleado)->get('/admin/gastos')->assertForbidden();
        $this->actingAs($cliente)->get('/admin/gastos')->assertForbidden();

        
        $this->get('/admin/gastos')->assertForbidden();
    }

    /** @test */
    public function filtra_por_fecha_y_texto(): void
    {
        $admin = $this->admin();

        
        Gasto::factory()->create([
            'usuario_id'  => $admin->id,
            'tipo'        => 'stock',
            'detalle'     => 'Shampoo',
            'monto'       => 121.05,
            'fecha_gasto' => '2025-10-01',
        ]);

        
        Gasto::factory()->create([
            'usuario_id'  => $admin->id,
            'tipo'        => 'personal',
            'detalle'     => 'Cera',
            'monto'       => 133.45,
            'fecha_gasto' => '2025-10-15',
        ]);

        
        $this->actingAs($admin)
            ->get('/admin/gastos?fecha_inicio=2025-10-10&fecha_fin=2025-10-20')
            ->assertOk()
            ->assertSee('Cera')
            ->assertDontSee('Shampoo');
    }

    /** @test */
    public function exporta_xlsx_si_esta_habilitado(): void
    {
        $this->markTestSkipped('Export XLSX no implementado o ruta no encontrada.');
    }

    /** @test */
    public function permite_subir_comprobante_si_aplica(): void
    {
        $this->markTestSkipped('El sistema actual no guarda comprobantes en el controlador.');
    }
}




