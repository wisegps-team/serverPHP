<?php
header('Access-Control-Allow-Origin: *');
include 'WX.php';
include 'api_v2.php';
include 'papiApi.php';
$a=new papiApi();

//对接papi的接口
if(!isset($_GET["method"]))
	echoExit(0x9004,'INVALID_METHOD');
//根据method进行操作
switch ($_GET["method"]){
	case "createaccountbindimei":
		echo $a->register($_POST);
		break;
	case "login":
		echo $a->login($_POST);
		break;
	case "updatepassword":
		echo $a->resetPassword($_POST);
		break;
	case "devicebind":
		echo $a->deviceBind($_POST);
		break;
	case "getwheelpath":
		echo $a->getwheelpath($_POST);
		break;
	case "getdeviceposition":
		echo $a->getPosition($_POST);
		break;
	case "remoteconfig":
		echo $a->remoteConfig($_POST);
		break;
	case "postdata":
		echo postData();
		break;
	case "deviceunbind":
		echo $a->deviceUnbind($_POST);
		break;
	default:
		echoExit(0x9004,'INVALID_METHOD');
		exit;
}







//收到回调，推送给用户
function postData(){
	global $opt;
	//根据imei查到车辆，根据车辆查到客户表，客户表查到用户，获取用户openid，给他推送
	// $_POST['fileType'];
	// $_POST['fileUrl'];
	// $_POST['imei'];
	// $_POST['tag'];
	$imei=$_GET['imei'];
	$url=$_GET['fileUrl'];
	$type='报警类型';
	$des='报警描述';
	$title='标题';
	$remark='备注';

	$API=new api_v2();//api接口类
	//用于获取车辆数据
	$vehicle=$API->start(array(
		'method'=>'wicare.vehicle.get',
		'did'=>$imei,
		'fields'=>'uid,name'
	),$opt);
	echo '<br>vehicle=';
	print_r($vehicle);
	if($vehicle['status_code']||!$vehicle['data'])return;
	$cust=$API->start(array(
		'method'=>'wicare.customer.get',
		'objectId'=>$vehicle['data']['uid'],
		'fields'=>'uid,parentId'
	),$opt);

	echo '<br>cust=';
	print_r($cust);
	if($cust['status_code']||!$cust['data'])return;
	$user=$API->start(array(
		'method'=>'wicare.user.get',
		'objectId'=>$cust['data']['uid'],
		'fields'=>'authData'
	),$opt);
	echo '<br>user=';
	print_r($user);
	if($user['status_code']||!$user['data']||!$user['data']['authData']['openId'])return;
	$openId=$user['data']['authData']['openId'];

	$parentCust=$API->start(array(//获取微信key
		'method'=>'wicare.customer.get',
		'objectId'=>$cust['data']['parentId'][0],
		'fields'=>'wxAppKey,wxAppSecret'
	),$opt);
	print_r($parentCust);
	if($parentCust['status_code']||!$parentCust['data'])return;

	$wx=new WX($parentCust['data']['wxAppKey'],$parentCust['data']['wxAppSecret']);

	$tid='Ua937cQPpSvrj40HlULlYa_NSNt0G_uEuvXiXacktEg';
	$data='{"first":{"value":"'.$title.'","color":"#173177"},"keyword1":{"value":"'.$vehicle['data']['name'].'","color":"#173177"},"keyword2":{"value":"'.date('Y-m-d H:i:s',strtotime('+8 hour')).'","color":"#173177"},"keyword3":{"value":"'.$type.'","color":"#173177"},"keyword4":{"value":"'.$des.'","color":"#173177"},"remark":{"value":"'.$remark.'","color":"#173177"}}';
	echo $wx->sendWeixin($openId,$tid,$data,$url);
}




/******工具函数******/
//输出错误信息并退出脚本
function echoExit($code,$str){
	echo '{"status_code":'.$code.',"err_msg":"'.$str.'"}';
	exit;
}