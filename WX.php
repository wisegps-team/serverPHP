<?php
class WX{
	const wx_url="https://api.weixin.qq.com/cgi-bin/";
	function __construct($appId="wxa5c196f7ec4b5df9",$appSecret="e89542d7376fc479aac35706305fc23f",$fileName="tokenAndTicket.json") {
	    $this->appId=$appId;
	    $this->appSecret=$appSecret;
	    $this->tokenUrl=WX::wx_url."token?grant_type=client_credential&appid=".$appId."&secret=".$appSecret;
	    $this->ticketUrl=WX::wx_url."ticket/getticket?type=jsapi&access_token=";
	    // $this->fileName=$_SERVER['DOCUMENT_ROOT'].'/baba/wx/wslib/toolkit/'.$appId.$fileName;
	    $this->fileName='tokenAndTicket/'.$appId.$fileName;
	}

	//刷新token和ticket
	protected function refalseTokenAndTicketUrl(){
		//获取token
		$res=$this->httpGet($this->tokenUrl);
		$token_json=json_decode($res,true);

		//获取ticket
		$res=$this->httpGet($this->ticketUrl.$token_json['access_token']);	
		$json=json_decode($res,true);

		$token_json['ticket']=$json['ticket'];
		$token_json['time']=time();
		//保存在文件里
		file_put_contents($this->fileName,json_encode($token_json));
		return $token_json;
	}

	function getTokenAndTicket(){
		if(file_exists($this->fileName)){//检查一下文件，是否有ticket
			$tokenAndTicket=file_get_contents($this->fileName);
			$json=json_decode($tokenAndTicket,true);
			$expires=$json['expires_in']/2-(time()-$json["time"]);
			if($expires>0){//检查是否过期
				$json['expires']=$expires;
				return $json;
			}
		}
		$json=$this->refalseTokenAndTicketUrl();
		$json['expires']=$json['expires_in']/2;
		return $json;
	}

	//获取凭证
	function getToken() {
		$json=$this->getTokenAndTicket();
		return array("access_token"=>$json['access_token'],"expires"=>$json['expires']);
	}

	//获取票据
	function getTicket(){
		$json=$this->getTokenAndTicket();
		return array("ticket"=>$json['ticket'],"expires"=>$json['expires']);
	}

	//生成二维码
	function getQrcode($msg){
		$token=$this->getToken();
		$url=WX::wx_url.'qrcode/create?access_token='.$token['access_token'];
		$json=json_decode($this->httpPost($url,$msg),true);
		$json['url']='https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket='.$json['ticket'];
		return $json;
	}

	//发送模板消息
	function sendWeixin($open_id,$template_id,$data,$link){
		$token=$this->getToken();
		$url = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . $token['access_token'];
		$data = '{"touser":"' . $open_id . '","template_id":"'. $template_id .'","url":"'. $link .'",' .
		    '"data": '. $data . '}';
		return $this->httpPost($url, $data);
	}

	//获取并添加模板
	function addTemplate($template_id){
		$tem=$this->getToken();
		$token=$tem['access_token'];
		$url='https://api.weixin.qq.com/cgi-bin/template/api_add_template?access_token='.$token;
		$data='{
           "template_id_short":"'.$template_id.'"
       	}';
		return json_decode($this->httpPost($url, $data),true);
	}



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

	function httpPost($url, $msg){
	    $ch = curl_init($url);
	    curl_setopt($ch, CURLOPT_POSTFIELDS, $msg);
	    curl_setopt($ch, CURLOPT_ENCODING ,'utf-8');
	    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	    $result = curl_exec($ch);
	    if(curl_errno($ch)){
	        echo 'ERROR: '.curl_error($ch).'<br />';
	    }
	    curl_close($ch);
	    return $result;
	}
}