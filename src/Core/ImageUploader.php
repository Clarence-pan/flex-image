<?php

namespace FlexImage\Core;

class ImageUploader
{
    // 外部参数，通过请求中的参数来控制
    protected $isMulti = false;         // $_REQUEST['multi']    -- 是否是上传多个图片
    protected $dir = '';                // $_REQUEST['dir']      -- 存放到哪个目录里面
    protected $rotate = false;          // $_REQUEST['rotate']   -- 图片旋转角度（单位：角度degree）
    protected $rotateBackground = '#00000000'; // $_REQUEST['rotateBackground'] -- 图片旋转后的填充背景色
    protected $clipRect = false;        // $_REQUEST['clipRect'] -- 是否要裁剪，如果要裁剪，以"left,top,width,height"的形式指定坐标和宽高
    protected $thumbSize = false;       // $_REQUEST['thumbSize'] -- 是否要制作缩略图，如果指定，请以"320x200"这样的形式指定宽和高
    protected $shouldOptimize = false;  // $_REQUEST['optimize'] -- 是否要优化
    protected $quality = 75;            // $_REQUEST['quality']  -- 优化到什么质量

    // 内部参数，通过new对象的时候的参数或环境变量来控制
    protected $imageHost;   // 图片服务器/本服务器的域名 -- 用于拼接fullUrl
    protected $siteBaseDir; // 站点的根目录
    protected $uploadDir;   // 上传的目录(相对于siteBaseDir)
    protected $maxFileSize = 2097152; // 最大文件大小，以字节为单位 (默认2M)
    protected $allowedFileExtNames = ['.png', '.jpg', '.jpeg', '.gif'];

    // 内部存储
    protected $uploadedImages;

    public function __construct($params = [])
    {
        $this->setParams($params);
    }

    public function capture($field = null)
    {
        if (empty($_FILES)) {
            throw new ImageException('您还没有选择上传的图片！', 404);
        }

        // 捕获参数
        $this->captureRequestParams();

        // 捕获图片文件
        if ($this->isMulti) {
            $files = $_FILES;
        } else {
            if ($field) {
                $files = isset($_FILES[$field]) ? [$_FILES[$field]] : null;
            } else {
                $files = [reset($_FILES)];
            }
        }

        if (empty($files)) {
            throw new ImageException('没有找到上传的图片！', 404);
        }

        foreach ($files as $file) {
            if (empty($file['name'])) {
                continue; // 忽略无效的文件
            }

            $extName = ImageUtils::getFileExt($file['name']);
            if (!in_array($extName, $this->allowedFileExtNames)) {
                throw new ImageException('您上传的图片格式不支持，请上传 '.implode(', ', $this->allowedFileExtNames).' 格式的图片。');
            }

            if (!empty($file['error'])) {
                throw new ImageException('上传出错，错误码: '.$file['error'], 410);
            }

            if ($file['size'] > $this->maxFileSize) {
                throw new ImageException('您上传的图片太大了', 412);
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                throw new ImageException('上传的图片文件找不到了', 411);
            }

            $this->uploadedImages[] = [
                'originalName' => $file['name'],
                'fileSize' => $file['size'],
                'tempFile' => $file['tmp_name'],
                'extName' => $extName,
            ];
        }

        if (empty($this->uploadedImages)) {
            throw new ImageException('您确定选择文件并上传图片了吗？');
        }

        return $this;
    }

