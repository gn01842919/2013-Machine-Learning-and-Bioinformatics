<?php
//指令格式範例： php grab.php 2012 09 10 2013 10 04
//如果在最後面加個參數train，則輸出的檔案會是 $key-train.txt
//如果在最後面加個參數simulate，則輸出的檔案會是 $key-sim.txt
error_reporting(E_ALL & ~E_NOTICE);



$file_array=array();//全域變數，在grabADay會用到

grabForAPeriod($argv[1],$argv[2],$argv[3],$argv[4],$argv[5],$argv[6]);


foreach($file_array as $key => $file){ // a $file is a StockFile
	if(count($argv) > 7 and $argv[7]== "train")
		$filename="$key-train.txt";
	elseif(count($argv) > 7 and $argv[7]== "simulate")
		$filename="$key-sim.txt";
	else
		$filename="$key.txt";
	$fp = fopen($filename, "w");
	fwrite($fp, "yyyy-mm-dd, volume, open, high, low, close\n");
	$file->writeToFile($fp);	
	fclose($fp);
	//$file->showContent();
}



function grabForAPeriod($s_year, $s_month, $s_day, $e_year, $e_month, $e_day){

	if(IsInputValid($s_year, $s_month, $s_day, $e_year, $e_month, $e_day)){
	
		if($s_year==$e_year){// the same year
		
			if($s_month==$e_month){// the same month
				for($i=$s_day;$i<=$e_day;$i++){
					grabADay($s_year, $s_month, $i);
				}
			}
			else{//different months
				for($i=$s_day; $i<=31; $i++){
					grabADay($s_year, $s_month, $i);
				}
				if(($e_month-$s_month)>1){
					for($i=$s_month+1; $i<=$e_month-1; $i++){
						grabAMonth($s_year, $i);
					}
				}
				for($i=1; $i<=$e_day; $i++){
					grabADay($e_year, $e_month, $i);
				}
			}
		}
		else{
			for($i=$s_day; $i<=31; $i++){//先把這個月的抓完
				grabADay($s_year, $s_month, $i);
			}
			for($i=$s_month+1; $i<=12; $i++){//從下個月開始把今年抓完
				grabAMonth($s_year, $i);
			}
			if(($e_year-$s_year)>1){//把中間的年度抓完
				for($i=$s_year+1; $i<=$e_year-1; $i++){
					grabAYear($i);
				}
			}			
			for($i=1; $i<=$e_month-1; $i++){//把最後一年的結束月份以前的全部抓完
				grabAMonth($e_year, $i);
			}
			for($i=1; $i<=$e_day; $i++){//把最後一年的結束月份抓完
				grabADay($e_year, $e_month, $i);
			}
		}	
	}
	else
		echo "\nThe start time should be earlier then the end time.\n";
}

function IsInputValid($s_year, $s_month, $s_day, $e_year, $e_month, $e_day){
	$v1=(($s_year-1900)*12+$s_month)*30+$s_day;
	$v2=(($e_year-1900)*12+$e_month)*30+$e_day;
	return $v1 <= $v2;
}

function grabAYear($year){
	for($i=1;$i<=12;$i++){
		grabAMonth($year, $i);
	}
}

function grabAMonth($year, $month){
	for($i=1;$i<=31;$i++){
		grabADay($year, $month, $i);
	}
}

function grabADay($year, $month, $day){

	//修正格式，前面加一個0
	if(1<=$month && $month<=9 && strlen($month)==1) $month ="0".$month;
	if(1<=$day && $day<=9 && strlen($day)==1) $day = "0".$day;
//echo "$year, $month, $day \n";
	$url="http://www.twse.com.tw/ch/trading/exchange/MI_INDEX//MI_INDEX3_print.php?genpage=genpage/Report$year$month/A112$year$month$day"."ALL_1.php&type=csv";

	$target=file($url);
	if(count($target)>50){//太少行的話代表這天沒有資料（例如日期錯誤），50是隨便抓的
		//分析有用的數據
		for($i=0;$i<count($target);$i++){
			//開頭是四個數字接著一個逗點
			if(is_numeric(substr($target[$i], 0, 4)) && substr($target[$i], 4, 1)==',') {

			analyze($target[$i], "$year-$month-$day") ;
			}
		}
	}
}

