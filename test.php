<?php
require 'httpClient.php';

$source=new httpClient();
//$source->debug=true;
$source->hashName='crc32b'; //optional hash of content
//$source->uAgent=''; //optional user-agent string
//$source->setCookies("var1=value1;var2=value2;domain=domain1.name"); //optional cookies
//$source->setCookies("var1=value1;var2=value2;domain=domain2.name"); //additional cookies

$source->get('https://google.com/');
$content=$source->getContent(); //get content in variable
//Or get content into other stream (file or socket)
//$fd=fopen('test.html','w+');
//$sended=$source->getContent($fd);

//Get hash of content, if hashName is set to standard hash type
//Usable if u need to pack content in archive
$crc32=$source->content->hash;


