<?php

use Typecho\Request;

/**
 * 后台扫码登录，支持接口：【微信，QQ】
 *
 * @package AdminLogin
 * @author _莫名_
 * @version 0.1.1
 * @link https://qzone.work/codes/266.html
 */
class AdminLogin_Plugin implements Typecho_Plugin_Interface
{

    const PLUGIN_NAME = 'AdminLogin';
    const PLUGIN_PATH = __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/AdminLogin/';

    /**
     * 启用插件方法,如果启用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Exception
     */
    public static function activate()
    {

        /** 判断插件是否可读写 */
        /** 数据保存也可用数据库，但感觉写起来略显复杂，因为懒.所以没写。*/
        $randstr  = Typecho_Common::randString(32);
        $filepath = self::PLUGIN_PATH . $randstr . '.db';
        @file_put_contents($filepath, $randstr);
        if (!file_exists($filepath) || file_get_contents($filepath) != $randstr) {
            throw new Typecho_Plugin_Exception('插件无法读写文件，启用失败！');
        }
        @file_put_contents($filepath, self::authcode(serialize(array()), 'ENCODE', $randstr));
        // --------------------------

        Typecho_Plugin::factory('admin/menu.php')->navBar    = array(__class__, 'render');
        Typecho_Plugin::factory('admin/header.php')->header  = array(__class__, 'login');
        Typecho_Plugin::factory('Widget_User')->loginSucceed = array(__class__, 'afterlogin');

        Helper::addRoute('bind', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/bind', 'AdminLogin_Action', 'bind');
        Helper::addRoute('login', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/login', 'AdminLogin_Action', 'login');
        Helper::addRoute('reset', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/reset', 'AdminLogin_Action', 'reset');
        Helper::addRoute('auth-bind', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/auth-bind', 'AdminLogin_Action', 'authbind');
        Helper::addRoute('getqrcode', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/getqrcode', 'AdminLogin_Action', 'getqrcode');
        Helper::addRoute('getresult', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/getresult', 'AdminLogin_Action', 'getresult');

    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Exception
     */
    public static function deactivate()
    {
        /** 取出数据文件路径 */
        $dirs = scandir(self::PLUGIN_PATH);
        foreach ($dirs as $dir) {
            $path = self::PLUGIN_PATH . $dir;
            if (is_file($path) && $arr = explode('.', $dir)) {
                if ($arr['1'] == 'db' && mb_strlen($arr[0]) == 32) {
                    $filename = $path;
                }
            }
        }
        @unlink($filename);
    }

    /**
     * 获取插件配置面板
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {

        /** 取出数据文件及密匙 */
        $dirs = scandir(self::PLUGIN_PATH);
        foreach ($dirs as $dir) {
            $path = self::PLUGIN_PATH . $dir;
            if (is_file($path) && $arr = explode('.', $dir)) {
                if ($arr['1'] == 'db' && mb_strlen($arr[0]) == 32) {
                    $key      = $arr['0'];
                    $datafile = $path;
                    break;
                }
            }
        }
        if (!isset($key))
            throw new Typecho_Plugin_Exception('插件数据损坏，请重新启用插件！');
        $data = AdminLogin_Plugin::getuser();;

        $user = Typecho_Widget::widget('Widget_User');

        $key = new Typecho_Widget_Helper_Form_Element_Text('key', null, $key, _t('数据加密密匙：'), _t('<b>插件使用本地文件保存授权数据，此密匙用来加解密数据，同时也是数据的文件名，启用时由系统随机生成，勿强行修改!</b>'));
        $form->addInput($key);

        $type = new Typecho_Widget_Helper_Form_Element_Radio('type', array('0' => 'QQ扫码', '1' => '微信扫码'), 0, _t('默认扫码方式：', ''));
        $form->addInput($type);

        $off = new Typecho_Widget_Helper_Form_Element_Radio('off', array('0' => '开启', '1' => '关闭'), 0, _t('账户密码登录：', ''));
        $form->addInput($off);

        $users = new Typecho_Widget_Helper_Form_Element_Radio('users', array('0' => '否', '1' => '是'), 0, _t('非管理员启用：', ''));
        $form->addInput($users);

        $username = $user->__get('name');

        $wx = $data[$username]['wx'];
        $qq = $data[$username]['qq'];

        echo '<ul class="typecho-option"><li><label class="typecho-label">使用说明：</label><p class="description">本插件可取代后台默认的账户密码登录，无需申请官方接口，管理员账户之间互相独立；登录接口分别是微信网页登录和QQ空间登录，所以在绑定登录时会有相应的提示；此插件对微信或QQ不会有任何影响，如果QQ提示异地登录，那是因为所在服务器使用了登录，同时本插件不会收集任何账户信息，如果不放心，请禁用删除；支持多用户，允许非管理人员使用，会在导航栏显示绑定按钮，同一微信或QQ只能绑定一个账户。<br/><b><font color=red>默认开启账户密码登录，如需关闭，请先确保已经绑定微信或QQ，否则将无法登录后台；如果您真的遇到这种情况，重装插件可以解决！</font></b></p></li></ul><ul class="typecho-option"><li><label class="typecho-label">绑定情况：</label>当前登录用户：' . $username . '&nbsp;&nbsp;微信：<u>' . (empty($wx) ? '暂未绑定' : $wx) . '</u>&nbsp;&nbsp;QQ：<u>' . (empty($qq) ? '暂未绑定' : $qq) . '</u><br/><p class="description">Ps：这里的微信是微信UIN值(微信用户信息识别码)，永久有效且唯一！</p></li><li><a href="' . self::tourl('AdminLogin/auth-bind') . '"><button type="submit" class="btn primary">（绑定 || 绑定）账号</button></a></li></ul>';

    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {

    }

    public static function afterlogin($this_, $name, $password, $temporarily, $expire)
    {

        $options = self::getoptions();
        if ($options->off === '1') {
            echo 'what are you doing?';
            // 登录之前没有合适的插入点，这里强制退出
            $this_->logout();
        }
    }

    public static function login($header)
    {
        $baseurl = Request::getInstance()->getRequestUrl();
        /** 判断是否登录 */
        if ($baseurl == __TYPECHO_ADMIN_DIR__ . 'login.php') {
            /** 清空输出缓存区 */
            ob_clean();

            require_once self::PLUGIN_PATH . 'views/login.php';

            ob_end_flush();
            exit();
        } else {
            return $header;
        }
    }

    public static function render()
    {
        $options = self::getoptions();
        if ($options->users) {
            echo '<a href="' . self::tourl('AdminLogin/auth-bind') . '" target="_blank"><span class="message success">' . _t('扫码登录绑定') . '</span></a>';
        }
    }

    /** 生成URL，解决部分博客未开启伪静态，仅对本插件有效 */
    public static function tourl($action)
    {
        return Typecho_Common::url(__TYPECHO_ADMIN_DIR__ . $action, Helper::options()->index);
    }

    /** 获取插件配置 */
    public static function getoptions()
    {
        return Helper::options()->plugin(AdminLogin_Plugin::PLUGIN_NAME);
    }

    /** 获取用户数据 */
    public static function getuser()
    {
        try {
            $options = Helper::options()->plugin(AdminLogin_Plugin::PLUGIN_NAME);
            $key     = $options->key;
        } catch (Typecho_Plugin_Exception $e) {
            $dirs = scandir(self::PLUGIN_PATH);
            foreach ($dirs as $dir) {
                $path = self::PLUGIN_PATH . $dir;
                if (is_file($path) && $arr = explode('.', $dir)) {
                    if ($arr['1'] == 'db' && mb_strlen($arr[0]) == 32) {
                        $key      = $arr['0'];
                        $datafile = $path;
                        break;
                    }
                }
            }
        }
        $filepath = self::PLUGIN_PATH . $key . '.db';
        $user     = unserialize(AdminLogin_Plugin::authcode(file_get_contents($filepath), 'DECODE', $key));
        return $user;
    }

    /** 加密函数 */
    public static function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)
    {
        $ckey_length   = 4;
        $key           = md5($key ? $key : ENCRYPT_KEY);
        $keya          = md5(substr($key, 0, 16));
        $keyb          = md5(substr($key, 16, 16));
        $keyc          = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
        $cryptkey      = $keya . md5($keya . $keyc);
        $key_length    = strlen($cryptkey);
        $string        = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;
        $string_length = strlen($string);
        $result        = '';
        $box           = range(0, 255);
        $rndkey        = array();
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result  .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }
        if ($operation == 'DECODE') {
            if ((substr($result, 0, 10) == 0 || substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26) . $keyb), 0, 16)) {
                return substr($result, 26);
            } else {
                return '';
            }
        } else {
            return $keyc . str_replace('=', '', base64_encode($result));
        }
    }
}