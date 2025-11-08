<?php 

namespace Tests\Feature\Auth\Admin;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use App\Models\Servicio;
use App\Models\Usuario;

class ServiciosTest extends TestCase
{
    use RefreshDatabase;

    

    private function admin(): Usuario
    {
        return Usuario::factory()->create(['rol' => 'admin']);
    }

    private function empleado(): Usuario
    {
        return Usuario::factory()->create(['rol' => 'empleado']);
    }

    private function actingAsApi(?Usuario $user = null): self
    {
        if ($user) $this->actingAs($user);
        return $this->withHeaders(['Accept' => 'application/json']);
    }

    /* =========================
     | Rutas base y utilidades
     |=========================*/

    private array $basesRead = [
        '/api/servicios',
        '/servicios',
    ];

    private array $basesWrite = [
        '/api/servicios',
        '/servicios',
        '/api/admin/servicios',
        '/admin/servicios',
    ];

    private function baseRead(): string
    {
        foreach ($this->basesRead as $b) {
            $resp = $this->getJson($b);
            if ($resp->getStatusCode() !== 404) return $b;
        }
        return $this->basesRead[0];
    }

    private function tryJson(string $method, string $suffix = '', array $data = []): TestResponse
    {
        $resp = null;
        foreach ($this->basesWrite as $b) {
            $url = rtrim($b, '/') . ($suffix ? '/' . ltrim($suffix, '/') : '');
            if (in_array(strtoupper($method), ['POST','PUT','PATCH','DELETE'])) {
                $resp = $this->json($method, $url, $data);
                if (!in_array($resp->getStatusCode(), [404,405])) return $resp;

                // Fallback: POST + _method para apps con form spoofing
                $resp = $this->postJson($url, array_merge($data, ['_method' => strtoupper($method)]));
                if (!in_array($resp->getStatusCode(), [404,405])) return $resp;
            } else {
                $resp = $this->json($method, $url, $data);
                if ($resp->getStatusCode() !== 404) return $resp;
            }
        }
        return $resp ?? $this->json($method, rtrim($this->basesWrite[0], '/') . ($suffix ? '/' . ltrim($suffix, '/') : ''), $data);
    }

    private function tryStore(array $data): TestResponse
    {
        return $this->tryJson('POST', '', $data);
    }

    private function tryUpdate(int|string $id, array $data): TestResponse
    {
        return $this->tryJson('PUT', (string)$id, $data);
    }

    private function tryDestroy(int|string $id): TestResponse
    {
        return $this->tryJson('DELETE', (string)$id);
    }

    private function assertOkOrCreated(TestResponse $resp): void
    {
        $status = $resp->getStatusCode();
        $this->assertTrue(in_array($status, [200, 201]), "Se esperaba 200/201 y llegó {$status}");
    }

    private function validCategorias(): array
    {
        return ['sedan','pickup','moto'];
    }

    private function categoriaValida(): string
    {
        $vals = $this->validCategorias();
        return $vals[array_rand($vals)];
    }

    private function payloadValido(array $overrides = []): array
    {
        $raw = Servicio::factory()->raw([
            'categoria' => $this->categoriaValida(),
            'activo'    => true,
        ]);

        $base = [
            'nombre'       => 'Lavado Premium',
            'descripcion'  => 'Servicio premium con espuma activa',
            'precio'       => 10.50,
            'duracion_min' => 30,
            'activo'       => true,
            'categoria'    => $this->categoriaValida(),
        ];

        return array_merge($raw, $base, $overrides);
    }

    
    private function payloadUpdateValido(Servicio $s, array $overrides = []): array
    {
        $base = [
            'nombre'       => $s->nombre ?? 'Servicio',
            'descripcion'  => $s->descripcion ?? 'Descripción',
            'precio'       => $s->precio ?? 9.99,
            'duracion_min' => $s->duracion_min ?? 20,
            'activo'       => $s->activo ?? true,
            
        ];

        return array_merge($base, $overrides);
    }

