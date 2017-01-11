<?php
include 'api_v2.php';

$shortUrl=$_GET['s'];

$api=new api_v2();
$arr=$api->start(array(
	'method'=>'wicare.qrLink.get',
    'id'=>$shortUrl,
	'fields'=>'url',
),$opt);

if($arr['data'] && $arr['data']['url']){
	$longUrl=$arr['data']['url'];
	Header("Location: ".$longUrl);  
}else{
	echo '该二维码尚未绑定活动信息';
}

