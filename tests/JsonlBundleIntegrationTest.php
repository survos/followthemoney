<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FollowTheMoney\Model;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;

final class JsonlBundleIntegrationTest extends TestCase
{
    public function testBundleRoundTrip(): void
    {
        if (!class_exists(JsonlWriter::class)) {
            self::markTestSkipped('Optional jsonl-bundle is not installed. Run through mono to test integration.');
        }
        $directory = sys_get_temp_dir().'/ftm-jsonl-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $model = Model::bundled();
            $entity = $model->create('Person', 'emily');
            $entity->name = 'Emily Armstead Berry';
            foreach (['jsonl', 'jsonl.gz'] as $extension) {
                $path = $directory.'/people.'.$extension;
                $writer = JsonlWriter::open($path);
                try {
                    $writer->write($entity);
                    $writer->finish();
                } finally {
                    $writer->close();
                }
                $rows = iterator_to_array(JsonlReader::open($path), false);
                self::assertCount(1, $rows);
                $restored = $model->fromArray($rows[0]);
                self::assertSame($entity->id, $restored->id);
                self::assertSame($entity->names, $restored->names);
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
