<?php

namespace Tests\Unit;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use function config;

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
     * @test
     * @return void
     */
    public function testingConnection()
    {

    }
}
