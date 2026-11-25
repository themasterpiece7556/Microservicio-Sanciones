<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateJwt;
use App\Models\User;
use App\Services\JwtService;
use App\Services\SoapLoanService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JwtUserIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('s', 32)]);
        Route::get('/identity-test', fn (Request $request) => response()->json([
            'id' => $request->user()?->id,
            'role' => $request->user()?->role,
            'auth_id' => Auth::user()?->id,
        ]))->middleware(AuthenticateJwt::class);
    }

    public function test_token_resolves_the_linked_user_and_reads_role_changes(): void
    {
        $user = User::factory()->create(['soap_username' => 'ana', 'role' => 'solicitante']);
        $token = app(JwtService::class)->issue('ana');

        $this->withToken($token)->getJson('/identity-test')->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('role', 'solicitante');
        $user->update(['role' => 'almacenista']);
        $this->withToken($token)->getJson('/identity-test')->assertOk()->assertJsonPath('role', 'almacenista');
    }

    public function test_tokens_without_a_linked_user_are_rejected(): void
    {
        $token = app(JwtService::class)->issue('unlinked');

        $this->withToken($token)->getJson('/identity-test')->assertUnauthorized();
    }

    public function test_users_and_soap_credentials_do_not_leak_between_requests(): void
    {
        $ana = User::factory()->create(['soap_username' => 'ana']);
        $bea = User::factory()->create(['soap_username' => 'bea']);

        $this->withToken(app(JwtService::class)->issue('ana'))->getJson('/identity-test')->assertJsonPath('id', $ana->id);
        $this->withToken(app(JwtService::class)->issue('bea'))->getJson('/identity-test')->assertJsonPath('id', $bea->id)->assertJsonPath('auth_id', $bea->id);
        $this->withToken('invalid')->getJson('/identity-test')->assertUnauthorized();
    }

    public function test_soap_success_does_not_register_an_unknown_local_user(): void
    {
        $this->mock(SoapLoanService::class)->shouldReceive('login')->once()->andReturn((object) ['exito' => true]);

        $this->postJson('/api/v1/login', ['username' => 'unknown', 'password' => 'password'])->assertUnauthorized()->assertJsonMissingPath('token');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_rejects_unknown_roles_and_ignores_client_privileges(): void
    {
        User::factory()->create(['soap_username' => 'ana', 'role' => 'unknown']);
        $this->mock(SoapLoanService::class)->shouldReceive('login')->once()->andReturn((object) ['exito' => true]);

        $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'password', 'role' => 'administrador'])->assertForbidden()->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método'])->assertJsonMissingPath('token');
    }

    public function test_role_changes_revoke_permissions_on_the_next_request(): void
    {
        $user = $this->withRole('administrador', 25);
        $this->getJson('/api/v1/admin/reposiciones/999')->assertNotFound();
        $user->update(['role' => 'solicitante']);

        $this->getJson('/api/v1/admin/reposiciones/999')->assertForbidden()->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
    }

    public function test_deleted_users_cannot_keep_using_a_signed_token(): void
    {
        $user = $this->withRole('administrador');
        $user->delete();

        $this->getJson('/api/v1/admin/reposiciones/999')->assertUnauthorized();
    }

    public function test_case_insensitive_database_matches_cannot_change_the_soap_identity(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('soap_username')->nullable()->collation('NOCASE')->change();
        });
        User::factory()->create(['soap_username' => 'ana', 'role' => 'administrador']);
        $this->mock(SoapLoanService::class)->shouldReceive('login')->once()->andReturn((object) ['exito' => true]);

        $this->assertSame('ana', User::where('soap_username', 'ANA')->firstOrFail()->soap_username);
        $this->postJson('/api/v1/login', ['username' => 'ANA', 'password' => 'password'])->assertUnauthorized()->assertJsonMissingPath('token');
        $this->withToken(app(JwtService::class)->issue('ANA'))->getJson('/identity-test')->assertUnauthorized();
    }

    public function test_reused_application_does_not_accumulate_request_guards(): void
    {
        $this->withRole('administrador');
        $this->getJson('/identity-test')->assertOk();
        $guard = Auth::guard('jwt');

        $this->getJson('/identity-test')->assertOk();
        $this->assertSame($guard, Auth::guard('jwt'));
    }

    public function test_soap_bindings_use_only_the_current_verified_bearer(): void
    {
        Route::get('/soap-binding-test', function () {
            $soap = app(SoapLoanService::class);

            return response()->json([
                'username' => new \ReflectionProperty(SoapLoanService::class, 'username')->getValue($soap),
                'token_hash' => hash('sha256', new \ReflectionProperty(SoapLoanService::class, 'token')->getValue($soap)),
            ]);
        });
        $ana = $this->withRole('administrador');
        $anaToken = app(JwtService::class)->issue($ana->soap_username);
        $this->withToken($anaToken)->getJson('/soap-binding-test')->assertJsonPath('username', $ana->soap_username)->assertJsonPath('token_hash', hash('sha256', $anaToken));
        $bea = $this->withRole('solicitante', 25);
        $beaToken = app(JwtService::class)->issue($bea->soap_username);

        $this->withToken($beaToken)->withHeaders(['X-WSS-Username' => $ana->soap_username, 'X-WSS-Token' => $anaToken])->getJson('/soap-binding-test')->assertJsonPath('username', $bea->soap_username)->assertJsonPath('token_hash', hash('sha256', $beaToken));
    }
}
