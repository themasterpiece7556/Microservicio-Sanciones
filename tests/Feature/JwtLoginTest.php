<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateJwt;
use App\Services\JwtService;
use App\Services\SoapLoanService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class JwtLoginTest extends TestCase
{
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
        Route::get('/jwt-test', fn () => response()->json(['ok' => true]))->middleware(AuthenticateJwt::class);
        $this->getJson('/jwt-test')->assertUnauthorized();
        $this->withToken('invalid')->getJson('/jwt-test')->assertUnauthorized();
        $token = app(JwtService::class)->issue('ana');
        $this->withToken($token)->getJson('/jwt-test')->assertOk();
        $this->withToken($token.'x')->getJson('/jwt-test')->assertUnauthorized();
    }

    public function test_expired_and_foreign_tokens_are_rejected(): void
    {
        Route::get('/jwt-test', fn () => response()->json(['ok' => true]))->middleware(AuthenticateJwt::class);
        foreach ([['exp' => time() - 1], ['iss' => 'foreign'], ['aud' => 'foreign']] as $override) {
            $claims = array_merge(['sub' => 'ana', 'username' => 'ana', 'iss' => config('jwt.issuer'), 'aud' => config('jwt.audience'), 'iat' => time() - 60, 'exp' => time() + 60], $override);
            $token = JWT::encode($claims, str_repeat('s', 32), 'HS256');
            $this->withToken($token)->getJson('/jwt-test')->assertUnauthorized();
        }
    }
}
