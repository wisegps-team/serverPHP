<?php
include 'api_v2.php';

$API=new api_v2();//api接口类

// if(isset($_GET['enterKey'])){
// 	$cacheData=array(
// 		'method' =>'wicare.cache.getObj', 
// 		'key'=>$_GET['enterKey']
// 	);
// 	$cache=$API->start($cacheData);
// 	setcookie('sid_'.$cache['sid'],json_encode($cache),time()+3600*24*30,"/");
// 	Header("Location: ".$cache['enterUrl']);
// 	exit;
// }

$_host=$_SERVER['HTTP_HOST'];//当前域名

//用于获取app数据
$appData=array(
	'domainName' => $_host,
	'method'=>'wicare.app.get',
	'fields'=>'devId,name,logo,version,appKey,appSecret,sid,wxAppKey'
);

//用于获取开发者key
$devData=array(
	'method'=>'wicare.developer.get',
	'fields'=>'devKey,devSecret,pushEngine,smsEngine'
);

//用于获取服务数据
$serviceData=array(
	'method'=>'wicare.service.get',
	'objectId'=>'',
	'fields'=>'name,enterUrl,desc'
);

//获取app数据
$appRes=$API->start($appData);
$appRes=err_exit($appRes);
if(isset($_GET['wxAppKey']))//如果有指定微信appkey，则替换
	$appRes['wxAppKey']=$_GET['wxAppKey'];
$key=md5($appRes['objectId'].time());
if(!$appRes['sid']){
	echo 'Not configured service';
	exit;
}else
	$serviceData['objectId']=$appRes['sid'];

//获取开发者数据
$devData['objectId']=$appRes['devId'];
$devRes=$API->start($devData);
$devRes=err_exit($devRes);

//获取服务数据
$serRes=$API->start($serviceData);
$serRes=err_exit($serRes);

$res=array_merge($devRes,$serRes,$appRes);

setcookie('_app_config_',json_encode($res),time()+3600*24*30,"/");
$url='http://'.$_host.$res['enterUrl'];

$get='';
foreach($_GET as $k=>$val) {
	$get.='&'.$k.'='.$val;
}

if($get){
	if(!strpos($url,'?')){
		$get[0]='?';
	}
	$url.=$get;
}

Header("Location: ".$url);

// $cacheData=array(
// 	'method' =>'wicare.cache.setObj', 
// 	'key'=>$key,
// 	'value'=>json_encode($res)
// );
// $cache=$API->start($cacheData);

// if(!$cache||$cache['status_code']){
// 	echo 'data error'+json_encode($cache);
// 	exit;
// }


// $url=$res['enterUrl'];
// if(substr_count($url,'?'))
// 	$url.='&enterKey='.$key;
// else
// 	$url.='?enterKey='.$key;

// $url='http://h5.bibibaba.cn/sid.php?enterKey='.$key;
// Header("Location: ".$url);


function err_exit($arr){
	if(!$arr){
		echo '服务器正在重启，请稍后重试';
		exit;
	}else if($arr['status_code']){
		echo '服务器错误：'+json_encode($arr);
		exit;
	}else if(!$arr['data']){
		echo '服务数据为空';
		exit;
	}else{
		return $arr['data'];
	}
}