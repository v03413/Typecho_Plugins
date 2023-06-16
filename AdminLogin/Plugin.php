<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Plugin\PluginInterface;
use Typecho\Request;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

/**
 * 后台扫码登录，支持接口：【微信，QQ】
 *
 * @package AdminLogin
 * @author _莫名_
 * @version 0.1.2
 * @link https://qzone.work/codes/266.html
 */
class AdminLogin_Plugin implements PluginInterface
{

    const PLUGIN_NAME = 'AdminLogin';
    const PLUGIN_PATH = __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/AdminLogin/';

    /**
     *
     * @return void
     */
    public static function activate(): void
    {

        /** 判断插件是否可读写 */
        /** 数据保存也可用数据库，但感觉写起来略显复杂，因为懒.所以没写。*/
        $rand     = Typecho\Common::randString(32);
        $filepath = self::PLUGIN_PATH . $rand . '.db';
        @file_put_contents($filepath, $rand);
        if (!file_exists($filepath) || file_get_contents($filepath) != $rand) {
            throw new Typecho\Plugin_Exception('插件无法读写文件，启用失败！');
        }
        @file_put_contents($filepath, self::encrypt(serialize(array()), 'ENCODE', $rand));
        // --------------------------

        Typecho\Plugin::factory('admin/menu.php')->navBar    = array(__class__, 'render');
        Typecho\Plugin::factory('admin/header.php')->header  = array(__class__, 'login');
        Typecho\Plugin::factory('Widget_User')->loginSucceed = array(__class__, 'afterLogin');

        Helper::addRoute('bind', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/bind', 'AdminLogin_Action', 'bind');
        Helper::addRoute('login', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/login', 'AdminLogin_Action', 'login');
        Helper::addRoute('reset', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/reset', 'AdminLogin_Action', 'reset');
        Helper::addRoute('auth-bind', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/auth-bind', 'AdminLogin_Action', 'authbind');
        Helper::addRoute('getqrcode', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/getqrcode', 'AdminLogin_Action', 'getqrcode');
        Helper::addRoute('getresult', __TYPECHO_ADMIN_DIR__ . 'AdminLogin/getresult', 'AdminLogin_Action', 'getresult');
    }

    /**
     * @return void
     */
    public static function deactivate(): void
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
     * @param Form $form
     * @return void
     * @throws \Typecho\Exception
     */
    public static function config(Form $form): void
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
        if (!isset($key)) {
            throw new Typecho\Exception('插件数据损坏，请重新启用插件！');
        }

        $data = AdminLogin_Plugin::getUser();;
        $user = Typecho\Widget::widget('Widget_User');

        $key = new Form\Element\Text('key', null, $key, _t('数据加密密匙：'), _t('<b>插件使用本地文件保存授权数据，此密匙用来加解密数据，同时也是数据的文件名，启用时由系统随机生成，勿强行修改!</b>'));
        $form->addInput($key);

        $type = new Form\Element\Radio('type', array('0' => 'QQ扫码', '1' => '微信扫码'), 0, _t('默认扫码方式：', ''));
        $form->addInput($type);

        $off = new Form\Element\Radio('off', array('0' => '开启', '1' => '关闭'), 0, _t('账户密码登录：', ''));
        $form->addInput($off);

        $users = new Form\Element\Radio('users', array('0' => '否', '1' => '是'), 0, _t('非管理员启用：', ''));
        $form->addInput($users);

        $username = $user->__get('name');
        $wx       = $data[$username]['wx'] ?? '';
        $qq       = $data[$username]['qq'] ?? '';

        echo '<ul class="typecho-option"><li><label class="typecho-label">使用说明：</label><p class="description">本插件可取代后台默认的账户密码登录，无需申请官方接口，管理员账户之间互相独立；登录接口分别是微信网页登录和QQ空间登录，所以在绑定登录时会有相应的提示；此插件对微信或QQ不会有任何影响，如果QQ提示异地登录，那是因为所在服务器使用了登录，同时本插件不会收集任何账户信息，如果不放心，请禁用删除；支持多用户，允许非管理人员使用，会在导航栏显示绑定按钮，同一微信或QQ只能绑定一个账户。<br/><b><font color=red>默认开启账户密码登录，如需关闭，请先确保已经绑定微信或QQ，否则将无法登录后台；如果您真的遇到这种情况，重装插件可以解决！</font></b></p></li></ul><ul class="typecho-option"><li><label class="typecho-label">绑定情况：</label>当前登录用户：' . $username . '&nbsp;&nbsp;微信：<u>' . (empty($wx) ? '暂未绑定' : $wx) . '</u>&nbsp;&nbsp;QQ：<u>' . (empty($qq) ? '暂未绑定' : $qq) . '</u><br/><p class="description">Ps：这里的微信是微信UIN值(微信用户信息识别码)，永久有效且唯一！</p></li><li><a href="' . self::generateUrl('AdminLogin/auth-bind') . '"><button type="submit" class="btn primary">（绑定 || 绑定）账号</button></a></li></ul>';
    }

    /**
     *
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {

    }

    public static function login($header)
    {
        $requestUrl = Request::getInstance()->getRequestUrl();
        if (str_contains($requestUrl, __TYPECHO_ADMIN_DIR__ . 'login.php')) {
            ob_clean();

            require_once self::PLUGIN_PATH . '/views/login.php';

            ob_end_flush();
            exit();
        }

        return $header;
    }

    /**
     * @throws \Typecho\Plugin\Exception
     */
    public static function afterLogin($this_, $name, $password, $temporarily, $expire): void
    {
        $options = self::getOptions();
        if ($options->off === '1') {
            echo 'what are you doing?';
            // 登录之前没有合适的插入点，这里强制退出
            $this_->logout();
        }
    }

    /**
     * @throws \Typecho\Plugin\Exception
     */
    public static function render(): void
    {
        $options = self::getOptions();
        if ($options->users) {
            echo '<a href="' . self::generateUrl('AdminLogin/auth-bind') . '" target="_blank"><span class="message success">' . _t('扫码登录绑定') . '</span></a>';
        }
    }

    /** 获取插件配置
     * @throws \Typecho\Plugin\Exception
     */
    public static function getOptions()
    {
        return Helper::options()->plugin(AdminLogin_Plugin::PLUGIN_NAME);
    }

    /** 获取用户数据 */
    public static function getUser()
    {
        try {
            $options = Helper::options()->plugin(AdminLogin_Plugin::PLUGIN_NAME);
            $key     = $options->key;
        } catch (\Typecho\Plugin\Exception) {
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

        return unserialize(AdminLogin_Plugin::encrypt(file_get_contents($filepath), 'DECODE', $key));
    }

    public static function encrypt($string, $operation = 'DECODE', $key = '', $expiry = 0): string
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

    public static function generateUrl($action): string
    {
        return Typecho\Common::url(__TYPECHO_ADMIN_DIR__ . $action, Helper::options()->index);
    }
}