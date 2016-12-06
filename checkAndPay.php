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
function checkAndPay($booking,$cid,$device,$payAll=true){
    global $opt,$API;
    $wei=pfb::getWeixin($cid);
    if(!$wei){
        return 3;
    }        
    $wx=new WX($wei['wxAppKey'],$wei['wxAppSecret']);
    $cust=pfb::getCustomer($cid);//customer表记录
    $pay_user=pfb::getPayUser($cust);//商户公帐
    $user=pfb::getUser($cust['uid']);//管理员user表记录
    $cust_openId=pfb::getOpenId($user);//商户管理员的openId
    $bill_type='预付款到账';
    $err_remark='请联系技术人员处理';

    if($payAll&&$booking['payMoney']){//如果有预付款,检查订单
        $order=$API->start(array(
            'method'=>'wicare.order.get',
            'oid'=>$booking['orderId']
        ),$opt);
        if(pfb::dataNull($order)){//找不到对应的预付订单
            pfb::sendError($wx,$wei,$user,'您有一个预订订单到账失败',$booking['objectId'],$bill_type,$booking['payMoney'],'找不到预付订单，'.$err_remark);
            return 4;
        }
        $order=$order['data'];
        $err_remark='账单编号：'.$order['objectId'].$err_remark;
        if($order['amount']==$booking['payMoney']&&$order['flag']==1){//检查金额，是否已支付
            //金额一致，检查是否已经支付,以及是否已转入商户
            if($order['flag']==1&&!pfb::checkPay($booking['objectId'])){
                //已支付并且未转入商户，则扣除手续费后转到商户余额
                $amount=pfb::processingFee($order['amount']);
                $remark=$booking['userName'].'/'.$booking['userMobile'].'预付款(扣除手续费)';
                pfb::payToCust($pay_user['objectId'],$amount,$remark,pfb::getAttach($booking['objectId']));
                //发送余额变动通知
                pfb::sendBalanceChange($wx,$wei,$cust_openId,$pay_user,$amount,$bill_type,$remark);
            }else{
                pfb::sendError($wx,$wei,$user,'您有一个预订订单到账失败',$booking['objectId'],$bill_type,$order['amount'],$err_remark);
                return 5;//指定订单未支付成功
            }
        }else{
            //金额不一致，说明出现意外
            pfb::sendError($wx,$wei,$user,'您有一个预订订单金额异常',$booking['objectId'],$bill_type,$order['amount'],$err_remark);
            return 6;//指定订单金额不一致
        }
    }
    //执行支付佣金

    //检查佣金是否支付过，
    if(pfb::checkCommission($booking)){//支付过
        return 0;
    }else{
        //获取推荐人
        $emp=pfb::getEmployee($booking['sellerId']);
        if(!$emp){//错误
            return 2;
        }
        $e_user=pfb::getUser($emp['uid']);
        $emp_openId=pfb::getOpenId($e_user);

        $commission=pfb::getCommission($booking,$device);//佣金金额
        $remark=$booking['userName'].'/'.$booking['userMobile'].'注册佣金';
        $pay=$API->start(array(//余额支付
            'method'=>'wicare.pay.balance',
            'uid'=>$pay_user['objectId'],
            'to_uid'=>$e_user['objectId'],
            'bill_type'=>3,
            'amount'=>$commission,
            'remark'=>$remark,
            'attach'=>pfb::getAttach($booking['objectId'],1,$emp['uid'])
        ),$opt);
        if($pay['status_code']){
            //支付出错
            if($pay['status_code']==8196){//余额不足，微信推送
                $tem=$wei['template']['OPENTM406963151'];
                $_url='http://wx.autogps.cn/commission.php/?bookingId='.$booking['objectId'].'&cid='.$cid.'&receipt='.$amount.'&title='.rawurlencode($emp['name'].'的佣金').'&amount='.$commission.'&remark='.rawurlencode($remark);
                $url="https://open.weixin.qq.com/connect/oauth2/authorize?appid=wx76f1169cbd4339c1&redirect_uri=".rawurlencode($_url)."&response_type=code&scope=snsapi_base&state=state#wechat_redirect";
                
	            $wx->sendWeixin($cust_openId,$tem,'"first": {
                    "value": "您有一笔待支付佣金",
                    "color": "#173177"
                },
                "keyword1": {
                    "value": "'.$emp['name'].'的佣金",
                    "color": "#173177"
                },
                "keyword2": {
                    "value": "佣金",
                    "color": "#173177"
                },
                "keyword3": {
                    "value": "'.$commission.'",
                    "color": "#173177"
                },
                "remark": {
                    "value": "由于您的余额不足，请点击详情进入微信支付",
                    "color": "#173177"
                }',$url);
                return 3;
            }
            $r='错误码'.$pay['status_code'].','.$err_remark;
            pfb::sendError($wx,$wei,$e_user,'您有一个预订佣金异常',$booking['objectId'],'预订佣金',$commission,$r);
            return 7;//其他错误
        }

        //支付成功，发送两条余额变动
        pfb::commissionSuccess($wx,$wei,$pay_user,$e_user,$commission,$remark,$booking['objectId'],$amount);
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
function addAndBind($uid,$vehicleName,$device,$open_id,$phone,$name,$booking){
    global $opt,$API;
    $cid=$device['uid'];//设备原拥有者，商户id
    $did=$device['did'];
    
    $car=$API->start(array(//添加车辆
        'method'=>'wicare.vehicle.create',
        'name'=>$vehicleName,
        'uid'=>$uid,
        'did'=>$did,
        'deviceType'=>$device['model']
    ),$opt);
    if($car['status_code']){
        return '1+'.$car['status_code'];
    }

    $dev=$API->start(array(//活动产品表获取设备安装费用的信息
        'method'=>'wicare.activityProduct.get',
        'uid'=>$cid,
        'productId'=>$device['modelId'],
        'fields'=>'objectId,uid,price,installationFee,reward,name,productId,brandId,brand'
    ),$opt);
    if(!$dev||!isset($dev['data'])){//活动产品表返回空
        // return '此产品无活动';
        $device['reward']=0;
    }else{
        $dev=$dev['data'];
        $device=array_merge($device,$dev);
    }

    $_device=$API->start(array(//绑定设备
        'method'=>'wicare._iotDevice.update',
        '_did'=>$did,
        'binded'=>true,
        'bindDate'=>date("Y-m-d h:i:sa"),
        'vehicleName'=>$vehicleName,
        'vehicleId'=>$car['objectId'],
    ),$opt);
    if($_device['status_code']){
        return '2:'.$_device['status_code'];
    }

    if($booking){
        $bo=$API->start(array(//更新预订信息
            'method'=>'wicare.booking.update',
            '_objectId'=>$booking['objectId'],
            'status'=>1,
            'status1'=>1,
            'resTime'=>date("Y-m-d h:i:sa"),
            'did'=>$did,
            'userOpenId'=>$open_id,
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

    checkAndPay($booking,$cid,$device,true);
}

//pay for booking
//容纳本页脚本需要用到的静态方法
class pfb{
    public static function getAttach($booking_id,$type=0,$to_uid){
        $str=array(
            '从公帐转入商户公帐',
            '从商户支付佣金'
        );
        $attach=$booking_id.','.$str[$type];
        if($to_uid)
            $attach.=','.$to_uid;
        return $attach;
    }

    //检查是否支付过佣金
    public static function checkCommission($booking){
        return pfb::checkPay($booking['objectId'],1);
    }

    //根据cid获取customer
    public function getCustomer($cid){
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
        if(!is_array($userOrUid)){
            $user=pfb::getUser($userOrUid);
        }else{
            $user=$userOrUid;
        }
        if(!$user||!$user['data']||!$user['data']['authData'])
            return false;
        else
            return $user['data']['authData']['openId'];
    }

    //获取公司公账号，传递customer
    public function getPayUser($cust){
        global $opt,$API;
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
        $b_pay=$booking['product']['reward'];
        $r_pay=$device['reward'];
        if($r_pay>$b_pay)
            return (($r_pay+$b_pay)/2);
        else
            return $r_pay;
    }

    //扣除手续费
    public function processingFee($amount){
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
    public static function getWeixin($cid){
        global $opt,$API;
        $wei=$API->start(array(
            'method'=>'wicare.weixin.get',
            'uid'=>$uid,
            'type'=>1,
            'fields'=>'wxAppKey,wxAppSecret,uid,type,objectId,name,template'
        ),$opt);

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
        if($pay&&$pay['data'])
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
            'remark'=>$remark,
            'attach'=>$attach
        ),$opt);
    }

    //发送账单异常处理提醒
    public static function sendError($wx,$wei,$user,$title,$id,$type,$amount,$remark=''){
        $tem=$wei['template']['OPENTM401266811'];
        $openId=pfb::getOpenId($user);
        $url='#';
        $wx->sendWeixin($openId,$tem,'"first": {
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
        }',$url);
    }

    //发送余额变动通知
    public static function sendBalanceChange($wx,$wei,$openId,$user,$amount,$title,$remark=''){
        $tem=$wei['template']['OPENTM405774153'];
        $url='#';
        $date=date("Y-m-d h:i:s");
        $user['balance']=$user['balance']+$amount;

        $wx->sendWeixin($openId,$tem,'"first": {
            "value": "'.$title.'",
            "color": "#173177"
        },
        "keyword1": {
            "value": "'.$amount.'元",
            "color": "#173177"
        },
        "keyword2": {
            "value": "'.$user['balance'].'元",
            "color": "#173177"
        },
        "keyword3": {
            "value": "'.$user['frozenBalance'].'元",
            "color": "#173177"
        },
        "keyword4": {
            "value": "'.$date.'",
            "color": "#173177"
        },
        "remark": {
            "value": "'.$remark.'",
            "color": "#173177"
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
    public function commissionSuccess($wx,$wei,$cust_openId,$pay_user,$e_user,$commission,$remark,$booking_id,$amount=0){
        global $API,$opt;
        $emp_openId=pfb::getOpenId($e_user);
        pfb::sendBalanceChange($wx,$wei,$cust_openId,$pay_user,(0-$commission),'佣金支出',$remark);//发送给商户
        pfb::sendBalanceChange($wx,$wei,$emp_openId,$e_user,$commission,'佣金到帐',$remark);//发送给推荐人

        //更新预订表信息
        $b=array(
            'method'=>'wicare.booking.update',
            '_objectId'=>$booking_id,
            'commission'=>$commission,
            'payTime'=>date("Y-m-d h:i:sa"),
            'status'=>2,
            'status2'=>1
        );
        if($amount)
            $b['receipt']=$amount;
        $pay=$API->start($b,$opt);
    }

    //根据营销人员id去获取营销人员信息
    public function getEmployee($sid){
        global $API,$opt;
        $emp=$API->start(array(
            'method'=>'wicare.employee.get',
            'objectId'=>$sid,
            'fields'=>'uid,companyId'
        ),$opt);
        if(pfb::dataNull($emp)){//并不是员工，
            $emp=$API->start(array(
                'method'=>'wicare.customer.get',
                'objectId'=>$sid,
                'fields'=>'uid,parentId,name'
            ),$opt);
            if(pfb::dataNull($emp))
                return false;
        }
        return $emp['data'];
    }
}