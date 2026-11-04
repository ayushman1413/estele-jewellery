<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function fakeVideo(string $name = 'video.mp4', int $kilobytes = 100): UploadedFile
    {
        $ftyp = hex2bin('00000018667479706d703432000000006d703432');

        return UploadedFile::fake()->createWithContent($name, str_pad($ftyp, $kilobytes * 1024, "\0"))->mimeType('video/mp4');
    }

    protected function fakePng(string $name = 'image.png', int $kilobytes = 8): UploadedFile
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, imagecolorallocate($image, 200, 160, 80));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $padding = $kilobytes * 1024 - strlen($png) - 12;

        if ($padding > 0) {
            $data = "pad\0".str_repeat('x', $padding - 4);
            $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
            $png = substr($png, 0, -12).$chunk.substr($png, -12);
        }

        return UploadedFile::fake()->createWithContent($name, $png)->mimeType('image/png');
    }
}
