<?php
/**
 * 后台下单页面，前端发起授权跳转，微信回调到本页面，
 * 本页面获取到openid并且根据传递的参数进行下单，直接echo到页面
 */
include 'api_v2.php';
include 'WX.php';
$API=new api_v2();//api接口类
//输出错误信息并退出脚本
function echoExit($str){
	echo '<h1>'.$str.'</h1>';
	exit;
}

function https_request($url){
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($curl);
    if(curl_errno($curl)){
        return 'ERROR'.curl_error($curl);
    }
    curl_close($curl);
    return $data;
}

function getOpenid($code,$appid,$appsecret){
    $access_token = "";
    // 根据code获取access_token
    $access_token_url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid=$appid&secret=$appsecret&code=$code&grant_type=authorization_code";
    $access_token_json = https_request($access_token_url);
    $access_token_array = json_decode($access_token_json, true);
    $access_token = $access_token_array['access_token'];
    if(!$access_token){
        echo 'code不正确<br>';
        echo $_SERVER['QUERY_STRING'];
        exit;
    }

    return $openid = $access_token_array['openid'];
}


function checkPSW(){
    global $opt,$API;
    if($_GET['order_type']!=3)return;//提现才需要校验密码

    $uid=$_GET['uid'];
    $psw=$_GET['psw'];
    $type=$_GET['uidType'];

    if($type==1){//公帐需要校验管理员密码
        $cust=$API->start(array(
            'method'=>'wicare.customer.get',
            'objectId'=>$uid,
            'fields'=>'objectId,uid'
        ),$opt);
        if(!$cust||!$cust['data']){
            echoExit('公司账号获取失败：'.json_encode($cust));
        }
        $uid=$cust['data']['uid'];
    }
    $user=$API->start(array(
        'method'=>'wicare.user.get',
        'objectId'=>$uid,
        'password'=>$psw,
        'fields'=>'objectId'
    ),$opt);
    if(!$user&&!$user['data'])//校验失败，退出脚本
        echoExit('密码错误');
}

if(isset($_GET['code'])){
    //根据域名获取公众号信息
    // $_host=$_SERVER['HTTP_HOST'];//当前域名
    // if(isset($_GET['wx_app_id'])){
    //     $appRes=$API->start(array(
    //         'wxAppKey' => $_GET['wx_app_id'],
    //         'method'=>'wicare.weixin.get',
    //         'fields'=>'wxAppKey,wxAppSecret'
    //     ),$opt);
    // }else{
    //     //用于获取app数据
    //     $appData=array(
    //         'domainName' => $_host,
    //         'method'=>'wicare.app.get',
    //         'fields'=>'devId,name,logo,version,appKey,appSecret,sid,wxAppKey,wxAppSecret'
    //     );
    //     //获取app数据
    //     $appRes=$API->start($appData);
    // }
    
    // if(!$appRes||!$appRes['data']){
    //     echo '微信appKey配置不对，无法下单';
    //     exit;
    // }
    // $appData=$appRes['data'];

    //智联车网
    // $appData=array(
    //     'wxAppKey'=>'wx76f1169cbd4339c1',
    //     'wxAppSecret'=>'5485870628e8add2a858a873fbaf4fe5'
    // );

    //微车联
    $appData=WX::payAppData();

    $code = $_GET['code'];
    $openid = getOpenid($code,$appData['wxAppKey'],$appData['wxAppSecret']);

    checkPSW();//校验密码
    $uid=$_GET['uid'];
    //下单
    $order=$API->start(array(
        'method'=>'wicare.pay.weixin',
        'open_id'=>$openid,
        'uid'=>$uid,
        'order_type'=>$_GET['order_type'],
        'remark'=>$_GET['remark'],
        'attach'=>$_GET['attach'],
        'amount'=>$_GET['amount']
    ),$opt);
    
    if($_GET['order_type']==3){
        echoExit('提现成功，可能需要一段时间到账，请等待微信零钱包通知');
    }
    if($order['status_code']||!$order['pay_args']){
        echoExit('下单失败，'.json_encode($order));
    }
    $pay_args=json_encode($order['pay_args']);
}else{
    echoExit('No code');
}
?>

