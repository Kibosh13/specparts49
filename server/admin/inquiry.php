<?php
declare(strict_types=1);
define('SP49_ADMIN',true);require __DIR__.'/lib.php';require __DIR__.'/mail.php';require __DIR__.'/inquiry-captcha.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');
ini_set('display_errors','0');
try {
    if (($_SERVER['REQUEST_METHOD']??'')==='GET' && ($_GET['challenge']??'')==='1') {
        if (($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') fail('Используйте форму обращения на сайте компании.',403);
        echo json_encode(inquiry_captcha_challenge(), JSON_THROW_ON_ERROR);
        exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail('Используйте форму обращения.',405);
    $origin=$_SERVER['HTTP_ORIGIN']??'';$p=parse_url($origin);$originHost=($p['host']??'').(isset($p['port'])?':'.$p['port']:'');if(!$origin || !in_array($p['scheme']??'', ['http','https'],true) || strcasecmp($originHost,$_SERVER['HTTP_HOST']??'')!==0)fail('Отправьте обращение с сайта компании.',403);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>17*1024*1024)fail('Общий размер запроса превышает 16 МБ.',413);
    $multipart=str_contains($_SERVER['CONTENT_TYPE']??'','multipart/form-data');$v=$multipart?$_POST:json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($v))fail('Неверный формат.');
    $result=locked(function()use($v){
        $c=json_read(data_dir().'/published.json',content());$s=$c['settings'];if(!$s['inquiriesEnabled'])fail('Приём обращений ещё не подключён.',503);
        if(!empty($v['website']))fail('Обращение не принято.');if(empty($v['consent']))fail('Подтвердите согласие на использование контактов.');
        $email=clean_text($v['email']??'',200);$name=clean_text($v['name']??'',100);$message=clean_text($v['message']??'',10000);
        if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$email) || mb_strlen($message)<3)fail('Укажите имя, корректный email и текст обращения.');
        $requestKey=clean_text($v['requestId']??'',80);$requestKey=preg_match('/^[a-zA-Z0-9-]{16,80}$/',$requestKey)?hash('sha256',$email.'|'.$requestKey):'';
        $rows=json_read(data_dir().'/inquiries.json');if($requestKey)foreach($rows as $old)if(($old['requestKey']??'')===$requestKey)return ['success'=>true,'id'=>$old['id'],'confirmation'=>($old['confirmation']??'disabled')==='accepted'];
        $captchaNonce=inquiry_captcha_verify($v['captcha']??null,$rows);
        check_login_rate('inquiry');login_attempt('inquiry',false);
        $id=gmdate('Ymd').'-'.bin2hex(random_bytes(6));$attachments=[];$pending=[];$files=$_FILES['attachments']??null;$total=0;
        if($files){$names=(array)$files['name'];if(count($names)>3)fail('Можно прикрепить не более 3 файлов.');
            // Validate every file before moving any uploaded personal material into storage.
            foreach($names as $i=>$original){$error=((array)$files['error'])[$i]??UPLOAD_ERR_NO_FILE;if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)fail('Не удалось загрузить файл. Максимум 5 МБ на файл.');
                $tmp=((array)$files['tmp_name'])[$i];$bytes=(int)(((array)$files['size'])[$i]??0);$total+=$bytes;if(!$bytes || $bytes>5*1024*1024 || $total>15*1024*1024)fail('До 3 файлов по 5 МБ, всего не более 15 МБ.',413);
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime]??null;
                if(!$ext || ($ext!=='pdf' && !getimagesize($tmp)))fail('Прикрепите JPG, PNG, WebP или PDF.');
                $pending[]=['tmp'=>$tmp,'ext'=>$ext,'mime'=>$mime,'bytes'=>$bytes,'name'=>clean_text(preg_replace('/[\\x00-\\x1f\\x7f]/u','',basename((string)$original)),160)?:'photo.'.$ext];
            }
            $folder=data_dir().'/inquiry-files/'.$id;
            try{foreach($pending as $a){if(!is_dir($folder))mkdir($folder,0700,true);$file=bin2hex(random_bytes(12)).'.'.$a['ext'];$path=$folder.'/'.$file;if(!move_uploaded_file($a['tmp'],$path))throw new RuntimeException('Cannot store attachment.');chmod($path,0600);$attachments[]=['file'=>$file,'name'=>$a['name'],'mime'=>$a['mime'],'bytes'=>$a['bytes'],'path'=>$path];}}
            catch(Throwable $e){foreach($attachments as $a)@unlink($a['path']);if(is_dir($folder))@rmdir($folder);throw $e;}
        }
        $row=['id'=>$id,'at'=>now(),'status'=>'new','name'=>$name,'email'=>$email,'phone'=>clean_text($v['phone']??'',80),'topic'=>clean_text($v['topic']??'Подбор запчастей',200),'message'=>$message,'page'=>clean_text($v['page']??'',1000),'consent'=>true,'requestKey'=>$requestKey,'captchaNonce'=>$captchaNonce,'notification'=>'disabled','confirmation'=>'disabled','attachments'=>array_map(function($a){unset($a['path']);return $a;},$attachments)];
        // Persist before attempting delivery: a mail outage must not lose a customer's request.
        $rows[]=$row;json_write(data_dir().'/inquiries.json',$rows);audit('inquiry_received',['id'=>$id,'attachments'=>count($attachments)]);
        $index=count($rows)-1;
        if($s['mailNotifications'] && filter_var($s['notificationEmail'],FILTER_VALIDATE_EMAIL)){
            $text="Новая заявка $id\nИмя: $name\nEmail: $email\nТелефон: ".$row['phone']."\nТема: ".$row['topic']."\n\n$message\n\nИсточник: ".$row['page']."\nЗаявка также сохранена в панели: ".rtrim($s['siteUrl'],'/').'/admin/#inquiries';
            $sent=send_company_mail($s['notificationEmail'],'Спецпартс49 — новая заявка '.$id,$text,$email,$attachments);$rows[$index]['notification']=$sent['status'];
        }
        if(!empty($s['clientConfirmations'])){
            $text="Здравствуйте, $name!\n\nВаш запрос № $id принят компанией «Спецпартс49». Менеджер рассмотрит обращение и свяжется с вами по указанным контактам.\n\nПо вопросам заявки: ".$s['email']."\n".$s['phone']."\n\nЭто автоматическое подтверждение получения заявки. Наличие, стоимость и сроки поставки будут согласованы с менеджером.";
            $sent=send_company_mail($email,'Спецпартс49 — запрос '.$id.' принят',$text,$s['email']);$rows[$index]['confirmation']=$sent['status'];
        }
        json_write(data_dir().'/inquiries.json',$rows);return ['success'=>true,'id'=>$id,'confirmation'=>$rows[$index]['confirmation']==='accepted'];
    });echo json_encode($result,JSON_UNESCAPED_UNICODE);
}catch(CmsError $e){http_response_code($e->getCode()>=400?$e->getCode():400);echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('SP49 inquiry: '.$e->getMessage());http_response_code(500);echo '{"success":false,"error":"Не удалось принять запрос. Повторите позже или свяжитесь с отделом продаж."}';}
