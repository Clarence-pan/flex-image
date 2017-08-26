<?php

namespace FlexImage\Controllers;

use App\Http\Controllers\Controller;
use FlexImage\Core\ImageCroper;
use FlexImage\Core\ImageException;
use FlexImage\Core\ImageFileResponse;
use FlexImage\Core\ImageServer;
use FlexImage\Core\ImageUploader;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FlexImageController extends Controller
{
    protected $app;
    protected $imageServer;
    protected $responseFactory;

    public function __construct(Container $app, ImageServer $imageServer, ResponseFactory $responseFactory)
    {
        $this->app = $app;
        $this->imageServer = $imageServer;
        $this->responseFactory = $responseFactory;
    }

    /**
     * 裁剪图片.
     */
    public function resizeImage($filePath)
    {
        if (!preg_match('#' .
            '^(?<baseName>.*)' .
            '_(?<width>\d+)x(?<height>\d+)' .
            '(_c(?<crop>\d+))?' .
            '\.(?<extName>' . implode('|', $this->getSupportedImageTypes()) . ')' .
            '#i', $filePath, $matches)
        ) {
            return $this->responseFactory->make('Image Not Found', 404);
        }

        $basePath = $this->imageServer->getBasePath();

        // 禁止访问上级目录, 粗暴点，所有带".."的都不能访问
        if (strpos($matches['baseName'], '..') !== false) {
            return $this->responseFactory->make('Not Found', 404);
        }

        // 检查对应的文件是否存在，如果存在，则直接发送即可
        $actualFile = sprintf('%s/%s_%dx%d_c%d.%s',
            $basePath, $matches['baseName'], $matches['width'], $matches['height'], $matches['crop'], $matches['extName']);
        if (is_file($actualFile)) {
            return new ImageFileResponse($actualFile);
        }

        if (!$this->isSizeAvailable($matches['width'], $matches['height'])) {
            return $this->responseFactory->make('Size Not Found', 404);
        }

        // 获取原始文件，如果原始文件都不存在，那么就404
        $originalFile = sprintf('%s/%s.%s', $basePath, $matches['baseName'], $matches['extName']);
        if (!is_file($originalFile)) {
            return $this->responseFactory->make('Original File Not Found', 404);
        }

        try {
            $imageCroper = new ImageCroper();

            try {
                $imageCroper->cropFile($originalFile, $actualFile, $matches['width'], $matches['height'], $matches['crop']);

                return new ImageFileResponse($actualFile);
            } catch (\ImagickException $e){
                $tmpFile = fopen('php://memory', 'rw');
                $imageCroper->cropFile($originalFile, $tmpFile, $matches['width'], $matches['height'], $matches['crop']);
                rewind($tmpFile);

                $response = new Response();
                $response->headers->set('Content-Type', MimeTypeGuesser::getInstance()->guess($originalFile));
                $response->setContent(stream_get_contents($tmpFile));

                fclose($tmpFile);

                return $response;
            }
        } catch (ImageException $e) {
            return $this->responseFactory->make($e->getMessage(), $e->getCode());
        }
    }

    public function upload(Request $request)
    {
        switch ($request->method()) {
            case 'GET':
            case 'HEAD':
                throw new NotFoundHttpException();
            case 'POST':
                try {
                    $uploader = new ImageUploader([
                        'config' => $this->app['config']['flex_image'],
                        'shortcutFile' => function ($file) {
                            return $this->imageServer->getSameFile($file);
                        },
                        'onFileSaved' => function($file){
                            return $this->imageServer->updateCachedFile($file);
                        }
                    ]);

                    $imgInfo = $uploader->capture()->save();

                    return $this->responseFactory->json([
                        'success' => true,
                        'message' => '上传成功！',
                        'status' => 100,
                        'data' => $imgInfo,
                    ]);
                } catch (ImageException $e) {
                    return $this->responseFactory->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                        'status' => $e->getCode() ?: 400,
                    ]);
                }
            case 'OPTIONS':
                return $this->responseFactory->make('', 200, [
                    'Allow' => 'POST',
                ]);
            default:
                throw new MethodNotAllowedHttpException(['POST']);
        }
    }

    /**
     * 判断一个尺寸是否支持
     *
     * @param $width
     * @param $height
     *
     * @return bool
     */
    protected function isSizeAvailable($width, $height)
    {
        static $imageSizes = null;

        if ($this->app['config']['flex_image.enable_all_sizes']) {
            return true;
        }

        if (is_null($imageSizes)) {
            $imageSizes = array_flip((array)$this->app['config']['flex_image.image_sizes']);
        }

        return is_array($imageSizes) && isset($imageSizes["{$width}x{$height}"]);
    }

    /**
     * @return array 获取所有支持的图片类型
     */
    protected function getSupportedImageTypes($withContentType = false)
    {
        return ['png', 'jpg', 'jpeg', 'gif'];
    }
}