    private function hasCategoriaError(TestResponse $resp): bool
    {
        if ($resp->getStatusCode() !== 422) return false;
        $errors = (array) data_get($resp->json(), 'errors', []);
        return array_key_exists('categoria', $errors);
    }

    private function ensureUpdateHasCategoriaIfNeeded(Servicio $s, array $data, TestResponse $resp): TestResponse
    {
        if (!$this->hasCategoriaError($resp)) return $resp;
        $data['categoria'] = $s->categoria ?: $this->categoriaValida();
        return $this->tryUpdate($s->id, $data);
    }

   

    /** @test */
    public function invitado_no_puede_acceder_a_rutas_protegidas(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $bases = $this->basesRead;
        $status = null; $resp = null;
        foreach ($bases as $b) {
            $resp = $this->getJson($b);
            $status = $resp->getStatusCode();
            if ($status !== 404) break;
        }

        if (in_array($status, [301,302,303,307,308])) {
            $resp->assertRedirect('/login');
        } elseif ($status === 404) {
            $this->fail('Las rutas de servicios no coinciden con /api/servicios ni /servicios. Ajusta $basesRead.');
        } else {
            $resp->assertStatus(401);
        }
    }

    /** @test */
    public function admin_puede_listar_servicios(): void
    {
        $admin = $this->admin();
        Servicio::factory()->count(2)->create();

        $this->actingAsApi($admin);
        $resp = $this->getJson($this->baseRead());
        $resp->assertStatus(200)->assertJsonPath('success', true);
    }

    /** @test */
    public function show_devuelve_404_si_servicio_no_existe(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        $base = $this->baseRead();
        $this->getJson(rtrim($base,'/').'/999999')->assertStatus(404);
    }

