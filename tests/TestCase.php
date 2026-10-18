<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * schema.sql creates enum types, which migrate:fresh keeps unless told
     * otherwise; re-running the schema would then fail on CREATE TYPE.
     */
    protected bool $dropTypes = true;
}