    public function save()
    {
        if (empty($this->uploadedImages)) {
            throw new ImageException('内部错误：图片文件尚未捕获！', 510);
        }

        if (!is_dir($this->siteBaseDir)) {
            throw new ImageException('内部错误：服务器存储路径错误！', 511);
        }

        foreach ($this->uploadedImages as &$uploadedImage) {
            // 分配上传文件的路径，并移动位置
            $uploadedImage['path'] = $this->allocUploadImagePath($this->siteBaseDir, [$this->uploadDir, $this->dir], $uploadedImage['extName']);
            $uploadedImage['fullPath'] = $this->siteBaseDir.$uploadedImage['path'];
            if ($this->imageHost) {
                $uploadedImage['fullUrl'] = 'http://'.$this->imageHost.$uploadedImage['path'];
            }

            if (move_uploaded_file($uploadedImage['tempFile'], $uploadedImage['fullPath']) === false) {
                throw new ImageException('图片文件无法移动位置！', 515);
            }

            // 裁剪，缩放，优化等
            $this->clipCropAndOptimize($uploadedImage['fullPath']);

            // 为了保护服务器的隐私，不要暴露全路径
            unset($uploadedImage['tempFile']);
            unset($uploadedImage['fullPath']);
        }

        if ($this->isMulti) {
            return $this->uploadedImages;
        } else {
            return reset($this->uploadedImages);
        }
    }

    /**
     * 获取参数.
     *
     * @return array
     */
    public function getParams()
    {
        return get_object_vars($this);
    }

    /**
     * 设置参数.
     *
     * @param array $params
     *
     * @return $this
     */
    public function setParams($params = [])
    {
        foreach ($params as $name => $value) {
            $this->{$name} = $value;
        }

        return $this;
    }

    /**
     * 裁剪，缩放，优化等.
     *
     * @param $file
     *
     * @throws ImageException
     */
    protected function clipCropAndOptimize($file)
    {
        if (!array_filter([$this->rotate, $this->clipRect, $this->thumbSize, $this->shouldOptimize])) {
            return;
        }

        try {
            $im = new \Imagick($file);
            $imgFormat = $im->getImageFormat();
            if (in_array($imgFormat, ['GIF', 'GIF87'])) {
                if (array_filter([$this->rotate, $this->clipRect, $this->thumbSize])) {
                    throw new ImageException('暂不支持对GIF图片的旋转和裁剪。');
                }

                return;
            }

            if ($this->rotate) {
                $success = $im->rotateImage(new \ImagickPixel($this->rotateBackground), floatval($this->rotate));
                if (!$success) {
                    throw new ImageException('旋转图片时候出现了一些问题，请稍微再试。');
                }
            }

            if ($this->clipRect) {
                $success = $im->cropImage($this->clipRect['width'], $this->clipRect['height'], $this->clipRect['left'], $this->clipRect['top']);
                if (!$success) {
                    throw new ImageException('裁剪图片时候出现了一些问题，请稍微再试。');
                }
            }

            if ($this->thumbSize) {
                $success = $im->cropThumbnailImage($this->thumbSize['width'], $this->thumbSize['height']);
                if (!$success) {
                    throw new ImageException('裁剪图片到指定大小的时候出现了一些问题，请稍微再试。');
                }
            }

            if ($this->shouldOptimize) {
                $im->stripImage();

                // 如果图片是jpg的，还可以适当压缩下
                if ($this->quality && in_array($imgFormat, ['JPG', 'JPEG'])) {
                    $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                    $im->setImageCompressionQuality($this->quality);
                }
            }

            $im->writeImage($file);
        } catch (\ImagickException $e) {
            throw new ImageException('裁剪/缩放/优化图片时候出现了一些问题，请稍微再试。');
        }
    }

    /**
     * 分配一个文件路径.
     *
     * @param string       $fileExtName
     * @param array|string $middlePaths
     * @param string       $fileExtName
     *
     * @return string
     *
     * @throws ImageException
     */
    protected function allocUploadImagePath($baseDir, $middlePaths, $fileExtName = '.jpg')
    {
        $baseDir = rtrim($baseDir, '/');
        $filePathPrefix = '/'.implode('/', array_filter(array_map(function ($s) {
                return trim($s, '/');
            }, (array) $middlePaths)))
            .'/'.date('Ymd').'/'.date('His');

        for ($tryTimes = 3; $tryTimes > 0; --$tryTimes) {
            $filePath = $filePathPrefix.$this->quickRandomString(6).$fileExtName;
            if (!is_file($baseDir.$filePath)) {
                $fileDir = dirname($baseDir.$filePath);
                if (!is_dir($fileDir)) {
                    mkdir($fileDir, 0777, true);
                }

                return $filePath;
            }
        }

        throw new ImageException('为上传的图片文件分配文件名失败！', 555);
    }

