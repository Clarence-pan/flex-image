<?php


namespace FlexImage\Storage;


use FlexImage\Core\ImageException;
use FlexImage\Core\ImageServer;
use FlexImage\Core\ImageUtils;

class LocalFileStorage implements AbstractStorage
{
    protected $config = [];

    protected $siteBaseDir = '';
    protected $uploadDir = '';

    protected $imageHost = '';
    protected $imageHostAlias = [];

    const SCHEMA = 'file';

    /**
     * 设置配置
     *
     * @param array $config
     * @throws
     */
    public function config(array $config = [])
    {
        $this->config = $config;

        $this->siteBaseDir = $config[self::SCHEMA]['site_base_dir'];
        $this->uploadDir = $config[self::SCHEMA]['upload_dir'];

        if (!is_dir($this->siteBaseDir)) {
            throw new ImageException('内部错误：服务器存储路径错误！', 511);
        }

        if (!is_dir($this->uploadDir)) {
            throw new ImageException('内部错误：服务器存储路径错误！', 512);
        }

        $this->imageHost = $config['img_server_host'] ?: app()->request->getHost();
        $this->imageHostAlias = $config['img_server_host_aliases'];
    }

    /**
     * @return string 协议格式
     */
    public function getSchema()
    {
        return self::SCHEMA;
    }

    /**
     * 保存图片
     *
     * @param array $file    {tempFile: /tmp/asdkf, fileSize: 123, originalName: a/b/c.jpg, extName: .jpg}
     * @param array $options {dir: 'xxx'}
     * @return array {path: 'file://asfka', url: 'http://xxx.xx/xxxx.jpg' }
     * @throws
     */
    public function saveImage(array $file, array $options = [])
    {
        // 分配上传文件的路径，并移动位置
        $filePath = $this->allocUploadImagePath(
            $this->siteBaseDir,
            [ImageUtils::relativePath($this->siteBaseDir, $this->uploadDir), $options['dir']],
            $file['extName']);

        $fileFullPath = $this->siteBaseDir . $filePath;

        if (move_uploaded_file($file['tempFile'], $fileFullPath) === false) {
            throw new ImageException('图片文件无法移动位置！', 515);
        }

        return [
            'path' => self::SCHEMA . '://' . $filePath,
            'url' => $this->imageHost ? 'http://' . $this->imageHost . $filePath : $filePath,
            'fullPath' => $fileFullPath,
        ];
    }

    /**
     * 获取图片的URL
     * @param string $path
     * @param number $width    图像宽度，默认自动
     * @param number $height   图像高度，默认自动
     * @param number $mode     图像裁剪/缩放的模式，默认自动
     * @param string $protocol 协议类型，默认http
     * @return string
     */
    public function getImageUrl($path, $width = null, $height = null, $mode = null, $protocol = 'http')
    {
        if (empty($path)) {
            return '';
        }

        // 干掉潜在的文件协议标识
        $path = preg_replace('~^' . self::SCHEMA . '://~', '', $path);
        if (empty($path)) {
            return '';
        }

        // 根据宽高等参数调整输出格式
        if ($width || $height || $mode) {
            // 如果是有完整域名的URL，则直接返回
            if (preg_match('#^http(s?)://(?<host>[^/]+)/(?<path>.*)#', $path, $matches)) {
                if (!in_array($matches['host'], (array)$this->imageHostAlias)) {
                    return $path;
                }
            }

            if (preg_match('#^' .
                'http(s?)://[^/]+/' .
                '(?<baseName>.*)' .
                '(_(?<width>\d+)x(?<height>\d+))?' .
                '(_c(?<crop>\d+))?' .
                '\.(?<extName>jpg|jpeg|png|gif|bmp)' .
                '$#i', $path, $matches)) {
                $path = sprintf('%s_%dx%d_c%d.%s', $matches['baseName'], $width, $height, $mode, $matches['extName']);
            } elseif (preg_match('#^' .
                '(?<baseName>.*)' .
                '(_(?<width>\d+)x(?<height>\d+))?' .
                '(_c(?<crop>\d+))?' .
                '\.(?<extName>jpg|jpeg|png|gif|bmp)' .
                '$#i', $path, $matches)) {
                $path = sprintf('%s_%dx%d_c%d.%s', $matches['baseName'], $width, $height, $mode, $matches['extName']);
            }
        }

        // 增加host
        if ($this->imageHost) {
            return $protocol . '://' . $this->imageHost . ($path[0] == '/' ? '' : '/') . $path;
        } else {
            return ($path[0] == '/' ? '' : '/') . $path;
        }
    }


    /**
     * 分配一个文件路径.
     *
     * @param string       $fileExtName
     * @param array|string $middlePaths
     * @param string       $fileExtName
     *
     * @return string 文件路径，带前导的'/'
     *
     * @throws ImageException
     */
    protected function allocUploadImagePath($baseDir, $middlePaths, $fileExtName = '.jpg')
    {
        $baseDir = rtrim($baseDir, '/');
        $filePathPrefix = '/' . implode('/', array_filter(array_map(function ($s) {
                return trim($s, '/');
            }, (array)$middlePaths)))
            . '/' . date('Ymd') . '/' . date('His');

        for ($tryTimes = 3; $tryTimes > 0; --$tryTimes) {
            $filePath = $filePathPrefix . ImageUtils::quickRandomString(6) . $fileExtName;
            if (!is_file($baseDir . $filePath)) {
                $fileDir = dirname($baseDir . $filePath);
                if (!is_dir($fileDir)) {
                    mkdir($fileDir, 0777, true);
                }

                return $filePath;
            }
        }

        throw new ImageException('为上传的图片文件分配文件名失败！', 555);
    }

}