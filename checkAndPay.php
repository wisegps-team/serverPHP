<?php
//检查订单和预订状态，支付预付款给商家和支付佣金给营销人员，并且推送余额变动

//如果余额不足推送余额不足要求充值信息
// include 'api_v2.php';
// include 'WX.php';
$API=new api_v2();//api接口类

/**
 * 返回码说明：
 * 0：支付成功
 * 1：uid查找不到对应用户
 * 2：推荐人无法获取
 * 3：营销微信未配置
 * 4：找不到对应订单
 * 5：指定订单未支付成功
 * 6：指定订单与预订表金额不一致
 */
//参数说明，$booking订单对象，$cid公司id，$device带营销产品参数的对象，$payAll标识是否进行预付款到账
function checkAndPay($booking,$cid,$device,$payAll){
    global $opt,$API;
    $sid=$device['serverId'];//微信公众号提供者
    $wei=pfb::getWeixin($sid);
    pfb::addLog('商户微信wei:'.$wei['wxAppKey'].'返回这个'.$wei['wxAppSecret']);
    if(!$wei){
        return 3;
    }
    $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);

    $cust=pfb::getCustomer($cid);//customer表记录
    pfb::addLog('商户信息cust:'.json_encode($cust));
    $pay_user=pfb::getPayUser($cust);//商户公帐
    pfb::addLog('公帐信息pay_user:'.json_encode($pay_user));
    $user=pfb::getUser($cust['uid']);//管理员user表记录
    pfb::addLog('管理员信息user:'.json_encode($user));
    $cust_openId=pfb::getOpenId($user);//商户管理员的openId
    $bill_type='预付款到账';
    $err_remark='请联系技术人员处理';
    $amount=0;
    $booking_id=$booking['objectId'];

    //给预订人发送微信推送
    if($booking['type']==1)
        pfb::sendToBooker($sid,$booking,$device,$cust);

    if($payAll)
        pfb::addLog('payAll&&booking[payMoney]:'.$booking['payMoney']);
    else
        pfb::addLog('payAll&&booking[payMoney]:'.$booking['payMoney'].'支付了这个');
    if($booking['payMoney']){//如果有预付款,检查订单
        $order=$API->start(array(
            'method'=>'wicare.order.get',
            'oid'=>$booking['orderId']
        ),$opt);
        pfb::addLog('orderId:'.$booking['orderId']);
        pfb::addLog('order订单信息:'.json_encode($order));
        if(pfb::dataNull($order)){//找不到对应的预付订单
            pfb::addLog('找不到对应的预付订单');
            $_r=pfb::sendError($wx,$wei,$user,'您有一个预订订单到账失败',$booking_id,$bill_type,$booking['payMoney'],'找不到预付订单，'.$err_remark);
            pfb::addLog('发送错误信息返回'.json_encode($_r));
            return 4;
        }
        $order=$order['data'];
        
        $err_remark='账单编号：'.$order['objectId'].$err_remark;
        if($order['amount']==$booking['payMoney']&&$order['flag']==1){//检查金额，是否已支付
            //金额一致，检查是否已经支付,以及是否已转入商户
            if($order['flag']==1&&!pfb::checkPay($booking_id)){
                //已支付并且未转入商户，则扣除手续费后转到商户余额
                pfb::addLog('已支付并且未转入商户，则扣除手续费后转到商户余额');
                $amount=pfb::processingFee($order['amount']);
                $remark='IMEI：'.$device['did'].'货款';
                $pay_res=pfb::payToCust($pay_user['objectId'],$amount,$remark,pfb::getAttach($booking_id));
                if($pay_res['status_code']){
                    pfb::addLog('预订订单下单失败');
                    pfb::sendError($wx,$wei,$user,'您有一个预订订单到账失败',$booking_id,$bill_type,$order['amount'],$err_remark);
                    return 8;//下单转给经销商失败
                }
                pfb::addLog('已转到商户余额');
                //更新货款转入安装网点时间和金额,还有支付的订单id防止重复支付
                $API->start(array(
                    'method'=>'wicare.booking.update',
                    '_objectId'=>$booking_id,
                    'payTime'=>date("Y-m-d H:i:s"),
                    'receipt'=>$amount
                ),$opt);
                //发送余额变动通知
                //点击进入企业账户页面
                $_url='http://'.api_v2::$domain['wx'].'/autogps/src/moblie/home.html?loginLocation=financial_manage';
                $_r=pfb::sendBalanceChange($wx,$wei,$cust_openId,$pay_user,$amount,$cust['name'],$remark,$_url);
                pfb::addLog('发送余额变动通知'.json_encode($_r));
            }else{
                pfb::addLog('预订订单到账失败');
                pfb::sendError($wx,$wei,$user,'您有一个预订订单到账失败',$booking_id,$bill_type,$order['amount'],$err_remark);
                return 5;//指定订单未支付成功
            }
        }else{
            pfb::addLog('金额不一致，说明出现意外'.$order['flag']);
            pfb::addLog('说明出现意外'.json_encode($order));
            //金额不一致，说明出现意外
            pfb::sendError($wx,$wei,$user,'您有一个预订订单金额异常',$booking_id,$bill_type,$order['amount'],$err_remark);
            return 6;//指定订单金额不一致
        }
    }
    //执行支付佣金

    //检查佣金是否支付过，
    if(pfb::checkCommission($booking)){//支付过
        pfb::addLog('佣金支付过，');
        return 0;
    }else{
        //获取推荐人
        pfb::addLog('获取推荐人');

        $emp=pfb::getEmployee($booking['sellerId']);
        pfb::addLog('推荐人emp:'.json_encode($emp));
        if(!$emp){//错误
            pfb::addLog('推荐人emp错误:'.json_encode($emp));
            return 2;
        }
        $e_user=pfb::getUser($emp['uid']);
        pfb::addLog('推荐人e_user:'.json_encode($e_user));
        $emp_openId=pfb::getOpenId($e_user);

        $commission=pfb::getCommission($booking,$device);//佣金金额
        $remark='IMEI：'.$device['did'].'佣金';
        $pay=$API->start(array(//余额支付
            'method'=>'wicare.pay.balance',
            'uid'=>$pay_user['objectId'],
            'to_uid'=>$e_user['objectId'],
            'bill_type'=>3,
            'amount'=>$commission,
            'remark'=>$remark,
            'attach'=>pfb::getAttach($booking_id,1)
        ),$opt);
        pfb::addLog('佣金支付返回pay:'.json_encode($pay));
        if($pay['status_code']){
            //支付出错
            if($pay['status_code']==8196){//余额不足，微信推送
                $appData=WX::payAppData();
                $tem=$wei['template']['OPENTM406963151'];
                $_url='http://'.api_v2::$domain['user'].'/commission.php?bookingId='.$booking_id.'&cid='.$cid.'&sid='.$sid.'&title='.rawurlencode($emp['name'].'的佣金').'&amount='.$commission.'&remark='.rawurlencode($remark).'&uid='.$pay_user['objectId'].'&to_uid='.$e_user['objectId'];
                $url="https://open.weixin.qq.com/connect/oauth2/authorize?appid=".$appData['wxAppKey']."&redirect_uri=".rawurlencode($_url)."&response_type=code&scope=snsapi_base&state=state#wechat_redirect";
                pfb::addLog('$cust_openId:'.$cust_openId);
	            $_res=$wx->sendWeixin($cust_openId,$tem,'
                {
                    "first": {
                        "value": "订单ID：'.$booking_id.'",
                        "color": "#173177"
                    },
                    "keyword1": {
                        "value": "'.$device['model'].'/IMEI：'.$device['did'].'",
                        "color": "#173177"
                    },
                    "keyword2": {
                        "value": "佣金",
                        "color": "#173177"
                    },
                    "keyword3": {
                        "value": "'.number_format($commission,2).'",
                        "color": "#173177"
                    },
                    "remark": {
                        "value": "'.$cust['name'].'账户余额不足，请点击详情进入微信支付",
                        "color": "#173177"
                    }
                }',$url);
                pfb::addLog('发送佣金代支付提醒pay返回:'.json_encode($_res));
                return 3;
            }
            $r='错误码'.$pay['status_code'].','.$err_remark;
            pfb::sendError($wx,$wei,$e_user,'您有一个预订佣金异常',$booking_id,'预订佣金',$commission,$r);
            return 7;//其他错误
        }

        //支付成功，发送两条余额变动
        pfb::commissionSuccess($wx,$wei,$cust_openId,$pay_user,$e_user,$commission,$remark,$booking_id,$sid,$cust['name'],$emp['name']);
        return 0;
    }
}

