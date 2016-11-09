<?php
/**
  * 代理商公众号事件处理
  * 只处理从user.autogps.cn域名进来的事件
  * 
  *
  * 目前只处理扫描带参数二维码关注公众号的用户，需要根据openId判断是否预订过活动；
  * 如果已经预订，则注册，添加车辆，绑定设备
  * 由于用户是关注不同代理商的公众号，所以代理商公众号的微信appid和appsecret是通过$_GET获取的
  */
date_default_timezone_set('PRC'); 
include 'api_v2.php';
include 'papiApi.php';
include 'WX.php';
$API=new api_v2();//api接口类
$papi=new papiApi();
$opt=array(
    'access_token'=>'3a9557ed4250440ec57b53564e391cb50ada46ae97bc96c6abf0c3a7a3b501c3b7c93e803c9016924569a69f7e1d4222b39bb1bd39c70601cbcb8cbe953e0bfe',
    'app_key'=>'0642502f628a83433f0ba801d0cae4ef',
    'dev_key'=>'86e3ddeb8db36cbf68f10a8b7d05e7ac',
    'app_secret'=>'15fe3ee5197e8ba810512671483d2697'
);
//define your token
define("TOKEN", "baba");
trackHttp();
$wechatObj = new wechatCallbackapiTest();
if(isset($_GET['echostr'])){
    $wechatObj->valid();
}else{
    $wechatObj->responseMsg();
}


class wechatCallbackapiTest
{
	public function valid()
    {
        $echoStr = $_GET["echostr"];

        //valid signature , option
        if($this->checkSignature()){
        	echo $echoStr;
        	exit;
        }
    }

