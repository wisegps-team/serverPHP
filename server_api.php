<?php
/**
 *一些操作服务器资源的api，例如保存微信授权凭证
 *
 **/
header('Access-Control-Allow-Origin: *');
header('Content-type:application/json; charset=utf-8'); 

//对接papi的接口
if(!isset($_GET["method"]))
	echoExit(0x9004,'INVALID_METHOD');
//根据method进行操作
switch ($_GET["method"]){
	case "wx_config_file":
        echo saveConfigFile();
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
    return '{"status_code":0,"data":{"count":'.$count.'}}';
}