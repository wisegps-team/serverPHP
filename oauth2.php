<?php
/**
 * Created by PhpStorm.
 * User: 1
 * Date: 2016-09-27
 */
include 'api_v2.php';
$API=new api_v2();//api接口类
$opt=array(
    'access_token'=>'3a9557ed4250440ec57b53564e391cb50ada46ae97bc96c6abf0c3a7a3b501c3b7c93e803c9016924569a69f7e1d4222b39bb1bd39c70601cbcb8cbe953e0bfe',
    'app_key'=>'0642502f628a83433f0ba801d0cae4ef',
    'dev_key'=>'86e3ddeb8db36cbf68f10a8b7d05e7ac',
    'app_secret'=>'15fe3ee5197e8ba810512671483d2697'
);
if(isset($_GET['code'])){

    //根据域名获取公众号信息
    $_host=$_SERVER['HTTP_HOST'];//当前域名
    
    if(isset($_GET['wx_app_id'])){
        $custData=array(
            'wxAppKey' => $_GET['wx_app_id'],
            'method'=>'wicare.weixin.get',
            'fields'=>'wxAppKey,wxAppSecret'
        );
        $appRes=$API->start($custData,$opt);
        // print_r($appRes);
    }else{
        //用于获取app数据
        $appData=array(
            'domainName' => $_host,
            'method'=>'wicare.app.get',
            'fields'=>'devId,name,logo,version,appKey,appSecret,sid,wxAppKey,wxAppSecret'
        );
        //获取app数据
        $appRes=$API->start($appData);
    }
    
    if(!$appRes||!$appRes['data']){
        echo 'Not configured domainName';
        exit;
    }
    $appData=$appRes['data'];

    $code = $_GET['code'];
    $userinfo = getUserInfo($code,$appData['wxAppKey'],$appData['wxAppSecret']);
    if(isset($_GET['state']))
        $userinfo["state"]=$_GET['state'];

}else{
    echo 'No code';
    exit();
}


function getUserInfo($code,$appid,$appsecret){
    $access_token = "";

    // 根据code获取access_token
    $access_token_url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid=$appid&secret=$appsecret&code=$code&grant_type=authorization_code";
//    echo $access_token_url;
    $access_token_json = https_request($access_token_url);
    $access_token_array = json_decode($access_token_json, true);
    $access_token = $access_token_array['access_token'];
//    echo "access_token:$access_token";
    $openid = $access_token_array['openid'];
    
    $userinfo_array=array("openid"=>$openid);
    if($_GET['state']=='getOpenId'){//如果只是获取openid，则就此返回
        return $userinfo_array;
    }
        
    // 根据access token获取用户信息
    // $userinfo_url = "https://api.weixin.qq.com/sns/userinfo?access_token=$access_token&openid=$openid";
    // $userinfo_json = https_request($userinfo_url);
    // $userinfo_array = json_decode($userinfo_json, true);
    if($_GET['state']=='sso_login'){//进行登录
        $login_info=sso_login($userinfo_array["openid"]);
        $login_info['sso_login']='sso_login';
    }
        
    
    if($login_info){
        $userinfo_array=array_merge($userinfo_array,$login_info);
    }
    return $userinfo_array;
}

function sso_login($login_id){
    global $API,$opt;
    $custData=array(
        'authData.openId' => $login_id,
        'method'=>'wicare.user.sso_login'
    );
    return $API->start($custData,$opt);
}

function https_request($url){
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($curl);
    if(curl_errno($curl)){
        return 'ERROR'.curl_error($curl);
    }
    curl_close($curl);
    return $data;
}

setcookie("_wx_user_", json_encode($userinfo), time()+86400,"/",$_SERVER['HTTP_HOST']);
$cookie_url=$_COOKIE["__login_redirect_uri__"];
$u_data="";
foreach($userinfo as $x=>$x_value) {
  $u_data=$u_data."&".$x."=".$x_value;
}
// print_r($userinfo);
if(strpbrk($cookie_url,"?"))
    $url=$cookie_url.$u_data;
else{
    $url=$cookie_url."?".substr($u_data,1);
}
// echo $url;
header("Location: ".$url);
exit;
?>

<!DOCTYPE html>
<html class="um landscape min-width-240px min-width-320px min-width-480px min-width-768px min-width-1024px">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="initial-scale=1, width=device-width, maximum-scale=1, user-scalable=no"  />
    <meta name="viewport" content="initial-scale=1.0,user-scalable=no,maximum-scale=1" media="(device-height: 568px)" />
    <title>用户信息</title>
</head>

<body class="um-vp c-wh" ontouchstart>
<div class="container">
    <!-- 内容 -->
    <p class="content">
        昵称：<?php echo $userinfo["nickname"]; ?><br>
        性别：<?php echo $userinfo["sex"]; ?><br>
        省份：<?php echo $userinfo["province"]; ?><br>
        城市：<?php echo $userinfo["city"]; ?><br>
        特权：<?php echo $userinfo["privilege"]; ?><br>
        <?php print_r($userinfo); ?>
    </p>
    <p>
        cookie回调地址：<?php echo urldecode($_COOKIE["__login_redirect_uri__"]); ?><br>
        openid：<?php echo $userinfo["openid"]; ?><br>
        头像地址：<?php echo $userinfo["headimgurl"]; ?><br>
        最后的跳转地址：<?php echo $url; ?><br>
            <br>
                <?php echo json_encode($userinfo); ?>
                
    </p>
</div>
</body>
</html>