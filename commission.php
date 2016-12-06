<?php
/**
 * 后台下单页面，前端发起授权跳转，微信回调到本页面，
 * 本页面获取到openid并且根据传递的参数进行下单，直接echo到页面
 */
include 'api_v2.php';
include 'checkAndPay.php';
$API=new api_v2();//api接口类
session_start();

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

//输出错误信息并退出脚本
function echoExit($str){
	echo '<h1>'.$str.'</h1>';
	exit;
}

function getOpenid($code,$appid,$appsecret){
    $access_token = "";
    // 根据code获取access_token
    $access_token_url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid=$appid&secret=$appsecret&code=$code&grant_type=authorization_code";
    $access_token_json = https_request($access_token_url);
    $access_token_array = json_decode($access_token_json, true);
    $access_token = $access_token_array['access_token'];
    if(!$access_token){
        echoExit('code不正确');
    }

    return $openid = $access_token_array['openid'];
}

function getOrder(){
    global $opt,$API;
    $order=$API->start(array(
        'method'=>'wicare.order.get',
        'oid'=>$_GET['oid'],
        'fields'=>'uid,to_uid,attach,amount'
    ),$opt);
    if($order&&$order['data'])
        return $order['data'];
    else
        echoExit('没有找到原订单');
}

function getToUid($order){
    $str=$order['attach'];
    $i=strrchr($str,',');
    return substr($str,$i);
}

function getBookingId($order){
    $str=$order['attach'];
    $i=strpos($str,',');
    return substr($str,0,$i);
}

function getBooking($id){
    global $opt,$API;
    $order=$API->start(array(
        'method'=>'wicare.booking.get',
        'objectId'=>$id,
        'fields'=>'sellerId,userName,userMobile'
    ),$opt);
    if($order&&$order['data'])
        return $order['data'];
    else
        echoExit('没有找到原订单');
}

if(isset($_POST['oid'])){//支付成功之后
    if($_POST['oid']!=$_SESSION['oid']){//被篡改
        echoExit('支付成功，但oid不正确');
    }     
    $cid=$_SESSION['cid'];
    //获取配置的营销号
    $wei=pfb::getWeixin($cid);
    if(!$wei){
        echoExit('支付成功，但营销号未正确配置，无法推送余额信息');
    }        
    $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
    $cust=pfb::getCustomer($cid);//customer表记录
    $pay_user=pfb::getPayUser($cust);//商户公帐
    $user=pfb::getUser($cust['uid']);//管理员user表记录
    $cust_openId=pfb::getOpenId($user);//商户管理员的openId

    $bookingId=$_SESSION['bookingId'];
    $booking=getBooking($bookingId);
    $emp=pfb::getEmployee($booking['sellerId']);
    if(!$emp){//错误
        echoExit('支付成功，但获取人员推送失败，请截图联系技术人员，bookingId='.$bookingId);
    }
    $e_user=pfb::getUser($emp['uid']);
    $remark=$booking['userName'].'/'.$booking['userMobile'].'注册佣金';

    pfb::commissionSuccess($wx,$wei,$pay_user,$e_user,$_SESSION['commission'],$remark,$bookingId,$_SESSION['receipt']);
    echoExit('支付成功，请关闭本页面');
}else if(isset($_GET['code'])){
    //根据域名获取公众号信息
    $_host=$_SERVER['HTTP_HOST'];//当前域名
    //用于获取app数据
    $appData=array(
        'domainName' => $_host,
        'method'=>'wicare.app.get',
        'fields'=>'devId,name,logo,version,appKey,appSecret,sid,wxAppKey,wxAppSecret'
    );
    //获取app数据
    $appRes=$API->start($appData);
    
    if(!$appRes||!$appRes['data']){
        echoExit('微信appKey配置不对，无法下单');
    }
    $appData=$appRes['data'];

    $code = $_GET['code'];
    $openid = getOpenid($code,$appData['wxAppKey'],$appData['wxAppSecret']);
    $o_order=getOrder();
    if($o_order['flag']==1)
        echoExit('佣金已支付');
    //下单
    $to_uid=getToUid($o_order);
    if(!$to_uid)
        echoExit('无佣金收取方');
    $order=$API->start(array(
        'method'=>'wicare.pay.weixin',
        'open_id'=>$openid,
        'uid'=>$o_order['uid'],
        'to_uid'=>$to_uid,
        'order_type'=>1,
        'remark'=>'微信支付佣金',
        'attach'=>$o_order['attach'],
        'amount'=>$o_order['amount']
    ),$opt);
    if($order['status_code']||!$order['pay_args']){
        echoExit('下单失败，'.json_encode($order));
    }
    $oid=$order['objectId'];
    $_SESSION['oid']=$order['objectId'];//新的oid
    $_SESSION['commission']=$o_order['amount'];//佣金
    $_SESSION['bookingId']=getBookingId($o_order);//预订id
    $_SESSION['receipt']=$_GET['receipt'];//预付款到帐金额
    $_SESSION['cid']=$_GET['cid'];//公司id
    
    $pay_args=json_encode($order['pay_args']);
}else {
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
			<img src="./img/icon_activation.png" style="width:120px;margin-top: 2em;max-width: 25%;">
			<div style="color: #6D6D6D;margin-top: 10px;"><script>echo(order.title)</script></div>	
			<div class="main">
				<p id="money"><script>echo(order.price_int)</script><span style="font-size: 26px;" id="decimal"><script>echo(order.price_decimal)</script></span></p>
				<p style="margin-top: 0;"><script>echo(order.detail)</script></p>
			</div>
		</div>		
		<div class="bottom">
			<button id="pay">确认支付</button>
		</div>
        <from id="f" action="#" method="post">
            <input type="hidden" name="oid" value="<?php echo $oid; ?>"> 
        </from>
	</body>
	<script>
	function weixinCallback(res) {//微信支付返回
        res.orderId=oid;
		localStorage.setItem(_g.key,JSON.stringify(res));
		if (res.err_msg == "get_brand_wcpay_request:ok") {
            var f=document.getElementById("f");
            f.submit();
		} else if(res.err_msg == "get_brand_wcpay_request：cancel"){
			pay.canPay=false;
			document.getElementById("pay").innerText="确认支付";
		}else{
			alert(JSON.stringify(res)+"；订单号为："+pay_args.package);
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

