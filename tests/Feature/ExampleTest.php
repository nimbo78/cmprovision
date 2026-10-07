<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The front page sends anonymous visitors to the login form.
     *
     * @return void
     */
    public function testFrontPageRedirectsToLogin()
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