//添加车辆绑定设备
/**
 *  返回码：
 *  '1:'+接口错误码：创建车辆失败
 *  '2:'+接口错误码：绑定设备失败
 *  '3:'+接口错误码：更新预订信息
*/
function addAndBind($uid,$vehicleName,$device,$open_id,$phone,$name,$booking,$carId){
    global $opt,$API;
    $cid=$device['uid'];//设备原拥有者，商户id
    $did=$device['did'];
    
    if(!$carId){//没有传车辆id进来，则必须传车牌号，新建一个车辆绑定
        $car=$API->start(array(//添加车辆
            'method'=>'wicare.vehicle.create',
            'name'=>$vehicleName,
            'uid'=>$uid,
            'did'=>$did,
            'deviceType'=>$device['model']
        ),$opt);
        if($car['status_code']){
            return '1:'.$car['status_code'];
        }
        $carId=$car['objectId'];
    }


    $_device=$API->start(array(//绑定设备
        'method'=>'wicare._iotDevice.update',
        '_did'=>$did,
        'binded'=>true,
        'bindDate'=>date("Y-m-d H:i:s"),
        'vehicleName'=>$vehicleName,
        'vehicleId'=>$carId,
        'uid'=>$uid
    ),$opt);
    if($_device['status_code']){
        return '2:'.$_device['status_code'];
    }

    
    if($booking){
        $sid=$booking['uid'];//活动创建商户id
        $dev=$API->start(array(//活动产品表获取设备安装费用的信息
            'method'=>'wicare.activityProduct.get',
            'uid'=>$sid,
            'productId'=>$device['modelId'],
            'fields'=>'price,installationFee,reward,name,productId,brandId,brand'
        ),$opt);
        if(!$dev||!isset($dev['data'])){//活动产品表返回空
            // return '此产品无活动';
            $device['reward']=0;
        }else{
            $dev=$dev['data'];
            $device=array_merge($device,$dev);
        }

        $bo=$API->start(array(//更新预订信息
            'method'=>'wicare.booking.update',
            '_objectId'=>$booking['objectId'],
            'status'=>1,
            'status1'=>1,
            'resTime'=>date("Y-m-d H:i:s"),
            'did'=>$did,
            'res.openId'=>$open_id,
            'res.mobile'=>$phone,
            'res.name'=>$name,
            'res.seller'=>$device['uid'],
            'res.product'=>$device['model'],
            'res.productId'=>$device['modelId'],
            'res.price'=>$device['price'],
            'res.installationFee'=>$device['installationFee'],
            'res.reward'=>$device['reward']
        ),$opt);
        if($bo['status_code']){
            return '3:'.$bo['status_code'];
        }
    }
        
    return $device;
}


