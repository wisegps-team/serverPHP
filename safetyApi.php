<?php
//ini_set('display_errors',1);            //错误信息
//ini_set('display_startup_errors',1);    //php启动错误信息
//error_reporting(-1);                    //打印出所有的 错误信息
header('Access-Control-Allow-Origin: *');
$a=new api();

//主要用于过滤一些不安全的api
if(!isset($_GET["method"]))
	echoExit(0x9004,'INVALID_METHOD');
//根据method进行操作
switch ($_GET["method"]){
	case "wicare.base.geocoder":
		geocoder();
		break;
	case "wicare.user.distributor.register":
		distributorRegister();
		break;
	case "wicare.user.distributor.checkParent":
		$u=checkParent();
		if(isset($u['cust_id'])){
			echo '{"status_code":0,"valid":true}';
			exit;
		}
		break;
	case "wicare.file.base64":
		fileBase64();
		break;
	case "wicare.user.getQrcode":
		getQrcode();
		break;
	case "wicare.user.kd":
		kd();
		break;
	default:
		echoExit(0x9004,'INVALID_METHOD');
		exit;
}

//下层注册
function distributorRegister(){
	$user=checkParent();
	if(isset($user['cust_id'])){
		//上层验证成功,开始注册
		$r=array('mobile' => $_GET['mobile'],'password'=>md5($_GET['password']),'valid_type'=>$_GET['valid_type'],'valid_code'=>$_GET['valid_code']);
		$res=register($r);
		if(!$res)
			echoExit('-4','注册失败');
		else if(isset($res['status_code'])&&$res['status_code']!="0")
			echoExit($res['status_code'],$res['err_msg']);
		else{
			//注册成功,开始更新用户信息
			//删除无用参数
			$g=$_GET;
			unset($g['mobile']);
			unset($g['password']);
			unset($g['valid_code']);
			unset($g['valid_type']);
			unset($g['parent_mobile']);
			unset($g['parent_open_id']);

			$g['dealer_tree_path']=$user['dealer_tree_path'].','.$res['cust_id'];
			$g['dealer_level']=$user['dealer_level']+1;
			$g['dealer_class']=5;
			$g['parent_dealer_id']=$user['cust_id'];
			$g['cust_type']=4;
			$g['_cust_id']=$res['cust_id'];
			$res1=updateUser($g);
			if(!$res1)
				echoExit('-5','注册失败');
			else if(isset($res1['status_code'])&&$res1['status_code']!="0")
				echoExit($res1['status_code'],$res1['err_msg']);
			else{
				$res1['cust_id']=$res['cust_id'];
				echo json_encode($res1);
			}
		}
	}
}

//验证上层是否有效
function checkParent(){
	$u=array('login_id' => $_GET['parent_open_id'],'mobile'=>$_GET['parent_mobile']);
	$user=getUser($u);
	if(!$user){//无返回或返回不是json
		echoExit('-2','校验上层用户失败');
	}else if(isset($user['status_code'])&&$user['status_code']!="0")//返回正常，但有错误
		echoExit($user['status_code'],$user['err_msg']);
	else if($user['cust_type']!=4||!isset($user['dealer_level'])||$user['dealer_level']==4){
		echoExit('-3','无效的邀请上层');
	}else if($user['mobile']==$_GET['parent_mobile']&$user['login_id']==$_GET['parent_open_id']){
		return $user;
	}else{
		echoExit('-6','无效的邀请上层');
	}
}

//经纬度转位置
function geocoder(){
	global $a;
	$bdUrl="http://api.map.baidu.com/geocoder/v2/?ak=DjMyWnXm12o3esdcWR8gIQLm&output=json&pois=1&location=".$_GET['lat'].",".$_GET['lon'];
	echo $a->sendHttp($bdUrl);
}

//dataUrl转文件
function fileBase64(){
	global $a;
	$file_code=$_POST['image'];
	$file=base64_decode($file_code);
	$fileName=md5(date('U').$_POST['imageName']).'.'.$_POST['suffix'];
	file_put_contents($fileName,$file);
	$data=array('method' => 'wicare.file.upload');
	$url=$a->makeUrl($data);
	echo $a->postFile($url,dirname(__FILE__).'/'.$fileName);
	unlink($fileName);
}

