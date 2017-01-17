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
                $content = "感谢关注。";
                if(isset($object->EventKey)&&$object->EventKey){//扫描带参数的二维码关注的
                    $scene=substr($object->EventKey,8);
                    $content=$this->switchScene($scene,$object->FromUserName);
                }
                break;
            case "SCAN"://已关注扫描二维码
                $content='感谢您的扫描';
                if(isset($object->EventKey)&&$object->EventKey){//扫描带参数的二维码关注的
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
            return '二维码不正确，无法获取相关信息'.json_encode($id);
        $d=$id['data']['data']['type'];
        $scene=$id['data']['data']['data'];
        switch ($d) {
            case '1000'://imei号
                return $this->register($scene,$open_id);
            case '1001'://
                return $this->booking($scene,$open_id);
                break;
            default:
                return '感谢扫码关注';
        }
    }

    private function booking($booking_id,$open_id){
        global $opt,$API,$longTimeTask;
        $booking=$API->start(array(//获取预订信息
            'method'=>'wicare.booking.get',
            'objectId'=>$booking_id,
            'status'=>0,
            'fields'=>'objectId,activityId,mobile,sellerId,sellerName,uid,name,openId,type,userName,userMobile,carType,createdAt,payStatus,orderId,payMoney,product'
        ),$opt);
        if(!$booking['data'])
            return '无预订信息';
        if($booking['data']['type']==0&&$booking['data']['openId']!=$open_id){//为自己预订且不是车主
            pfb::addLog('微信appid'.$_GET['wxAppKey'].'---感谢关注open_id：'.$open_id.'---bookingOpenId：'.$booking['data']['openId']);
            return '感谢关注';
        }
        $booking=$booking['data'];
        $product=$booking['product'];
        $pro=$API->start(array(//获取产品品牌
            'method'=>'wicare.product.get',
            'objectId'=>$product['id'],
            'fields'=>'objectId,name,company,uid,brand,brandId'
        ),$opt);
        if($pro['data'])
            $product['brand']=$pro['data']['brand'];
        else
            $product['brand']='';

        $activity=$API->start(array(//校验是否有这个活动
            'method'=>'wicare.activity.get',
            'objectId'=>$booking['activityId'],
            'fields'=>'name,price,installationFee,deposit,product'
        ),$opt);
        if(!$activity['data'])
            return '无活动信息';
        if($booking['payMoney']!=0)
            $pay=number_format($booking['payMoney'],2);
        else
            $pay='0.00';
            
        //loginLocation参数需经过两次编码
        $in_url='http://'.api_v2::$domain['user'].'/?loginLocation=%252Fwo365_user%252Forder.html%253FbookingId%253D'.$booking['objectId'].'&wx_app_id='.$_GET['wxAppKey'];

        $remark='点击详情选择授权安装网点';
        if($booking['type']==1&&$booking['openId']==$open_id){//为他人预订
            $remark='点击详情按提示将预订信息发送给车主选择安装网点';
        }

        $date=date("Y-m-d H:i",strtotime($booking['createdAt']));
        $user=$booking['userName'].'/'.$booking['userMobile'];
        $p=$product['brand'].' '.$product['name'];
        $title='订单ID：'.$booking['objectId'];
        $_spare='订单ID：'.$booking['objectId'].'
预订时间：'.$date.'
预订产品：'.$p.'
预付款项：'.$pay.'
车主信息：'.$user.'
预付款：'.$pay.'
'.$remark.'
<a href="'.$in_url.'">详情</a>';
        $wei=pfb::getWeixin($_GET['wxAppKey'],-1);
        //保存一个匿名方法，把响应返回给微信之后调用
        //发送推送给营销人员，或者是车主（推荐有礼）
        $longTimeTask[count($longTimeTask)]=function() use($wei,$title,$date,$p,$pay,$user,$booking,$in_url){
            global $opt,$API;
            $booking_id=$booking['objectId'];
            //loginLocation参数需经过两次编码
            $url='http://'.api_v2::$domain['wx'].'/?loginLocation=%252Fautogps%252Forder.html%253FbookingId%253D'.$booking['objectId'].'&wx_app_id=';
            if($booking['carType']['qrStatus']==1){
                return;
            }
            $r=$API->start(array(
                'method'=>'wicare.booking.update',
                '_objectId'=>$booking_id,
                'carType.qrStatus'=>'1'
            ),$opt);
            pfb::addLog('预订信息'.json_encode($booking));
            if($r['status_code']&&$r['status_code']!=0){
                pfb::addLog('预订'.$booking_id.'更新qrStatus出错：'.$r['status_code']);
            }
            $emp=$API->start(array(//获取人员信息
                'method'=>'wicare.employee.get',
                'objectId'=>$booking['sellerId'],
                'fields'=>'uid,name,objectId,companyId'
            ),$opt);
            pfb::addLog('获取人员id：'.$booking['sellerId'].'；获取到的信息'.json_encode($emp));
            if($emp&&$emp['data']){//推荐人是一个员工
                $uid=$emp['data']['uid'];
                $wei=pfb::getWeixin($emp['data']['companyId']);
                if(!$wei)return;
                $in_url=$url.$wei['wxAppKey'];
                $open_id=pfb::getOpenId($uid);
            }else{//车主或者管理员
                $cust=$API->start(array(//获取人员信息
                    'method'=>'wicare.customer.get',
                    'objectId'=>$booking['sellerId'],
                    'fields'=>'uid,name,objectId,custTypeId'
                ),$opt);
                if(!$cust||!$cust['data'])return;
                if($cust['data']['custTypeId']!=7){//不是车主
                    $wei=pfb::getWeixin($cust['data']['objectId']);
                    if(!$wei)return;
                    $uid=$emp['data']['uid'];
                    $in_url=$url.$wei['wxAppKey'];
                    $open_id=pfb::getOpenId($uid);
                }else{//是车主的话
                    $seller_user=pfb::getUser($uid);
                    $key=api_v2::getOpenIdKey(api_v2::$domain['user']);//获取车主公众号的openid
                    if($seller_user&&$seller_user['authData']&&$seller_user['authData'][$key]){
                        $open_id=$seller_user['authData'][$key];
                    }else{
                        return false;
                    }
                }
            }
            pfb::addLog('获取销售人员openId：'.$open_id);
            if(!$open_id)return false;
            $remark='预订人：'.$booking['name'].'/'.$booking['mobile'];
            $res=sendBookingSuccess($wei,$open_id,$title,$date,$p,$pay,$user,$remark,$in_url);
            pfb::addLog('给营销人员推送返回：'.json_encode($res));
        };
        if(!$wei){
            return $_spare;
        }
        $res=sendBookingSuccess($wei,$open_id,$title,$date,$p,$pay,$user,$remark,$in_url);
        if($res['errcode'])//如果出错则推送文字
            return $_spare;
        else
            return '';
    }

    private function register($did,$open_id){
        global $opt,$API,$longTimeTask;
        $content='';
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

        $cust=$API->start(array(//获取当前拥有者
            'method'=>'wicare.customer.get',
            'objectId'=>$device['uid'],
            'fields'=>'objectId,name,tel'
        ),$opt);
        if(!$cust||!isset($cust['data'])||$cust['data']['custTypeId']==7){
            return '未查找到设备拥有者';
        }
        $cust=$cust['data'];

        //先尝试使用openId登录
        $openIdKey=api_v2::getOpenIdKey();
        $user=$API->start(array(
            'authData.'.$openIdKey => $open_id,
            'method'=>'wicare.user.sso_login'
        ),array(
            'app_key'=>"5775b54f2e44e702a4c975c064ec2efd",
            'app_secret'=>"46f011a3b2503226c5f3a55a56e9ac05"
        ));

        $booking=$API->start(array(//获取预订信息
            'method'=>'wicare.booking.list',
            'userOpenId'=>$open_id,
            'installId'=>$device['uid'],
            'status'=>0,
            'fields'=>'objectId,type,activityId,sellerId,uid,mobile,name,openId,carType,install,installId,userMobile,userName,userOpenId,payMoney,orderId,product'
        ),$opt);
        $link='http://'.api_v2::$domain['user'].'/?location=%2Fwo365_user%2Fregister.html&intent=logout&needOpenId=true&wx_app_id='.
            $_GET['wxAppKey']
            .'&did='.$did   //设备imei
            .'&openid='.$open_id    //扫码人openId
            .'&installId='.$device['uid']//设备拥有者，就是安装网点
            .'&modelId='.$device['modelId'];//产品型号id
        if($user['access_token']){
            $link.='&token='.$user['access_token'].'&uid='.$user['uid'];  //扫码人的user id，如果有账号的话
        }
        $remark='点击详情继续注册';
        if($booking&&$booking['data']){
            $bookings=$booking['data'];
            $len=count($bookings);
            if($len>0){
                if($len>1)//如果不止一条预订，则通过bookingId=true通知前端页面
                    $link=$link.'&bookingId=more';
                else{ //只有一条预订，直接传递给前端
                    $booking=$bookings[0];
                    $link=$link.'&bookingId='.$booking['objectId'];
                }
            }
        }

        $pro=$API->start(array(//获取产品品牌
            'method'=>'wicare.product.get',
            'objectId'=>$device['modelId'],
            'fields'=>'objectId,name,company,uid,brand,brandId'
        ),$opt);
        $product=$pro['data'];
        
        $wei=pfb::getWeixin($_GET['wxAppKey'],-1);
        if(!$wei){
            return $content;
        }
        $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
        $tem=$wei['template']['OPENTM408183089'];
        $res=$wx->sendWeixin($open_id,$tem,'
        {
            "first": {
                "value": "",
                "color": "#173177"
            },
            "keyword1": {
                "value": "'.$cust['name'].'",
                "color": "#173177"
            },
            "keyword2": {
                "value": "'.$product['brand'].' '.$device['model'].'",
                "color": "#173177"
            },
            "keyword3": {
                "value": "'.$did.'",
                "color": "#173177"
            },
            "remark": {
                "value": "'.$remark.'",
                "color": "#173177"
            }
        }',$link);
        pfb::addLog('发送返回'.json_encode($res));
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
        if(!$content||$content==""||!isset($content))
            $result='';
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

function sendBookingSuccess($wei,$open_id,$title,$date,$p,$pay,$user,$remark,$in_url){
    $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
    $tem=$wei['template']['OPENTM408168978'];
    $res=$wx->sendWeixin($open_id,$tem,'
    {
        "first": {
            "value": "'.$title.'",
            "color": "#173177"
        },
        "keyword1": {
            "value": "'.$date.'",
            "color": "#173177"
        },
        "keyword2": {
            "value": "'.$p.'",
            "color": "#173177"
        },
        "keyword3": {
            "value": "'.$pay.'",
            "color": "#173177"
        },
        "keyword4": {
            "value": "'.$user.'",
            "color": "#173177"
        },
        "remark": {
            "value": "'.$remark.'",
            "color": "#173177"
        }
    }',$in_url);
    return json_decode($res,true);
}
?>