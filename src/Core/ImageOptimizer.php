<?php

namespace FlexImage\Core;

class ImageOptimizer
{
    protected $quality = 75; // 图片质量
    protected $minFileSize = 102400;  // 小于多少的就不用优化了 -- 默认100k

    public function __construct($attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * 优化图片.
     *
     * @param $file
     *
     * @return bool|int
     */
    public function optimize($file)
    {
        $extName = ImageUtils::getFileExt($file);

        if (!in_array(strtolower($extName), ['.jpg', '.jpeg'])) {
            return false;
        }

        $fileSize = filesize($file);
        if ($fileSize <= $this->minFileSize) {
            return false;
        }

        try {
            $im = new \Imagick($file);
            $im->stripImage();

            // 如果图片是jpg的，还可以适当压缩下
            if ($im->getImageFormat() == 'JPEG') {
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality($this->quality);
            }

            $im->writeImage($file);
        } catch (\ImagickException $e) {
            return false;
        }

        $newFileSize = filesize($file);

        return $fileSize - $newFileSize;
    }
}
