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

    /**
     * 判断字符串是否是以特定子串开头
     * @param string            $haystack
     * @param string|array      $needles
     * @param bool|false $caseInsensitivity
     * @return bool
     */
    public static function strStartsWith($haystack, $needles, $caseInsensitivity=false)
    {
        $haystackLen = strlen($haystack);

        foreach ((array)$needles as $needle) {
            $needleLen = strlen($needle);
            if (($needleLen === 0 && $haystackLen === 0)
                || ($needleLen <= $haystackLen && substr_compare($haystack, $needle, 0, $needleLen, $caseInsensitivity) === 0)){
                return true;
            }
        }

        return false;
    }


    /**
     * 快速生成一个随机字符串.
     *
     * @param int $length
     *
     * @return string
     */
    public static function quickRandomString($length = 16)
    {
        return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', $length)), 0, $length);
    }

    /**
     * @param $baseDir
     * @param $filePath
     * @return bool|string 相对路径
     */
    public static function relativePath($baseDir, $filePath)
    {
        $baseDirLen = strlen($baseDir);
        $filePathLen = strlen($filePath);

        if ($filePathLen >= $baseDirLen && strncmp($baseDir, $filePath, $baseDirLen) === 0){
            return substr($filePath, $baseDirLen);
        } else {
            return false;
        }
    }
}