//生成带参数的二维码
function getQrcode(){
	include 'WX.php';
	$wx=new WX();
	$msg='{"action_name":"QR_LIMIT_STR_SCENE","action_info":{"scene":{"scene_str":"sellerId_'.$_GET["scene_str"].'"}}}';
	$q=$wx->getQrcode($msg);
	echo json_encode($q);
}

function kd(){
	echo "string";
	$html=httpGet('http://m.kuaidi100.com/index_all.html?type='.$_GET["type"].'&postid='.$_GET["postid"].'#result');
	echo $html;
	$table=strstr($html,'<table');
	$table=strstr($table,'</table>',true);
	echo $table;
}




/*****不应该暴露的接口******/

//获取用户基本信息
function getUser($p){
	global $a;
	$p['method']='wicare.user.get';
	$p['fields']='login_id,cust_id,cust_name,cust_type,saler_id,parent_cust_id,tel,mobile,seller_id,dealer_tree_path,dealer_level,dealer_class';
	$res=json_decode($a->start($p),true);
	return $res;
}

//注册用户
function register($p){
	global $a;
	$p['method']='wicare.user.register';
	$p['fields']='cust_id';
	$res=json_decode($a->start($p),true);
	return $res;
}

//更新用户信息
function updateUser($p){
	global $a;
	$p['method']='wicare.user.update';
	$p['fields']='cust_id';
	$u=$a->makeUrl($p);
	$res=json_decode($a->start($p),true);
	return $res;
}

//
function httpGet($url) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_ENCODING ,'utf-8');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 500);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_URL, $url);

    $res = curl_exec($curl);
    curl_close($curl);

    return $res;
}


/****api类****/
class api{
	const api_url="http://o.bibibaba.cn/router/rest";
	const secret="21fb644e20c93b72773bf0f8d0905052";
	protected $params=array(
		"app_key"=>"9410bc1cbfa8f44ee5f8a331ba8dd3fc",
	 	"v"=>"1.0",
	 	"format"=>"json",
	 	"sign_method"=>"md5",
	 	"access_token"=>"f1b3afaf9bbedfcb0ca3f0465a1d2e7e157c1ea55ad8d2dbcaa7083d125d360c403fe6c7ed1ace5c25682eb77a070c90"
	);

	function encode($str){
		$url = rawurlencode($str); 
		$a = array("%3B","%5C","%2F","%3F","%3A","%40","%26","%3D","%2B","%24","%2C","%23"); 
		$b = array(";","\\","/","?",":","@","&","=","+","$",",","#");
		$url = str_replace($a, $b, $url); 
		return $url; 
	}

	function makeUrl($p){
		$d=$this->params;
		foreach ($d as $key => $value) {
			$p[$key]=$value;
		}

		$p["timestamp"]=date('Y-m-d H:i:s',strtotime('+8 hour'));
		ksort($p);
		$str="";
		$url=api::api_url."?";
		$val="";
		foreach($p as $x=>$x_value) {
			$val=$this->encode($x_value);
			$str.=$x.$val;
			$url.=$x."=".$val."&";
		}
		$str=api::secret.$str.api::secret;
		$sign=strtoupper(md5($str));
		$url.="sign=".$sign;
		return $url;
	}
	
	function sendHttp($url){//发送http请求
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);//需要获取的URL地址，也可以在curl_init()函数中设置。
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//将curl_exec()获取的信息以文件流的形式返回，而不是直接输出。
	    curl_setopt($ch, CURLOPT_TIMEOUT,10);//超时
	    
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);//禁用后cURL将终止从服务端进行验证。
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);//与上面设置连体
	    
	    $response = curl_exec($ch);//执行设置好的会话,成功时返回 TRUE，或者在失败时返回 FALSE。 然而，如果 CURLOPT_RETURNTRANSFER选项被设置，函数执行成功时会返回执行的结果，失败时返回 FALSE 。
	    $error = curl_error($ch);//返回错误信息
	    curl_close($ch);//关闭一个cURL会话
	    return $response;
	}

	function postFile($url,$file){
		$fields['image'] = '@'.$file;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url );
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, 1 );
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields );

		$response=curl_exec($ch);

		if ($error = curl_error($ch) ) {
		    die($error);
		}
		curl_close($ch);
		return $response;
	}

	function start($data){
		$url=$this->makeUrl($data);
		return $this->sendHttp($url);
	}
}



/******工具函数******/
//输出错误信息并退出脚本
function echoExit($code,$str){
	echo '{"status_code":'.$code.',"err_msg":"'.$str.'"}';
	exit;
}


