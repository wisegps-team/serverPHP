<?php
/****papiApi类****/
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
	function login($post_data){
		$url=papiApi::api_url.'login';
		return $this->api($url,$post_data,'post');
	}

	/**
	 * 注册
	 */
	function register($post_data){
		$url=papiApi::api_url.'createaccountbindimei';
		return $this->api($url,$post_data,'post');
	}

	/**
	 * 修改密码
	 * 	data{
	 * 		account:13564564,
	 * 		password:123456,
	 * 		oldpwd:1234
	 * }
	 */
	function resetPassword($post_data){
		$url=papiApi::api_url.'updatepassword';
		return $this->api($url,$post_data,'post');
	}

	/**
	 * 绑定设备
	 * data{
	 * 		mobile:13564564,
	 * 		did:123456,
	 * }
	 */
	function deviceBind($post_data){
		$url=papiApi::api_url.'devicebind';
		return $this->api($url,$post_data,'post');
	}

	/**
	 * 解绑设备
	 * data{
	 * 		mobile:13564564,
	 * 		did:123456,
	 * }
	 */
	function deviceUnbind($post_data){
		$url=papiApi::api_url.'deviceunbind';
		return $this->api($url,$post_data,'post');
	}

	/**
	 * 获取当前经纬度
	 * data{
	 * 		map:0,
	 * 		did:123456,
	 * }
	 */
	function getPosition($post_data){
		$url=papiApi::api_url.'getdeviceposition';
		return $this->api($url,$post_data,'get');
	}

	/**
	 * 历史轨迹
	 * data{
	 * 		map:0,
	 * 		did:123456,
	 *		date: 查询日期(只能按天查询，日期格式2016-04-07)
	 * }
	 */
	function getwheelpath($post_data){
		$url=papiApi::api_url.'getwheelpath';
		return $this->api($url,$post_data,'get');
	}

	/**
	 * 远程设置防盗
	 * data{
	 * 		steal:震动防盗灵敏度，只能为(0,1,2)中的一个值，('0':'低','1':'中','2':'高')
	 *		alertdelay:震动报警延迟设定值，只能为(0,10,20,30,40,50,60)中的一个值，单位为秒
	 * 		did:123456,
	 * }
	 */
	function remoteConfig($post_data){
		$url=papiApi::api_url.'remoteconfig';
		return $this->api($url,$post_data,'get');
	}
}