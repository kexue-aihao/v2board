<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test builds a different in-memory schema. Eloquent caches the
        // guardable column list statically across application instances.
        $columns = new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns');
        $columns->setAccessible(true);
        $columns->setValue(null, []);
    }
}
