<?php

use Beanbun\Beanbun;
use Beanbun\Lib\Helper;
use Symfony\Component\DomCrawler\Crawler;
use Beanbun\Lib\Db;

require_once(__DIR__ . '/vendor/autoload.php');

Db::$config = [
    'zhihu' => [
        'server' => '127.0.0.1',
        'port' => '3306',
        'username' => 'root',
        'password' => '123456',
        'database_name' => 'weather',
        'database_type' => 'mysql',
        'charset' => 'utf8',
    ]
];
/**
 *  @info ip代理池
 *
 */
function getProxies($beanbun) {
    $proxies = [];
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
                    $proxies[] = $proxy;
                    echo "$proxy \t success.\n";
                } catch (\Exception $e) {
                    echo "error.\n";
                }

            }
        }
    }
    //过虑不能访问的ip
    if (isset($beanbun->proxies) && count($beanbun->proxies) > 0) {
        foreach($beanbun->proxies as $proxy){
            try {
                $proxy = $ip . ":" . $port;
                $client->get('http://www.weather.com.cn/', [
                    'proxy'   =>$proxy,
                    'timeout' => 6,
                ]);
                $proxies[] = $proxy;
                echo "$proxy \t success.\n";
            } catch (\Exception $e) {
                echo "error.\n";
            }
        } 
    }
    $beanbun->proxies = $proxies;
    //去重复
    if ($beanbun->proxies) $beanbun->proxies = array_unique($beanbun->proxies); 
}



$beanbun = new Beanbun;
$beanbun->name = '中国天气';
$beanbun->count = 10;
/* $beanbun->seed = 'http://www.weather.com.cn/'; */ 
$beanbun->seed = 'http://www.weather.com.cn/weather1d/101280101.shtml'; 
$beanbun->max = 0;  //无限制页面数量  
$beanbun->logFile = __DIR__ . '/weather_access.log';
$beanbun->urlFilter = [
    '/http:\/\/www.weather.com.cn\/weather1d\/[0-9]+.shtml/',
    '/http:\/\/forecast.weather.com.cn\/town\/weather1dn\/[0-9].shtml/',
];


// 设置队列
$beanbun->setQueue('redis', [
    'host' => '127.0.0.1',
    /* 'port' => '2207' */
    'port' => '6379'
]);

if (isset($argv[1]) && $argv[1] == 'start') getProxies($beanbun);
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
        $beanbun->log('to download data was failed '.$beanbun->url);
        $beanbun->error();
    }

    //当天气象数据抽取
    if (preg_match('/weather1d/', $beanbun->url)) {
        //详情
        $crawler = new Crawler();
        $crawler->addHtmlContent($beanbun->page);
        $hasData     = $crawler->filterXPath("//*[@id='today']/script/text()")->text(); 
        if (strlen($hasData) === 0){
            $beanbun->log('【error】 Did`t get the data of  one day ');
            $beanbun->error();
        }
        preg_match('/{.+}/', html_entity_decode($hasData), $html2Json);
        $html2Data = json_decode($html2Json[0], true);
       // var_dump($html2Data);exit;
        //位置名
        $location  = $crawler->filterXPath('//*[@id="today"]/div[1]/div/div[2]/h2/span/text()')->text(); 
        for ($i=1; $i<=5; $i++) {
            try{
                $data['url']  = $crawler->filterXPath("//div[@class='crumbs fl']/a[$i]/@href")->text();
                $data['name']    = $crawler->filterXPath("//div[@class='crumbs fl']/a[$i]/text()")->text();
                preg_match_all('/weather.*\/(.+)\.shtml/', $data['url'], $catch);
                if (isset($catch[1][0])) {
                    $data['id']   = $catch[1][0];
                    $data['pid']  = $pid;
                    $pid          = $catch[1][0] ;
                    $data['path'] = $path;
                    $path        .=  '-' . $pid;
                }else{
                    preg_match_all('/:\/\/(.+)\.weather\.com\.cn/', $data['url'], $getProvince); 
                    $data['id']   = end($getProvince[1]);
                    $data['pid']  = 0;
                    $data['path'] = 0;
                    $pid          = end($getProvince[1]);
                    $path         = end($getProvince[1]);
                }
            }catch(\EXCEPTION $e){

            }
        }
        //入库
        Db::instance('weather')->select("city", [
            "id",
            "name",
            "pid",
            "path",
            "url"
        ], [
            "id[>]" => 0 
        ]);
        exit;


    } 
      
};

$beanbun->start();
