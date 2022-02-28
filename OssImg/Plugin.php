<?php
/**
 *  阿里云OSS 图床外链，基于插件(OssForTypecho)制作
 *
 * @package OssImg
 * @author _莫名_
 * @version 1.0.0
 * @link https://qzone.work/resources/255.html
 * @dependence 1.0-*
 * @date 2019-05-18
 *
 */

class OssImg_Plugin implements Typecho_Plugin_Interface
{
    const UPLOAD_DIR  = '/usr/uploads'; //上传文件目录路径
    const PLUGIN_NAME = 'OssImg'; //插件名称

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
        $desc = new Typecho_Widget_Helper_Form_Element_Text('desc', NULL, '', _t('插件使用说明：'), _t('<ol><li>基于插件(OssForTypecho)二次开发。<br></li><li>在阿里云 <a target="_blank" href="https://ak-console.aliyun.com/#/accesskey">AccessKey管理控制台</a> 页面里获取AccessKeyID与AccessKeySecret。<br></li><li>插件不会验证配置的正确性，请自行确认配置信息正确，否则不能正常使用。<br></li><b><li>本插件不会替换之前已上传图片的链接，已存在不受影响！<br></li><li>修复原插件(OssForTypecho)文件无法删除的问题。<br></li><li>必须保证Bucket拥有公共读权限，否则图床外链无效。<br></li></b></ol>'));
        $form->addInput($desc);

        $acid = new Typecho_Widget_Helper_Form_Element_Text('acid', NULL, '', _t('AccessKeyId：'));
        $form->addInput($acid->addRule('required', _t('AccessId不能为空！')));

        $ackey = new Typecho_Widget_Helper_Form_Element_Text('ackey', NULL, '', _t('AccessKeySecret：'));
        $form->addInput($ackey->addRule('required', _t('AccessKey不能为空！')));

        $EndPoint = new  Typecho_Widget_Helper_Form_Element_Text('EndPoint', NULL, NULL, 'EndPoint(地域节点)：', _t('例如：oss-cn-shanghai.aliyuncs.com（需加上前面的 http:// 或 https://）'));
        $form->addInput($EndPoint->addRule('required', _t('EndPoint不能为空！')));

        $bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', NULL, '', _t('Bucket名称：'));
        $form->addInput($bucket->addRule('required', _t('Bucket名称不能为空！')));

