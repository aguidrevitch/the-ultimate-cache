<?php
declare(strict_types = 1);

final class BackendTest extends TestCase
{
    protected $cacheDir = '/tmp/ultimate-cache';

    public function setUp()
    {
        parent::setUp();
        @mkdir($this->cacheDir);
    }

    public function tearDown()
    {
        parent::tearDown();
        $this->rrmdir($this->cacheDir);
    }

    function rrmdir($dir) {
        if (is_dir($dir)) {
            @chmod($dir, 0755);
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir."/".$object))
                        $this->rrmdir($dir."/".$object);
                    else
                        unlink($dir."/".$object);
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
        $this->assertEquals(strlen("ultimate-cache-write"), $backend->store($key, "ultimate-cache-write"));
        $this->assertFileExists($filename);
        $this->assertEquals(file_get_contents($filename), "ultimate-cache-write");
    }

    public function testReadSuccess()
    {
        @mkdir($this->cacheDir . '/fc/39/', 0755, true);
        file_put_contents($this->cacheDir . '/fc/39/fc39497565cf59c6e3054b1bb500ec91', "ultimate-cache-read");

        $backend = new the_ultimate_cache_backend(array(
            'dir' => $this->cacheDir
        ));

        $this->assertEquals("ultimate-cache-read", $backend->retrieve('GET|/'));
    }

}