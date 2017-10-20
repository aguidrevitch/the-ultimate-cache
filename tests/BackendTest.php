<?php
declare(strict_types = 1);

final class BackendTest extends TestCase
{
    protected $cacheDir = '/tmp/ultimate-cache';

    public function setUp()
    {
        parent::setUp();
//        $this->rrmdir($this->cacheDir);
        @mkdir($this->cacheDir);
    }

    public function tearDown()
    {
        parent::tearDown();
        $this->rrmdir($this->cacheDir);
    }

    function rrmdir($dir)
    {
        if (is_dir($dir)) {
            @chmod($dir, 0755);
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object))
                        $this->rrmdir($dir . "/" . $object);
                    else
                        unlink($dir . "/" . $object);
                }
            }
            rmdir($dir);
        }
    }

    public function testConstructor()
    {
        try {
            $backend = new the_ultimate_cache_backend(array());
            $this->fail("Should have failed");
        } catch (Exception $e) {
            $this->addToAssertionCount(1);
        }

        try {
            $backend = new the_ultimate_cache_backend(array(
                'dir' => '/tmp/not-exists'
            ));
            $this->fail("Should have failed");
        } catch (Exception $e) {
            $this->addToAssertionCount(1);
        }

        @chmod($this->cacheDir, 0000);
        try {
            $backend = new the_ultimate_cache_backend(array(
                'dir' => $this->cacheDir
            ));
            $this->fail("Should have failed");
        } catch (Exception $e) {
            $this->addToAssertionCount(1);
        }
        @chmod($this->cacheDir, 0755);

        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $this->assertInstanceOf('the_ultimate_cache_backend', $backend);
    }

    public function testPathGenerator()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));

        $result = $this->callMethod($backend, 'cache_filename', array('GET|/'));
        $this->assertEquals($result, $this->cacheDir . '/fc/39/fc39497565cf59c6e3054b1bb500ec91');
    }

    public function testStoreSuccess()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $key = 'GET|/';
        $filename = $this->callMethod($backend, 'cache_filename', array($key));
        $this->assertFileNotExists($filename);
        $this->assertEquals(4 + strlen("ultimate-cache-write"), $backend->store($key, "ultimate-cache-write"));
        $this->assertFileExists($filename);
        $this->assertEquals(pack("L", 0) . "ultimate-cache-write", file_get_contents($filename));
    }

    public function testStoreOverwriteSuccess()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $key = 'GET|/';
        $filename = $this->callMethod($backend, 'cache_filename', array($key));
        $this->assertFileNotExists($filename);
        $this->assertEquals(4 + strlen("ultimate-cache-write"), $backend->store($key, "ultimate-cache-write"));
        $this->assertFileExists($filename);
        $this->assertEquals(4 + strlen("ultimate-cache-overwrite"), $backend->store($key, "ultimate-cache-overwrite"));
        $this->assertFileExists($filename);
        $this->assertEquals(pack("L", 0) . "ultimate-cache-overwrite", file_get_contents($filename));
    }

    public function testTTL()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $key = 'GET|/';
        $backend->store($key, "ultimate-cache-ttl", 1);
        $this->assertEquals("ultimate-cache-ttl", $backend->retrieve($key));
        sleep(2);
        $this->assertFalse($backend->retrieve($key));
    }

    public function testDeleteSingle()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $key = 'GET|/single';
        $backend->store($key, "ultimate-cache-single", 100);
        $backend->store('GET|/single2', "ultimate-cache-single2", 100);
        $this->assertEquals("ultimate-cache-single", $backend->retrieve($key));
        $this->assertEquals(1, $backend->invalidate(["GET|/single"]));
        $this->assertFalse($backend->retrieve($key));
    }

    public function testDeletePattern()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $backend->store('GET|/single', "ultimate-cache-single", 100);
        $backend->store('GET|/signin', "ultimate-cache-signin", 100);
        $this->assertEquals("ultimate-cache-single", $backend->retrieve('GET|/single'));
        $this->assertEquals("ultimate-cache-signin", $backend->retrieve('GET|/signin'));
        $this->assertEquals(2, $backend->invalidate(["GET|/si*"]));
        $this->assertFalse($backend->retrieve('GET|/single'));
        $this->assertFalse($backend->retrieve('GET|/signin'));
    }

    public function testDeletePattern2()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));
        $backend->store('GET|/single', "ultimate-cache-single", 100);
        $backend->store('GET|/signin', "ultimate-cache-signin", 100);
        $this->assertEquals("ultimate-cache-single", $backend->retrieve('GET|/single'));
        $this->assertEquals("ultimate-cache-signin", $backend->retrieve('GET|/signin'));
        $this->assertEquals(1, $backend->invalidate(["GET|/sin*"]));
        $this->assertFalse($backend->retrieve('GET|/single'));
        $this->assertEquals("ultimate-cache-signin", $backend->retrieve('GET|/signin'));
    }

    public function testMassDeletePattern()
    {
        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));

        $items = [];
        foreach (range(0, 9999) as $i) {
            $items[] = md5((string)$i);
        }

        foreach ($items as $item) {
            $this->assertEquals(32 + 4, $backend->store($item, $item, 100));
        }

        foreach ($items as $i => $item) {
            $this->assertEquals($item, $backend->retrieve($item));
        }

        $this->assertEquals(584, $backend->invalidate(['e*']));
        foreach ($items as $item) {
            $result = $backend->retrieve($item);
            if (substr($item, 0, 1) === 'e') {
                $this->assertFalse($result);
            } else {
                $this->assertEquals($item, $result);
            }
        }
    }

}