function addDeviceLog($device,$uid,$name,$booking){
    global $opt,$API;
    $cid=$device['uid'];//设备原拥有者，商户id
    $did=$device['did'];
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
        'from'=>$cid,
        'fromName'=>'',
        'to'=>$uid,
        'toName'=>$name,
        'status'=>2
    );
    $popLog=array(//出库
        'uid'=>$cid,
        'type'=>0,
        'inCount'=>0,
        'outCount'=>1
    );
    $pushLog=array(//下级的入库
        'uid'=>$uid,
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
    
    if($booking)
        return checkAndPay($booking,$cid,$device,true);
    else
        return true;
}

//pay for booking
//容纳本页脚本需要用到的静态方法
class pfb{
    public static function getAttach($booking_id,$type=0){
        $str=array(
            'payToCust',//'从公帐转入商户公帐',
            'payToSeller'//'从商户支付佣金'
        );
        $attach=$booking_id.','.$str[$type];
        return $attach;
    }

    //检查是否支付过佣金
    public static function checkCommission($booking){
        return pfb::checkPay($booking['objectId'],1);
    }

    //根据cid获取customer
    public static function getCustomer($cid){
        global $opt,$API;
        $cust=$API->start(array(
            'method'=>'wicare.customer.get',
            'objectId'=>$cid,
            'fields'=>'objectId,uid,name'
        ),$opt);
        if(pfb::dataNull($cust)){
            return false;
        }
        return $cust['data'];
    }

    //根据uid获取用户
    public static function getUser($uid){
        global $opt,$API;
        $user=$API->start(array(
            'method'=>'wicare.user.get',
            'objectId'=>$uid,
            'fields'=>'objectId,authData,balance,frozenBalance'
        ),$opt);
        if(pfb::dataNull($user)){
            return false;
        }
        return $user['data'];
    }

    //根据uid获取openId
    public static function getOpenId($userOrUid){
        if(is_array($userOrUid)){
            $user=$userOrUid;
        }else{
            $user=pfb::getUser($userOrUid);
        }
        $key=api_v2::getOpenIdKey(api_v2::$domain['wx']);//获取营销号的openid
        if(!$user||!$user['authData'])
            return false;
        else{
            if($user['authData'][$key])
                return $user['authData'][$key];
            else{//没有营销号的openId，说明是获取推荐人而且推荐人是车主，所以需要服务号来推
                $key=api_v2::getOpenIdKey(api_v2::$domain['user']);
                return $user['authData'][$key];
            }
        }
    }

