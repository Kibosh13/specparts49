<?php
declare(strict_types=1);
define('SP49_ADMIN',true);
require __DIR__.'/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
ini_set('display_errors','0');
function respond(array $value,int $status=200): never { http_response_code($status); echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit; }
function settings_validate(array $v,array $old): array {
    $s=$old;
    foreach (['companyName'=>100,'slogan'=>300,'phone'=>80,'email'=>200,'warehouse'=>500,'address'=>1000,'hours'=>500,'legalName'=>250,'inn'=>20,'ogrn'=>30,'notificationEmail'=>200] as $key=>$max) if (array_key_exists($key,$v)) $s[$key]=clean_text($v[$key],$max);
    foreach (['email','notificationEmail'] as $key) if ($s[$key] && !filter_var($s[$key],FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес электронной почты.');
    foreach (['accent','ink','paper'] as $key) if (isset($v[$key])) { if (!preg_match('/^#[0-9a-f]{6}$/i',$v[$key])) fail('Неверный формат цвета.'); $s[$key]=$v[$key]; }
    foreach (['logo','favicon'] as $key) if (isset($v[$key])) $s[$key]=safe_link($v[$key],true);
    if (isset($v['font'])) { if (!in_array($v['font'],['Manrope, Arial, sans-serif','Arial, sans-serif','Georgia, serif','system-ui, sans-serif'],true)) fail('Выберите шрифт из списка.');$s['font']=$v['font']; }
    if (isset($v['siteUrl'])) { $url=rtrim(clean_text($v['siteUrl'],300),'/'); if (!preg_match('~^https://[a-z0-9.-]+$~i',$url) && !(PHP_SAPI==='cli-server' && preg_match('~^http://(localhost|127\.0\.0\.1):\d+$~',$url))) fail('Укажите HTTPS-адрес основного сайта без пути.');$s['siteUrl']=$url; }
    foreach (['indexing','inquiriesEnabled','mailNotifications'] as $key) if (isset($v[$key])) $s[$key]=(bool)$v[$key];
    if (!$s['companyName'] || !$s['legalName']) fail('Укажите название компании.');
    if ($s['inquiriesEnabled'] && !$s['email']) fail('Для приёма обращений укажите контактный email компании.');
    if ($s['mailNotifications'] && !$s['notificationEmail']) fail('Укажите email для уведомлений.');
    return $s;
}
function page_validate(array $p): array {
    $file=page_file((string)($p['file']??''));
    $title=clean_text($p['title']??'',250); if (!$title) fail('Укажите заголовок страницы для поиска.');
    return ['file'=>$file,'title'=>$title,'description'=>clean_text($p['description']??'',1000),'image'=>safe_link($p['image']??'assets/hero.jpg',true),'noindex'=>(bool)($p['noindex']??false),'archived'=>(bool)($p['archived']??false),'html'=>sanitize_html((string)($p['html']??''))];
}
function import_rows(string $csv,array $c): array {
    if (strlen($csv)>5*1024*1024) fail('CSV должен быть меньше 5 МБ.');
    $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);
    if (!mb_check_encoding($csv,'UTF-8')) $csv=mb_convert_encoding($csv,'UTF-8','Windows-1251');
    $line=strtok($csv,"\n");$delimiter=substr_count($line?:'',';')>=substr_count($line?:'',',')?';':',';
    $f=fopen('php://temp','r+');fwrite($f,$csv);rewind($f);$header=fgetcsv($f,0,$delimiter,'"','');
    if (!$header) fail('Пустой CSV-файл.');
    $aliases=['артикул'=>'article','название'=>'name','бренд'=>'brand','категория'=>'cat','группа'=>'cat','цена'=>'price','остаток'=>'quantity','описание'=>'desc','статус'=>'stock','изображение'=>'image','опубликован'=>'published'];
    $header=array_map(fn($v)=>$aliases[mb_strtolower(trim($v))]??mb_strtolower(trim($v)),$header);
    if (!in_array('name',$header,true) || !in_array('article',$header,true)) fail('В CSV нужны столбцы name и article (название и артикул).');
    $rows=[];$errors=[];$n=1;$used=[];
    while (($values=fgetcsv($f,0,$delimiter,'"',''))!==false) {
        $n++; if (count($rows)+count($errors)>5000) fail('За один импорт можно загрузить до 5000 строк.');
        if (!array_filter($values,fn($v)=>trim($v)!=='')) continue;
        if (count($values)!==count($header)) { $errors[]="Строка $n: число столбцов не совпадает с заголовком.";continue; }
        $row=array_combine($header,$values);foreach ($row as &$cell) if (str_starts_with($cell,"'") && preg_match('/^[=+@\-\t\r]/',substr($cell,1))) $cell=substr($cell,1);unset($cell);
        $article=trim($row['article']);$existing=null;$providedId=trim($row['id']??'');$matches=[];
        foreach ($c['products'] as $p) if (!($p['archived']??false)) {
            if ($providedId && $p['id']===$providedId) { $existing=$p;break; }
            if (!$providedId && $article!=='' && ($p['article']??'')===$article && (empty($row['brand']) || ($p['brand']??'')===trim($row['brand']))) $matches[]=$p;
        }
        if (!$providedId && count($matches)>1) { $errors[]="Строка $n: несколько товаров с этим артикулом. Укажите id или бренд.";continue; }
        $existing=$existing??($matches[0]??null);
        $v=$existing??['id'=>$providedId?:'item-'.substr(hash('sha256',$article.'|'.($row['brand']??'').'|'.$row['name']),0,16),'cat'=>$c['categories'][0]['id']??'engine','types'=>['bulldozer','loader','dumptruck','grader'],'stock'=>'unknown','example'=>false,'published'=>false,'asset'=>'gear'];
        foreach (['name','article','brand','cat','desc','stock','image'] as $key) if (isset($row[$key]) && $row[$key]!=='') $v[$key]=$row[$key];
        foreach (['price','quantity'] as $key) if (array_key_exists($key,$row)) $v[$key]=$row[$key]===''?null:str_replace(',','.',str_replace(' ','',$row[$key]));
        if (isset($row['published'])) $v['published']=in_array(mb_strtolower(trim($row['published'])),['1','true','да'],true);
        try { $v=entity_validate('products',$v,$c);if (isset($used[$v['id']])) fail('Артикул повторяется в файле.');$used[$v['id']]=true;$rows[]=['row'=>$n,'mode'=>$existing?'update':'create','item'=>$v]; }
        catch (CmsError $e) { $errors[]="Строка $n: ".$e->getMessage(); }
    }
    fclose($f);return ['rows'=>$rows,'errors'=>$errors,'create'=>count(array_filter($rows,fn($r)=>$r['mode']==='create')),'update'=>count(array_filter($rows,fn($r)=>$r['mode']==='update'))];
}
try {
    start_session(); $action=(string)($_GET['action']??'status'); $method=$_SERVER['REQUEST_METHOD']??'GET';
    $reads=['status','data','media','history','audit','users','inquiries','export','exportProducts'];
    if (!in_array($action,$reads,true) && $method!=='POST') fail('Используйте POST.',405);
    if ($method==='POST') {
        csrf_check();
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>22*1024*1024) fail('Запрос слишком большой.',413);
        if (str_contains($_SERVER['CONTENT_TYPE']??'','multipart/form-data')) $body=$_POST;
        else { $raw=file_get_contents('php://input');$body=json_decode($raw?:'{}',true,512,JSON_THROW_ON_ERROR);if (!is_array($body)) fail('Неверный формат запроса.'); }
    } else $body=[];
    if ($action==='status') respond(['version'=>CMS_VERSION,'installed'=>count(auth_data()['users'])>0,'user'=>current_user(false),'csrf'=>$_SESSION['csrf'],'requirements'=>requirements(),'setupReady'=>!requirements() && (is_file(__DIR__.'/install-key.php') || (PHP_SAPI==='cli-server' && getenv('SP49_TEST_KEY_HASH')))]);
    $result=locked(function() use($action,$body) {
        if ($action==='setup') {
            if (requirements()) fail('Сервер не готов: '.implode(', ',requirements()),500);
            $auth=auth_data(); if ($auth['users']) fail('Первичная настройка уже завершена.',403);
            $keyFile=__DIR__.'/install-key.php';$config=is_file($keyFile)?require $keyFile:[];
            if (PHP_SAPI==='cli-server' && getenv('SP49_TEST_KEY_HASH')) $config=['hash'=>getenv('SP49_TEST_KEY_HASH'),'expires'=>time()+3600];
            if (!$config || time()>(int)($config['expires']??0) || !hash_equals((string)($config['hash']??''),hash('sha256',(string)($body['key']??'')))) fail('Ссылка первоначальной настройки недействительна.',403);
            $email=strtolower(clean_text($body['email']??'',200));if (!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Укажите email администратора.');
            $password=(string)($body['password']??'');valid_password($password);if ($password!==($body['passwordRepeat']??'')) fail('Пароли не совпадают.');
            $u=['id'=>bin2hex(random_bytes(12)),'name'=>clean_text($body['name']??'Администратор',100),'email'=>$email,'role'=>'owner','active'=>true,'version'=>1,'hash'=>password_digest($password),'createdAt'=>now()];
            $auth['users'][]=$u;json_write(data_dir().'/auth.json',$auth);sign_in($u);content();audit('setup');
            if (is_file($keyFile)) @unlink($keyFile);
            return ['user'=>current_user(),'csrf'=>$_SESSION['csrf']];
        }
        if ($action==='login') {
            $email=strtolower(clean_text($body['email']??'',200));check_login_rate($email);check_login_rate('*all*');$found=null;
            foreach (auth_data()['users'] as $u) if ($u['email']===$email && ($u['active']??true)) $found=$u;
            $hash=$found['hash']??'$2y$12$2Yf.1J2RiJKYLTlxb0W7B.95OWgmN8zIusnN2WBrdG0pVdlsWwQbi';
            $ok=password_verify((string)($body['password']??''),$hash) && $found;
            login_attempt($email,(bool)$ok);login_attempt('*all*',(bool)$ok);
            if (!$ok) { audit('login_failed');fail('Неверный email или пароль.',401); }
            sign_in($found);audit('login');return ['user'=>current_user(),'csrf'=>$_SESSION['csrf']];
        }
        $user=current_user();
        if ($action==='logout') { audit('logout');$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));return ['csrf'=>$_SESSION['csrf']]; }
        if ($action==='password') {
            $auth=auth_data();foreach ($auth['users'] as &$u) if ($u['id']===$user['id']) {
                if (!password_verify((string)($body['currentPassword']??''),$u['hash'])) fail('Текущий пароль неверен.',403);
                $p=(string)($body['password']??'');valid_password($p);if ($p!==($body['passwordRepeat']??'')) fail('Пароли не совпадают.');$u['hash']=password_digest($p);$u['version']++;$next=$u;
            }unset($u);json_write(data_dir().'/auth.json',$auth);sign_in($next);audit('password_changed');return ['csrf'=>$_SESSION['csrf']];
        }
        if ($action==='users') { require_owner();$users=auth_data()['users'];foreach ($users as &$u) unset($u['hash']);return ['users'=>$users]; }
        if ($action==='userSave') {
            require_owner();$auth=auth_data();$v=$body['user']??[];$id=clean_text($v['id']??'',50);$email=strtolower(clean_text($v['email']??'',200));if (!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Укажите корректный email.');
            foreach ($auth['users'] as $u) if ($u['email']===$email && $u['id']!==$id) fail('Этот email уже используется.');
            $exists=null;foreach ($auth['users'] as $i=>$u) if ($u['id']===$id) $exists=$i;
            $u=$exists===null?['id'=>bin2hex(random_bytes(12)),'createdAt'=>now(),'version'=>1]:$auth['users'][$exists];
            $u['email']=$email;$u['name']=clean_text($v['name']??'',100);$u['role']=($v['role']??'editor')==='owner'?'owner':'editor';$u['active']=(bool)($v['active']??true);
            if ($u['id']===$user['id'] && (!$u['active'] || $u['role']!=='owner')) fail('Нельзя отключить или понизить собственную учётную запись.');
            if (!empty($v['password'])) { valid_password($v['password']);$u['hash']=password_digest($v['password']);$u['version']++; }
            if (!isset($u['hash'])) fail('Задайте пароль нового пользователя.');
            if ($exists===null) $auth['users'][]=$u;else {$u['version']++;$auth['users'][$exists]=$u;}
            if (!array_filter($auth['users'],fn($x)=>$x['role']==='owner' && $x['active'])) fail('Нужен хотя бы один действующий администратор.');
            json_write(data_dir().'/auth.json',$auth);if ($u['id']===$user['id']) sign_in($u);audit('user_saved',['id'=>$u['id']]);return ['csrf'=>$_SESSION['csrf']];
        }
        if ($action==='audit') { require_owner();return ['audit'=>array_reverse(json_read(data_dir().'/audit.json'))]; }
        if ($action==='inquiries') { require_owner();return ['inquiries'=>array_reverse(json_read(data_dir().'/inquiries.json'))]; }
        if ($action==='inquiryStatus') {
            require_owner();$rows=json_read(data_dir().'/inquiries.json');foreach ($rows as &$r) if ($r['id']===($body['id']??'')) { if (!in_array($body['status']??'', ['new','working','done','archived'],true)) fail('Неверный статус.');$r['status']=$body['status'];$r['updatedAt']=now(); }unset($r);json_write(data_dir().'/inquiries.json',$rows);audit('inquiry_status',['id'=>$body['id']??'']);return ['saved'=>true];
        }
        $c=content();
        if ($action==='data') return ['content'=>$c,'user'=>$user];
        if ($action==='media') return ['media'=>media_list($c)];
        if ($action==='history') { $rows=[];foreach (glob(data_dir().'/history/*.json') as $f) { $v=json_read($f);$rows[]=['id'=>basename($f),'at'=>$v['at'],'label'=>$v['label'],'revision'=>$v['content']['revision']]; }usort($rows,fn($a,$b)=>strcmp($b['id'],$a['id']));return ['history'=>$rows]; }
        if ($action==='export') return ['download'=>['filename'=>'specparts49-content-'.gmdate('Y-m-d').'.json','type'=>'application/json','body'=>json_encode(['format'=>'sp49-cms-v1','exportedAt'=>now(),'content'=>$c],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)]];
        if ($action==='exportProducts') {
            $f=fopen('php://temp','r+');$columns=['id','article','name','brand','cat','price','quantity','stock','image','desc','published'];fwrite($f,"\xEF\xBB\xBF");fputcsv($f,$columns,';','"','');
            foreach ($c['products'] as $p) if (!($p['archived']??false)) { $row=[];foreach ($columns as $col) { $v=(string)($p[$col]??'');if (preg_match('/^[=+@\-\t\r]/',$v)) $v="'".$v;$row[]=$v; }fputcsv($f,$row,';','"',''); }rewind($f);$csv=stream_get_contents($f);fclose($f);return ['download'=>['filename'=>'specparts49-products.csv','type'=>'text/csv; charset=utf-8','body'=>$csv]];
        }
        revision_check($c,$body['revision']??0);
        if ($action==='pageSave') { $p=page_validate($body['page']??[]);if (!isset($c['pages'][$p['file']]) && count($c['pages'])>=100) fail('Можно создать до 100 страниц.');if ($p['file']==='index.html' && $p['archived']) fail('Главная должна оставаться доступной.');$c['pages'][$p['file']]=$p;$c=save_content($c,'page_saved'); }
        elseif ($action==='commonSave') { $slot=$body['slot']??'';if (!array_key_exists($slot,$c['common'])) fail('Неизвестный общий блок.');$html=sanitize_html((string)($body['html']??''));$d=html_doc($html);$node=xpath($d,'//*['.class_xpath($slot).']')[0]??null;if (!$node) fail('Сохраните внешний контейнер общего блока.');$c['common'][$slot]=$d->saveHTML($node);$c=save_content($c,'common_saved'); }
        elseif ($action==='settingsSave') {
            require_owner();$old=$c['settings'];$s=settings_validate($body['settings']??[],$old);
            foreach ($c['pages'] as &$p) { $d=html_doc($p['html']);bind_settings($d,$s,$old);$p['html']=$d->saveHTML(); }unset($p);
            foreach ($c['common'] as &$html) { $d=html_doc($html);bind_settings($d,$s,$old);$html=$d->saveHTML(); }unset($html);
            $c['settings']=$s;$c=save_content($c,'settings_saved');
        }
        elseif ($action==='navigationSave') { $items=[];foreach ((array)($body['items']??[]) as $v) { if (count($items)>=15) fail('Не больше 15 пунктов основного меню.');$title=clean_text($v['title']??'',60);if ($title) $items[]=['title'=>$title,'url'=>safe_link($v['url']??'')]; }$c['navigation']=$items;$c=save_content($c,'navigation_saved'); }
        elseif ($action==='entitySave') {
            $key=$body['collection']??'';if (!in_array($key,['products','categories','brands','offers'],true)) fail('Неизвестный раздел.');
            $v=entity_validate($key,(array)($body['item']??[]),$c);$found=false;
            foreach ($c[$key] as &$row) if ($row['id']===$v['id']) {$row=$v;$found=true;break;}unset($row);if (!$found) $c[$key][]=$v;$c=save_content($c,$key.'_saved');
        }
        elseif ($action==='entityArchive') {
            $key=$body['collection']??'';if (!in_array($key,['products','categories','brands','offers'],true)) fail('Неизвестный раздел.');$id=(string)($body['id']??'');$archive=(bool)($body['archived']??true);
            if ($key==='categories' && $archive) foreach ($c['products'] as $p) if (!($p['archived']??false) && $p['cat']===$id) fail('Сначала перенесите товары из этой категории в другую.');
            foreach ($c[$key] as &$v) if ($v['id']===$id) $v['archived']=$archive;unset($v);$c=save_content($c,$key.'_archived');
        }
        elseif ($action==='importProducts') {
            $plan=import_rows((string)($body['csv']??''),$c);if ($body['dryRun']??false) return ['plan'=>$plan];if ($plan['errors']) fail('Исправьте ошибки CSV перед импортом.');
            $map=[];foreach ($c['products'] as $p) $map[$p['id']]=$p;foreach ($plan['rows'] as $row) $map[$row['item']['id']]=$row['item'];$c['products']=array_values($map);$c=save_content($c,'products_imported');
        }
        elseif ($action==='upload') {
            $f=$_FILES['file']??null;if (!$f || $f['error']!==UPLOAD_ERR_OK) fail('Файл не загружен. Проверьте размер и повторите.');if ($f['size']>20*1024*1024) fail('Максимальный размер файла — 20 МБ.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif','application/pdf'=>'pdf','image/svg+xml'=>'svg'][$mime]??null;
            if (!$ext) fail('Разрешены PNG, JPG, WebP, GIF, безопасный SVG и PDF.');
            if ($ext==='svg') {
                $svg=(string)file_get_contents($f['tmp_name']);if (strlen($svg)>2*1024*1024 || preg_match('/<!DOCTYPE|<!ENTITY|<\s*(script|foreignObject|iframe|object|embed|animate|set|discard)|\bon[a-z]+\s*=|javascript:|@import|url\s*\(\s*[\x22\x27]?(?!#)/i',$svg)) fail('SVG содержит небезопасные элементы. Сохраните изображение как PNG.');
                $doc=new DOMDocument();$old=libxml_use_internal_errors(true);$ok=$doc->loadXML($svg,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);if (!$ok || $doc->documentElement->localName!=='svg') fail('Некорректный SVG.');
                foreach ($doc->getElementsByTagName('*') as $el) foreach ($el->attributes as $attr) if (str_ends_with(strtolower($attr->name),'href') && !str_starts_with($attr->value,'#')) fail('Внешние ссылки в SVG запрещены.');
            } elseif ($ext!=='pdf' && !getimagesize($f['tmp_name'])) fail('Повреждённое изображение.');
            $folder=root_dir().'/assets/uploads';if (!is_dir($folder)) mkdir($folder,0755,true);chmod($folder,0755);$path='assets/uploads/'.gmdate('Ymd').'-'.bin2hex(random_bytes(10)).'.'.$ext;
            if (!move_uploaded_file($f['tmp_name'],root_dir().'/'.$path)) fail('Не удалось сохранить загрузку.',500);chmod(root_dir().'/'.$path,0644);
            $item=['path'=>$path,'name'=>clean_text($f['name'],200),'alt'=>clean_text($body['alt']??'',500),'at'=>now(),'archived'=>false];$c['media'][]=$item;$c=save_content($c,'media_uploaded');return ['content'=>$c,'uploaded'=>$item];
        }
        elseif ($action==='mediaSave') { $path=(string)($body['path']??'');if (!str_starts_with($path,'assets/')) fail('Неверный путь.');$found=false;foreach ($c['media'] as &$m) if ($m['path']===$path) { $m['name']=clean_text($body['name']??$m['name'],200);$m['alt']=clean_text($body['alt']??'',500);$m['archived']=(bool)($body['archived']??false);$found=true; }unset($m);if (!$found) $c['media'][]=['path'=>$path,'name'=>clean_text($body['name']??basename($path),200),'alt'=>clean_text($body['alt']??'',500),'archived'=>(bool)($body['archived']??false)];$c=save_content($c,'media_saved'); }
        elseif ($action==='restore') { require_owner();$id=(string)($body['id']??'');if (!preg_match('/^[0-9]{10}-[0-9]{14}-[a-f0-9]{6}\.json$/',$id)) fail('Неизвестная версия.');$v=json_read(data_dir().'/history/'.$id);if (!isset($v['content'])) fail('Копия не найдена.',404);$next=$v['content'];$next['publishedRevision']=$c['publishedRevision'];$next['publishedAt']=$c['publishedAt'];$c=save_content($next,'restored'); }
        elseif ($action==='restoreFile') {
            require_owner();$v=$body['backup']??[];if (($v['format']??'')!=='sp49-cms-v1' || !is_array($v['content']??null)) fail('Неизвестный формат резервной копии.');$next=array_intersect_key($v['content'],$c);
            if (!isset($next['pages']['index.html'])) fail('В копии отсутствует главная страница.');$pages=[];foreach ($next['pages'] as $p) { $p=page_validate($p);$pages[$p['file']]=$p; }$next['pages']=$pages;$next['settings']=settings_validate($next['settings']??[],$c['settings']);
            foreach (['products','categories','brands','offers'] as $key) { if (!is_array($next[$key]??null)) fail('Повреждён раздел '.$key);$next[$key]=array_map(fn($x)=>entity_validate($key,$x,$next),$next[$key]); }
            foreach ($c['common'] as $key=>$value) $next['common'][$key]=sanitize_html((string)($next['common'][$key]??$value));
            $next['navigation']=array_map(fn($v)=>['title'=>clean_text($v['title']??'',60),'url'=>safe_link($v['url']??'')],(array)($next['navigation']??[]));
            $next['media']=is_array($next['media']??null)?$next['media']:[];
            $next['publishedRevision']=$c['publishedRevision'];$next['publishedAt']=$c['publishedAt'];$c=save_content($next,'backup_imported');
        }
        elseif ($action==='publish') { $publication=publish($c); return ['content'=>$c,'publication'=>$publication]; }
        else fail('Неизвестное действие.',404);
        return ['content'=>$c];
    });
    if (isset($result['download'])) { $d=$result['download'];header('Content-Type: '.$d['type']);header('Content-Disposition: attachment; filename="'.$d['filename'].'"');echo $d['body'];exit; }
    respond(['success'=>true]+$result);
} catch (CmsError $e) { respond(['success'=>false,'error'=>$e->getMessage()],in_array($e->getCode(),[400,401,403,404,405,409,413,429,500],true)?$e->getCode():400); }
catch (JsonException $e) { respond(['success'=>false,'error'=>'Неверный формат данных.'],400); }
catch (Throwable $e) { error_log('SP49 CMS: '.$e->getMessage());respond(['success'=>false,'error'=>'Не удалось выполнить операцию. Проверьте права и свободное место на хостинге.'],500); }
