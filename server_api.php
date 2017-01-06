<?php
/**
 *一些操作服务器资源的api，例如保存微信授权凭证
 *
 **/
date_default_timezone_set('PRC'); 
include 'WX.php';
include 'api_v2.php';
include 'checkAndPay.php';
header('Access-Control-Allow-Origin: *');
header('Content-type:application/json; charset=utf-8'); 
$API=new api_v2();//api接口类


if(!isset($_GET["method"]))
	echoExit(0x9004,'INVALID_METHOD');
//根据method进行操作
switch ($_GET["method"]){
	case "wx_config_file"://生成保存微信凭证文件
        saveConfigFile();
		break;
	case "getQrcode"://根据did获取二维码
        getQrcode();
		break;
	case "getAnyQrcode"://根据参数获取二维码
		getAnyQrcode();
		break;
	case "sendWeixinByTemplate"://添加模板并发送模板消息
		sendWeixinByTemplate();
		break;
	case "sendWeixinByUid"://给营销号的关注用户推送模板消息
		sendWeixinByUid();
		break;
	case "getInstallByUid"://获取指定uid下的安装网点
		getInstallByUid();
		break;
	case "setWxTemplate"://给公众号设置推送模板
		setWxTemplate();
		break;
	case "getUserOpenId"://获取某一用户的openId
		getUserOpenId();
		break;
	case "checkExists"://检查用户是否已注册（特指user表和customer都存在的情况）
		checkExists();
		break;
	case "addAndBind"://校验did，添加车辆绑定设备，增加出入库记录，如果有预订，支付预付款和佣金
		addAndBindCar();
		break;
	case "setMenu"://校验did，添加车辆绑定设备，增加出入库记录，如果有预订，支付预付款和佣金
		setMenu();
		break;
	case "getBrand"://根据产品id获取产品品牌，主要在booking.js预订时使用，展示产品品牌
		getBrand();
		break;
	case "getWeixinKey"://根据uid获取公众号key
		getWeixinKey();
		break;
	case "bindOpenId"://绑定微信openid
		bindOpenId();
		break;
	default:
		echoExit(0x9004,'INVALID_METHOD');
		exit;
}

/******工具函数******/
//输出错误信息并退出脚本
function echoExit($code,$str){
	echo '{"status_code":'.$code.',"err_msg":"'.$str.'"}';
	exit;
}

//把信息保存为txt文件供微信确认使用
function saveConfigFile(){
    $count=file_put_contents($_GET['fileName'], substr($_GET['fileName'],10,-4));
    echo '{"status_code":0,"data":{"count":'.$count.'}}';
}

//根据did获取服务号二维码接口
function getQrcode(){
	global $opt,$API;
	$did=$_GET['did'];
	
	//获取设备的serverId
	$dev=array(
		'method'=>'wicare._iotDevice.get',
		'did'=>$did,
		'fields'=>'serverId'
	);
	$device=$API->start($dev,$opt);
	if(!$device||!$device['data']){
		echoExit(-4,'没有查找到该设备');
	}
	//根据serverId获取对应代理商公众微信key和secret
	$cus=array(
		'method'=>'wicare.customer.get',
		'objectId'=>$device['data']['serverId'],
		'fields'=>'wxAppKey,wxAppSecret'
	);
	$cust=$API->start($cus,$opt);
	if(!$cust||!$cust['data']){
		echoExit(-5,'没有查找到服务商');
	}
	if(!$cust['data']['wxAppKey']||!$cust['data']['wxAppSecret']){
		echoExit(-6,'服务商公众号未正确配置');
	}
	$wx=new WX($cust['data']['wxAppKey'],$cust['data']['wxAppSecret']);

	$scene=addQr('1000',$did);
	$q=$wx->getQrcode('{"expire_seconds": 2592000, "action_name": "QR_SCENE", "action_info": {"scene": {"scene_id": '.$scene.'}}}');
	
	echo json_encode($q);
}

//传递任意整数获取临时二维码
function getAnyQrcode(){
	$scene=addQr($_GET['type'],$_GET['data']);
	$wei=getWeixin();
	$wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
	$q=$wx->getQrcode('{"expire_seconds": 2592000, "action_name": "QR_SCENE", "action_info": {"scene": {"scene_id": '.$scene.'}}}');
	unset($wei['wxAppSecret']);
	echo json_encode(array_merge($q,$wei));
}

//传递模板编号发送微信模板消息
function sendWeixinByTemplate(){
	$wei=getWeixin();
	$wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
	$tem=$wei['template'][$_GET['templateId']];
	echo $wx->sendWeixin($_GET['openId'],$tem,$_GET['data'],$_GET['link']);
}

