<?php

namespace FlexImage\Core;

final class ImageUtils
{
    /**
     * 获取文件的扩展名.
     *
     * @param $file
     *
     * @return string
     */
    public static function getFileExt($file)
    {
        return strtolower(strrchr($file, '.'));
    }

    /**
     * 下载文件.
     *
     * @param $url
     * @param $saveTo
     *
     * @return bool
     */
    public static function downloadFile($url, $saveTo)
    {
        try {
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
        } catch (\Exception $e) {
            if (!empty($remoteFile)) {
                fclose($remoteFile);
            }

            if (!empty($localFile)) {
                fclose($localFile);
            }

            return false;
        }
    }
}
