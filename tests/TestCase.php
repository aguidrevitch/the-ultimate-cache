<?php

#use PHPUnit\Framework\TestCase;

class TestCase extends PHPUnit\Framework\TestCase
{
    public static function callMethod($obj, $name, array $args)
    {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}