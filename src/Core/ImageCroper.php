<?php

namespace FlexImage\Core;

class ImageCroper
{
    protected $quality = 80;  // 根据二八定律，80%的信息足够用了, 人眼分辨不出来的

    public function __construct($attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * 裁剪文件.
     *
     * @param $originalImageFile
     * @param $saveToImageFile
     * @param $width
     * @param $height
     * @param $cropMode
     *
     * @throws ImageException
     */
    public function cropFile($originalImageFile, $saveToImageFile, $width, $height, $cropMode)
    {
        // 使用Imagick进行裁剪/生成缩略图
        $im = new \Imagick($originalImageFile);
        if (!$im) {
            throw new ImageException('创建裁剪器失败！', 510);
        }

        $im = $this->cropImage($im, $width, $height, $cropMode);
        if (!$im) {
            throw new ImageException('裁剪图片失败！', 511);
        }

        // 移除所有的元数据，减少图片大小
        try {
            $im->stripImage();

            // 如果图片是jpg的，还可以适当压缩下
            if ($im->getImageFormat() == 'JPEG') {
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality($this->quality);
            }
        } catch (\ImagickException $e) {
        }

        if (is_resource($saveToImageFile)){
            $im->writeImageFile($saveToImageFile);
        } else {
            $im->writeImage($saveToImageFile);
        }

    }

    /**
     * 规范化裁剪模式.
     *
     * @param $cropMode
     *
     * @return int
     */
    protected function normalizeCropMode($cropMode)
    {
        $availableCropModes = $this->getAvailableCropModes();

        return isset($availableCropModes[$cropMode]) ? $cropMode : 0;
    }

    /**
     * 裁剪图片.
     *
     * @param \Imagick $imagick
     * @param          $width
     * @param          $height
     * @param          $cropMode
     *
     * @return \Imagick|mixed|null
     *
     * @throws ImageException
     */
    protected function cropImage(\Imagick $imagick, $width, $height, $cropMode)
    {
        $availableCropModes = $this->getAvailableCropModes();
        if (!isset($availableCropModes[$cropMode])) {
            throw new ImageException('裁剪模式无效！', 414);
        }

        $crop = $availableCropModes[$cropMode];

        if ($width == 0 && $height == 0) {
            return $imagick;
        } elseif ($width == 0) {
            $width = intval($imagick->getImageWidth() * $height / $imagick->getImageHeight());
        } elseif ($height == 0) {
            $height = intval($imagick->getImageHeight() * $width / $imagick->getImageWidth());
        }

        if (is_callable($crop['func'])) {
            return call_user_func($crop['func'], $imagick, $width, $height);
        } else {
            throw new ImageException('裁剪模式无效！', 415);
        }
    }

    protected static $cropModes = null;

    /**
     * @return array 获取所有支持的裁剪模式
     */
    public function getAvailableCropModes()
    {
        if (static::$cropModes) {
            return static::$cropModes;
        }

        static::$cropModes = [
            '0' => [
                'desc' => '等比例缩放后居中裁剪',
                'func' => function (\Imagick $imagick, $width, $height) {
                    $imagick->cropThumbnailImage($width, $height);

                    return $imagick;
                },
            ],
            '1' => [
                'desc' => '等比例缩放至刚好最高或最宽',
                'func' => function (\Imagick $imagick, $width, $height) {
                    $imagick->scaleImage($width, $height, true);

                    return $imagick;
                },
            ],
            '2' => [
                'desc' => '等比例缩放至刚好最高或最宽，然后再填充空白',
                'func' => function (\Imagick $imagick, $width, $height) {
                    $imagick->thumbnailImage($width, $height, true, true);

                    return $imagick;
                },
            ],
            '3' => [
                'desc' => '拉伸缩放',
                'func' => function (\Imagick $imagick, $width, $height) {
                    $imagick->scaleImage($width, $height, false);

                    return $imagick;
                },
            ],
            '4' => [
                // 注意: gif左上角裁剪有点小问题 -- 图像仍然是裁剪前的大小, 剩余部分用白色填充了
                'desc' => '左上角裁剪，无缩放',
                'func' => function (\Imagick $imagick, $width, $height) {
                    $imagick->cropImage($width, $height, 0, 0);

                    return $imagick;
                },
            ],
        ];

        return static::$cropModes;
    }
}
