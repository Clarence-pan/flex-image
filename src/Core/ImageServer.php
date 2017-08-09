<?php

namespace FlexImage\Core;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

class ImageServer
{
    protected $app;
    protected $config;
    protected $host;
    protected $uploadDir = 'uploads';
    protected $cache = 'cache';
    protected $cacheKeyPrefix = 'image_server:';
    protected $siteBaseDir;
    protected $enabledStorage;
    protected $defaultStorage;

    public function __construct(Container $app, $attributes = [])
    {
        $this->app = $app;
        $this->config = $this->app['config']['flex_image'];

        foreach ($attributes as $key => $val) {
            $this->{$key} = $val;
        }

        if (!isset($this->host)) {
            $this->host = @$this->config['img_server_host'];
        }

        if ($this->cache && is_string($this->cache)) {
            $this->cache = $app->make($this->cache);
        }

        $this->enabledStorage = @$this->config['enabled_storage'];
        $this->defaultStorage = @$this->config['default_storage'];
    }

    public function rebuildCache($progressCallback = null, $dir = null)
    {
        $dir = $dir ?: $this->getBasePath();
        $files = scandir($dir);
        if ($progressCallback) {
            call_user_func($progressCallback, "Processing {$dir}, total " . (count($files) - 2) . ' files/directories');
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $file = $dir . DIRECTORY_SEPARATOR . $file;
            if (!is_dir($file)) {
                $this->updateCachedFile($file);
            } else {
                $this->rebuildCache($progressCallback, $file);
            }
        }
    }

    /**
     * @param $file string 文件路径
     * @note 注： 文件应该已经存在
     * @return bool
     */
    public function updateCachedFile($file)
    {
        $cacheKey = $this->cacheKeyPrefix . $this->hashFile($file);
        $cachedFiles = $this->cache->get($cacheKey);
        if (!$cachedFiles){
            $cachedFiles = [];
        }

        $siteBaseDir = $this->getSiteBaseDir();
        $cachedFiles[] = ImageUtils::strStartsWith($file, $siteBaseDir) ? substr($file, strlen($siteBaseDir)) : $file;
        $cachedFiles = array_unique($cachedFiles);
        $this->cache->forever($cacheKey, $cachedFiles);
        return true;
    }

    public function hashFile($file)
    {
        return 'SHA1' . base64_encode(pack('N', filesize($file)) . sha1_file($file, true));
    }

    /**
     * @param $file string 文件路径
     * @note 注： 文件应该已经存在
     * @return false|string
     */
    public function getSameFile($file)
    {
        $cacheKey = $this->cacheKeyPrefix . $this->hashFile($file);
        $cachedFiles = $this->cache->get($cacheKey);
        if (!$cachedFiles){
            return false;
        }


        // 随机采样100x16字节内容看看是否内容一样
        srand(time());
        $fileSize = filesize($file);
        $randTestsPoints = [];
        $randTestsPointLen = 16;

        foreach (range(0, 100) as $i){
            $randTestsPoints[] = rand() % $fileSize;
        }

        sort($randTestsPoints);

        $fileHandle = @fopen($file, 'rb');
        if (!$fileHandle){
            return false;
        }

        $randTestsPointsValue = [];
        foreach ($randTestsPoints as $point) {
            fseek($fileHandle, $point, SEEK_SET);
            $randTestsPointsValue[] = fread($fileHandle, $randTestsPointLen);
        }

        fclose($fileHandle);

        foreach ($cachedFiles as $cachedFile) {
            $cachedFile = $this->getSiteBaseDir() . $cachedFile;
            $cachedFileHandle = @fopen($cachedFile, 'rb');
            if (!$cachedFileHandle){
                continue;
            }

            $diffs = false;
            foreach ($randTestsPoints as $index => $point) {
                fseek($cachedFileHandle, $point, SEEK_SET);
                $got = fread($cachedFileHandle, $randTestsPointLen);
                if ($got !== $randTestsPointsValue[$index]){
                    $diffs = true;
                }
            }

            fclose($cachedFileHandle);

            if (!$diffs){
                return $cachedFile;
            }
        }

        return false;
    }

    public function getUploadDir()
    {
        return $this->uploadDir;
    }

    public function getSiteBaseDir()
    {
        return $this->siteBaseDir ? : ($this->siteBaseDir = dirname($this->app->make('path')) . str_replace('/', DIRECTORY_SEPARATOR, '/public'));
    }

    /**
     * 获取图片的基础路径.
     */
    public function getBasePath()
    {
        return $this->getSiteBaseDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->uploadDir);
    }

    /**
     * 生成图片的地址
     *
     * @param string $path
     * @param number $width    图像宽度，默认自动
     * @param number $height   图像高度，默认自动
     * @param number $mode     图像裁剪/缩放的模式，默认自动
     * @param string $protocol 协议类型，默认http
     *
     * @return string
     */
    public function getImageUrl($path, $width = null, $height = null, $mode = null, $protocol = 'http')
    {
        if (preg_match('~^(?<schema>[a-zA-Z0-9-_]+)://~', $path, $matches)){
            $schema = $matches['schema'];
        } else {
            $schema = $this->defaultStorage;
        }

        // 无法识别的协议类型，则就用默认的存储方式进行解析
        if (!isset($this->enabledStorage[$schema])){
            reset($this->enabledStorage);
            $schema = key($this->enabledStorage);
        }

        $storage = $this->app->make($this->enabledStorage[$schema]);
        $storage->config($this->config);

        return $storage->getImageUrl($path, $width, $height, $mode, $protocol);
    }

    public function getAvailableCropModes()
    {
        return (new ImageCroper())->getAvailableCropModes();
    }

    /**
     * @return mixed
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param mixed $host
     */
    public function setHost($host)
    {
        $this->host = $host;
    }
}
