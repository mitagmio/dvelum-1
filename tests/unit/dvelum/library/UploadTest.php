<?php
use PHPUnit\Framework\TestCase;

class UploadTest extends TestCase
{
    public function testCreateDirs()
    {
        $rootPath = '../';
        $subPath = '/uploads/0/1/2/3';
        $uploader = new \Dvelum\App\Upload\Uploader([]);
        $this->assertTrue($uploader->createDirs($rootPath , $subPath));
        $this->assertTrue(file_exists('../uploads/0/1/2/3'));
        $this->assertTrue(is_dir('../uploads/0/1/2/3'));
        \Dvelum\File::rmdirRecursive('../uploads/', true);
    }
}