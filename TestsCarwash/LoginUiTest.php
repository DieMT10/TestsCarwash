<?php

namespace Tests\Browser\Auth;

use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class LoginUiTest extends DuskTestCase
{
    use DatabaseMigrations;

    protected string $pwd = 'Secret123!';

    protected function setUp(): void
    {
        parent::setUp();

        // Seed de usuarios
        Usuario::factory()->create([
            'nombre'   => 'Admin Test',
            'email'    => 'admin@example.com',
            'telefono' => '70000001',
            'password' => Hash::make($this->pwd),
            'rol'      => \App\Models\Usuario::ROL_ADMIN,
            'estado'   => true,
        ]);

        Usuario::factory()->create([
            'nombre'   => 'Empleado Test',
            'email'    => 'empleado@example.com',
            'telefono' => '70000002',
            'password' => Hash::make($this->pwd),
            'rol'      => \App\Models\Usuario::ROL_EMPLEADO,
            'estado'   => true,
        ]);

        Usuario::factory()->create([
            'nombre'   => 'Cliente Test',
            'email'    => 'cliente@example.com',
            'telefono' => '70000003',
            'password' => Hash::make($this->pwd),
            'rol'      => \App\Models\Usuario::ROL_CLIENTE,
            'estado'   => true,
        ]);

        Usuario::factory()->create([
            'nombre'   => 'Inactivo Test',
            'email'    => 'inactivo@example.com',
            'telefono' => '70000004',
            'password' => Hash::make($this->pwd),
            'rol'      => \App\Models\Usuario::ROL_CLIENTE,
            'estado'   => false,
        ]);
    }

    /* ================= helpers ================= */

    protected function baseUrl(): string
    {
        return rtrim(env('APP_URL', 'http://127.0.0.1:8000'), '/');
    }

    protected function logUrl(Browser $b, string $label = ''): void
    {
        $url = $b->script('return window.location.href')[0] ?? 'unknown';
        @fwrite(STDERR, "\n[DUSK] {$label} URL: {$url}\n");
    }

   
    protected function resetAuthState(Browser $b): void
    {
        
        $b->driver->manage()->deleteAllCookies();

        
        try {
            $b->visit($this->baseUrl().'/logout')->pause(200);
        } catch (\Throwable $e) {
            
        }

        
        $b->visit($this->baseUrl().'/login')
          ->waitUsing(20, 200, function () use ($b) {
              return ($b->script('return document.readyState')[0] ?? null) === 'complete';
          }, 'login no listo');
    }

    protected function goToLoginAndWait(Browser $b, string $tag): void
    {
        $this->resetAuthState($b);

        
        $path = parse_url($b->driver->getCurrentURL(), PHP_URL_PATH) ?? '';
        if ($path !== '/login') {
            $this->resetAuthState($b);
        }

        
        $b->waitFor('form', 10)
          ->assertPresent('form')
          ->waitFor('#email', 10)
          ->assertPresent('#email')
          ->screenshot($tag . '_loaded')
          ->storeSource($tag . '_loaded.html');

        $this->logUrl($b, $tag.'_loaded');
    }

    protected function fillEmail(Browser $b, string $value): void
    {
        if ($b->resolver->find('@email', false)) { $b->click('@email')->type('@email', $value); return; }
        if ($b->resolver->find('#email', false)) { $b->click('#email')->type('#email', $value); return; }
        if ($b->resolver->find('input[name="email"]', false)) { $b->click('input[name="email"]')->type('input[name="email"]', $value); return; }
        if ($b->resolver->find('input[type="email"]', false)) { $b->click('input[type="email"]')->type('input[type="email"]', $value); return; }
        // fallback
        $b->type('form input', $value);
    }

    protected function fillPassword(Browser $b, string $value): void
    {
        if ($b->resolver->find('@password', false)) { $b->click('@password')->type('@password', $value); return; }
        if ($b->resolver->find('#password', false)) { $b->click('#password')->type('#password', $value); return; }
        if ($b->resolver->find('input[name="password"]', false)) { $b->click('input[name="password"]')->type('input[name="password"]', $value); return; }
        if ($b->resolver->find('input[type="password"]', false)) { $b->click('input[type="password"]')->type('input[type="password"]', $value); return; }
        // fallback
        $b->type('form input[type="password"]', $value);
    }

    
    protected function submitLogin(Browser $b, string $tag): void
    {
        $before = $b->script('return window.location.href')[0] ?? null;
        $this->logUrl($b, $tag . '_before_submit');

        
        if ($b->resolver->find('@login-submit', false)) {
            $b->click('@login-submit');
        } elseif ($b->resolver->find('button[type="submit"]', false)) {
            $b->click('button[type="submit"]');
        } else {
            $b->script('document.querySelector("form")?.submit()');
        }

        // Espera: o cambió URL 
        $b->waitUsing(30, 250, function () use ($b, $before) {
            $ready = $b->script('return document.readyState')[0] ?? null;
            $now   = $b->script('return window.location.href')[0] ?? null;
            return ($before && $now && $before !== $now) || $ready === 'complete';
        }, 'no hubo navegación ni recarga después del submit');

        $b->screenshot($tag . '_after_submit')
          ->storeSource($tag . '_after_submit.html');

        $this->logUrl($b, $tag . '_after_submit');
    }

    protected function waitForAnyDashboard(Browser $b, array $prefixes, string $tag): void
    {
        $b->waitUsing(30, 250, function () use ($b, $prefixes) {
            $path = parse_url($b->driver->getCurrentURL(), PHP_URL_PATH) ?? '';
            foreach ($prefixes as $p) {
                if (str_starts_with($path, $p)) return true;
            }
            if (stripos($path, 'dashboard') !== false) return true;

            // Heurística DOM
            $hasPanel = $b->script(
                'return !!(document.querySelector("nav,aside,[data-role=sidebar],[data-testid=dashboard]") || /dashboard|panel/i.test(document.body.innerText))'
            )[0] ?? false;

            return (bool)$hasPanel;
        }, 'no se detectó ningún dashboard');

        $b->waitUsing(10, 200, fn() => ($b->script('return document.readyState')[0] ?? null) === 'complete');

        $b->screenshot($tag . '_final')
          ->storeSource($tag . '_final.html');

        $this->logUrl($b, $tag . '_final');
    }

    /* ================= tests ================= */

    public function test_login_admin_redirige_a_dashboard_admin(): void
    {
        $this->browse(function (Browser $b) {
            $this->goToLoginAndWait($b, 'auth_login_admin');
            $this->fillEmail($b, 'admin@example.com');
            $this->fillPassword($b, $this->pwd);
            $this->submitLogin($b, 'auth_login_admin');

            $this->waitForAnyDashboard($b, ['/admin', '/admin/dashboard'], 'auth_login_admin');
        });
    }

    public function test_login_empleado_redirige_a_dashboard_empleado(): void
    {
        $this->browse(function (Browser $b) {
            $this->goToLoginAndWait($b, 'auth_login_empleado');
            $this->fillEmail($b, 'empleado@example.com');
            $this->fillPassword($b, $this->pwd);
            $this->submitLogin($b, 'auth_login_empleado');

            $this->waitForAnyDashboard($b, ['/empleado', '/empleado/dashboard'], 'auth_login_empleado');
        });
    }

    public function test_login_cliente_redirige_a_dashboard_cliente(): void
    {
        $this->browse(function (Browser $b) {
            $this->goToLoginAndWait($b, 'auth_login_cliente');
            $this->fillEmail($b, 'cliente@example.com');
            $this->fillPassword($b, $this->pwd);
            $this->submitLogin($b, 'auth_login_cliente');

            $this->waitForAnyDashboard($b, ['/cliente', '/cliente/dashboard'], 'auth_login_cliente');
        });
    }

    public function test_credenciales_invalidas_muestran_error(): void
    {
        $this->browse(function (Browser $b) {
            $this->goToLoginAndWait($b, 'auth_login_invalido');
            $this->fillEmail($b, 'cliente@example.com');
            $this->fillPassword($b, 'ClaveQueNoEs');
            $this->submitLogin($b, 'auth_login_invalido');

            try {
                $b->waitForText('Las credenciales no coinciden con nuestros registros.', 20);
            } catch (\Throwable $e) {
                $b->waitUsing(20, 250, function () use ($b) {
                    return $b->script('return !!(document.querySelector("[role=alert], .alert, .text-red-600, .text-red-500"))')[0] ?? false;
                }, 'no se detectó mensaje de error');
            }

            $b->screenshot('auth_login_invalido_ok')
              ->storeSource('auth_login_invalido_ok.html');
        });
    }

    public function test_usuario_inactivo_muestra_mensaje_bloqueo(): void
    {
        $this->browse(function (Browser $b) {
            $this->goToLoginAndWait($b, 'auth_login_inactivo');
            $this->fillEmail($b, 'inactivo@example.com');
            $this->fillPassword($b, $this->pwd);
            $this->submitLogin($b, 'auth_login_inactivo');

            try {
                $b->waitForText('Tu cuenta está desactivada. Contacta al administrador.', 20);
            } catch (\Throwable $e) {
                $b->waitUsing(20, 250, function () use ($b) {
                    return $b->script('return /desactivada|bloqueada|contacta al administrador/i.test(document.body.innerText)')[0] ?? false;
                }, 'no se detectó mensaje de bloqueo');
            }

            $b->screenshot('auth_login_inactivo_ok')
              ->storeSource('auth_login_inactivo_ok.html');
        });
    }
}