    //获取公司公账号，传递customer
    public static function getPayUser($cust){
        global $opt,$API;
        $uid=$cust['objectId'];
        $user=$API->start(array(
            'method'=>'wicare.user.get',
            'mobile'=>$uid,
            'fields'=>'objectId,balance,frozenBalance'
        ),$opt);
        if(pfb::dataNull($user)){
            return false;
        }
        return $user['data'];
    }

    //获取应付佣金
    //（若注册产品佣金>预订产品佣金，则佣金为两者之和除于2，若注册产品佣金<预订产品佣金,则佣金为注册产品佣金）
    public static function getCommission($booking,$device){
        pfb::addLog('计算佣金'.json_encode($booking).json_encode($device));
        $b_pay=$booking['product']['reward'];
        $r_pay=$device['reward'];
        if($r_pay>$b_pay)
            return (($r_pay+$b_pay)/2);
        else
            return $r_pay;
    }

    //扣除手续费
    public static function processingFee($amount){
        // $a=floor($amount*100*0.994)/100;//扣除0.6%的手续费
        return $amount;
    }

    //检查是否有数据
    public static function dataNull($res){
        if(!$res||!isset($res['data']))
            return true;
        else
            return false;
    }

    //获取微信
    public static function getWeixin($cid,$type=1){
        global $opt,$API;
        $data=array(
                'method'=>'wicare.weixin.get',
                'fields'=>'wxAppKey,wxAppSecret,uid,type,objectId,name,template'
            );
        if($type<0)
            $data['wxAppKey']=$cid;
        else{
            $data['uid']=$cid;
            $data['type']=$type;
        }
        $wei=$API->start($data,$opt);
        if(!$wei['data']||!$wei['data']['wxAppKey']||!$wei['data']['wxAppSecret']){
            return false;
        }
        return $wei['data'];
    }

    //检查是否有支付过
    public static function checkPay($booking_id,$type=0){
        global $opt,$API;
        $attach=pfb::getAttach($booking_id,$type);
        $pay=$API->start(array(//余额支付
            'method'=>'wicare.order.get',
            'attach'=>$attach
        ),$opt);
        if($pay&&$pay['data']&&$pay['data']['flag']==1)
            return true;
        else
            return false;
    }

    //从公账号转到商户公账号
    public static function payToCust($uid,$amount,$remark,$attach){
        global $opt,$API;
        $pay=$API->start(array(//余额支付
            'method'=>'wicare.pay.balance',
            'uid'=>0,
            'to_uid'=>$uid,
            'bill_type'=>2,
            'amount'=>$amount,
            'remark'=>rawurlencode($remark),
            'attach'=>$attach
        ),$opt);
        pfb::addLog('转商户余额返回'.json_encode($pay));
        return $pay;
    }

    //发送账单异常处理提醒
    public static function sendError($wx,$wei,$user,$title,$id,$type,$amount,$remark=''){
        $tem=$wei['template']['OPENTM401266811'];
        $openId=pfb::getOpenId($user);
        $url='#';
        return $wx->sendWeixin($openId,$tem,'{
            "first": {
                "value": "'.$title.'",
                "color": "#173177"
            },
            "keyword1": {
                "value": "'.$id.'",
                "color": "#173177"
            },
            "keyword2": {
                "value": "'.$type.'",
                "color": "#173177"
            },
            "keyword3": {
                "value": "'.$amount.'",
                "color": "#173177"
            },
            "remark": {
                "value": "'.$remark.'",
                "color": "#173177"
            }
        }',$url);
    }

