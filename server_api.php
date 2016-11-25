<?php
/**
 *一些操作服务器资源的api，例如保存微信授权凭证
 *
 **/
include 'WX.php';
include 'api_v2.php';
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
		$user['data']=$user['data']['authData']['openId'];
	}
	echo json_encode($user);
}

//设置微信推送模板
function setWxTemplate(){
	global $opt,$API;
	$temp=array('OPENTM407674335','OPENTM405760757');
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
		'fields'=>'objectId,authData'
	),$opt);
	if($user['data']){
		$cust=$API->start(array(
			'method'=>'wicare.customer.get',
			'uid'=>$user['data']['objectId'],
			'fields'=>'objectId,name'
		),$opt);
		if($cust['data'])
			$res['exist']=true;
	}
	echo json_encode($res);
}







//根据appkey获取secret
function getWeixin(){
	global $opt,$API;
	$_wx=array(
		'method'=>'wicare.weixin.get',
		'wxAppKey'=>$_GET['wxAppKey'],
		'fields'=>'wxAppKey,wxAppSecret,uid,type,objectId,name,template'
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