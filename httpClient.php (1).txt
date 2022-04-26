<?php //http client wo dependecies by cmdprompt 2022
class httpClient{
 public $chSize,$hashName,$debug,$reqheaders,$method,$host,$sock,$uri
 ,$referer,$uagent,$cookies,$post,$code,$headers,$content,$error,$maxRedirects=5;
 private $inflate,$hash_ctx,$redirects,$socks;
 
public function __construct($cookie=null){
if(!empty($cookie))
 $this->setCookies($cookie);
 $this->uagent="Mozilla/5.0 (Linux; Android 10; M2004J19C) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.92 Mobile Safari/537.36";
 $this->post=new stdclass;
 $this->content=new stdclass;
 $this->chSize=16384;
}

private function setUrl($uc){
 
$this->host=$uc['host'];
if(empty($uc['scheme']))$uc['scheme']='https';
$this->port=empty($uc['port'])?
(($this->ssl=$uc['scheme']=='https')?443:80):$uc['port'];

$this->uri=empty($uc['path'])?'/':$uc['path'];
if(!empty($uc['query']))$this->uri.='?'.$uc['query'];
}

private function connect($uc){
 
$addr = ($this->ssl ? 'ssl://' : '').$this->host.':'.$this->port;
 
$this->sock=$this->socks[$this->host] = stream_socket_client($addr, $errno, $errstr, 30, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT);//$stream_context);

 if(!$this->sock){
 $this->error = "Unable to connect to server: $errstr ($errno)";
 return false;
 }

return stream_set_timeout($this->sock,30);
}

private function checkUrl($url){
 if(!$uc=parse_url($url)){
  $this->error="wrong url:$url";
  $this->code=0;
 return false;
 }
 //echo "$this->host$this->uri\n$url";
 //if(($this->host.$this->uri)!=$url){
 $newhost=$this->host!=$uc['host'];
 $this->setUrl($uc);

  if(empty($this->socks[$uc['host']])||feof($this->socks[$uc['host']]))
   return $this->connect($uc);
  elseif($newhost)
   $this->sock=$this->socks[$uc['host']];
  

 return $uc;
}

public function request($method,$url,$body='',$headers=null){

if(!($uc=$this->checkUrl($url))) return false;
if(!in_array($this->code,array(301,302,303,307,308)))
 $this->redirects=$this->maxRedirects;

$allcookies='';

if(!empty($this->cookies))
foreach($this->cookies as$dn=>$dv)
 if(preg_match('`'.preg_quote($dn).'$`i',$this->host))
  foreach($dv as$p=>$v)
   if(strpos($this->uri,$p)===0)
    foreach($v as$cn=>$cv)
     $allcookies.="$cn=$cv; ";

$this->method=$method;
$this->reqheaders=$headers;

if($method=='POST')
 $this->post->body=$body;

if(is_array($headers))
 $headers=implode("\r\n",array_map('trim',$headers));

$bsize=strlen($body);

 $hdr="$method ".($this->uri[0]=='/'?'':'/')."$this->uri HTTP/1.1\r\n"
."Host: $this->host\r\n"
."User-Agent: $this->uagent\r\n"
.(!empty($this->referer)&&($this->ssl||substr($this->referer,0,5)=='http:')?"Referer: $this->referer\r\n":'')
."Connection: keep-alive\r\n"
."Keep-Alive: 300\r\n"
."Accept-Encoding: gzip, deflate\r\n"
.($method=='POST'&&strpos(strtolower($headers),'content-length')===false?"Content-Length: $bsize\r\n":'')
.(!empty($allcookies)?"Cookie: ".trim($allcookies)."\r\n":'')
.(!empty($headers)?trim($headers)."\r\n":'')
."\r\n".$body;

$this->log("\n\nReq: $hdr\n");

$inf=stream_get_meta_data($this->sock);

if($inf['unread_bytes']>0)$this->getContent(-1);


if(!fwrite($this->sock,$hdr))return false;

$this->referer=$url;

if(empty($this->post->files)){
 $this->getHeaders();
 return $this->code;
}else
 return true;
}

public function get($url,$headers=null){

return $this->request('GET',$url,'',$headers);
}

public function post($url,$body='',$headers=''){

if(is_array($body))$body=http_build_query($body);

$posthdr=trim($headers);
if(strpos(strtolower($posthdr),'content-type')===false)
 $posthdr.="\r\nContent-Type: application/x-www-form-urlencoded";

return $this->request('POST',$url,$body,$posthdr);
}


public function postFiles_begin($url,$files)
{
 $bound='-------------'.uniqid();
$this->post->bodyend="\r\n--$bound--\r\n";

$bsize=0;

foreach($files as$i=>&$f){
 if(is_string($f['handle'])){
  if(file_exists($f['handle'])){
  $f['size']=filesize($f['handle']);
  $f['handle']=fopen($f['handle'],'rb');
  }else return false;//http?
 }
if(empty($f['mime']))$f['mime']="application/octet-stream";
$fn=rawurlencode($f['filename']);
$bsize+=$f['size']+strlen($hfiles[$i]['header']="--$bound\r\n"
."Content-Disposition: form-data; name=\"$f[name]\"; filename=\"$fn\"\r\n"
."Content-Type: $f[mime]\r\n\r\n");
$hfiles[$i]['size']=$f['size'];
$hfiles[$i]['handle']=$f['handle'];
}

$this->post->files=$hfiles;
$bsize+=strlen($this->post->bodyend);

$posthdr="Content-Type: multipart/form-data; boundary=$bound\r\n"
."Content-Length: $bsize";

return $this->request('POST',$url,'',$posthdr);
}

public function postFiles_transfer()
{
 $hs=$fs=0;
 foreach($this->post->files as$f){
  $this->log($f['header']);
 $hs+=fwrite($this->sock,$f['header']);
 $fs+=stream_copy_to_stream($f['handle'],$this->sock,$f['size']);
 fclose($f['handle']);
 }
 $this->log("\ntransferred:$hs+$fs bytes\n");
 return $hs+$fs;
}

public function postFiles_end()
{
fwrite($this->sock,$this->post->bodyend);
$this->getHeaders();
return $this->code;
}

public function stream_copy($in,$out,$size){
 $copied=0;
 
 while($size>0&&!feof($in)){
  
  $chunk=fread($in,min($this->chSize,$size));
  $size-=strlen($chunk);

  if($this->inflate)
   $chunk=inflate_add($this->inflate,$chunk);
 
  if($this->hash_ctx)
   hash_update($this->hash_ctx,$chunk);
   
  fwrite($out,$chunk);
  $copied+=strlen($chunk);
 }
 
 return $copied;
}

public function getContent($file=NULL){
 
 if($file==-1)$stream=fopen('/dev/null','w');
 elseif(!empty($file))$stream=$file;
 elseif(!($stream=fopen('php://memory','r+')))
  return false;
  
 switch($this->content->encoding){
case 'gzip':
 $this->inflate=inflate_init(ZLIB_ENCODING_GZIP);
 break;
case 'deflate':
 $this->inflate=inflate_init(ZLIB_ENCODING_DEFLATE);
 break;
default:
 $this->inflate=null;
}

 $this->hash_ctx=$this->hashName?hash_init($this->hashName):null;


 $r=0;

 if($this->content->chunked){
 while(($chsizeh=fgets($this->sock))!="0\r\n"){
  $r+=$this->stream_copy($this->sock,$stream,intval($chsizeh,16));
  fread($this->sock,2);
 }
  fread($this->sock,2);
 }elseif(!empty($this->content->length))
  $r=$this->stream_copy($this->sock,$stream,$this->content->length);
 else{
  fclose($stream);
  return false;
 }
 /*
  if($this->inflate){
   $chunk=inflate_add($this->inflate,NULL,ZLIB_FINISH);
  
   $this->log("\nlast chunk:".htmlspecialchars($chunk));

  if(strlen($chunk)){
  fwrite($stream,$chunk);
  $r+=strlen($chunk);
  if($this->hash_ctx)
   hash_update($this->hash_ctx,$chunk);
   

  }
}*/

 if($this->hash_ctx)
  $this->content->hash=hash_final($this->hash_ctx,true);

 if(!empty($file))return$r;

 rewind($stream);
 $cont=stream_get_contents($stream);
 fclose($stream);

/*
switch($this->content->encoding){
 case 'gzip':
  return empty($file)?gzdecode($cont):fwrite($file,gzdecode($cont));
 case 'deflate':
  return empty($file)?gzinflate($cont):fwrite($file,gzinflate($cont));
}*/
  return$cont;
}

private function getCode($headers){
 return (!empty($headers[0])&&substr($headers[0],0,7)=='HTTP/1.')?
   intval(substr($headers[0],9,3)):0;
}

private function getHeaders(){
$resp='';

while(($s=fgets($this->sock))&&$s!="\r\n")
 $resp.=$s;
 
if(empty($resp)){
 $s2=fgets($this->sock);
 $inf=stream_get_meta_data($this->sock);
 $this->log("\n\nfgets return 0". var_dump($s,$s2,$inf).(feof($this->sock)?'true':'false').addslashes($s));
$this->error='empty response';
$this->code=0;
return false;
}


$this->headers=explode("\r\n",$resp);
unset($this->headers[count($this->headers)-1]);

$this->code=$this->getCode($this->headers);

$this->content->filename=$this->content->encoding=null;
$this->content->chunked=false;

$this->log("Resp: $resp");

foreach($this->headers as$h){
 $hdra=explode(': ', $h, 2);
 $hdr=strtolower($hdra[0]);
switch($hdr)
{
 case 'content-disposition':
  if(preg_match("`filename\*=UTF-8''([^;]+)`",$h,$m))
   $this->content->filename=urldecode($m[1]);
  elseif(preg_match('`filename=[\'"]?([^\'";]+)`',$h,$m))
   $this->content->filename=urldecode($m[1]);

//filename*=UTF-8''
 break;
 
 case 'content-length':
  $this->content->length=intval($hdra[1]);
 break;
 
 case 'transfer-encoding':
  $this->content->chunked=$hdra[1]=='chunked';
 break;
 
 case 'content-type':
 $this->content->mime=$hdra[1];
 break;
 
 case 'content-encoding':
  $this->content->encoding=$hdra[1];
 break;
 
 case 'set-cookie':
  $this->parseCookies($hdra[1]);
 break;
 
 case 'location';
 if(in_array($this->code,array(301,302,303,307,308)))
  $loc=$hdra[1];
}
 if(empty($this->content->filename))
 $this->content->filename=urldecode(basename(parse_url($this->host.$this->uri)['path']));

}


 if(!empty($loc)&&$this->redirects--){
  $this->getContent(-1);
  if(in_array($this->code,array(307,308))&&$this->method!='GET')
   return $this->request($this->method,$loc,$this->post->body,$this->reqheaders);
  return $this->get($loc);
 }else$this->redirects=$this->maxRedirects;

return$this->headers;
}

public function setCookies($cookies){
 if(is_array($cookies))
  foreach($cookies as$c)
   $this->parseCookies($c);
 else$this->parseCookies($cookies);

}

 private function parseCookies($cookies){
  $path='/';
  $domain=$this->host;
  foreach(explode(';',$cookies)as$c){
   $ac=explode('=',trim($c),2);
  switch(strtolower($ac[0])){
   case 'domain':
    if(empty($ac[1])
    ||$ac[1][strlen($ac[1])-1]=='.')return false;
    $domain=$ac[1][0]=='.'?substr($ac[1],1):$ac[1];
    if(!strpos($domain,'.'))return false;
    break;
   case 'path':
    $path=$ac[1];
    break;
   case 'expires':
   case 'httponly':
   case 'secure':
   case 'samesite':
   case 'priority':
   case 'max-age':
    break;
    
   default:
    if(isset($ac[1])){
     $acookie[$ac[0]]=$ac[1];
    }
   }
  }
  foreach($acookie as$cn=>$cv)
  if(empty($cv))unset($this->cookies[$domain][$path][$cn]);
  else $this->cookies[$domain][$path][$cn]=$cv;
   
  $this->log("\nset cookie! ".print_r($acookie,1)."\n");
return true;
 }

private function log($str){

 if($this->debug)echo $str;
}

}
