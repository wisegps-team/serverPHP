<?php
/**
 * Created by PhpStorm.
 * User: 1
 * Date: 2016-09-27
 */
date_default_timezone_set('PRC'); 
include 'api_v2.php';
$API=new api_v2();//api接口类

if(isset($_GET['code'])){

    //根据域名获取公众号信息
    $_host=$_SERVER['HTTP_HOST'];//当前域名
    $weixin=array();
    if(isset($_GET['wx_app_id'])){
        $wxData=array(
            'wxAppKey' => $_GET['wx_app_id'],
            'method'=>'wicare.weixin.get',
            'fields'=>'wxAppKey,wxAppSecret'
        );
        $weixin=$API->start($wxData,$opt);
        $weixin=$weixin['data'];
        // echo '获取到的<br/>';
        // print_r($weixin);
    }
    //用于获取app数据
    $appData=array(
        'domainName' => $_host,
        'method'=>'wicare.app.get',
        'fields'=>'devId,name,logo,version,appKey,appSecret,sid,wxAppKey,wxAppSecret'
    );
    //获取app数据
    $appRes=$API->start($appData);
    
    if(!$appRes||!$appRes['data']){
        echo 'Not configured domainName';
        exit;
    }
    $appData=$appRes['data'];
    $appData=array_merge($appData,$weixin);

    $code = $_GET['code'];
    $userinfo = getUserInfo($code,$appData);
    if(isset($_GET['state']))
        $userinfo["state"]=$_GET['state'];

}else{
    echo 'No code';
    exit();
}


function getUserInfo($code,$appData){
    $appid=$appData['wxAppKey'];
    $appsecret=$appData['wxAppSecret'];
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
    if(!$openid){
        print_r($access_token_array);
        echo '<br/>'.$_GET['wx_app_id'];
        echo '<br/>';
        print_r($appData);
        exit;
    }
        
    // 根据access token获取用户信息
    // $userinfo_url = "https://api.weixin.qq.com/sns/userinfo?access_token=$access_token&openid=$openid";
    // $userinfo_json = https_request($userinfo_url);
    // $userinfo_array = json_decode($userinfo_json, true);
    if($_GET['state']=='sso_login'){//进行登录
        $login_info=sso_login($userinfo_array["openid"],$appData);
        $login_info['sso_login']='sso_login';
    }
        
    
    if($login_info){
        $userinfo_array=array_merge($userinfo_array,$login_info);
    }
    return $userinfo_array;
}

function sso_login($login_id,$appData){
    global $API,$opt;
    if(!$login_id){
        return array();
    }
    $op=array(
        'app_key'=>$appData['appKey'],
        'app_secret'=>$appData['appSecret']
    );
    $key=api_v2::getOpenIdKey();
    $custData=array(
        'authData.'.$key => $login_id,
        'method'=>'wicare.user.sso_login'
    );
    $res=$API->start($custData,$op);
    if($res['uid']){//登录成功，更新登录信息
        // echo $res['uid'];
        $auth=array(
            'method'=>'wicare.user.update',
            '_objectId'=>$res['uid'],
            'authData.'.$key.'_wx'=>$appData['wxAppKey'],
            'authData.'.$key.'_login_date'=>date('Y-m-d H:i:s')
        );
        // print_r($auth);
        $r=$API->start($auth,$opt);
        // print_r($r);
        // echo '没有错误';
        // exit;
    }
    return $res;
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