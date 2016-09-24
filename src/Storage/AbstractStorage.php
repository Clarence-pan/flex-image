<?php


namespace FlexImage\Storage;


interface AbstractStorage
{
    /**
     * 设置配置
     * @param array $config 即配置文件config/flex_image中的内容
     * @return void
     */
    public function config(array $config=[]);

    /**
     * 获取协议
     * @return string
     */
    public function getSchema();

    /**
     * 保存图片
     * @param array $file {tempFile: /tmp/asdkf, fileSize: 123, originalName: a/b/c.jpg, extName: .jpg}
     * @param array $options {dir: 'xxx'}
     * @return array {path: 'file://asfka', url: 'http://xxx.xx/xxxx.jpg' }
     * @throws
     */
    public function saveImage(array $file, array $options=[]);

    /**
     * 获取图片的URL
     * @param string $path 即保存文件的时候返回的path
     * @param number $width    图像宽度，默认自动
     * @param number $height   图像高度，默认自动
     * @param number $mode     图像裁剪/缩放的模式，默认自动
     * @param string $protocol 协议类型，默认http
     * @return string
     */
    public function getImageUrl($path, $width = null, $height = null, $mode = null, $protocol = 'http');
}