        $domain = new Typecho_Widget_Helper_Form_Element_Text('domain', NULL, '', _t('Bucket域名：'), _t('可使用自定义域名（留空则使用默认域名）<br>例如：http://oss.example.com（需加上前面的 http:// 或 https://）'));
        $form->addInput($domain);

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
        $ext = self::getSafeName($file['name']);
        //判定是否是允许的文件类型
        if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            return false;
        }
        // 获取保存路径
        $date    = new Typecho_Date($options->gmtTime);
        $fileDir = self::getUploadDir($ext) . '/' . $date->year . '/' . $date->month;

        // 判断是否是图片
        if (self::isImage($ext)) {
            //获取设置参数
            $options = Helper::options()->plugin(self::PLUGIN_NAME);
            //获得上传文件
            $fileName   = sprintf('%u', crc32(uniqid())) . '.' . $ext;
            $path       = $fileDir . '/' . $fileName;
            $uploadfile = self::getUploadFile($file);
            //如果没有临时文件，则退出
            if (!isset($uploadfile)) {
                return false;
            }
            /* 上传到OSS */
            //初始化OSS
            $ossClient = self::OssInit();
            try {
                $result = $ossClient->uploadFile($options->bucket, substr($path, 1), $uploadfile);
            } catch (Exception $e) {
                return false;
            }
            if (!isset($file['size'])) {
                $fileInfo     = $result['info'];
                $file['size'] = $fileInfo['size_upload'];
            }
        } else {
            //创建上传目录
            if (!is_dir($fileDir)) {
                if (!self::makeUploadDir($fileDir)) {
                    return false;
                }
            }
            //获取文件名
            $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
            $path     = $fileDir . '/' . $fileName;
            if (isset($file['tmp_name'])) {
                //移动上传文件
                if (!@move_uploaded_file($file['tmp_name'], $path)) {
                    return false;
                }
            } elseif (isset($file['bytes'])) {
                //直接写入文件
                if (!file_put_contents($path, $file['bytes'])) {
                    return false;
                }
            } else {
                return false;
            }
            if (!isset($file['size'])) {
                $file['size'] = filesize($path);
            }
        }
        //返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @Typecho_Common::mimeContentType($path)
        );
    }

    public static function deleteHandle(array $content)
    {
        //获取设置参数
        $options = Typecho_Widget::widget('Widget_Options')->plugin(self::PLUGIN_NAME);
        //初始化COS
        $bucket    = 'wwwroot-site-public';
        $ossClient = self::OssInit();
        $ext       = self::getSafeName($content['title']);
        // 判断是否为图片
        if (self::isImage($ext)) {
            try {
                $ossClient->deleteObject($options->bucket, mb_substr($content['attachment']->path, 1));
            } catch (Exception $e) {
                return false;
            }
        } else { //本地删除
            @unlink($content['attachment']->path);
        }
        return true;
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        //获取扩展名
        $ext = self::getSafeName($file['name']);
        //判定是否是允许的文件类型
        if ($content['attachment']->type != $ext || Typecho_Common::isAppEngine()) {
            return false;
        }
        //获取设置参数
        $options = Typecho_Widget::widget('Widget_Options')->plugin(self::PLUGIN_NAME);
        //获取文件路径
        $path = $content['attachment']->path;
        //获得上传文件
        $uploadfile = self::getUploadFile($file);
        //如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }
        if (self::isImage($ext)) {
            /* 上传到OSS */
            /* 初始化OSS */
            $ossClient = self::OssInit();
            try {
                $result = $ossClient->uploadFile($options->bucket, substr($path, 1), $uploadfile);
            } catch (Exception $e) {
                return false;
            }
            if (!isset($file['size'])) {
                $fileInfo     = $result['info'];
                $file['size'] = $fileInfo['size_upload'];
            }
        } else {
            //创建上传目录
            if (!is_dir($dir)) {
                if (!self::makeUploadDir($dir)) {
                    return false;
                }
            }
            if (isset($file['tmp_name'])) {
                @unlink($path);
                //移动上传文件
                if (!@move_uploaded_file($file['tmp_name'], $path)) {
                    return false;
                }
            } elseif (isset($file['bytes'])) {
                @unlink($path);
                //直接写入文件
                if (!file_put_contents($path, $file['bytes'])) {
                    return false;
                }
            } else {
                return false;
            }
            if (!isset($file['size'])) {
                $file['size'] = filesize($path);
            }
        }


        //返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $content['attachment']->path,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    public static function OssInit()
    {
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        require_once 'aliyun-oss-php-sdk-2.3.0.phar';
        return new OSS\OssClient($options->acid, $options->ackey, $options->EndPoint);
    }

    public static function attachmentHandle(array $content)
    {
        $arr     = unserialize($content['text']);
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        //获取扩展名
        $ext   = self::getSafeName($content['title']);
        $host_ = str_replace('.', '_', parse_url(Helper::options()->siteUrl)['host']);
        // 判断是否为图片，是否上传到OSS
        if (self::isImage($ext) && stripos($arr['path'], $host_) !== false) {
            return $options->domain . $arr['path'];
        } else {
            $ret = explode(self::UPLOAD_DIR, $arr['path']);
            return Typecho_Common::url(self::UPLOAD_DIR . $ret[1], Helper::options()->siteUrl);
        }
    }

    private static function getUploadDir($ext = '')
    {
        if (self::isImage($ext)) {
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

    private static function getUploadFile($file)
    {
        return isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bytes']) ? $file['bytes'] : (isset($file['bits']) ? $file['bits'] : ''));
    }

    private static function getDomain()
    {
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        $domain  = $options->domain;
        if (empty($domain)) $domain = 'https://' . $options->bucket . '.' . $options->region . '.aliyuncs.com';
        return $domain;
    }

    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function makeUploadDir($path)
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

        return self::makeUploadDir($path);
    }

    private static function isImage($ext)
    {
        $img_ext_arr = array('gif', 'jpg', 'jpeg', 'png', 'tiff', 'bmp');
        return in_array($ext, $img_ext_arr);
    }
}