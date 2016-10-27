<?php
class WiStormApi{
	const api_url="http://o.bibibaba.cn/router/rest";
	const secret="856cddc42ff9f964aa8c2e9ce87efc28";//这里配置app_secret
	protected $params=array(
		"app_key"=>"cb48b206a511905e07aeae503bf112f2",//这里配置app_key
	 	"v"=>"1.0",
	 	"format"=>"json",
	 	"sign_method"=>"md5"
	);

	//构造函数
	function WiStormApi($token){
		if(isset($token))
			$this->params['access_token']=$token;
	}

	//获取access_token
	function getToken($p){
		$p['method']='wicare.user.access_token';
		$p['fields']='access_token';
		$p['password']=md5($p['password']);
		$res=json_decode($this->start($p),true);
		if (!$res['status_code']) {
			$this->params["access_token"]=$res['access_token'];
		}
		return $res;
	}

	//url编码转换
	function encode($str){
		$url = rawurlencode($str); 
		$a = array("%3B","%5C","%2F","%3F","%3A","%40","%26","%3D","%2B","%24","%2C","%23"); 
		$b = array(";","\\","/","?",":","@","&","=","+","$",",","#");
		$url = str_replace($a, $b, $url); 
		return $url; 
	}

	//构造url
	function makeUrl($p){
		$d=$this->params;
		foreach ($d as $key => $value) {
			$p[$key]=$value;
		}

		$p["timestamp"]=date('Y-m-d H:i:s',strtotime('+8 hour'));
		ksort($p);
		$str="";
		$url=WiStormApi::api_url."?";
		$val="";
		foreach($p as $x=>$x_value) {
			$val=$this->encode($x_value);
			$str.=$x.$val;
			$url.=$x."=".$val."&";
		}
		$str=WiStormApi::secret.$str.WiStormApi::secret;
		$sign=strtoupper(md5($str));
		$url.="sign=".$sign;
		return $url;
	}
	
	function sendHttp($url){//发送http请求
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_TIMEOUT,10);//超时时间默认10秒
	    
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
	    
	    $response = curl_exec($ch);
	    $error = curl_error($ch);
	    curl_close($ch);
	    return $response;
	}


	function start($data){
		$url=$this->makeUrl($data);
		echo $url;
		return $this->sendHttp($url);
	}
}



//不传access_token创建实例会有Warning
$api=new WiStormApi();

//必须先获取access_token
$p= array(
	'account' => "shhx",//帐号
	'password'=>"123456",//密码
	'type' => "2"
);
$res=$api->getToken($p);
print_r($res);
//最好缓存好token，不然每次都需要调用一下getToken方法
//如果有缓存token的话，则可以使用$api=new WiStormApi(token)创建一个实例，这个实例就不需要调用getToken就可以直接使用了


//之后就可以调用其他接口了（这里以wicare.device.get为例）

//配置相关参数
$p= array(
	'method'=>'wicare.device.get',//调用的接口
	'serial'=>'56624831336',//序列号
	'fields'=>'device_id,serial,active_gps_data'//需要返回的字符串
);
//直接调用start方法，返回一个json格式的字符串
$res=$api->start($p);
echo "<br><br>wicare.device.get return:<br><br>";
print_r($res);



//配置相关参数
$p= array(
	'method'=>'wicare.devices.list',//调用的接口
	'dealer_id'=>'50',//user_id
	'fields'=>'device_id,serial,active_gps_data',//需要返回的字符串
	'sorts'=>'device_id',
	'page'=>'device_id',
	'min_id'=>0,
	'max_id'=>0,
	'limit'=>20
);
//直接调用start方法
$res=$api->start($p);
echo "<br><br>wicare.devices.list return:<br><br>";
print_r($res);

