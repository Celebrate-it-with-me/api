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
        $s3Client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => '',
                'secret' => '',
            ]
        ]);

        try {
            $buckets = $s3Client->listBuckets();
            foreach ($buckets['Buckets'] as $bucket) {
                echo $bucket['Name'] . "\n";
            }
        } catch(AwsException $e) {
            // output error message if fails
            dd($e->getMessage());
        }
    }
}
