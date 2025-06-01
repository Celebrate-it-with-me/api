<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }

    /**
     * Testing the connection to the S3 service using AWS SDK for PHP.
     *
     * @test
     *
     * @return void
     */
    public function testing_connection()
    {
    
    }
}