//根据uid取客户的营销号，然后给openId推送模板消息
function sendWeixinByUid (){
	global $opt,$API;
	$_wx=array(
		'method'=>'wicare.weixin.get',
		'uid'=>$_GET['uid'],
		'type'=>$_GET['type'],
		'fields'=>'wxAppKey,wxAppSecret,uid,type,objectId,name,template'
	);
	$wei=$API->start($_wx,$opt);
	if(!$wei['data']||!$wei['data']['wxAppKey']||!$wei['data']['wxAppSecret']){
		echoExit(-6,'服务商公众号未正确配置');
	}
	$wx=new WX($wei['data']['wxAppKey'],$wei['data']['wxAppSecret']);
	$tem=$wei['data']['template'][$_GET['templateId']];
	echo $wx->sendWeixin($_GET['openId'],$tem,$_GET['data'],$_GET['link']);
}

//获取代理商名下安装网点
function getInstallByUid(){
	global $opt,$API;
	$data=array(
		'method'=>'wicare.customer.list',
		'parentId'=>$_GET['uid'],
		'isInstall'=>1,
		'limit'=>-1,
		'fields'=>'createdAt,objectId,uid,name,treePath,parentId,tel,custTypeId,custType,province,provinceId,city,cityId,area,areaId,address,contact,logo,sex,dealer_id,other,isInstall,serverId,wxAppKey'
	);
	echo json_encode($API->start($data,$opt));
}

//获取用户的openId
function getUserOpenId(){
	global $opt,$API;
	$user=$API->start(array(
		'method'=>'wicare.user.get',
		'objectId'=>$_GET['objectId'],
		'fields'=>'objectId,authData'
	),$opt);
	if($user['data']&&$user['data']['authData']){
		$k=api_v2::getOpenIdKey(api_v2::$domain['wx']);
		$user['data']=$user['data']['authData'][$k];
	}
	echo json_encode($user);
}

//设置微信推送模板
function setWxTemplate(){
	global $opt,$API;
				//预订成功通知      服务预约成功通知   安装成功通知       账户变动提醒       提现通知	          待支付提醒         账单异常处理提醒
	$temp=array('OPENTM408168978','OPENTM405760757','OPENTM408183089','OPENTM207664902','OPENTM207428984','OPENTM406963151','OPENTM401266811');
	$arrlength=count($temp);
	$wei=getWeixin();
	$wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
	$template=$wei['template'];
	$res=array();
	for($i=0;$i<$arrlength;$i++){
		$id=$temp[$i];
		if(!$template[$id]){
			$res=$wx->addTemplate($id);
			if($res['errcode']){
				echoExit($res['errcode'],$res['errmsg'].'，模板id:'.$id);
				return;
			}else{
				//更新template_id
				$res=$API->start(array(
					'method'=>'wicare.weixin.update',
					'_objectId'=>$wei['objectId'],
					'template.'.$id=>$res['template_id'],
					'fields'=>'objectId'
				),$opt);
			}
		}
	}

	echo json_encode($res);
}

//检查用户是否已注册（特指user表和customer都存在的情况
function checkExists(){
	global $opt,$API;
	$res=array(
		'status_code'=>0,
		'exist'=>false
	);
	$user=$API->start(array(
		'method'=>'wicare.user.get',
		'mobile'=>$_GET['mobile'],
		'fields'=>'objectId,authData,userType'
	),$opt);
	if($user['data']){
		$d=array(
			'method'=>'wicare.customer.get',
			'uid'=>$user['data']['objectId'],
			'fields'=>'objectId,name'
		);
		if($user['data']['userType']==9){
			$d['method']='wicare.employee.get';
		}else
			$d['appId']=$_GET['appId'];
		
		$cust=$API->start($d,$opt);
		if($cust['data'])
			$res['exist']=true;
	}
	echo json_encode($res);
}

//校验did，添加车辆绑定设备，增加出入库记录，如果有预订，支付预付款和佣金
function addAndBindCar(){
	global $opt,$API;
	$did=$_GET['did'];
	$uid=$_GET['uid'];
	$open_id=$_GET['openId'];
	$phone=$_GET['mobile'];
	$name=$_GET['name'];
	$bookingId=$_GET['bookingId'];

	//如果没有传车牌号，则必须传递一个车辆id
	$carId=$_GET['carId'];
	$carNum=$_GET['carNum'];

	$device=$API->start(array(//验证设备
		'method'=>'wicare._iotDevice.get',
		'did'=>$did,
		'fields'=>'objectId,did,uid,model,modelId,binded,bindDate,vehicleName,vehicleId,serverId'
	),$opt);
	if(!$device||!isset($device['data'])){
		echoExit(123,'未查找到设备');
	}
	if($device['data']['binded']){//已经有账号
		echoExit(123,'设备已被绑定');
	}
	$device=$device['data'];

	$booking=$API->start(array(//获取预订信息
		'method'=>'wicare.booking.get',
		'objectId'=>$bookingId,
		'status'=>0,
		'fields'=>'objectId,type,activityId,sellerId,uid,mobile,name,openId,carType,install,installId,userMobile,userName,userOpenId,payMoney,orderId,product'
	),$opt);
	if(!$booking||!$booking['data'])
		$booking=null;
	else
		$booking=$booking['data'];
	//添加车辆绑定设备
	$device=addAndBind($uid,$carNum,$device,$open_id,$phone,$name,$booking,$carId);
	if(!is_array($device)){
		echo '{"status_code":"'.$device.'"}';
	}

	//添加出入库记录
	addDeviceLog($device,$uid,$name,$booking);
	echo '{"status_code":0}';
}