//從字串中取出我們要的資訊
function analyze($target, $date){

	//$target='="062818",元大9E,"802,000",9,"232,680",0.30,0.34,0.27,0.27,－,0.07,0.25,10,0.28,36,21.90';
	
	//證券代號
	preg_match('/([\d]{4}),/',$target,$r);
	$code=$r[1];

	//去掉已經成功match的部份及無用的部份
	$tmp = preg_split('/,/',$target, 3);
	$tmp = $tmp[2];

	//去掉成交股數，因為格式跟成交金額一樣
	if($tmp[0]=='"'){//金額大於1000，所以會多引號及逗點
		$tmp = preg_split('/\"([\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]+)\"/',$tmp, 2);
		$tmp = $tmp[1];
	}
	else{
		$tmp = preg_split('/[\d]{1,3}/',$tmp, 2);
		$tmp = $tmp[1];
	}

	//去掉成交筆數，因為格式跟成交金額一樣
	if($tmp[0]=='"'){//金額大於1000，所以會多引號及逗點
		$tmp = preg_split('/\"([\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]+)\"/',$tmp, 2);
		$tmp = $tmp[1];
	}
	else{
		$tmp = preg_split('/[\d]{1,3}/',$tmp, 2);
		$tmp = $tmp[1];
	}

	//成交金額
	if($tmp[1]=='"'){//金額大於1000，所以會多引號及逗點
	preg_match('/\"([\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]*,?[\d]+)\"/',$tmp, $r);
	//$volumn=$r[1];
	$volumn=preg_replace('/,/', '', $r[1]);
	$tmp = explode($r[0],$tmp);
	$tmp = $tmp[1];
	}
	else{
		preg_match('/[\d]{1,3}/',$tmp, $r);
		$volumn=$r[0];
		$tmp = explode($r[0],$tmp);
		$tmp = $tmp[1];
	}

	//開盤價
	if($tmp[1]=='-'){
		$tmp = substr($tmp, 3);
		$open="--";
	}
	else{
		preg_match('/[\d]+[.][\d]{1,2}/',$tmp, $r);
		$open = $r[0];
		$tmp = substr($tmp, strlen($r[0])+1);
	}

	//最高價
	if($tmp[1]=='-'){
		$tmp = substr($tmp, 3);
		$high="--";
	}
	else{
		preg_match('/[\d]+[.][\d]{1,2}/',$tmp, $r);
		$high = $r[0];
		$tmp = substr($tmp, strlen($r[0])+1);
	}

	//最低價
	if($tmp[1]=='-'){
		$tmp = substr($tmp, 3);
		$low="--";
	}
	else{
		preg_match('/[\d]+[.][\d]{1,2}/',$tmp, $r);
		$low = $r[0];//
		$tmp = substr($tmp, strlen($r[0])+1);
	}

	//收盤價
	if($tmp[1]=='-'){
		$tmp = substr($tmp, 3);
		$close="--";
	}
	else{
		preg_match('/[\d]+[.][\d]{2}/',$tmp, $r);
		$close = $r[0];
		//$tmp = substr($tmp, strlen($r[0])+1);
	}	

//  證券代號  日期  成交金額  開盤價  最高價  最低價  收盤價
//    $code  $date  $volumn   $open   $high   $low    $close

	//一筆股票的資料
	$stock = new StockInfo($date,$volumn,$open,$high,$low,$close);
	//$stock->showContent();
	
	global $file_array;
	
	if(! array_key_exists($code, $file_array)){
		$file_array[$code]=new StockFile();
	}
	//將這筆資料，加到這個股票的資料list當中
	$file_array[$code]->push($stock);

	
}

//一個股票的資料，可以有不同日期的
class StockFile{
	protected $stocklist;
	
	function __construct(){
		$this->stocklist=array();
	}
	
	function push($stock){
		array_push($this->stocklist, $stock);
	}
	function showContent(){
		print_r($this->stocklist);
	}
	function writeToFile($fp){
		
		$list=array_reverse($this->stocklist, true);//順序反轉，以方便genFeature中做事情
		
		foreach($list as $stock){// A $stock is a StockInfo
			fwrite($fp, $stock->toString());
			//echo $stock->toString();
		}
	}
}

//一筆股票的資料
class StockInfo{
	//This is a data structure containing the data of dirrerent days of the same stock 
	//protected $code;
	protected $theDate;
	protected $volume;
	protected $open;
	protected $high;
	protected $low;
	protected $close;
	
	function __construct($d,$v,$o,$h,$l,$cl){
		//$this->code=$c;
		$this->theDate=$d;
		$this->volume=$v;
		$this->open= $o;
		$this->high=$h;
		$this->low=$l;
		$this->close=$cl;
	}
	
	function showContent(){
		echo "$this->theDate\t$this->volume\t$this->open\t$this->high\t$this->low\t$this->close\n";
	}
	
	function toString(){
		return "$this->theDate, $this->volume, $this->open, $this->high, $this->low, $this->close\n";
	}
}


?>