<?php
declare(strict_types=1);
if (!defined('SP49_ADMIN')) { http_response_code(404); exit; }
function mail_config(): array {
    return array_merge(['mode'=>'mail','host'=>'','port'=>587,'security'=>'starttls','username'=>'','password'=>'','from'=>'sales@specparts49.ru'],json_read(data_dir().'/mail-config.json'));
}
function mail_config_public(): array { $v=mail_config();$v['passwordSet']=!empty($v['password']);unset($v['password']);return $v; }
function mail_header(string $v): string { return '=?UTF-8?B?'.base64_encode(str_replace(["\r","\n"],' ',$v)).'?='; }
function smtp_expect($socket,array $codes): void {
    $line='';for($n=0;$n<80;$n++){ $line=fgets($socket,2048);if($line===false)throw new RuntimeException('SMTP connection ended.');if(strlen($line)>=4 && $line[3]===' ')break; }
    if(!in_array((int)substr($line,0,3),$codes,true)) throw new RuntimeException('SMTP rejected operation ('.(int)substr($line,0,3).').');
}
function smtp_write($socket,string $text): void { $length=strlen($text);for($i=0;$i<$length;){$n=fwrite($socket,substr($text,$i));if(!$n)throw new RuntimeException('SMTP write failed.');$i+=$n;} }
function smtp_command($socket,string $line,array $codes): void { smtp_write($socket,$line."\r\n");smtp_expect($socket,$codes); }
function send_company_mail(string $to,string $subject,string $text,string $replyTo='',array $attachments=[]): array {
    $cfg=mail_config();$from=$cfg['from'];
    foreach ([$from,$to,$replyTo?:$from] as $address) if(!filter_var($address,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$address))return ['status'=>'failed','reason'=>'Invalid email configuration'];
    if(!preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+$/i',$from))return ['status'=>'failed','reason'=>'Invalid sender address'];
    $headers=['From: '.mail_header('Спецпартс49').' <'.$from.'>','Reply-To: '.($replyTo?:$from),'MIME-Version: 1.0'];
    $body=chunk_split(base64_encode($text),76,"\r\n");
    if($attachments){$boundary='sp49-'.bin2hex(random_bytes(18));$headers[]='Content-Type: multipart/mixed; boundary="'.$boundary.'"';$body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$body;
        foreach($attachments as $a){$bytes=file_get_contents($a['path']);if($bytes===false)throw new RuntimeException('Cannot read inquiry attachment.');$body.='--'.$boundary."\r\nContent-Type: ".$a['mime']."\r\nContent-Disposition: attachment; filename*=UTF-8''".rawurlencode($a['name'])."\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bytes),76,"\r\n");}
        $body.='--'.$boundary.'--'."\r\n";
    }else{$headers[]='Content-Type: text/plain; charset=UTF-8';$headers[]='Content-Transfer-Encoding: base64';}
    // Only the isolated CLI integration server can intercept outbound mail.
    if(PHP_SAPI==='cli-server' && getenv('SP49_TEST_MAIL')){ $dir=data_dir().'/test-mail';if(!is_dir($dir))mkdir($dir,0700);json_write($dir.'/'.bin2hex(random_bytes(8)).'.json',['to'=>$to,'subject'=>$subject,'text'=>$text,'mime'=>implode("\r\n",$headers)."\r\n\r\n".$body,'attachments'=>array_map(fn($a)=>['name'=>$a['name'],'mime'=>$a['mime']],$attachments)]);return ['status'=>'accepted']; }
    try {
        if($cfg['mode']==='mail'){if(!function_exists('mail'))return ['status'=>'failed','reason'=>'PHP mail is unavailable'];$ok=@mail($to,mail_header($subject),$body,implode("\r\n",$headers),'-f'.$from);return ['status'=>$ok?'accepted':'failed','reason'=>$ok?'':'Hosting mail transport rejected the message'];}
        if(!$cfg['host'] || !$cfg['username'] || !$cfg['password'])return ['status'=>'failed','reason'=>'SMTP configuration is incomplete'];
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$cfg['host']]]);
        $socket=@stream_socket_client(($cfg['security']==='ssl'?'ssl':'tcp').'://'.$cfg['host'].':'.$cfg['port'],$errno,$error,8,STREAM_CLIENT_CONNECT,$ctx);
        if(!$socket)throw new RuntimeException('SMTP connection unavailable.');stream_set_timeout($socket,8);
        try{smtp_expect($socket,[220]);$domain=parse_url(content()['settings']['siteUrl'],PHP_URL_HOST)?:'specparts49.ru';smtp_command($socket,'EHLO '.$domain,[250]);
            if($cfg['security']==='starttls'){smtp_command($socket,'STARTTLS',[220]);if(!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS failed.');smtp_command($socket,'EHLO '.$domain,[250]);}
            smtp_command($socket,'AUTH LOGIN',[334]);smtp_command($socket,base64_encode($cfg['username']),[334]);smtp_command($socket,base64_encode($cfg['password']),[235]);smtp_command($socket,'MAIL FROM:<'.$from.'>',[250]);smtp_command($socket,'RCPT TO:<'.$to.'>',[250,251]);smtp_command($socket,'DATA',[354]);
            $head='To: <'.$to.'>'."\r\nSubject: ".mail_header($subject)."\r\nDate: ".gmdate('D, d M Y H:i:s').' +0000'."\r\nMessage-ID: <".bin2hex(random_bytes(12)).'@'.$domain.'>'."\r\n".implode("\r\n",$headers)."\r\n\r\n";
            smtp_write($socket,preg_replace('/(?m)^\./','..',$head.$body)."\r\n.\r\n");smtp_expect($socket,[250]);@fwrite($socket,"QUIT\r\n");
        }finally{fclose($socket);}return ['status'=>'accepted'];
    }catch(Throwable $e){error_log('SP49 mail: '.$e->getMessage());return ['status'=>'failed','reason'=>'Mail transport failed; check private server log'];}
}
