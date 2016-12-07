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
include 'checkAndPay.php';

$API=new api_v2();//api接口类
$papi=new papiApi();

//define your token
define("TOKEN", "baba");
trackHttp();
$wechatObj = new wechatCallbackapiTest();
if(isset($_GET['echostr'])){
    $wechatObj->valid();
}else{
    $wechatObj->responseMsg();
}

$longTimeTask=array();//保存一组方法，在返回响应给微信后会依次执行

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
                doLongTimeTask();//执行耗时代码
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
        global $opt,$API;
        $content = "";
        switch($object->Event){
            case "subscribe"://未关注扫描二维码
                $content = "欢迎您进入WiCARE车联网世界。";
                $reg='http://user.autogps.cn/?location=%2Fwo365_user%2Fregister.html&intent=logout&needOpenId=true&wx_app_id='.$_GET['wxAppKey'];
                $my='http://user.autogps.cn/?loginLocation=%2Fwo365_user%2Fsrc%2Fmoblie%2Fmy_account&wx_app_id='.$_GET['wxAppKey'];
                $home='http://user.autogps.cn/?wx_app_id='.$_GET['wxAppKey'];

                $wei=$API->start(array(
                    'method'=>'wicare.weixin.get',
                    'wxAppKey'=>$_GET['wxAppKey'],
                    'fields'=>'menu'
                ),$opt);
                if($wei['data']&&$wei['data']['menu'])
                    $menu=json_encode($wei['data']['menu']).',';
                else
                    $menu='';
                // 设置菜单
                $jsonmenu = '{
                    "button": [
                        {
                            "type": "view",
                            "name": "我的主页",
                            "url": "'.$home.'"
                        },
                        '.$menu.'
                        {
                            "name": "更多",
                            "sub_button": [
                                {
                                    "type": "view",
                                    "name": "注册",
                                    "url": "'.$reg.'"
                                },
                                {
                                    "type": "view",
                                    "name": "我的账号",
                                    "url": "'.$my.'"
                                },
                                {
                                    "type": "view",
                                    "name": "车主推荐",
                                    "url": "#"
                                }
                            ]
                        }
                    ]
                }';
                $acess = getAccessToken();
                $url = "https://api.weixin.qq.com/cgi-bin/menu/create?access_token=" . $acess;
                $result = httpPost($url, $jsonmenu);

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

    private function switchScene($scene,$open_id) {
        global $opt,$API;
        $id=$API->start(array(
            'method'=>'wicare.qrData.get',
            'id'=>$scene,
            'fields'=>'data'
        ),$opt);
        if(!$id['data'])
            return '二维码不正确，无法获取相关信息';
        $d=$id['data']['data']['type'];
        $scene=$id['data']['data']['data'];
        switch ($d) {
            case '1000'://imei号
                return $this->register($scene,$open_id);
            case '1001'://
                return $this->booking($scene,$open_id);
                break;
            default:
                return '功能编号未设置'.$d.$scene;
        }
    }

    private function booking($booking_id,$open_id){
        global $opt,$API;
        $booking=$API->start(array(//获取预订信息
            'method'=>'wicare.booking.get',
            'objectId'=>$booking_id,
            'status'=>0,
            'fields'=>'activityId,mobile,sellerId,sellerName,uid,name,openId,type,userName,userMobile,carType,createdAt,payStatus,payMoney'
        ),$opt);
        if(!$booking['data'])
            return '无预订信息';
        if($booking['data']['type']==0&&$booking['data']['openId']!=$open_id)//为自己预订且不是车主
            return '感谢关注';

        $remark='';
        if($booking['data']['type']==1&&$booking['data']['openId']==$open_id){//为他人预订
            $remark='请分享此二维码给'.$booking['data']['userName'].'（车主）以便其获取授权安装网点信息。<a href="'.$booking['data']['carType']['qrUrl'].'">【二维码】</a>';
        }

        $activity=$API->start(array(//获取预订信息
            'method'=>'wicare.activity.get',
            'objectId'=>$booking['data']['activityId'],
            'fields'=>'name,price,installationFee,deposit,product'
        ),$opt);
        if(!$activity['data'])
            return '无活动信息';
        $pay='未预付';
        if($booking['data']['payStatus']){
            if($booking['data']['payStatus']==1)
                $pay='订金：'.$booking['data']['payMoney'];
            else if($booking['data']['payStatus']==2)
                $pay='全款+安装费：'.$booking['data']['payMoney'];
        }
        $in_url='http://user.autogps.cn/autogps/booking_install.html?intent=logout&needOpenId=true&bookingId='.$booking['data']['objectId'].'&wx_app_id='.$_GET['wxAppKey'];
        return  $activity['data']['name'].'
预订时间：'.date("Y-m-d h:i",strtotime($booking['data']['createdAt'])).'
预订人：'.$booking['data']['name'].'/'.$booking['data']['mobile'].'
客户：'.$booking['data']['userName'].'/'.$booking['data']['userMobile'].'
产品型号：'.$activity['data']['product'].'/'.$activity['data']['price'].'元（安装费用：'.$activity['data']['installationFee'].'）
预付款：'.$pay.'
<a href="'.$in_url.'">请点击进入选择安装网点</a>
'.$remark;
    }

    private function register($did,$open_id){
        global $opt,$API,$longTimeTask;

        $device=$API->start(array(//验证设备
            'method'=>'wicare._iotDevice.get',
            'did'=>$did,
            'fields'=>'objectId,did,uid,model,modelId,binded,bindDate,vehicleName,vehicleId,serverId'
        ),$opt);
        if(!$device||!isset($device['data'])){
            return '未查找到设备';
        }
        if($device['data']['binded']){//已经有账号
            return '设备已被绑定';
        }
        $device=$device['data'];

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
            'fields'=>'objectId,activityId,mobile,sellerId,uid,name,carType,installId,userMobile,userName'
        ),$opt);
        if(!$booking||!$booking['data'])
            $content='设备IMEI：'.$did.'，<a href="http://user.autogps.cn/?location=%2Fwo365_user%2Fregister.html&intent=logout&needOpenId=true&wx_app_id='.$_GET['wxAppKey'].'">请点击注册</a>';
        else{
            //检查用户是否已注册
            $booking=$booking['data'];
            $phone=$booking['userMobile'];
            $name=$booking['userName'];
            if(!$phone){
                $phone=$booking['mobile'];
                $name=$booking['name'];
            }
            $user=$API->start(array(//查找一下是否已注册
                'method'=>'wicare.user.get',
                'mobile'=>$phone,
                'fields'=>'objectId,mobile,authData'
            ),$opt);
            if(!$user||!$user['data']){
                $user=$API->start(array(//添加用户表
                    'method'=>'wicare.user.create',
                    'mobile'=>$phone,
                    'password'=>md5(substr($phone,-6)),
                    'userType'=>7,
                    'authData'=>array('openId'=>$open_id)
                ),$opt);
            }else{
                $user=$user['data'];
            }
            $cust=$API->start(array(
                'method'=>'wicare.customer.get',
                'uid'=>$user['objectId'],
                'fields'=>'objectId,tel'
            ),$opt);
            if(isset($cust['data'])){//已经有账号
                return '您已注册，请进入系统进行设备绑定';
            }
            
            $cust=$API->start(array(//添加客户表
                'method'=>'wicare.customer.create',
                'tel'=>$phone,
                'name'=>$name,
                'parentId'=>array($device['uid']),
                'uid'=>$user['objectId'],
                'userType'=>7,
                'custType'=>'私家车主',
                'contact'=>$name
            ),$opt);

            //添加车辆绑定设备
            $device=addAndBind($cust['objectId'],'默认车牌',$device,$open_id,$phone,$name,$booking);

            //保存一个匿名方法，把响应返回给微信之后调用
            $i=count($longTimeTask);
            $longTimeTask[$i]=function() use($device,$did,$cust,$booking,$phone){
                global $opt,$API,$papi;
                $p_user=json_decode($papi->register(array(
                    'phone'=>$phone,
                    'pswd'=>substr($phone,-6),
                    'imei'=>$did
                )),true);
                if($p_user['error']){
                    $error='注册失败，'.$p_user['errormsg'];
                }
                addDeviceLog($device,$cust['objectId'],$booking['name'],$booking);
            };
            $content='注册成功';
        }
        return $content;
    }

    private function receiveText($object){
        if($object->Content=='test_server')
            $content = "服务器url配置成功！";
        else
            $content = "如果有问题请留言，我们将会在2个工作日内回复";
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
    $wx=new WX($_GET['wxAppKey'],$_GET['wxAppSecret']);
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

//马上输出返回给微信，断开与客户端的连接，再执行某些耗时代码
function doLongTimeTask(){
    global $longTimeTask;
    header("Content-Length: $size"); //告诉浏览器数据长度,浏览器接收到此长度数据后就不再接收数据
    header("Connection: Close"); //告诉浏览器关闭当前连接,即为短连接
    ob_flush();
    flush();
    //$longTimeTask是一个全局方法数组，在本页脚本内任意地方push进去，在这里遍历执行它
    $arrlength=count($longTimeTask);
	for($i=0;$i<$arrlength;$i++){
		$longTimeTask[$i]();
	}
}

?>