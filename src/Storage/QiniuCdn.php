<?php


namespace FlexImage\Storage;

use FlexImage\Core\ImageException;
use FlexImage\Core\ImageUtils;
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;


class QiniuCdn implements AbstractStorage
{
    protected $config = [];

    protected $accessKey;
    protected $secretKey;
    protected $bucket;
    protected $accessDomain;

    const SCHEMA = 'qiniu-cdn';

    /**
     * 设置配置
     *
     * @param array $config 即配置文件config/flex_image中的内容
     * @return void
     */
    public function config(array $config = [])
    {
        $this->config = $config;

        $this->accessKey = $config[self::SCHEMA]['access_key'];
        $this->secretKey = $config[self::SCHEMA]['secret_key'];
        $this->bucket = $config[self::SCHEMA]['bucket'];
        $this->accessDomain = $config[self::SCHEMA]['access_domain'];
    }

    public function getSchema()
    {
        return 'qiniu-cdn';
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
        $auth = new Auth($this->accessKey, $this->secretKey);
        $token = $auth->uploadToken($this->bucket);

        $filePath = $this->allocFilePath($options['dir'], $file['extName']);

        $uploadMgr = new UploadManager();
        list($ret, $err) = $uploadMgr->putFile($token, $filePath, $file['tempFile']);
        if ($err) {
            throw new ImageException('上传图片到CDN失败: ' . (data_get($err, 'response.error') ?: json_encode($err)), 550);
        }

        if (empty($ret['key'])) {
            throw new ImageException('上传到图片CDN失败：没有获取到key！');
        }

        return [
            'path' => self::SCHEMA . '://' . $ret['key'],
            'url' => (@$options['protocol'] ?: 'http') . '://' . $this->accessDomain . '/' . $ret['key'],
        ];
    }

    /**
     * 获取图片的URL
     * @param string $path 即保存文件的时候返回的path
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

        // 裁剪等参数
        $params = '';
        if ($width || $height || $mode){
            $params = $this->getParams($width, $height, $mode);
        }

        return $protocol . '://' . $this->accessDomain . ($path[0] === '/' ? '' : '/') . $path . $params;
    }

    protected function getParams($width, $height, $mode)
    {
        // 转换模式
        switch ($mode){
            // 1: 等比例缩放至刚好最高或最宽
            case 1:
                $mode = 0;
                break;

            // 2等比例缩放至刚好最高或最宽，然后再填充空白
            case 2:
                $mode = 0;
                break;

            // 3拉伸缩放  -- 七牛不支持
            // 4左上角裁剪，无缩放  -- 七牛不支持
            // 0或其他情况：等比例缩放后居中裁剪
            default:
                $mode = 1;
                break;
        }

        return sprintf('?imageView2/%d/w/%d/h/%d', $mode, $width, $height);
    }

    /**
     * @param $dir
     * @param $fileExtName
     * @return string
     */
    protected function allocFilePath($dir, $fileExtName)
    {
        return implode('/', array_filter([$dir, date('Ymd'), ImageUtils::quickRandomString(6)])) . $fileExtName;
    }
}