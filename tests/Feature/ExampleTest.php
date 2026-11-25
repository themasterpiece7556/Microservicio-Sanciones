<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_requires_a_bearer_token(): void
    {
        $response = $this->get('/');

        $response->assertUnauthorized();
    }
}
