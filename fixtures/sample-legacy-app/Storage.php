<?php

namespace Demo;

interface StorageInterface
{
}

trait Auditable
{
    public static function example()
    {
        self::create();
    }
}

class BaseStorage
{
}

class Storage extends BaseStorage implements StorageInterface
{
    use Auditable;
}
