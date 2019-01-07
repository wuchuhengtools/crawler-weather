<?php

use Beanbun\Beanbun;
use Beanbun\Lib\Helper;
use Symfony\Component\DomCrawler\Crawler;

require_once(__DIR__ . '/vendor/autoload.php');


/**
 *  @info ip代理池
 *
 */
function getProxies($beanbun) {
    $client = new \GuzzleHttp\Client();
    for ($i = 1; $i <= 5; $i++) {
        $url = "https://www.kuaidaili.com/free/inha/{$i}";
        $response = $client->request('GET', $url)->getBody()->getContents();
        //进行XPath页面数据抽取
        $data    = []; //结构化数据存本数组
        $crawler = new Crawler();
        $crawler->addHtmlContent($response);
        //抽出可访问IP放入IP池
        for ($inI = 1; $inI<= 15; $inI++) {
            $ip     = $crawler->filterXPath("//tbody/tr[$inI]/td[1]")->text(); 
            $port   = $crawler->filterXPath("//tbody/tr[$inI]/td[2]")->text(); 
            if ($ip && $port) {
                try {
                    $proxy = $ip . ":" . $port;
                    $client->get('http://www.weather.com.cn/', [
                        'proxy'   =>$proxy,
                        'timeout' => 6,
                    ]);
                    $beanbun->proxies[] = $proxy;
                    echo "$proxy \t success.\n";
                } catch (\Exception $e) {
                    echo "error.\n";
                }

            }
        }
    }
    //去重复
    if ($beanbun->proxies) $beanbun->proxies = array_unique($beanbun->proxies); 
}


/**
 *
 *
 *
 *
 */
function unicode_decode($unistr, $encoding = 'utf-8', $prefix = '&#', $postfix = ';') {
    $arruni = explode($prefix, $unistr);
    $unistr = '';
    for ($i = 1, $len = count($arruni); $i < $len; $i++) {
        if (strlen($postfix) > 0) {
            $arruni[$i] = substr($arruni[$i], 0, strlen($arruni[$i]) - strlen($postfix));
        }
        $temp = intval($arruni[$i]);
        $unistr .= ($temp < 256) ? chr(0) . chr($temp) : chr($temp / 256) . chr($temp % 256);
    }
    return iconv('UCS-2', $encoding, $unistr);
}



$beanbun = new Beanbun;
$beanbun->name = '中国天气';
$beanbun->count = 10;
/* $beanbun->seed = 'http://www.weather.com.cn/'; */ 
$beanbun->seed = 'http://www.weather.com.cn/weather1d/101280801.shtml'; 
$beanbun->max = 0;  //无限制页面数量  
$beanbun->logFile = __DIR__ . '/weather_access.log';
$beanbun->urlFilter = [
    '/http:\/\/www.weather.com.cn\/weather1d\/[0-9]+.shtml/'
];


// 设置队列
$beanbun->setQueue('redis', [
    'host' => '127.0.0.1',
    /* 'port' => '2207' */
    'port' => '6379'
]);


if ($argv[1] == 'start') getProxies($beanbun);
$beanbun->startWorker = function($beanbun) {
    // 每隔半小时，更新一下代理池
    Beanbun::timer(1800, 'getProxies', $beanbun);
};

//爬取前
$beanbun->beforeDownloadPage = function ($beanbun) {
    // 在爬取前设置请求的 headers 
    /* $beanbun->options['headers'] = [ */
    /*     'Host' => 'www.baidu.com', */
    /*     'Connection' => 'keep-alive', */
    /*     'Cache-Control' => 'max-age=0', */
    /*     'Upgrade-Insecure-Requests' => '1', */
    /*     'User-Agent' => 'Mozilla/5.0 (compatible; Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)', */
    /*     'Accept' => 'application/json, text/plain, *1/*', */
    /*     'Accept-Encoding' => 'gzip, deflate, sdch, br', */
    /*     'authorization' => 'oauth c3cef7c66a1843f8b3a9e6a1e3160e20', */
    /* ]; */
    //启用代理
    if (isset($beanbun->proxies) && count($beanbun->proxies)) {
	    $beanbun->options['proxy'] = $beanbun->proxies[array_rand($beanbun->proxies)];
    }
};


//处理下载下来的数据
$beanbun->afterDownloadPage = function($beanbun) {
    //下载失败重新加入队列中
    if(strlen($beanbun->page) < 6000 )
    {
        $beanbun->queue()->add($beanbun->url);
        $beanbun->error();
    }

    //当天气象数据抽取
    if (preg_match('/weather1d/', $beanbun->url)) {
        $crawler = new Crawler();
        $crawler->addHtmlContent($beanbun->page);
        $tmp     = $crawler->filterXPath("//*[@id='today']/script/text()")->text(); 
        $tmp     = substr($tmp, 15);
        $tmp     = iconv('Unicode','GBK',$tmp); 
        $tmp     = json_decode($tmp);    
        var_dump($tmp);
    } 
      
};

$beanbun->start();
