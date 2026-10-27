<?php
declare(strict_types=1);
define('SP49_ADMIN',true);require __DIR__.'/lib.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') fail('Используйте форму обращения.',405);
    $origin=$_SERVER['HTTP_ORIGIN']??'';$p=parse_url($origin);$originHost=($p['host']??'').(isset($p['port'])?':'.$p['port']:'');if (!$origin || !in_array($p['scheme']??'', ['http','https'],true) || strcasecmp($originHost,$_SERVER['HTTP_HOST']??'')!==0) fail('Отправьте обращение с сайта компании.',403);
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>32000) fail('Обращение слишком большое.',413);
    $v=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if (!is_array($v)) fail('Неверный формат.');
    $result=locked(function() use($v) {
        $c=content();if (!$c['settings']['inquiriesEnabled']) fail('Приём обращений ещё не подключён.',503);
        if (!empty($v['website'])) fail('Обращение не принято.');
        if (empty($v['consent'])) fail('Подтвердите согласие на использование контактов.');
        $email=clean_text($v['email']??'',200);$name=clean_text($v['name']??'',100);$message=clean_text($v['message']??'',10000);
        if (!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($message)<3) fail('Укажите имя, корректный email и текст обращения.');
        check_login_rate('inquiry');login_attempt('inquiry',false);
        $id=gmdate('Ymd').'-'.bin2hex(random_bytes(6));$row=['id'=>$id,'at'=>now(),'status'=>'new','name'=>$name,'email'=>$email,'phone'=>clean_text($v['phone']??'',80),'topic'=>clean_text($v['topic']??'Подбор запчастей',200),'message'=>$message,'page'=>clean_text($v['page']??'',1000),'consent'=>true,'notification'=>'disabled'];
        if ($c['settings']['mailNotifications'] && filter_var($c['settings']['notificationEmail'],FILTER_VALIDATE_EMAIL)) {
            $host=parse_url($c['settings']['siteUrl'],PHP_URL_HOST);$headers=['From'=>'Спецпартс49 <no-reply@'.$host.'>','Reply-To'=>$email,'Content-Type'=>'text/plain; charset=UTF-8'];
            $text="Обращение $id\nИмя: $name\nEmail: $email\nТелефон: ".$row['phone']."\nТема: ".$row['topic']."\n\n$message";
            $row['notification']=@mail($c['settings']['notificationEmail'],'=?UTF-8?B?'.base64_encode('Спецпартс49 — новое обращение '.$id).'?=',$text,$headers)?'accepted-by-mail-server':'failed';
        }
        $rows=json_read(data_dir().'/inquiries.json');$rows[]=$row;json_write(data_dir().'/inquiries.json',$rows);audit('inquiry_received',['id'=>$id]);return ['success'=>true,'id'=>$id];
    });echo json_encode($result,JSON_UNESCAPED_UNICODE);
} catch (CmsError $e) { http_response_code($e->getCode()>=400?$e->getCode():400);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
catch (Throwable $e) { error_log('SP49 inquiry: '.$e->getMessage());http_response_code(500);echo '{"success":false,"error":"Не удалось сохранить обращение. Повторите позже."}'; }