    /** @test */
    public function admin_puede_ver_detalle_servicio(): void
    {
        $admin = $this->admin();
        $s = Servicio::factory()->create(['nombre' => 'Lavado Básico']);

        $this->actingAsApi($admin);
        $base = $this->baseRead();

        $this->getJson(rtrim($base,'/')."/{$s->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.nombre', 'Lavado Básico');
    }

    /** @test */
    public function admin_puede_crear_servicio_valido(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        $payload = $this->payloadValido(); 

        $resp = $this->tryStore($payload);
        if ($resp->getStatusCode() === 404) {
            $this->fail('STORE no localizada en /api/servicios|/servicios (+ variantes).');
        }
        if ($resp->getStatusCode() === 405) {
            $this->fail('STORE responde 405 en todas las variantes. Revisa que exista una ruta POST válida.');
        }

        
        if ($this->hasCategoriaError($resp)) {
            $payload['categoria'] = $this->categoriaValida();
            $resp = $this->tryStore($payload);
        }

        $this->assertOkOrCreated($resp);
        $this->assertTrue(data_get($resp->json(), 'success', false) === true);
        $this->assertDatabaseHas('servicios', ['nombre' => 'Lavado Premium']);
    }

    /** @test */
    public function valida_campos_requeridos_y_restricciones_en_store(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        
        $payload = $this->payloadValido([
            'precio'       => null,
            'duracion_min' => 3,
            'categoria'    => '',
        ]);

        $resp = $this->tryStore($payload);
        if ($resp->getStatusCode() === 404) { $this->fail('STORE no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('STORE responde 405 en todas las variantes.'); }

        $resp->assertStatus(422);
    }

    /** @test */
    public function no_admin_no_puede_crear_servicio(): void
    {
        $empleado = $this->empleado();
        $this->actingAsApi($empleado);

        $resp = $this->tryStore($this->payloadValido());
        if ($resp->getStatusCode() === 404) { $this->fail('STORE no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('STORE responde 405 en todas las variantes.'); }

        $resp->assertStatus(403);
    }

    /** @test */
    public function admin_puede_actualizar_servicio(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        
        $s = Servicio::factory()->create([
            'categoria' => $this->categoriaValida(),
        ]);

        
        $data = $this->payloadUpdateValido($s, [
            'nombre'       => 'Lavado Estandar',
            'precio'       => 15.75,
            'duracion_min' => 25,
        ]);

        $resp = $this->tryUpdate($s->id, $data);
        if ($resp->getStatusCode() === 404) { $this->fail('UPDATE no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('UPDATE devuelve 405 incluso con POST+_method.'); }

        
        if ($this->hasCategoriaError($resp)) {
            $data['categoria'] = $s->categoria ?: $this->categoriaValida();
            $resp = $this->tryUpdate($s->id, $data);
        }

        $resp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseHas('servicios', ['id' => $s->id, 'nombre' => 'Lavado Estandar']);
    }

    /** @test */
    public function no_admin_no_puede_actualizar_servicio(): void
    {
        $empleado = $this->empleado();
        $this->actingAsApi($empleado);

        $s = Servicio::factory()->create([
            'categoria' => $this->categoriaValida(),
        ]);

        $data = $this->payloadUpdateValido($s, [
            'precio' => 8.99,
        ]);

        $resp = $this->tryUpdate($s->id, $data);
        if ($resp->getStatusCode() === 404) { $this->fail('UPDATE no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('UPDATE devuelve 405 incluso con POST+_method.'); }

        $resp->assertStatus(403);
    }

    /** @test */
    public function update_rechaza_datos_invalidos(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        $s = Servicio::factory()->create([
            'categoria' => $this->categoriaValida(),
        ]);

        
        $data = $this->payloadUpdateValido($s, [
            'precio'       => -1,
            'duracion_min' => 4,
        ]);

        $resp = $this->tryUpdate($s->id, $data);
        if ($resp->getStatusCode() === 404) { $this->fail('UPDATE no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('UPDATE devuelve 405 incluso con POST+_method.'); }

        $resp->assertStatus(422);
    }

    /** @test */
    public function admin_puede_eliminar_servicio(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        $s = Servicio::factory()->create();

        $resp = $this->tryDestroy($s->id);
        if ($resp->getStatusCode() === 404) { $this->fail('DESTROY no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('DESTROY devuelve 405 incluso con POST+_method.'); }

        $resp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseMissing('servicios', ['id' => $s->id]);
    }

    /** @test */
    public function no_admin_no_puede_eliminar_servicio(): void
    {
        $empleado = $this->empleado();
        $this->actingAsApi($empleado);

        $s = Servicio::factory()->create();

        $resp = $this->tryDestroy($s->id);
        if ($resp->getStatusCode() === 404) { $this->fail('DESTROY no localizada.'); }
        if ($resp->getStatusCode() === 405) { $this->fail('DESTROY devuelve 405 incluso con POST+_method.'); }

        $resp->assertStatus(403);
    }

    /** @test */
    public function filtra_por_categoria_y_activos(): void
    {
        $admin = $this->admin();
        $this->actingAsApi($admin);

        $categoria = $this->categoriaValida();

        $s1 = Servicio::factory()->create(['activo' => true,  'categoria' => $categoria]);
        $s2 = Servicio::factory()->create(['activo' => false, 'categoria' => $categoria]);
        Servicio::factory()->create(['activo' => true,  'categoria' => $this->categoriaValida()]);

        $resp = null;
        foreach ($this->basesRead as $b) {
            $url = rtrim($b,'/')."/categoria/{$categoria}?activos=1";
            $resp = $this->getJson($url);
            if ($resp->getStatusCode() !== 404) break;
        }

        $resp->assertStatus(200)->assertJsonPath('success', true);

        $ids = collect($resp->json('data') ?? [])->pluck('id')->all();
        $this->assertTrue(in_array($s1->id, $ids));
        $this->assertFalse(in_array($s2->id, $ids));
    }
}






