    public function responseMsg()
    {
		//get post data, May be due to the different environments
		$postStr = $GLOBALS["HTTP_RAW_POST_DATA"];
        addLog($postStr);

      	//extract post data
		if (!empty($postStr)){
                //解析xml
              	$postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);

                $RX_TYPE = trim($postObj->MsgType);

                switch($RX_TYPE){
                    case "event"://事件推送
                        $result = $this->receiveEvent($postObj);
                        break;
                    case "text"://收到文字信息
                        $result = $this->receiveText($postObj);
                        break;
                    case "image"://收到图片信息
                        $result = $this->receiveImage($postObj);
                        break;
                    case "voice"://收到语言信息
                        $result = $this->receiveVoice($postObj);
                        break;
                    case "location"://收到位置信息
                        $result = $this->receiveLocation($postObj);
                        break;
                    default:
                        $result = "unknow msg type:".$RX_TYPE;
                }
                addLog($result);
                echo $result;
        }else {
        	echo "";
        	exit;
        }
    }
		
	private function checkSignature()
	{
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];	
        		
		$token = TOKEN;
		$tmpArr = array($token, $timestamp, $nonce);
		sort($tmpArr, SORT_STRING);
		$tmpStr = implode( $tmpArr );
		$tmpStr = sha1( $tmpStr );
		
		if( $tmpStr == $signature ){
			return true;
		}else{
			return false;
		}
	}

    private function receiveEvent($object){
        $content = "";
        switch($object->Event){
            case "subscribe"://未关注扫描二维码
                if(isset($object->EventKey)){//扫描带参数的二维码关注的
                    $scene=substr($object->EventKey,8);
                    $content=$this->switchScene($scene,$object->FromUserName);
                }
                break;
            case "SCAN"://已关注扫描二维码
                $content='感谢您的扫描';
                if(isset($object->EventKey)){//扫描带参数的二维码关注的
                    $scene=$object->EventKey; 
                    $content=$this->switchScene($scene,$object->FromUserName);
                }
                break;
            case "unsubscribe"://取消关注
                $content = "";
                break;
            case "CLICK"://点击菜单事件
                switch($object->EventKey){
//                    case "V1001_BIND_USER":
//                        $content = "<a href='https://open.weixin.qq.com/connect/oauth2/authorize?appid=wx789e9656ed870ea9&redirect_uri=http://php.bibibaba.cn/oauth2.php&response_type=code&scope=snsapi_base&state=1#wechat_redirect'>点击绑定用户</a>";
//                        break;
                }
                break;
        }
        $result = $this->transmitText($object, $content);
        return $result;
    }

    private function switchScene($did,$open_id) {
        global $opt,$API;

        $device=$API->start(array(//验证设备
            'method'=>'wicare._iotDevice.get',
            'did'=>$did,
            'fields'=>'objectId,uid,model,modelId,binded,bindDate,vehicleName,vehicleId,serverId'
        ),$opt);
        if(!$device||!isset($device['data'])){//已经有账号
            return '未查找到设备';
        }
        $device=$device['data'];
        if($device['binded']){//已经有账号
            return '设备已被绑定';
        }

        //先尝试使用openId登录
        $user=$API->start(array(
            'authData.openId' => $open_id,
            'method'=>'wicare.user.sso_login'
        ),$opt);
        if(isset($user['access_token'])){//已经有账号
            return '您已注册，请进入系统进行设备绑定';
        }

        $booking=$API->start(array(//获取预订信息
            'method'=>'wicare.booking.get',
            'openId'=>$open_id,
            'status'=>0,
            'fields'=>'objectId,activityId,mobile,sellerId,uid,name,carType,installId'
        ),$opt);
        if(!$booking||!$booking['data'])
            $content='设备IMEI：'.$did.'，<a href="http://user.autogps.cn/?location=%2Fwo365_user%2Fregister.html&intent=logout&needOpenId=true&wx_app_id='.$_GET['wxAppKey'].'">请点击注册</a>';
        else{
            $booking=$booking['data'];
            $user=$API->start(array(
                'method'=>'wicare.user.get',
                'mobile'=>$booking['mobile'],
                'fields'=>'objectId,mobile'
            ),$opt);
            if(isset($user['objectId'])){//已经有账号
                return '您已注册，请进入系统进行设备绑定';
            }
            $p_user=json_decode($papi->register(array(
                'phone'=>$booking['mobile'],
                'pswd'=>substr($booking['mobile'],-6),
                'imei'=>$did
            )),true);
            if($p_user['error']){
                return '注册失败，'.$p_user['errormsg'];
            }
            $user=$API->start(array(//添加用户表
                'method'=>'wicare.user.create',
                'mobile'=>$booking['mobile'],
                'password'=>md5(substr($booking['mobile'],-6)),
                'userType'=>7,
                'authData'=>array('openId'=>$open_id)
            ),$opt);
            $cust=$API->start(array(//添加用户表
                'method'=>'wicare.customer.create',
                'tel'=>$booking['mobile'],
                'name'=>$booking['name'],
                'parentId'=>array($device['uid']),
                'uid'=>$user['objectId'],
                'userType'=>7,
                'custType'=>'私家车主',
                'contact'=>$booking['name']
            ),$opt);
            $car=$API->start(array(//添加车辆
                'method'=>'wicare.vehicle.create',
                'name'=>$booking['carType']['car_num'],
                'uid'=>$cust['objectId'],
                'did'=>$did,
                'deviceType'=>$device['model']
            ),$opt);
            
            $dev=$API->start(array(//绑定设备
                'method'=>'wicare._iotDevice.update',
                '_did'=>$did,
                'binded'=>true,
                'bindDate'=>date("Y-m-d h:i:sa"),
                'vehicleName'=>$booking['carType']['car_num'],
                'vehicleId'=>$car['objectId'],
            ),$opt);

            $bo=$API->start(array(//更新预订信息
                'method'=>'wicare.booking.update',
                '_objectId'=>$booking['objectId'],
                'status'=>1,
                'status1'=>1,
                'resTime'=>date("Y-m-d h:i:sa"),
                'did'=>$did
            ),$opt);
            //添加出入库记录
            $pro=$API->start(array(
                'method'=>'wicare.product.get',
                'objectId'=>$device['modelId'],
                'fields'=>'brand,brandId,name,objectId'
            ),$opt);
            $MODEL=array(
                'brand'=>$pro['data']['brand'],
                'brandId'=>$pro['data']['brandId'],
                'model'=>$pro['data']['name'],
                'modelId'=>$pro['data']['objectId'],
            );
            $log=array(
                'method'=>'wicare.deviceLog.create',
                'did'=>array($did),
                'from'=>$device['uid'],
                'fromName'=>'',
                'to'=>$cust['objectId'],
                'toName'=>$booking['name'],
                'status'=>2
            );
            $popLog=array(//出库
                'uid'=>$device['uid'],
                'type'=>0,
                'inCount'=>0,
                'outCount'=>1
            );
            $pushLog=array(//下级的入库
                'uid'=>$cust['objectId'],
                'type'=>1,
                'inCount'=>1,
                'outCount'=>0
            );
            $popLog=array_merge($popLog,$log,$MODEL);
            $pushLog=array_merge($pushLog,$log,$MODEL);
            //给上一级添加出库信息
            $API->start($popLog,$opt);
            //给下一级添加入库信息
            $API->start($pushLog,$opt);
            $content='注册成功';
        }
        return $content;
    }

    private function receiveText($object){
        $content = "有任何的问题，可以咨询我们的客服哦！";
        // $lottery = getLottery($object->FromUserName);
        // addlog($lottery);
        // $c = $lottery["total"];
        // if($c > 0){
        //     $content = $content."<a href=\"http://m.bibibaba.cn/\">您的账户里还躺着".$c."个红包，请立即收取</a>";
        // }
        $result = $this->transmitText($object, $content);
        return $result;
    }

    private function receiveImage($object){
        $result = $this->transmitImage($object, $object->MediaId);
        return $result;
    }

    private function receiveVoice($object){
        //$result = $this->transmitVoice($object, $object->MediaId);
        $content = $object->Recognition;
        $result = $this->transmitText($object, $content);
        return $result;
    }

    private function receiveLocation($object){
        $content = $object->Label;
        $result = $this->transmitText($object, $content);
        return $result;
    }

    private function transmitText($object, $content){
        $textTpl = "<xml><ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[text]]></MsgType>
							<Content><![CDATA[%s]]></Content>
							<FuncFlag>0</FuncFlag>
							</xml>";
        $result = sprintf($textTpl, $object->FromUserName, $object->ToUserName, time(), $content);

        return $result;
    }

    private function transmitImage($object, $mediaId){
        $textTpl = "<xml><ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[image]]></MsgType>
							<Image><MediaId><![CDATA[%s]]></MediaId></Image>
							<FuncFlag>0</FuncFlag>
							</xml>";
        $result = sprintf($textTpl, $object->FromUserName, $object->ToUserName, time(), $mediaId);
        return $result;
    }

    private function transmitVoice($object, $mediaId){
        $textTpl = "<xml><ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[voice]]></MsgType>
							<Voice><MediaId><![CDATA[%s]]></MediaId></Voice>
							<FuncFlag>0</FuncFlag>
							</xml>";
        $result = sprintf($textTpl, $object->FromUserName, $object->ToUserName, time(), $mediaId);
        return $result;
    }
}

