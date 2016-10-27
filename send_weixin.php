<?php
include 'WX.TokenAndTicket.php';

echo $wx->sendWeixin($_GET['open_id'],$_GET['template_id'],$_GET['data'],$_GET['url']);
?>

