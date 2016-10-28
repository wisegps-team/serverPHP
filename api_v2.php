<?php
/****api类****/
class api_v2{
	const api_url="http://wop-api.chease.cn/router/rest";
	protected $params=array(
	 	"v"=>"2.0",
	 	"format"=>"json",
	 	"sign_method"=>"md5"
	);

	function encode($str){
		$type=gettype($str);
		if($type=='array')
			$str=json_encode($str);
		$url = rawurlencode($str); 
		$a = array("%3B","%2F","%3F","%3A","%40","%26","%3D","%2B","%24","%2C","%23"); 
		$b = array(";","/","?",":","@","&","=","+","$",",","#");
		$url = str_replace($a, $b, $url); 
		return $url; 
	}

	function makeUrl($p,$opt){
		$d=$this->params;
		foreach ($d as $key => $value) {
			$p[$key]=$value;
		}
		foreach ($opt as $key => $value) {
			$p[$key]=$value;
		}

		if($p['method']=='wicare.cache.getObj'){
			unset($p['app_key']);
		}
		$p["timestamp"]=date('Y-m-d H:i:s',strtotime('+8 hour'));
		ksort($p);
		$str="";
		$url=api_v2::api_url."?";
		$val="";
		foreach($p as $x=>$x_value) {
			$val=$this->encode($x_value);
			$str.=$x.$val;
			$url.=$x."=".$val."&";
		}
		if($p['method']!='wicare.cache.getObj'){
			$str=$opt['app_secret'].$str.$opt['app_secret'];
		}
		$sign=strtoupper(md5($str));
		$url.="sign=".$sign;
		// echo "$url<br>";
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

	function start($data,$opt=0){
		if($opt==0){
			$opt=array(
				'access_token'=>'565975d7d7d01462245984408739804d',
				'app_key'=>'96a3e23a32d4b81894061fdd29e94319',
				'app_secret'=>'565975d7d7d01462245984408739804d'
			);
		}
		$url=$this->makeUrl($data,$opt);
		$res=$this->sendHttp($url);
		return json_decode(characet($res),true);
	}
}

function characet($data){
	return $data;
}
