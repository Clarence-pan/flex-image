<?php

namespace FlexImage\Core;

use Illuminate\Contracts\Container\Container;

class ImageServer
{
    protected $app;
    protected $host;
    protected $uploadDir = 'uploads';

    public function __construct(Container $app, $attributes = [])
    {
        $this->app = $app;

        foreach ($attributes as $key => $val) {
            $this->{$key} = $val;
        }

        if (!isset($this->host)) {
            $this->host = $this->app['config']['flex_image.img_server_host'];
        }
    }

    public function getUploadDir()
    {
        return $this->uploadDir;
    }

    public function getSiteBaseDir()
    {
        return $this->app->make('path').str_replace('/', DIRECTORY_SEPARATOR, '/../public');
    }

    /**
     * 获取图片的基础路径.
     */
    public function getBasePath()
    {
        return $this->getSiteBaseDir().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->uploadDir);
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
        if (empty($path)) {
            return '';
        }

        if ($width || $height || $mode) {
            // 如果是有完整域名的URL，则直接返回
            if (preg_match('#^http(s?)://(?<host>[^/]+)/(?<path>.*)#', $path, $matches)) {
                if (!in_array($matches['host'], (array) $this->app['config']['flex_image.img_server_host_aliases'])) {
                    return $path;
                }
            }

            if (preg_match('#^'.
                'http(s?)://[^/]+/'.
                '(?<baseName>.*)'.
                '(_(?<width>\d+)x(?<height>\d+))?'.
                '(_c(?<crop>\d+))?'.
                '\.(?<extName>jpg|jpeg|png|gif|bmp)'.
                '$#i', $path, $matches)) {
                $path = sprintf('%s_%dx%d_c%d.%s', $matches['baseName'], $width, $height, $mode, $matches['extName']);
            } elseif (preg_match('#^'.
                '(?<baseName>.*)'.
                '(_(?<width>\d+)x(?<height>\d+))?'.
                '(_c(?<crop>\d+))?'.
                '\.(?<extName>jpg|jpeg|png|gif|bmp)'.
                '$#i', $path, $matches)) {
                $path = sprintf('%s_%dx%d_c%d.%s', $matches['baseName'], $width, $height, $mode, $matches['extName']);
            }
        }

        if ($this->host) {
            return $protocol.'://'.$this->host.($path[0] == '/' ? '' : '/').$path;
        } else {
            return ($path[0] == '/' ? '' : '/').$path;
        }
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