    /**
     * 快速生成一个随机字符串.
     *
     * @param int $length
     *
     * @return string
     */
    protected function quickRandomString($length = 16)
    {
        return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', $length)), 0, $length);
    }

    /**
     * 下载文件.
     *
     * @param $url
     * @param $saveTo
     *
     * @return bool
     */
    protected function downloadFile($url, $saveTo)
    {
        $saveToDir = dirname($saveTo);
        if (!is_dir($saveToDir)) {
            @mkdir(dirname($saveToDir), $mode = 0777, $rec = true);
        }

        $localFile = fopen($saveTo, 'wb');
        if (!$localFile) {
            return false;
        }

        $remoteFile = fopen($url, 'rb');
        if (!$remoteFile) {
            fclose($localFile);
            unlink($localFile);

            return false;
        }

        $blockSize = 4 * 1024;
        while (!feof($remoteFile)) {
            fwrite($localFile, fread($remoteFile, $blockSize), $blockSize);
        }

        fclose($remoteFile);
        fclose($localFile);

        return file_exists($saveTo);
    }

    /**
     * @throws \FlexImage\Core\ImageException
     */
    protected function captureRequestParams()
    {
        // 识别控制参数
        $falsy = ['false', 'no', '0', ''];
        if (!empty($_REQUEST['multi'])) {
            $this->isMulti = !in_array(strtolower(substr($_REQUEST['multi'], 0, 5)), $falsy);
        }

        if (!empty($_REQUEST['optimize'])) {
            $this->shouldOptimize = !in_array(strtolower(substr($_REQUEST['optimize'], 0, 5)), $falsy);
        }

        if (!empty($_REQUEST['rotate'])) {
            if (!preg_match('/^-?\d+(\.\d+)?$/', $_REQUEST['rotate'])) {
                throw new ImageException('图片旋转的角度格式不正确!');
            }

            $this->rotate = floatval($_REQUEST['rotate']);
        }

        if (!empty($_REQUEST['rotateBackground'])) {
            if (!preg_match('/^#(([0-9a-f]{3})|([0-9a-f]{6})|([0-9a-f]{8}))$/i', $_REQUEST['rotateBackground'])) {
                throw new ImageException('图片旋转的背景色格式不正确!');
            }

            $this->rotateBackground = $_REQUEST['rotateBackground'];
        }

        if (!empty($_REQUEST['clipRect'])) {
            if (!preg_match('/^\d+,\d+,\d+,\d+$/', $_REQUEST['clipRect'])) {
                throw new ImageException('裁剪范围格式不正确!');
            }

            $this->clipRect = array_combine(
                ['left', 'top', 'width', 'height'],
                array_map('intval', explode(',', $_REQUEST['clipRect']))
            );
        }

        if (!empty($_REQUEST['dir'])) {
            $this->dir = $_REQUEST['dir'];
            // 注意：不要访问了非法的目录
            if (!preg_match('~^[a-zA-Z0-9/_-]+$~', $this->dir)) {
                throw new ImageException('非法上传的目录!');
            }
        }

        if (!empty($_REQUEST['quality'])) {
            if (!preg_match('/^\d+$/', $_REQUEST['quality'])) {
                throw new ImageException('图片质量的格式不正确！');
            }

            $this->quality = intval($_REQUEST['quality']);
        }

        if (!empty($_REQUEST['thumbSize'])) {
            if (!preg_match('/^(?<width>\d+)x(?<height>\d+)$/', $_REQUEST['thumbSize'], $matches)) {
                throw new ImageException('缩略图大小的格式不正确！');
            }

            $this->thumbSize = [
                'width' => $matches['width'],
                'height' => $matches['height'],
            ];
        }
    }
}
