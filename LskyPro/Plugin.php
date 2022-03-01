<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 一款使用开源程序LskyPro(蓝空图床)的外链插件
 *
 * @package LskyPro
 * @author 莫名博客
 * @version 1.0.0
 * @link https://qzone.work
 */
class LskyPro_Plugin implements Typecho_Plugin_Interface
{
    const UPLOAD_DIR  = '/usr/uploads'; //上传文件目录路径
    const PLUGIN_NAME = 'LskyPro'; //插件名称

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle     = array(__CLASS__, 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle     = array(__CLASS__, 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle     = array(__CLASS__, 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array(__CLASS__, 'attachmentHandle');
    }

    public static function deactivate()
    {

    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $html = <<<HTML
<p>作者：<a target="_blank" href="https://qzone.work/codes/725.html">莫名博客</a>；一款使用<a href="https://github.com/wisp-x/lsky-pro" target="_blank">兰空图床</a>，将图片单独托管的外链插件。</p>
HTML;
        $desc = new Typecho_Widget_Helper_Form_Element_Text('desc', NULL, '', '插件介绍：', $html);
        $form->addInput($desc);

        $api = new Typecho_Widget_Helper_Form_Element_Text('api', NULL, 'https://img.qzone.work:8443/', 'Api：', '默认地址为作者自建的图床，如果填其它地址，请必须保证地址为http开头，/结尾');
        $form->addInput($api);

        $token = new Typecho_Widget_Helper_Form_Element_Text('token', NULL, '', 'Token：', '如果为空，则上传的所属用户为游客；如果有需求请自行修改。');
        $form->addInput($token);

        echo '<script>window.onload = function(){document.getElementsByName("desc")[0].type = "hidden";}</script>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {

            return false;
        }

        //获取扩展名
        $ext = self::_getSafeName($file['name']);
        //判定是否是允许的文件类型
        if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {

            return false;
        }

        // 判断是否是图片
        if (self::_isImage($ext)) {

            return self::_uploadImg($file, $ext);
        }

        return self::_uploadOtherFile($file, $ext);
    }

    public static function deleteHandle(array $content): bool
    {
        $ext = self::_getSafeName($content['title']);
        if (self::_isImage($ext)) {

            return self::_deleteImg($content);
        }

        return unlink($content['attachment']->path);
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {

            return false;
        }

        $ext = self::_getSafeName($file['name']);
        if ($content['attachment']->type != $ext || Typecho_Common::isAppEngine()) {

            return false;
        }

        if (!self::_getUploadFile($file)) {

            return false;
        }

        if (self::_isImage($ext)) {
            self::_deleteImg($content);

            return self::_uploadImg($file, $ext);
        }

        return self::_uploadOtherFile($file, $ext);
    }

    public static function attachmentHandle(array $content): string
    {
        $arr = unserialize($content['text']);
        $ext = self::_getSafeName($content['title']);
        if (self::_isImage($ext)) {

            return $content['attachment']->path ?? '';
        }

        $ret = explode(self::UPLOAD_DIR, $arr['path']);
        return Typecho_Common::url(self::UPLOAD_DIR . $ret[1], Helper::options()->siteUrl);
    }

    private static function _getUploadDir($ext = ''): string
    {
        if (self::_isImage($ext)) {
            $url = parse_url(Helper::options()->siteUrl);
            $DIR = str_replace('.', '_', $url['host']);
            return '/' . $DIR . self::UPLOAD_DIR;
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            $path = Typecho_Common::url(self::UPLOAD_DIR, __TYPECHO_ROOT_DIR__);
            return $path;
        }
    }

    private static function _getUploadFile($file): string
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
    }

    private static function _getSafeName(&$name): string
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function _makeUploadDir($path): bool
    {
        $path    = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last    = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last    = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat  = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::_makeUploadDir($path);
    }

    private static function _isImage($ext): bool
    {
        $img_ext_arr = array('gif', 'jpg', 'jpeg', 'png', 'tiff', 'bmp');
        return in_array($ext, $img_ext_arr);
    }

    private static function _uploadOtherFile($file, $ext)
    {
        $dir = self::_getUploadDir($ext) . '/' . date('Y') . '/' . date('m');
        if (!self::_makeUploadDir($dir)) {

            return false;
        }

        $path = sprintf('%s/%u.%s', $dir, crc32(uniqid()), $ext);
        if (!isset($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $path)) {

            return false;
        }

        return [
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'] ?? filesize($path),
            'type' => $ext,
            'mime' => @Typecho_Common::mimeContentType($path)
        ];
    }

    private static function _uploadImg($file, $ext)
    {
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        $api     = $options->api . 'api/upload';
        $token   = $options->token;
        $tmp     = self::_getUploadFile($file);
        if (empty($tmp)) {

            return false;
        }

        $img = $tmp . '.' . $ext;
        if (!rename($tmp, $img)) {

            return false;
        }

        $res = self::_curlPost($api, ['image' => new CURLFile($img)], $token);
        unlink($img);

        if (!$res) {

            return false;
        }

        $json = json_decode($res, true);
        if ($json['code'] != 200) {  // 上传失败

            return false;
        }

        return [
            'img_id' => $json['data']['id'],
            'name'   => $json['data']['name'],
            'path'   => $json['data']['url'],
            'size'   => $json['data']['size'],
            'type'   => $ext,
            'mime'   => $json['data']['mime']
        ];
    }

    private static function _deleteImg(array $content): bool
    {
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        $api     = $options->api . 'api/delete';
        $token   = $options->token;
        if (empty($token)) {

            return true;
        }

        $id = $content['attachment']->img_id;
        if (empty($id)) {

            return false;
        }

        $res  = self::_curlPost($api, ['id' => $id], $token);
        $json = json_decode($res, true);
        if (!is_array($json)) {

            return false;
        }

        return $json['code'] == 200;
    }

    private static function _curlPost($api, $post, $token)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['token: ' . $token]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.102 Safari/537.36');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }
}