//给指定微信号设置车主菜单
function setMenu(){
	$wei=getWeixin();
	if($wei['type']==1){
		echoExit(0,'');
	}
	$reg='http://'.api_v2::$domain['user'].'/?location=%2Fwo365_user%2Fregister.html&intent=logout&needOpenId=true&wx_app_id='.$_GET['wxAppKey'];
	$my='http://'.api_v2::$domain['user'].'/?loginLocation=%2Fwo365_user%2Fsrc%2Fmoblie%2Fmy_account.html&wx_app_id='.$_GET['wxAppKey'];
	$home='http://'.api_v2::$domain['user'].'/?wx_app_id='.$_GET['wxAppKey'];

	if(isset($wei['menu'])&&!isset($wei['menu']['none']))
		$menu=unicodeJson($wei['menu']).',';
	else
		$menu='';
	// 设置菜单
	$jsonmenu = '{
		"button": [
			{
				"type": "view",
				"name": "我的主页",
				"url": "'.$home.'"
			},
			'.$menu.'
			{
				"name": "我的",
				"type": "view",
				"url": "'.$my.'"
			}
		]
	}';
	// echo $jsonmenu;

	$wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
	echo json_encode($wx->setMenu($jsonmenu));
}

//获取产品品牌
function getBrand(){
	global $opt,$API;
	$pro=$API->start(array(
		'method'=>'wicare.product.get',
		'objectId'=>$_GET['objectId'],
		'fields'=>'objectId,brand,brandId,name,company'
	),$opt);
	echo json_encode($pro);
}

//根据uid获取公众号appid
function getWeixinKey(){
	global $opt,$API;
	$_wx=array(
		'method'=>'wicare.weixin.get',
		'uid'=>$_GET['uid'],
		'type'=>$_GET['type'],
		'fields'=>'wxAppKey,uid,type,objectId,name'
	);
	$wei=$API->start($_wx,$opt);

	if(!$wei['data']||!$wei['data']['wxAppKey']){
		echoExit(-6,'服务商公众号未正确配置');
	}
	echo json_encode($wei);
}

//绑定微信
function bindOpenId(){
	global $opt,$API;
	//验证短信
	$r=$API->start(array(
		'method'=>'wicare.comm.validCode',
		'valid_type'=>1,
		'valid_code'=>$_GET['code'],
		'mobile'=>$_GET['mobile']
	),$opt);
	if($r&&!$r['status_code']&&$r['valid']){
		$k=api_v2::getOpenIdKey($_GET['host']);
		$key='authData.'.$k;
		$user=$API->start(array(
			'method'=>'wicare.user.get',
			'fields'=>'objectId,mobile',
			$key => $_GET['openId']
		),$opt);
		if($user['data']){//解绑原来的账号
			$user=$API->start(array(
				'method'=>'wicare.user.update',
				'_objectId'=>$user['data']['objectId'],
				$key => rand(0,100000000),
				'authData.'.$k.'_unbind'=>date('Y-m-d H:i:s')
			),$opt);
		}
		$res=$API->start(array(
			'method'=>'wicare.user.update',
			'_mobile'=>$_GET['mobile'],
			$key => $_GET['openId'],
			'authData.'.$k.'_bind'=>date('Y-m-d H:i:s')
		),$opt);
		echo json_encode($res);
	}else{
		$r['status_code']=36872;
		echo json_encode($r);
	}
}





//根据appkey获取secret
function getWeixin(){
	global $opt,$API;
	$_wx=array(
		'method'=>'wicare.weixin.get',
		'wxAppKey'=>$_GET['wxAppKey'],
		'fields'=>'wxAppKey,wxAppSecret,uid,type,objectId,name,template,menu'
	);
	$wei=$API->start($_wx,$opt);

	if(!$wei['data']||!$wei['data']['wxAppKey']||!$wei['data']['wxAppSecret']){
		echoExit(-6,'服务商公众号未正确配置');
	}
	return $wei['data'];
}

//添加映射
function addQr($type,$data){
	global $opt,$API;
	$id=$API->start(array(
		'method'=>'wicare.qrData.create',
		'id'=>0,
		'data'=>json_encode(array(
			'type'=>$type,
			'data'=>$data
		))
	),$opt);
	return $id['autoId'];
}

//把json中unicode编码的中文，转换成utf-8
function unicodeJson($str){  
	$json = json_encode($str);  
	return preg_replace_callback(
        "#\\\u([0-9a-f]+)#i",
        function ($matches) {
            return iconv('UCS-2','UTF-8', pack('H4',$matches[1]));
        },
        $json
    );  
}  