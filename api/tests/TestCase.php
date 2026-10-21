<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        putenv('PASSKEY_ENCRYPTION_KEY='.$key);
        $_ENV['PASSKEY_ENCRYPTION_KEY'] = $key;
        $_SERVER['PASSKEY_ENCRYPTION_KEY'] = $key;

        parent::setUp();
    }
}
