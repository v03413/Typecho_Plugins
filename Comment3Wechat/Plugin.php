<?php

use Typecho\Plugin;
use Typecho\Widget;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Plugin\PluginInterface;

/**
 * [莫名博客] 微信推送评论通知
 *
 * @package Comment3Wechat
 * @author 莫名博客
 * @version 1.0.0
 * @link https://qzone.work/
 */
class Comment3Wechat_Plugin implements PluginInterface
{
    public const NAME = 'Comment3Wechat';

    /**
     * @return string
     */
    public static function activate(): string
    {
        Plugin::factory('Widget_Feedback')->comment   = [__CLASS__, 'push'];
        Plugin::factory('Widget_Feedback')->trackback = [__CLASS__, 'push'];
        Plugin::factory('Widget_XmlRpc')->pingback    = [__CLASS__, 'push'];

        return _t('请配置此插件的 TOKEN, 以使您的微信推送生效');
    }

    /**
     * @return void
     */
    public static function deactivate()
    {
    }

    /**
     * @param Form $form
     * @return void
     */
    public static function config(Form $form): void
    {
        $key = new Text('token', NULL, NULL, _t('TOKEN'), _t('Token 需要在 <a href="https://www.pushplus.plus/">PushPlus</a> 获取<br />'));
        $form->addInput($key->addRule('required', _t('您必须填写一个正确的 Token')));
    }

    /**
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 推送消息
     * @param $comment
     * @param $post
     * @return mixed
     */
    public static function push($comment, $post): mixed
    {
        $api     = 'https://www.pushplus.plus/send';
        $options = Widget::widget('Widget_Options');
        $token   = $options->plugin(self::NAME)->token;
        $content = "**" . $comment['author'] . "** 在 [「" . $post->title . "」](" . $post->permalink . " \"" . $post->title . "\") 中说到: \n\n > " . $comment['text'];
        $data    = [
            'token'    => $token,
            'title'    => '【莫名博客】有新评论啦',
            'content'  => $content,
            'template' => 'markdown',
        ];
        $context = [
            'http' => [
                'timeout' => 5,
                'method'  => 'POST',
                'header'  => 'Content-type: application/json',
                'content' => json_encode($data)
            ]
        ];

        file_get_contents($api, false, stream_context_create($context));

        return $comment;
    }
}