    //发送余额变动通知
    public static function sendBalanceChange($wx,$wei,$openId,&$user,$amount,$account,$remark,$url='#'){
        if($amount==0)return array(
            "errcode"=>0,
            "errmsg"=>"金额为0，不进行推送"
        );
        $tem=$wei['template']['OPENTM207664902'];
        $date=date("Y-m-d H:i:s");
        $user['balance']=$user['balance']+$amount;
        $type='入账';
        $color='#173177';
        if($amount<0){
            $amount=0-$amount;
            $type='支付';
            $color='#990000';            
        }

        return $wx->sendWeixin($openId,$tem,'
        {
            "first": {
                "value": "账户：'.$account.'",
                "color": "#173177"
            },
            "keyword1": {
                "value": "'.$date.'",
                "color": "#173177"
            },
            "keyword2": {
                "value": "'.$type.'",
                "color": "#173177"
            },
            "keyword3": {
                "value": "'.number_format($amount,2).'",
                "color": "'.$color.'"
            },
            "remark": {
                "value": "'.$remark.'",
                "color": "#173177"
            }
        }',$url);
    }

    //发送给预订人，车主已注册
    public static function sendToBooker($cid,$booking,$device,$cust){
        $wei=pfb::getWeixin($cid,0);
        if(!$wei)
            return;
        $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
        $tem=$wei['template']['OPENTM408183089'];
        $url='#';
        $openId=$booking['openId'];

        $date=date("Y-m-d H:i");
        $user=$booking['userName'].'/'.$booking['userMobile'];
        $remark='订单'.$booking['objectId'].'注册成功！';
        return $res=$wx->sendWeixin($openId,$tem,'
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
                "value": "'.$device['brand'].' '.$device['model'].'",
                "color": "#173177"
            },
            "keyword3": {
                "value": "'.$device['did'].'",
                "color": "#173177"
            },
            "remark": {
                "value": "'.$remark.'",
                "color": "#173177"
            }
        }',$url);
    }

    /**
     * 参数说明：
     * $wx 用于发送信息的WX类实例,$wei 商户的营销号记录,
     * $cust_openId 商户公司管理员的openId,$pay_user 商户公司的公账号,
     * $e_user 推荐人的user表记录,$commission 所付佣金金额,
     * $remark 推送的备注,$booking_id 预订单的id,
     * $amount 可选，预订时预付款的金额
     */
    public static function commissionSuccess($wx,$wei,$cust_openId,&$pay_user,&$e_user,$commission,$remark,$booking_id,$sid,$payName,$name,$flag){
        global $API,$opt;

        if(!isset($flag)||!$flag){//默认发送给支付人
            pfb::addLog('commissionSuccess:发送余额变动');
            $emp_openId=pfb::getOpenId($e_user);
            //点击进入企业账户页面
            $_url='http://'.api_v2::$domain['wx'].'/autogps/src/moblie/home.html?loginLocation=financial_manage';
            $_comm=0-$commission;
            $_r=pfb::sendBalanceChange($wx,$wei,$cust_openId,$pay_user,$_comm,$payName,$remark,$_url);//发送给商户
            pfb::addLog('佣金支出'.json_encode($_r));
        }

        //更新预订表信息
        $b=array(
            'method'=>'wicare.booking.update',
            '_objectId'=>$booking_id,
            'commission'=>$commission,
            'commissionDate'=>date("Y-m-d H:i:s"),//支付佣金时间
            'status'=>2,
            'status2'=>1,
            'status1'=>1
        );
        $pay=$API->start($b,$opt);
        pfb::addLog('更新预订信息'.json_encode($pay));

        //点击进入个人钱包
        $_url='http://'.api_v2::$domain['wx'].'/autogps/src/moblie/home.html?loginLocation=myAccount%2Fwallet';
        if($e_user['userType']==7){//是车主的话，获取服务号进行推送
            $wei=pfb::getWeixin($sid,0);
            if(!$wei)
                return;
            $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
            $_url='http://'.api_v2::$domain['user'].'/wo365_user/src/moblie/home.html?loginLocation=myAccount%2Fwallet';
        }

        $remark='订单'.$booking_id.'佣金';
        $_r=pfb::sendBalanceChange($wx,$wei,$emp_openId,$e_user,$commission,$name,$remark,$_url);//发送给推荐人
        pfb::addLog('佣金到帐'.json_encode($_r));
    }

    //根据营销人员id去获取营销人员信息
    public static function getEmployee($sid){
        global $API,$opt;
        $emp=$API->start(array(
            'method'=>'wicare.employee.get',
            'objectId'=>$sid,
            'fields'=>'uid,companyId'
        ),$opt);
        if(pfb::dataNull($emp)){//并不是员工，有可能是商户管理员，有可能是车主
            $emp=$API->start(array(
                'method'=>'wicare.customer.get',
                'objectId'=>$sid,
                'fields'=>'uid,parentId,name,custTypeIdm,contact'
            ),$opt);
            if(pfb::dataNull($emp))
                return false;
            if($emp['data']['custTypeId']!=7){//不是车主，是管理员
                if($emp['data']['contact'])
                    $emp['data']['name']=$emp['data']['contact'];
                else
                    $emp['data']['name']=$emp['data']['name'].'-管理员';
            }
        }
        return $emp['data'];
    }

    //添加日志
    public static function addLog($content){
        $log_filename = "aa_pay_log.txt";
        $content.='------'.date("Y-m-d H:i:s").'
        ';
        file_put_contents($log_filename,$content,FILE_APPEND);
    }
}