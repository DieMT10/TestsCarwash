<?php

namespace Tests\Feature\Auth\Admin;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Servicio;
use App\Models\Cita;
use App\Models\Pago;
use Illuminate\Testing\TestResponse;

class CitasAdminTest extends TestCase
{
    use RefreshDatabase;

  

    protected function admin(): Usuario
    {
        return Usuario::factory()->create([
            'rol'    => 'admin',
            'estado' => true,
        ]);
    }

    protected function actingAsWeb(Usuario $user): void
    {
        $this->actingAs($user, 'web');
    }

    protected function cita(
        string $estado = 'pendiente',
        ?Usuario $user = null,
        ?Vehiculo $vehiculo = null,
        ?\Carbon\Carbon $fecha = null,
        bool $withPago = false
    ): Cita {
        $user ??= Usuario::factory()->create(['rol' => 'cliente', 'estado' => true]);
        $vehiculo ??= Vehiculo::factory()->create(['usuario_id' => $user->id]);

        $cita = Cita::factory()->create([
            'usuario_id' => $user->id,
            'vehiculo_id' => $vehiculo->id,
            'estado' => $estado,
            'fecha_hora' => $fecha ?? now()->addDay(),
        ]);

       
        $servicios = Servicio::factory()->count(2)->create();
        foreach ($servicios as $s) {
            $cita->servicios()->attach($s->id, [
                'precio' => $s->precio,
                'descuento' => 0,
            ]);
        }

        
        if ($withPago) {
            // Total: suma de servicios
            $monto = $cita->servicios->sum('pivot.precio');

            Pago::factory()->create([
                'cita_id'        => $cita->id,
                'monto'          => $monto,
                'estado'         => 'pagado',
                'metodo'         => 'efectivo',
                'monto_recibido' => $monto, 
                'vuelto'         => 0,
            ]);
        }

        return $cita->fresh(['usuario','vehiculo','servicios','pago']);
    }

    protected function assertOkOrCreated(TestResponse $resp): void
    {
        $status = $resp->getStatusCode();
        $this->assertTrue(in_array($status, [200, 201]), "Se esperaba 200/201 y llegó {$status}");
    }

    /* =======================
     |  Tests
     ========================*/

    /** @test */
    public function invitado_no_puede_ver_panel_citas(): void
    {
        $resp = $this->get(route('admin.citasadmin.index'));
        
        if (in_array($resp->getStatusCode(), [301, 302, 303, 307, 308])) {
            $resp->assertRedirect();
        } else {
            $resp->assertStatus(401); 
        }
    }

    /** @test */
    public function admin_ve_el_panel_de_citas(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        $resp = $this->get(route('admin.citasadmin.index'));
        $resp->assertStatus(200);
    }

    /** @test */
    public function admin_puede_ver_detalles_de_una_cita_en_json(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        $cita = $this->cita('pendiente');

        $resp = $this->getJson(route('admin.citasadmin.detalles', $cita->id));
        $resp->assertStatus(200)
             ->assertJsonStructure([
                 'id',
                 'usuario' => ['nombre','email','telefono'],
                 'vehiculo' => ['marca','modelo','placa','tipo','tipo_formatted','color','descripcion'],
                 'fecha_hora',
                 'estado',
                 'estado_formatted',
                 'observaciones',
                 'total',
                 'servicios',
                 'created_at',
             ]);
    }

    /** @test */
    public function validar_estado_requerido_al_actualizar(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        $cita = $this->cita('pendiente');

        $resp = $this->putJson(route('admin.citasadmin.actualizar-estado', $cita->id), [
            // sin 'estado'
        ]);

        $resp->assertStatus(422)->assertJson(['success' => false]);
    }

    /** @test */
    public function se_puede_confirmar_una_cita_pendiente(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        $cita = $this->cita('pendiente');

        $resp = $this->putJson(route('admin.citasadmin.actualizar-estado', $cita->id), [
            'estado' => 'confirmada',
        ]);

        $resp->assertStatus(200)
             ->assertJson([
                 'success' => true,
                 'estado_codigo' => 'confirmada',
             ]);
    }

    /** @test */
    public function no_se_puede_finalizar_sin_pago_completado(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        // Cita sin pago
        $cita = $this->cita('en_proceso');

        $resp = $this->putJson(route('admin.citasadmin.actualizar-estado', $cita->id), [
            'estado' => 'finalizada',
        ]);

        $resp->assertStatus(422)
             ->assertJson([
                 'success' => false,
                 'requiere_pago' => true,
             ]);
    }

    /** @test */
    public function no_se_puede_cancelar_una_finalizada_con_pago_pagado(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        
        $cita = $this->cita('finalizada', null, null, now()->addHours(2), true);

        $resp = $this->putJson(route('admin.citasadmin.actualizar-estado', $cita->id), [
            'estado' => 'cancelada',
        ]);

        $resp->assertStatus(422)
             ->assertJson(['success' => false]);
    }

    /** @test */
    public function index_filtra_por_estado_y_fecha_sin_romper(): void
    {
        $admin = $this->admin();
        $this->actingAsWeb($admin);

        $c1 = $this->cita('pendiente', null, null, now()->addDay());
        $this->cita('confirmada', null, null, now()->addDays(2));
        $this->cita('finalizada', null, null, now()->addDays(3), true);

        $resp = $this->get(route('admin.citasadmin.index', [
            'estado' => 'pendiente',
            'fecha'  => $c1->fecha_hora->toDateString(),
        ]));

        $resp->assertStatus(200);
    }
}
