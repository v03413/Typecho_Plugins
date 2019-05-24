<?php
require_once __TYPECHO_ROOT_DIR__.__TYPECHO_ADMIN_DIR__.'common.php';
// 获取当前用户名
$name = $user->__get('name');
$data = AdminLogin_Plugin::getuser();
$option = AdminLogin_Plugin::getoptions();
$group = $user->__get('group');

$wx = empty($data[$name]['wx'])?'未绑定':$data[$name]['wx'];
$qq = empty($data[$name]['qq'])?'未绑定':$data[$name]['qq'];

if($group != 'administrator' && !$option->users){ //非管理员且[非管理员启用]处于否
	throw new Typecho_Widget_Exception(_t('禁止访问'), 403);
}
?>
<!DOCTYPE HTML>
<html class="no-js">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="renderer" content="webkit">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>AdminLogin - 扫描登录授权绑定</title>
        <meta name="robots" content="noindex, nofollow">
        <link rel="stylesheet" href="/admin/css/normalize.css?v=17.10.30">
		<link rel="stylesheet" href="/admin/css/grid.css?v=17.10.30">
		<link rel="stylesheet" href="/admin/css/style.css?v=17.10.30">
		<!--[if lt IE 9]>
		<script src="/admin/js/html5shiv.js?v=17.10.30"></script>
		<script src="/admin/js/respond.js?v=17.10.30"></script>
		<![endif]-->    
</head>
    <body class="body-100">
    <!--[if lt IE 9]>
        <div class="message error browsehappy" role="dialog">当前网页 <strong>不支持</strong> 你正在使用的浏览器. 为了正常的访问, 请 <a href="http://browsehappy.com/">升级你的浏览器</a>.</div>
    <![endif]-->
	<div class="typecho-login-wrap">
    <div class="typecho-login">
        <h1><a href="#" class="i-logo">Typecho</a></h1>
		<div class="qrlogin">
			<h3>用户授权：<?=$name?></h3>
			<p>微信：<?=$wx?> &nbsp;&nbsp;QQ：<?=$qq?></p>
			<div id="qrimg" style=""></div>
			<p id='msg'>请使用微信扫描...</p><hr/>
			<button type="submit" class="btn primary" id="wx_auth" onclick="getqrocde('wx')">切换微信绑定</button>
			<button type="submit" class="btn primary" id="qq_auth" onclick="getqrocde('qq')">切换 QQ 绑定</button>
			<button type="submit" class="btn primary" onclick="reset()">重置绑定数据</button>
		</div>
        <p class="more-link"> <a href="/">返回首页</a> </p>
    </div>
</div>
<script src="/admin/js/jquery.js?v=17.10.30"></script>
<script src="/admin/js/jquery-ui.js?v=17.10.30"></script>
<script src="/admin/js/typecho.js?v=17.10.30"></script>
<script>
	var data = {};
	function bind(type,uin){
		var api = "<?= AdminLogin_Plugin::tourl('AdminLogin/bind');?>";
		$.ajax({
			url: api,
			type: 'POST',
			data: 'type=' + type+'&uin='+uin,
			dataType: 'json',
			success: function (data) {
				alert(data.msg);
				window.location.reload();
			},
			error: function () {
				alert('绑定失败！~~');
			}
		});
	}
	function getqrocde(type) {
		var api = "<?= AdminLogin_Plugin::tourl('AdminLogin/getqrcode');?>";
		$.ajax({
			url: api,
			type: 'POST',
			data: 'type=' + type,
			dataType: 'json',
			success: function (data) {
				window.data = data;
				if (type == 'qq') {
					$('#qrimg').html('<img style="max-width:147px;max-height:147px;" src="data:image/png;base64,' + data.data + '" >');
				} else {
					var text = "https://login.weixin.qq.com/l/" + data['data'];
					$('#qrimg').html('<img style="max-width:147px;max-height:147px;" src="//api.qzone.work/api/qr.encode?text=' + encodeURIComponent(text) + '">');
				}
				// 开始循环请求结果
				if(window.id){
					window.clearInterval(window.id);
				}
				window.id = setInterval(getresult, 3000);
			},
			error: function () {
				alert('二维码获取失败！');
			}
		});
		if (type == 'qq') {
			window.type = 'qq';
			$("#wx_auth").show();
			$("#qq_auth").hide();
		} else {
			window.type = 'wx';
			$("#qq_auth").show();
			$("#wx_auth").hide();
		}
	}
	function reset(){
		var api = "<?= AdminLogin_Plugin::tourl('AdminLogin/reset');?>";
		$.ajax({
			url: api,
			aycnc: false,
			type: 'POST',
			dataType: 'json',
			success: function (data) {
				if(data.code == 200){
					alert(data.msg);
				}
				$("#msg").html(data.msg);
				window.location.reload();
			},
			error: function () {
				console.log('falil!~~');
			}
		});
	}
	function getresult() {
		var api = "<?= AdminLogin_Plugin::tourl('AdminLogin/getresult');?>";
		if (window.type == 'wx') {
			post = 'uuid=' + data['data'];
		} else {
			post = 'qrsig=' + data['qrsig'];
		}
		$.ajax({
			url: api,
			aycnc: false,
			type: 'POST',
			data: post,
			dataType: 'json',
			success: function (data) {
				if(data.code == 200){
					bind(window.type,data.data.uin);
					window.clearInterval(window.id);
				}
				$("#msg").html(data.msg);
			},
			error: function () {
				console.log('登录结果获取失败！');
			}
		});
	}
	$(document).ready(function () {
		// 默认微信
		window.type = "wx";
		window.nums = 0;
		$("#wx_auth").hide();
		// 获取微信登录二维码
		getqrocde(type);
	});

</script>
    </body>
</html>