function getAccessToken() {
    include 'WX.php';
    $wx=new WX($_GET['appid'],$_GET['appsecret']);
    //测试
    // $wx=new WX('wx9b96a6e2d701fb94','7161735b615cfe687eaa287e64fe5cfa');
    $res=$wx->getToken();
    return $res['access_token'];
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

function getLottery($open_id){
    $d=date('Y-m-d H:i:s',strtotime('+8 hour'));
    $D=date('Y-m-d%20H:i:s',strtotime('+8 hour'));
    $str='21fb644e20c93b72773bf0f8d0905052app_key9410bc1cbfa8f44ee5f8a331ba8dd3fcfieldscode,lottery_idformatjsonis_receive0limit-1max_id0methodwicare.lottery_logs.listmin_id0open_id'.$open_id.'pagesign_methodmd5sortstimestamp'.D.'v1.021fb644e20c93b72773bf0f8d0905052';
    $sing=strtoupper(md5($str));

    $url = 'http://o.bibibaba.cn/router/rest?timestamp='.D.'&format=json&app_key=9410bc1cbfa8f44ee5f8a331ba8dd3fc&v=1.0&sign_method=md5&method=wicare.lottery_logs.list&open_id='.$open_id.'&is_receive=0&fields=code%2Clottery_id&sorts=&page=&max_id=0&min_id=0&limit=-1&sign='.$sing;
    $info_json = httpGet($url);
    $info_array = json_decode($info_json, true);
    return $info_array;
}

function trackHttp(){
    $content = date('Y-m-d H:i:s'). "\nREMOTE_ADDR:".$_SERVER["REMOTE_ADDR"]."\nQUERYSTRING:".$_SERVER["QUERY_STRING"]."\n\n";
    addlog($content);
}

function addLog($content){
    $max_size = 100000;
    $log_filename = "log.xml";
    if(file_exists($log_filename) and (abs(filesize($log_filename)) > $max_size)){
        unlink($log_filename);
    }
    file_put_contents($log_filename, $content, FILE_APPEND);
}

?>