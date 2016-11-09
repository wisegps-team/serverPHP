<?php
/**
 *一些操作服务器资源的api，例如保存微信授权凭证
 *
 **/
include 'WX.php';
include 'api_v2.php';
header('Access-Control-Allow-Origin: *');
// header('Content-type:application/json; charset=utf-8'); 

$opt=array(
	'access_token'=>'3a9557ed4250440ec57b53564e391cb50ada46ae97bc96c6abf0c3a7a3b501c3b7c93e803c9016924569a69f7e1d4222b39bb1bd39c70601cbcb8cbe953e0bfe',
	'app_key'=>'0642502f628a83433f0ba801d0cae4ef',
	'dev_key'=>'86e3ddeb8db36cbf68f10a8b7d05e7ac',
	'app_secret'=>'15fe3ee5197e8ba810512671483d2697'
);

if(!isset($_GET["method"]))
	echoExit(0x9004,'INVALID_METHOD');
//根据method进行操作
switch ($_GET["method"]){
	case "wx_config_file":
        saveConfigFile();
		break;
	case "getQrcode":
        getQrcode();
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

//获取二维码接口
function getQrcode(){
	global $opt;
	$did=$_GET['did'];
	$API=new api_v2();//api接口类
	
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
	$q=$wx->getQrcode('{"expire_seconds": 2592000, "action_name": "QR_SCENE", "action_info": {"scene": {"scene_id": '.$did.'}}}');
	
	echo json_encode($q);
}