<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateJwt;
use App\Models\User;
use App\Services\JwtService;
use App\Services\SoapLoanService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class JwtLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('s', 32)]);
    }

    public function test_login_requires_credentials(): void
    {
        $this->postJson('/api/v1/login', [])->assertUnprocessable()->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_valid_credentials_produce_a_soap_compatible_token(): void
    {
        User::factory()->create(['soap_username' => 'ana', 'role' => 'solicitante']);
        $this->mock(SoapLoanService::class, function ($mock): void {
            $mock->shouldReceive('login')->once()->with('ana', 'correct-password')->andReturn((object) ['exito' => true]);
        });
        $response = $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'correct-password'])->assertOk()->assertJsonPath('token_type', 'Bearer');
        $claims = JWT::decode($response->json('token'), new Key(str_repeat('s', 32), 'HS256'));
        $this->assertSame('ana', $claims->sub);
        $this->assertSame('ana', $claims->username);
        $this->assertSame(3600, $claims->exp - $claims->iat);
        $this->assertObjectNotHasProperty('password', $claims);
    }

    public function test_invalid_credentials_do_not_issue_a_token(): void
    {
        $this->mock(SoapLoanService::class, function ($mock): void {
            $mock->shouldReceive('login')->andReturn((object) ['exito' => false]);
        });
        $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'wrong'])->assertUnauthorized()->assertJsonMissingPath('token');
    }

    public function test_soap_failure_returns_a_gateway_error(): void
    {
        $this->mock(SoapLoanService::class, function ($mock): void {
            $mock->shouldReceive('login')->andThrow(new \RuntimeException('SOAP unavailable'));
        });
        $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'password'])->assertStatus(502)->assertJsonMissingPath('token');
    }

    public function test_short_secrets_are_rejected(): void
    {
        config(['jwt.secret' => 'short']);
        $this->expectException(\LogicException::class);
        app(JwtService::class)->issue('ana');
    }

    public function test_protected_requests_require_a_valid_token(): void
    {
        User::factory()->create(['soap_username' => 'ana']);
        Route::get('/jwt-test', fn () => response()->json(['ok' => true]))->middleware(AuthenticateJwt::class);
        $this->getJson('/jwt-test')->assertUnauthorized();
        $this->withToken('invalid')->getJson('/jwt-test')->assertUnauthorized();
        $token = app(JwtService::class)->issue('ana');
        $this->withToken($token)->getJson('/jwt-test')->assertOk();
        $this->withToken($token.'x')->getJson('/jwt-test')->assertUnauthorized();
    }

    public function test_expired_and_foreign_tokens_are_rejected(): void
    {
        User::factory()->create(['soap_username' => 'ana']);
        Route::get('/jwt-test', fn () => response()->json(['ok' => true]))->middleware(AuthenticateJwt::class);
        $this->withToken(app(JwtService::class)->issue('ana'))->getJson('/jwt-test')->assertOk();
        foreach ([['exp' => time() - 1], ['iss' => 'foreign'], ['aud' => 'foreign']] as $override) {
            $claims = array_merge(['sub' => 'ana', 'username' => 'ana', 'iss' => config('jwt.issuer'), 'aud' => config('jwt.audience'), 'iat' => time() - 60, 'exp' => time() + 60], $override);
            $token = JWT::encode($claims, str_repeat('s', 32), 'HS256');
            $this->withToken($token)->getJson('/jwt-test')->assertUnauthorized();
        }
    }

    public function test_malformed_soap_login_does_not_issue_a_token(): void
    {
        $this->mock(SoapLoanService::class)->shouldReceive('login')->once()->andReturn((object) ['exito' => 'true']);

        $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'password'])->assertStatus(502)->assertJsonMissingPath('token');
    }

    public function test_login_is_limited_before_the_sixth_soap_call(): void
    {
        $this->mock(SoapLoanService::class)->shouldReceive('login')->times(5)->andReturn((object) ['exito' => false]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/login', ['username' => 'ana', 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_required_claims_and_wrong_algorithms_are_rejected(): void
    {
        User::factory()->create(['soap_username' => 'ana', 'role' => 'administrador']);
        $this->withToken(app(JwtService::class)->issue('ana'))->getJson('/api/v1/admin/reposiciones/999')->assertNotFound();
        $claims = ['sub' => 'ana', 'iss' => config('jwt.issuer'), 'aud' => config('jwt.audience'), 'iat' => time(), 'exp' => time() + 60];
        foreach (['sub', 'iss', 'aud', 'iat', 'exp'] as $required) {
            $invalid = $claims;
            unset($invalid[$required]);
            $this->withToken(JWT::encode($invalid, str_repeat('s', 32), 'HS256'))->getJson('/api/v1/admin/reposiciones/1')->assertUnauthorized();
        }

        $this->withToken(JWT::encode($claims, str_repeat('s', 64), 'HS512'))->getJson('/api/v1/admin/reposiciones/1')->assertUnauthorized();
    }
}
