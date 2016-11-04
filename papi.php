<?php
header('Access-Control-Allow-Origin: *');
include 'WX.php';
include 'api_v2.php';
$a=new papiApi();

//对接papi的接口
if(!isset($_GET["method"]))
	echoExit(0x9004,'INVALID_METHOD');
//根据method进行操作
switch ($_GET["method"]){
	case "createaccountbindimei":
		echo $a->register();
		break;
	case "login":
		echo $a->login();
		break;
	case "updatepassword":
		echo $a->resetPassword();
		break;
	case "devicebind":
		echo $a->deviceBind();
		break;
	case "getwheelpath":
		echo $a->getwheelpath();
		break;
	case "getdeviceposition":
		echo $a->getPosition();
		break;
	case "remoteconfig":
		echo $a->remoteConfig();
		break;
	case "postdata":
		echo postData();
		break;
	default:
		echoExit(0x9004,'INVALID_METHOD');
		exit;
}




/****api类****/
class papiApi{
	const api_url="http://papi.rmtonline.cn/api/v1/";
	const secret="2EC9585F20DA1AB59A6898C9CABA9883";
	protected $params=array(
		"appkey"=>"xrqqw20160406001"
	);

	function encode($str){
		$url = rawurlencode($str); 
		// $a = array("%3B","%5C","%2F","%3F","%3A","%40","%26","%3D","%2B","%24","%2C","%23"); 
		// $b = array(";","\\","/","?",":","@","&","=","+","$",",","#");
		// $url = str_replace($b,$a,$str); 
		return $url; 
	}

	function sign($p){
		$d=$this->params;
		foreach ($d as $key => $value) {
			$p[$key]=$value;
		}
		$p["timestamp"]=time();
		ksort($p);
		$str="";
		foreach($p as $x=>$x_value) {
			$str.=$x.'='.$x_value;
		}
		$str.='secret='.papiApi::secret;
		$p["sign"]=strtoupper(md5($val=$this->encode($str)));
		return $p;
	}

	function makeData($p){
		$p=$this->sign($p);
		$str="";
		foreach($p as $x=>$x_value) {
			$val=$this->encode($x_value);
			$str.='&'.$x.'='.$val;
		}
		return substr($str,1);
	}
	
	function get($url){//发送http请求
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
	function post($url,$post_data){
		$ch = curl_init();
        curl_setopt ($ch,CURLOPT_URL,$url);
        curl_setopt ($ch,CURLOPT_RETURNTRANSFER,true); 

        curl_setopt ($ch,CURLOPT_POST,true);
        if($post_data != ''){
            curl_setopt($ch,CURLOPT_POSTFIELDS,$post_data);
        }
        
        curl_setopt ($ch,CURLOPT_TIMEOUT,10);
        $response=curl_exec($ch);
        curl_close($ch);
        return $response;
	}

	function api($url,$data,$type='get'){
		$data_str=$this->makeData($data);
		if($type=='get'){
			return $this->get($url.'?'.$data_str);
		}else{
			return $this->post($url,$data_str);
		}
	}
	

	/**
	 * 登录
	 * data {
	 * 		account:1555,
	 * 		password:123456
	 * }
	 */
	function login(){
		$url=papiApi::api_url.'login';
		return $this->api($url,$_POST,'post');
	}

	/**
	 * 注册
	 */
	function register(){
		$url=papiApi::api_url.'createaccountbindimei';
		return $this->api($url,$_POST,'post');
	}

	/**
	 * 修改密码
	 * 	data{
	 * 		account:13564564,
	 * 		password:123456,
	 * 		oldpwd:1234
	 * }
	 */
	function resetPassword(){
		$url=papiApi::api_url.'updatepassword';
		return $this->api($url,$_POST,'post');
	}

	/**
	 * 绑定设备
	 * data{
	 * 		mobile:13564564,
	 * 		did:123456,
	 * }
	 */
	function deviceBind(){
		$url=papiApi::api_url.'devicebind';
		return $this->api($url,$_POST,'post');
	}

	/**
	 * 解绑设备
	 * data{
	 * 		mobile:13564564,
	 * 		did:123456,
	 * }
	 */
	function deviceUnbind(){
		$url=papiApi::api_url.'deviceunbind';
		return $this->api($url,$_POST,'post');
	}

	/**
	 * 获取当前经纬度
	 * data{
	 * 		map:0,
	 * 		did:123456,
	 * }
	 */
	function getPosition(){
		$url=papiApi::api_url.'getdeviceposition';
		return $this->api($url,$_POST,'get');
	}

	/**
	 * 历史轨迹
	 * data{
	 * 		map:0,
	 * 		did:123456,
	 *		date: 查询日期(只能按天查询，日期格式2016-04-07)
	 * }
	 */
	function getwheelpath(){
		$url=papiApi::api_url.'getwheelpath';
		return $this->api($url,$_POST,'get');
	}

	/**
	 * 远程设置防盗
	 * data{
	 * 		steal:震动防盗灵敏度，只能为(0,1,2)中的一个值，('0':'低','1':'中','2':'高')
	 *		alertdelay:震动报警延迟设定值，只能为(0,10,20,30,40,50,60)中的一个值，单位为秒
	 * 		did:123456,
	 * }
	 */
	function remoteConfig(){
		$url=papiApi::api_url.'remoteconfig';
		return $this->api($url,$_POST,'get');
	}
}


//收到回调，推送给用户
function postData(){
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
	$opt=array(
		'access_token'=>'3a9557ed4250440ec57b53564e391cb50ada46ae97bc96c6abf0c3a7a3b501c3b7c93e803c9016924569a69f7e1d4222b39bb1bd39c70601cbcb8cbe953e0bfe',
		'app_key'=>'0642502f628a83433f0ba801d0cae4ef',
		'dev_key'=>'86e3ddeb8db36cbf68f10a8b7d05e7ac',
		'app_secret'=>'15fe3ee5197e8ba810512671483d2697'
	);
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