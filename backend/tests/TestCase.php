<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Signed builds live on their own disk (config/filesystems.php 'signed'). Fake it for every
        // test so no test writes signed IPAs into storage/app/artifacts or reaches the S3 bucket.
        Storage::fake('signed');
    }
}
