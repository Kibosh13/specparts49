<?php
declare(strict_types=1);
// Runtime data is deliberately kept outside the directory served by nginx.
if (!defined('SP49_ADMIN')) { http_response_code(404); exit; }
const CMS_VERSION = '1.0.0';
const CORE_PAGES = ['index.html','catalog.html','product.html','request.html','manufacturers.html','offers.html','about.html','delivery.html','contacts.html','privacy.html','sources.html'];
function requirements(): array {
    $missing=[];if (PHP_VERSION_ID<80100) $missing[]='PHP 8.1 или новее';
    foreach (['DOMDocument'=>'DOM','finfo'=>'Fileinfo'] as $class=>$label) if (!class_exists($class)) $missing[]=$label;
    if (!function_exists('mb_substr')) $missing[]='Mbstring';
    if (!is_writable(root_dir()) || !is_writable(root_dir().'/assets')) $missing[]='Права на запись в каталог сайта и assets';
    return $missing;
}
function root_dir(): string { return dirname(__DIR__); }
function data_dir(): string {
    static $dir;
    if (!$dir) {
        $dir = PHP_SAPI === 'cli-server' && getenv('SP49_TEST_DATA') ? getenv('SP49_TEST_DATA') : dirname(root_dir()).'/.specparts49-cms';
        umask(0077);
        foreach ([$dir, "$dir/history", "$dir/sessions"] as $p) if (!is_dir($p) && !mkdir($p,0700,true) && !is_dir($p)) throw new RuntimeException('Не удалось создать закрытый каталог данных.');
        if (str_starts_with(realpath($dir) ?: $dir, realpath(root_dir()).DIRECTORY_SEPARATOR)) throw new RuntimeException('Каталог данных должен находиться вне сайта.');
    }
    return $dir;
}
function now(): string { return gmdate('c'); }
function fail(string $message, int $code=400): never { throw new CmsError($message,$code); }
class CmsError extends RuntimeException {}
function json_read(string $file, array $default=[]): array {
    if (!is_file($file)) return $default;
    $value=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Повреждён файл данных. Восстановите резервную копию.');
    return $value;
}
function atomic_write(string $file, string $body, int $mode=0600): void {
    $temp=$file.'.tmp-'.bin2hex(random_bytes(6));
    if (file_put_contents($temp,$body,LOCK_EX)===false) throw new RuntimeException('Не удалось сохранить файл. Проверьте свободное место.');
    chmod($temp,$mode);
    if (!rename($temp,$file)) { @unlink($temp); throw new RuntimeException('Не удалось применить изменения.'); }
}
function json_write(string $file,array $value): void { atomic_write($file,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)); }
function locked(callable $fn): mixed {
    $handle=fopen(data_dir().'/store.lock','c');
    if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Хранилище временно занято.');
    try { return $fn(); } finally { flock($handle,LOCK_UN); fclose($handle); }
}
function auth_data(): array { return json_read(data_dir().'/auth.json',['users'=>[]]); }
function start_session(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.upload_progress.enabled','0');
    session_name('sp49_admin'); session_save_path(data_dir().'/sessions');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/admin/','secure'=>PHP_SAPI!=='cli-server','httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function current_user(bool $required=true): ?array {
    $users=auth_data()['users']; $id=$_SESSION['user_id']??'';
    foreach ($users as $u) if ($u['id']===$id && ($u['active']??true) && ($u['version']??0)===($_SESSION['auth_version']??-1)) {
        if (time()-($_SESSION['last_seen']??0)>1800 || time()-($_SESSION['started']??0)>43200) break;
        $_SESSION['last_seen']=time(); unset($u['hash']); return $u;
    }
    unset($_SESSION['user_id']); if ($required) fail('Войдите в панель управления.',401); return null;
}
function require_owner(): array { $u=current_user(); if ($u['role']!=='owner') fail('Доступно только администратору.',403); return $u; }
function sign_in(array $user): void {
    session_regenerate_id(true); $_SESSION=['user_id'=>$user['id'],'auth_version'=>$user['version'],'started'=>time(),'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
}
function csrf_check(): void {
    $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if (!$token || !hash_equals($_SESSION['csrf']??'', $token)) fail('Сессия формы устарела. Обновите страницу.',403);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if ($origin) {
        $p=parse_url($origin); $host=$_SERVER['HTTP_HOST']??'';
        $originHost=($p['host']??'').(isset($p['port'])?':'.$p['port']:'');
        if (!in_array($p['scheme']??'', ['http','https'],true) || strcasecmp($originHost,$host)!==0) fail('Запрос с другого сайта запрещён.',403);
    }
}
function audit(string $action,array $meta=[]): void {
    $rows=json_read(data_dir().'/audit.json');
    $rows[]=['at'=>now(),'user'=>$_SESSION['user_id']??'guest','action'=>$action,'meta'=>$meta];
    json_write(data_dir().'/audit.json',array_slice($rows,-1000));
}
function clean_text(mixed $s,int $max=1000): string { if (!is_scalar($s) && $s!==null) fail('Неверный тип значения.');return mb_substr(trim(str_replace("\0",'',(string)$s)),0,$max); }
function safe_link(mixed $value,bool $image=false): string {
    $v=clean_text($value,1500); if ($v==='') return '';
    if (preg_match('/[\x00-\x20<>"\\\\]/u',$v) || str_starts_with($v,'//')) fail('Недопустимый адрес ссылки или файла.');
    if (preg_match('~^https://~i',$v)) { if (!filter_var($v,FILTER_VALIDATE_URL)) fail('Неверный HTTPS-адрес.'); return $v; }
    if (!$image && preg_match('~^(mailto:|tel:|#)~i',$v)) return $v;
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~',$v) || str_contains(rawurldecode($v),'..')) fail('Недопустимый адрес.');
    return $v;
}
function page_file(string $s): string {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,70}\.html$/',$s)) fail('Используйте имя страницы латиницей, например services.html.'); return $s;
}
function html_doc(string $html): DOMDocument {
    if (strlen($html)>1500000) fail('Страница слишком большая.');
    $d=new DOMDocument('1.0','UTF-8'); $old=libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    foreach (iterator_to_array($d->childNodes) as $n) if ($n->nodeType===XML_PI_NODE) $d->removeChild($n);
    libxml_clear_errors(); libxml_use_internal_errors($old); return $d;
}
function xpath(DOMDocument $d,string $q): array { return iterator_to_array((new DOMXPath($d))->query($q)); }
function class_xpath(string $name): string { return "contains(concat(' ',normalize-space(@class),' '),' $name ')"; }
function set_inner(DOMElement $node,string $html): void {
    $doc=html_doc('<div id="cms-fragment">'.$html.'</div>'); $source=$doc->getElementById('cms-fragment');
    while ($node->firstChild) $node->removeChild($node->firstChild);
    foreach (iterator_to_array($source->childNodes) as $child) $node->appendChild($node->ownerDocument->importNode($child,true));
}
function sanitize_html(string $html): string {
    $d=html_doc($html);
    foreach (xpath($d,'//iframe|//object|//embed|//base|//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="refresh"]') as $n) $n->parentNode?->removeChild($n);
    foreach (xpath($d,'//script') as $n) {
        $src=$n->getAttribute('src');
        if (!preg_match('~^assets/(app|inquiries|contact-config|cms-data)\.js(?:\?[a-zA-Z0-9=.-]+)?$~',$src)) $n->parentNode?->removeChild($n);
        else $n->textContent='';
    }
    foreach (xpath($d,'//*') as $n) {
        foreach (iterator_to_array($n->attributes) as $a) {
            $key=strtolower($a->name); $v=$a->value;
            if (str_starts_with($key,'on') || in_array($key,['srcdoc','formaction','contenteditable'],true) || str_starts_with($key,'data-editor-')) $n->removeAttribute($a->name);
            elseif (in_array($key,['href','src','xlink:href','action','poster'],true)) {
                try { safe_link($v,in_array($key,['src','poster'],true)); } catch (CmsError $e) { $n->removeAttribute($a->name); }
            } elseif ($key==='style' && preg_match('/expression|javascript|@import|url\s*\(/i',$v)) $n->removeAttribute('style');
        }
    }
    foreach (xpath($d,'//style') as $n) if (preg_match('/@import|expression|javascript|url\s*\(/i',$n->textContent)) $n->parentNode?->removeChild($n);
    return $d->saveHTML();
}
function initial_content(): array {
    $seed=json_read(__DIR__.'/catalog.seed.json');
    $pages=[];
    foreach (CORE_PAGES as $name) {
        $html=(string)file_get_contents(root_dir().'/'.$name); $d=html_doc($html);
        $title=$d->getElementsByTagName('title')->item(0)?->textContent??$name;
        $desc=xpath($d,'//meta[@name="description"]')[0]??null;
        $pages[$name]=['file'=>$name,'title'=>$title,'description'=>$desc?->getAttribute('content')??'','image'=>'assets/hero.jpg','noindex'=>false,'html'=>$html,'archived'=>false];
    }
    $d=html_doc($pages['index.html']['html']);
    $common=[]; foreach (['topbar','header','cta-band','footer'] as $cls) { $n=xpath($d,'//*['.class_xpath($cls).']')[0]??null; $common[$cls]=$n?$d->saveHTML($n):''; }
    return ['revision'=>1,'publishedRevision'=>1,'updatedAt'=>now(),'publishedAt'=>now(),'pages'=>$pages,'common'=>$common,
        'products'=>$seed['products']??[],'categories'=>$seed['categories']??[],'brands'=>$seed['brands']??[],'offers'=>[],
        'settings'=>['companyName'=>'Спецпартс49','slogan'=>'С нами ваша техника всегда в строю.','logo'=>'assets/specparts49-logo.png','favicon'=>'assets/favicon.svg','phone'=>'','email'=>'','warehouse'=>'Склад в г. Магадан','address'=>'г. Магадан, ул. Кольцевая, д. 3','hours'=>'','legalName'=>'ООО «СП49»','inn'=>'4900015825','ogrn'=>'1244900000201','accent'=>'#ffe500','ink'=>'#171918','paper'=>'#f5f5f1','font'=>'Manrope, Arial, sans-serif','siteUrl'=>'https://specparts49.ru','indexing'=>true,'inquiriesEnabled'=>false,'mailNotifications'=>false,'notificationEmail'=>''],
        'navigation'=>[['title'=>'Каталог','url'=>'catalog.html'],['title'=>'Производители','url'=>'manufacturers.html'],['title'=>'Акции','url'=>'offers.html'],['title'=>'О компании','url'=>'about.html'],['title'=>'Поставка','url'=>'delivery.html'],['title'=>'Контакты','url'=>'contacts.html']], 'media'=>[]];
}
function content(): array {
    $f=data_dir().'/content.json'; if (!is_file($f)) json_write($f,initial_content()); return json_read($f);
}
function revision_check(array $c,mixed $revision): void { if ((int)$revision!==$c['revision']) fail('Данные уже изменены в другой вкладке. Обновите панель, чтобы сохранить обе правки.',409); }
function snapshot(array $c,string $label): void {
    $file=data_dir().'/history/'.sprintf('%010d',$c['revision']).'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3)).'.json';
    json_write($file,['at'=>now(),'label'=>$label,'user'=>$_SESSION['user_id']??'system','content'=>$c]);
    $files=glob(data_dir().'/history/*.json'); sort($files); foreach (array_slice($files,0,max(0,count($files)-40)) as $f) @unlink($f);
}
function save_content(array $c,string $label): array {
    $old=content(); snapshot($old,$label); $c['revision']=$old['revision']+1; $c['updatedAt']=now();
    json_write(data_dir().'/content.json',$c); audit($label,['revision'=>$c['revision']]); return $c;
}
function entity_validate(string $collection,array $v,array $c): array {
    $v['id']=clean_text($v['id']??'',80);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/',$v['id'])) fail('Идентификатор: латинские буквы, цифры и дефис.');
    $v['published']=(bool)($v['published']??true); $v['order']=(int)($v['order']??0);
    $v['name']=clean_text($v['name']??$v['title']??'',250); if (!$v['name']) fail('Укажите название.');
    foreach (['image','logo'] as $key) if (isset($v[$key])) $v[$key]=safe_link($v[$key],true);
    foreach (['description','desc'] as $key) if (isset($v[$key])) $v[$key]=clean_text($v[$key],20000);
    if ($collection==='products') {
        if (!in_array($v['cat']??'',array_column($c['categories'],'id'),true)) fail('Выберите существующую группу товаров.');
        $v['article']=clean_text($v['article']??'',150); $v['brand']=clean_text($v['brand']??'',100); $v['example']=(bool)($v['example']??false);
        $v['specs']=clean_text($v['specs']??'',10000);$v['seoTitle']=clean_text($v['seoTitle']??'',250);$v['seoDescription']=clean_text($v['seoDescription']??'',1000);
        $v['types']=array_values(array_intersect(array_filter((array)($v['types']??[]),'is_string'),['bulldozer','loader','dumptruck','grader']));
        $v['stock']=in_array($v['stock']??'', ['unknown','in_stock','on_order','out_of_stock'],true)?$v['stock']:'unknown';
        foreach (['price','quantity'] as $key) { $value=$v[$key]??null; $v[$key]=$value===''||$value===null?null:filter_var($value,FILTER_VALIDATE_FLOAT); if ($v[$key]!==null && ($v[$key]===false || $v[$key]<0 || $v[$key]>1e12)) fail('Цена и остаток должны быть неотрицательными числами.'); }
        if ($v['quantity']!==null && floor($v['quantity'])!==$v['quantity']) fail('Остаток должен быть целым числом.');
        $v['asset']=preg_match('/^[a-z0-9-]+$/',$v['asset']??'')?$v['asset']:'gear';
    }
    if ($collection==='offers') {
        $v['title']=$v['name']; foreach (['article','manufacturer','price','oldPrice','until'] as $key) $v[$key]=clean_text($v[$key]??'',250);
        if ($v['until'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$v['until'])) fail('Неверная дата окончания акции.');
    }
    return $v;
}
function ordered_published(array $rows): array { $rows=array_values(array_filter($rows,fn($r)=>($r['published']??true) && !($r['archived']??false))); usort($rows,fn($a,$b)=>($a['order']??0)<=>($b['order']??0)); return $rows; }
function h(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function brand_grid(array $c): string {
    $html=''; foreach (ordered_published($c['brands']) as $b) {
        $name=$b['name']; $image=$b['logo']??'';
        $html.='<a class="brand-tile brand-'.h($b['id']).(!empty($b['dark'])?' brand-dark':'').'" href="request.html?brand='.rawurlencode($name).'" aria-label="Запросить продукцию '.h($name).'">'.($image?'<img src="'.h($image).'" alt="'.h($name).'" width="150" height="62" loading="lazy"'.(empty($b['dark'])?' style="background:transparent;padding:0"':'').'>':'<span class="brand-text-mark">'.h($name).'</span>').'<span class="brand-tile-name">'.h($name).'<svg class="ico" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h12M11 5l5 5-5 5"/></svg></span>'.(!empty($b['description'])?'<small class="brand-tile-description">'.h($b['description']).'</small>':'').'</a>';
    } return $html;
}
function category_grid(array $c): string {
    $html=''; foreach (ordered_published($c['categories']) as $v) {
        if ($v['id']==='all') continue;
        $html.='<a class="group-card" href="catalog.html?category='.rawurlencode($v['id']).'"><div class="group-copy"><h3>'.h($v['name']).'</h3><span class="group-rule"></span><p>'.h($v['description']??'').'</p></div><img src="'.h($v['image']??'assets/gear.svg').'" alt="" loading="lazy"><span class="group-arrow">→</span></a>';
    } return $html;
}
function product_status(array $p): string { return isset($p['quantity'])?($p['quantity']>0?'В наличии: '.$p['quantity'].' шт.':'Нет в наличии'):(['in_stock'=>'В наличии','on_order'=>'Под заказ','out_of_stock'=>'Нет в наличии'][$p['stock']??'']??'Наличие уточняется'); }
function product_price(array $p): string { return isset($p['price'])?rtrim(rtrim(number_format((float)$p['price'],2,',',' '),'0'),',').' ₽':'По запросу'; }
function product_image(array $p): string { return $p['image']??'assets/'.($p['asset']??'gear').'.svg'; }
function product_url(array $p): string { return !empty($p['example'])?'product.html?id='.rawurlencode($p['id']):'part-'.$p['id'].'.html'; }
function product_card_html(array $p,array $c): string {
    $cat='Запчасти';foreach ($c['categories'] as $v) if ($v['id']===$p['cat']) $cat=$v['name'];
    $kind=!empty($p['example'])?'ПРИМЕР ПОЗИЦИИ':($p['brand']??'');
    return '<article class="product-card"><a class="product-art" href="'.h(product_url($p)).'" aria-label="'.h($p['name']).'">'.($kind?'<span class="product-kind">'.h($kind).'</span>':'').'<img src="'.h(product_image($p)).'" alt="'.h($p['name']).'" loading="lazy"></a><div class="product-info"><small>'.h($cat).'</small><a href="'.h(product_url($p)).'"><h3>'.h($p['name']).'</h3></a>'.(!empty($p['article'])?'<p class="offer-article">Артикул: '.h($p['article']).'</p>':'').(isset($p['price'])?'<p class="cms-product-price">'.h(product_price($p)).'</p>':'').'<div class="product-status">'.h(product_status($p)).'</div><button class="button small" data-add="'.h($p['id']).'">В запрос +</button></div></article>';
}
function product_page(array $p,array $c): array {
    $page=$c['pages']['product.html'];$page['file']='part-'.$p['id'].'.html';$page['title']=$p['seoTitle']?:$p['name'].' — '.$c['settings']['companyName'];$page['description']=$p['seoDescription']?:mb_substr($p['desc']??'',0,250);$page['image']=product_image($p);$page['noindex']=false;$page['archived']=false;
    $d=html_doc($page['html']);$d->getElementsByTagName('body')->item(0)->setAttribute('data-product-id',$p['id']);
    $n=$d->getElementById('product-detail');
    $specs='';foreach (explode("\n",$p['specs']??'') as $line) if (trim($line)) {$parts=explode(':',$line,2);$specs.='<div><dt>'.h(count($parts)>1?$parts[0]:'Характеристика').'</dt><dd>'.h($parts[1]??$parts[0]).'</dd></div>';}
    if ($n) set_inner($n,'<div class="detail-art"><img src="'.h(product_image($p)).'" alt="'.h($p['name']).'"></div><div class="detail-copy"><h1>'.h($p['name']).'</h1><p>'.h($p['desc']??'').'</p><dl><div><dt>Артикул</dt><dd>'.h($p['article']??'Уточняется').'</dd></div><div><dt>Бренд</dt><dd>'.h($p['brand']??'Уточняется').'</dd></div><div><dt>Стоимость</dt><dd>'.h(product_price($p)).'</dd></div>'.$specs.'</dl><div class="product-status">'.h(product_status($p)).'</div><button class="button" data-add="'.h($p['id']).'">В запрос +</button></div>');
    $page['html']=$d->saveHTML();return $page;
}
function bind_settings(DOMDocument $d,array $s,array $old=[]): void {
    foreach (xpath($d,'//*['.class_xpath('brand-slogan').' or '.class_xpath('footer-slogan').']') as $n) $n->textContent=$s['slogan'];
    foreach (xpath($d,'//a['.class_xpath('brand').']/img') as $n) { $n->setAttribute('src',$s['logo']); $n->setAttribute('alt',$s['companyName']); }
    foreach (xpath($d,'//link[@rel="icon"]') as $n) $n->setAttribute('href',$s['favicon']);
    foreach (xpath($d,'//nav[@id="main-nav"]/a') as $n) $n->setAttribute('class','');
    if ($old) foreach (xpath($d,'//body//text()[normalize-space(.)!=""]') as $text) {
        foreach (['companyName','legalName','inn','ogrn','warehouse','address'] as $key) if (!empty($old[$key]) && $old[$key]!==$s[$key]) { $text->nodeValue=str_replace($old[$key],$s[$key],$text->nodeValue);if ($key==='companyName') $text->nodeValue=str_replace(mb_strtoupper($old[$key]),mb_strtoupper($s[$key]),$text->nodeValue); }
    }
}
function render_page(array $page,array $c): string {
    $html=sanitize_html($page['html']); $d=html_doc($html); $s=$c['settings'];
    foreach ($c['common'] as $cls=>$fragment) {
        $node=xpath($d,'//*['.class_xpath($cls).']')[0]??null;
        if ($node && $fragment) { $temp=html_doc($fragment); $new=xpath($temp,'//*['.class_xpath($cls).']')[0]??null; if ($new) $node->parentNode->replaceChild($d->importNode($new,true),$node); }
    }
    bind_settings($d,$s);
    $nav=$d->getElementById('main-nav');
    if ($nav) { $markup=''; foreach ($c['navigation'] as $v) if (($v['title']??'') && ($v['url']??'')) $markup.='<a href="'.h($v['url']).'" class="'.(explode('?',$v['url'])[0]===$page['file']?'active':'').'">'.h($v['title']).'</a>'; set_inner($nav,$markup); }
    foreach (xpath($d,'//*['.class_xpath('supplied-brand-grid').']') as $node) set_inner($node,brand_grid($c));
    if ($page['file']==='index.html') foreach (xpath($d,'//*['.class_xpath('group-grid').']') as $node) set_inner($node,category_grid($c));
    if ($page['file']==='catalog.html' && $d->getElementById('product-grid')) { $cards='';foreach (ordered_published($c['products']) as $p) $cards.=product_card_html($p,$c);set_inner($d->getElementById('product-grid'),$cards); }
    if ($page['file']==='contacts.html') {
        $node=xpath($d,'//*['.class_xpath('contact-lines').']')[0]??null;
        if ($node) {
            $rows=[['Телефон отдела продаж',$s['phone']?:'Будет добавлен',$s['phone']?'tel:'.preg_replace('/[^+0-9]/','',$s['phone']):''],['Электронная почта',$s['email']?:'Будет добавлена',$s['email']?'mailto:'.$s['email']:''],['Юридический адрес',$s['address'],''],['Склад',$s['warehouse'],''],['Режим работы',$s['hours'],''],['Реквизиты',$s['legalName'].' · ИНН '.$s['inn'].' · ОГРН '.$s['ogrn'],'']];
            $markup=''; foreach ($rows as [$label,$value,$url]) if ($value) $markup.='<div class="contact-line"><div><small>'.h($label).'</small><h3>'.($url?'<a href="'.h($url).'">'.h($value).'</a>':h($value)).'</h3></div></div>'; set_inner($node,$markup);
        }
    }
    $head=$d->getElementsByTagName('head')->item(0); if (!$head) fail('У страницы отсутствует блок head.');
    foreach (xpath($d,'//link[starts-with(@href,"assets/style.css")]') as $style) $style->setAttribute('href','assets/style.css?v='.substr(hash_file('sha256',root_dir().'/assets/style.css'),0,12));
    $title=$d->getElementsByTagName('title')->item(0); if (!$title) { $title=$d->createElement('title'); $head->appendChild($title); } $title->textContent=$page['title'];
    foreach (xpath($d,'//meta[@name="description" or @name="robots" or @name="googlebot" or @name="bingbot"]|//meta[starts-with(@property,"og:")]|//link[@rel="canonical"]') as $n) $n->parentNode->removeChild($n);
    foreach (['description'=>$page['description'],'robots'=>($s['indexing'] && !$page['noindex'] && !$page['archived'])?'index, follow':'noindex, nofollow'] as $name=>$value) { $n=$d->createElement('meta'); $n->setAttribute('name',$name);$n->setAttribute('content',$value);$head->appendChild($n); }
    $url=rtrim($s['siteUrl'],'/').'/'.($page['file']==='index.html'?'':$page['file']);
    $n=$d->createElement('link');$n->setAttribute('rel','canonical');$n->setAttribute('href',$url);$head->appendChild($n);
    foreach (['og:title'=>$page['title'],'og:description'=>$page['description'],'og:url'=>$url,'og:type'=>'website','og:image'=>preg_match('~^https://~',$page['image'])?$page['image']:rtrim($s['siteUrl'],'/').'/'.$page['image']] as $name=>$value) { $n=$d->createElement('meta');$n->setAttribute('property',$name);$n->setAttribute('content',$value);$head->appendChild($n); }
    if (!xpath($d,'//link[starts-with(@href,"assets/cms-theme.css")]')) { $n=$d->createElement('link');$n->setAttribute('rel','stylesheet');$n->setAttribute('href','assets/cms-theme.css?v='.$c['revision']);$head->appendChild($n); }
    // Sandboxed previews have no scripts; publish only the trusted application entry points.
    foreach (xpath($d,'//script') as $script) $script->parentNode->removeChild($script);
    foreach (['contact-config','cms-data','app','inquiries'] as $asset) { $script=$d->createElement('script');$version=$asset==='cms-data'?'cms-'.$c['revision']:substr(hash_file('sha256',root_dir().'/assets/'.$asset.'.js'),0,12);$script->setAttribute('src','assets/'.$asset.'.js?v='.$version);$script->setAttribute('defer','defer');$head->appendChild($script); }
    if ($page['archived']) { $main=$d->getElementById('main'); if ($main) set_inner($main,'<div class="wrap section"><h1>Страница недоступна</h1><p>Вернитесь на главную или откройте каталог.</p><a class="button" href="index.html">На главную</a></div>'); }
    return $d->saveHTML();
}
function public_bundle(array $c): string {
    $settings=$c['settings'];
    $v=['products'=>ordered_published($c['products']),'categories'=>ordered_published($c['categories']),'brands'=>ordered_published($c['brands']),'revision'=>$c['revision']];
    $offers=array_values(array_filter(ordered_published($c['offers']),fn($o)=>empty($o['until'])||$o['until']>=gmdate('Y-m-d')));
    $config=['email'=>$settings['email'],'endpoint'=>$settings['inquiriesEnabled']?rtrim($settings['siteUrl'],'/').'/admin/inquiry.php':''];
    $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR;
    return 'window.SP49_CONTENT='.json_encode($v,$flags).';window.SP49_OFFERS='.json_encode($offers,$flags).';window.SP49_CONTACT='.json_encode($config,$flags).';';
}
function publish(array &$c): array {
    $s=$c['settings'];$plan=[];
    foreach ($c['pages'] as $file=>$page) $plan[page_file($file)]=render_page($page,$c);
    $actual=[];foreach (ordered_published($c['products']) as $p) if (empty($p['example'])) { $page=product_page($p,$c);$plan[$page['file']]=render_page($page,$c);$actual[$p['id']]=true; }
    $previous=json_read(data_dir().'/published.json');foreach ($previous['products']??[] as $p) if (empty($p['example']) && !isset($actual[$p['id']])) { $page=product_page($p,$c);$page['archived']=true;$page['noindex']=true;$plan[$page['file']]=render_page($page,$c); }
    $plan['assets/cms-data.js']=public_bundle($c);
    $plan['assets/cms-theme.css']=':root{--yellow:'.$s['accent'].';--ink:'.$s['ink'].';--paper:'.$s['paper'].'}body{font-family:'.$s['font'].'}';
    $plan['robots.txt']='User-agent: *'."\n".($s['indexing']?'Allow: /':'Disallow: /')."\nDisallow: /admin/\nSitemap: ".rtrim($s['siteUrl'],'/')."/sitemap.xml\n";
    $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($c['pages'] as $page) if (!$page['archived'] && !$page['noindex'] && !in_array($page['file'],['product.html','request.html'],true)) $xml.='<url><loc>'.h(rtrim($s['siteUrl'],'/').'/'.($page['file']==='index.html'?'':$page['file'])).'</loc><lastmod>'.gmdate('Y-m-d').'</lastmod></url>';
    foreach (array_keys($actual) as $id) $xml.='<url><loc>'.h(rtrim($s['siteUrl'],'/').'/part-'.$id.'.html').'</loc><lastmod>'.gmdate('Y-m-d').'</lastmod></url>';
    $plan['sitemap.xml']=$xml.'</urlset>';
    $stage=data_dir().'/publish-'.bin2hex(random_bytes(8)); mkdir($stage,0700); $backup=[];
    try {
        foreach ($plan as $file=>$body) { $dest=root_dir().'/'.$file; if (!is_writable(dirname($dest)) || (is_file($dest)&&!is_writable($dest))) fail('Нет прав на публикацию файла '.$file,500); $backup[$file]=is_file($dest)?file_get_contents($dest):null; atomic_write($stage.'/'.str_replace('/','__',$file),$body); }
        $done=[];
        try { foreach ($plan as $file=>$body) { $dest=root_dir().'/'.$file; atomic_write($dest,$body,0644); $done[]=$file; } }
        catch (Throwable $e) { foreach ($done as $file) { if ($backup[$file]===null) @unlink(root_dir().'/'.$file); else atomic_write(root_dir().'/'.$file,$backup[$file],0644); } throw $e; }
        $c['publishedRevision']=$c['revision'];$c['publishedAt']=now();json_write(data_dir().'/published.json',$c);json_write(data_dir().'/content.json',$c);
        audit('publish',['revision'=>$c['revision'],'files'=>count($plan)]); return ['files'=>count($plan),'revision'=>$c['revision'],'at'=>$c['publishedAt']];
    } finally { foreach (glob($stage.'/*') as $f) @unlink($f); @rmdir($stage); }
}
function media_list(array $c): array {
    $items=[];$base=root_dir().'/assets'; $known=[];foreach ($c['media'] as $m) $known[$m['path']]=$m;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && preg_match('/\.(png|jpe?g|webp|gif|svg|pdf)$/i',$f->getFilename())) {
        $p='assets/'.str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($base)+1));$meta=$known[$p]??[];
        if ($meta['archived']??false) continue;
        $items[]=['path'=>$p,'name'=>$meta['name']??$f->getFilename(),'alt'=>$meta['alt']??'','bytes'=>$f->getSize(),'image'=>!str_ends_with(strtolower($p),'.pdf')];
    } return $items;
}
function valid_password(string $p): void { if (strlen($p)<12 || strlen($p)>200) fail('Пароль должен содержать от 12 до 200 символов.'); }
function password_digest(string $p): string { return password_hash($p,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT); }
function check_login_rate(string $email): void {
    $rows=json_read(data_dir().'/rate.json');$key=hash('sha256',strtolower($email).'|'.($_SERVER['REMOTE_ADDR']??''));
    $recent=array_values(array_filter($rows[$key]??[],fn($t)=>$t>time()-900)); if (count($recent)>=6) fail('Слишком много попыток входа. Повторите через 15 минут.',429);
}
function login_attempt(string $email,bool $success): void {
    $rows=json_read(data_dir().'/rate.json');$key=hash('sha256',strtolower($email).'|'.($_SERVER['REMOTE_ADDR']??''));
    foreach ($rows as $k=>$times) { $rows[$k]=array_values(array_filter($times,fn($t)=>$t>time()-900)); if (!$rows[$k]) unset($rows[$k]); }
    if ($success) unset($rows[$key]);else $rows[$key][]=time();json_write(data_dir().'/rate.json',$rows);
}