<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="viewport" content="initial-scale=1.0, user-scalable=no">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<title>确认支付</title>
		<style>
			body{font-family: "微软雅黑";margin: 0;padding: 0;}
			.nav{width: 100%; background-color:whitesmoke;border-bottom: 1px solid #ddd;position: fixed;}
			.fl{float: left;}
			.back{width: 10px;height: 10px;margin:16px 0 0 11px;border-bottom: 2px solid #3F3F3F;border-left: 2px solid #3F3F3F;transform:rotate(45deg);-webkit-transform:rotate(45deg);position: absolute;}
			.mui-title{text-align: center;height: 25px;padding: 10px 0;overflow: hidden;width: 100%;}
			.mui-content{padding-top: 45px;width: 100%;text-align: center;}
			.main{top: 50%;font-size: 13px;color:#ccc;}
			#money{font-size: 36px;font-weight: 500;color:#323232 ;margin-bottom: 0;margin-top: 14px;}
			#money:before{content: "￥";}
			#decimal:before{content: ".";}
			.bottom{bottom: 10px;width: 100%;text-align: center;margin-top: 5em;}
			.bottom button{padding: 0;border: none;width: 80%;border-radius: 5px;background-color: #3EB447;color: white;font-size: 18px;line-height: 46px}
			.bottom button:active{background: #329239;}
		</style>
	</head>
	<script>
    function echo(str){
		if(!str)str="";
		document.write(str);
	}
    var isWeixin=navigator.userAgent.indexOf('MicroMessenger') > -1;
    var oid="<?php echo $order['oid']; ?>";
    var _g={};
    var search=location.search.split("?")[1].split("&");
    for(var i=0;i<search.length;i++){
        _g[search[i].split("=")[0]]=decodeURIComponent(search[i].split("=")[1]);
    }
    
	var order = {
        title:_g.title||'订单支付',
        price:parseFloat(_g.amount).toFixed(2),
        detail:_g.remark||''
    };
    order.price_int=order.price.split(".")[0];
    order.price_decimal=order.price.split(".")[1];
	</script>
	<body>
		<div class="mui-content">
			<img src="./autogps/img/icon_activation.png" style="width:120px;margin-top: 2em;max-width: 25%;">
			<div style="color: #6D6D6D;margin-top: 10px;"><script>echo(order.title)</script></div>	
			<div class="main">
				<p id="money"><script>echo(order.price_int)</script><span style="font-size: 26px;" id="decimal"><script>echo(order.price_decimal)</script></span></p>
				<p style="margin-top: 0;"><script>echo(order.detail)</script></p>
			</div>
		</div>		
		<div class="bottom">
			<button id="pay">确认支付</button>
		</div>
	</body>
	<script>
	function weixinCallback(res) {//微信支付返回
        res.orderId=oid;
		localStorage.setItem(_g.key,JSON.stringify(res));
		if (res.err_msg == "get_brand_wcpay_request:ok") {
            if(_g.callback)
                location=_g.callback;
            else
                history.back(-2);//跳回授权之前的页
		} else if(res.err_msg == "get_brand_wcpay_request:cancel"){
			pay.canPay=false;
			document.getElementById("pay").innerText="确认支付";
		}else{
			alert("支付失败；订单信息:"+JSON.stringify(res));
		}
	}
	
	function weixinPay(){//微信调起支付
		WeixinJSBridge.invoke('getBrandWCPayRequest',<?php echo $pay_args; ?>,weixinCallback);
	}
	
	function pay() {
		this.innerText="支付中……";
		if(pay.canPay)
			return;
		pay.canPay=true;
		if (isWeixin) {//微信下调用微信支付
			if (typeof WeixinJSBridge == "undefined"){
			    document.addEventListener('WeixinJSBridgeReady', weixinPay, false);
			}else{
			   weixinPay();
			}
		}
	}
	
	window.onload = function() {
		var payBtn = document.getElementById('pay');
		payBtn.addEventListener("touchstart",function (){
            this.startX=event.changedTouches[0].clientX;
        });
		payBtn.addEventListener("touchend",function (){
		    var x=event.changedTouches[0].clientX;
            if(Math.abs(x-this.startX)<10)
                pay.call(this);
        });
	}
	</script>
</html>

