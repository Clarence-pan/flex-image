<?php

namespace FlexImage\Core;

use Illuminate\Support\Str;

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
    protected $selectedStorage = null;  // $_REQUEST['storage']  -- 可以选择存储到哪里

    // 内部参数，通过new对象的时候的参数或环境变量来控制
    protected $imageHost;   // 图片服务器/本服务器的域名 -- 用于拼接fullUrl
    protected $siteBaseDir; // 站点的根目录
    protected $maxFileSize = 2097152; // 最大文件大小，以字节为单位 (默认2M)
    protected $allowedFileExtNames = ['.png', '.jpg', '.jpeg', '.gif'];
    protected $shortcutFile = null; // 极速秒传文件 -- 即使用缓存中的文件
    protected $onFileSaved = null; // 保存文件后的回调处理

    // 采用什么存储
    protected $enabledStorage = [];
    protected $defaultStorage = null;

    // 配置
    protected $config = [];

    // 内部存储
    protected $uploadedImages;

    public function __construct($params = [])
    {
        $this->setParams($params);

        $this->imageHost = $this->config['img_server_host'] ?: app()->request->getHost();
        $this->maxFileSize = $this->config['max_upload_size'];
        $this->enabledStorage = $this->config['enabled_storage'];
        $this->defaultStorage = $this->config['default_storage'];

        $this->siteBaseDir = $this->config['file']['site_base_dir'];
        if (!is_dir($this->siteBaseDir)) {
            throw new ImageException('内部错误：服务器存储路径错误！', 511);
        }
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
                throw new ImageException('您上传的图片格式不支持，请上传 ' . implode(', ', $this->allowedFileExtNames) . ' 格式的图片。');
            }

            if ($file['size'] > $this->maxFileSize) {
                throw new ImageException('您上传的图片太大了，请换个小一点的。', 412);
            }

            if (!empty($file['error'])) {
                throw new ImageException($this->getPhpFileErrorMessage($file['error']), 430);
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                throw new ImageException('囧，上传的图片文件找不到了，请稍后再试或联系管理员。', 411);
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

        $storage = app()->make($this->enabledStorage[$this->selectedStorage]);
        $storage->config($this->config);

        foreach ($this->uploadedImages as &$uploadedImage) {
            // 判断是否可以极速秒传
            // todo: 七牛如何极速秒传？
            if ($this->selectedStorage === 'file'
                && !$this->willClipCrop()
                && $this->shortcutFile
                && ($shortcut = call_user_func($this->shortcutFile, $uploadedImage['tempFile']))
                && ImageUtils::strStartsWith($shortcut, $this->siteBaseDir) ){
                $uploadedImage['path'] = substr($shortcut, strlen($this->siteBaseDir));
                $uploadedImage['fullPath'] = $shortcut;
                $uploadedImage['fullUrl'] = $storage->getImageUrl($uploadedImage['path']);
            }  else {
                // 裁剪，缩放，优化等
                $this->clipCropAndOptimize($uploadedImage['tempFile']);

                // 保存文件
                $savedImage = $storage->saveImage($uploadedImage, [
                    'dir' => $this->dir
                ]);

                // 合并图片信息
                $uploadedImage = array_merge($uploadedImage, $savedImage);

                if (!isset($uploadedImage['fullUrl']) && isset($uploadedImage['url'])){
                    $uploadedImage['fullUrl'] = $uploadedImage['url'];
                }

                // 通知保存成功
                if ($this->selectedStorage === 'file' && $this->onFileSaved && !empty($uploadedImage['fullPath'])){
                    call_user_func($this->onFileSaved, $uploadedImage['fullPath']);
                }
            }

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
     * @return bool 判断是否需要裁剪
     */
    protected function willClipCrop()
    {
        return $this->rotate || $this->clipRect || $this->thumbSize;
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
     * @throws \FlexImage\Core\ImageException
     */
    protected function captureRequestParams()
    {
        if (!empty($_REQUEST['storage'])){
            $this->selectedStorage = $_REQUEST['storage'];
            if (!isset($this->enabledStorage[$this->selectedStorage])){
                throw new ImageException("存储方式无效：" . $this->selectedStorage);
            }
        } else {
            $this->selectedStorage = $this->defaultStorage;
        }

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

    protected function getPhpFileErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '您上传的文件太大了！请选择一个小一点的文件。';
            case UPLOAD_ERR_PARTIAL:
                return '网络似乎不太好，文件上传不完整，请重试。';
            case UPLOAD_ERR_NO_FILE:
                return '服务器没有收到文件~';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器没有地方可以放您上传的文件，请联系管理员。';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器保存上传的文件失败，请联系管理员处理。';
            default:
                return '网络似乎不太好，请稍后再试（内部错误代码：' . $errorCode . '）。';
        }
    }
}
