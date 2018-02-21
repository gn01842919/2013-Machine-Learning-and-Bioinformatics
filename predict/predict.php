<?php
//指令格式範例： php predict.php 2013 10 02

$debug=0;
//debug=1的時候，就不會grab資料也不會執行RVKDE

$year=$argv[1];
$month=$argv[2];
$day=$argv[3];
$codeArr=array();


$topDV_bound=50;
$topDVs=array();
$topDVs[]=1E-200;

//$topDV=1E-200;

$missingValueCount=0;
$zeroValueCount=0;
$featuresCount=0;
$mode="predict";
if(count($argv)==5 and $argv[4]=="simulate"){
	$mode="simulate";
	echo "-----Simulation Mode-----\n";
}


//修正格式，前面加一個0
if(1<=$month && $month<=9 && strlen($month)==1) $month ="0".$month;
if(1<=$day && $day<=9 && strlen($day)==1) $day = "0".$day;

$file_list = shell_exec('ls');
$file_list = preg_split("/[\n]/", $file_list);//把它切成array

$today= "$year-$month-$day";

//計算保險起見要抓幾天前的資料
if($day>8)
	$grabStartDay="$year $month ".($day-8);
elseif($month>1)
	$grabStartDay="$year ".($month-1)." 21";
else
	$grabStartDay=($year-1)." 12 21";

if((! $debug) and $mode=="predict"){	//simulate mode 不用重抓資料，由另一個檔案一次抓完
	echo "Grabbing data from $grabStartDay to yesterday....\n";
	shell_exec("php grab.php $grabStartDay $year $month $day");
	echo "Reading files....\n";
}
$stock = array();//存所有的股票的資訊，屬於Stock這個class
$codeArr=array();//用來對照第幾筆資料的編號是什麼（解讀predict_result用）

$correctStock=1102;//我信任1102這支股票，相信他的資料不會錯，用他來檢查其他人的資料有沒有錯
$correctArr=array();
$filename="$correctStock.txt";
if($mode=="simulate") $filename="$correctStock-sim.txt";

$fp=fopen($filename,"r");
$count=0;
$start=0;
if($fp){
	while(! feof($fp) and $count<3){
		$buffer = fgets($fp);
		if(is_numeric($buffer[0])){
			$term = preg_split("/[,][\s]/", $buffer);
			$term = trimTerms($term);
			//$i:code, $term[0]:time, $term[1]:volumn, $term[2,3,4,5]:open, high, low, close
			if($mode=="predict"){
				if($term[0]==$today) continue;
				$correctArr[]=$term[0];
				$count++;
			}
			elseif($mode=="simulate"){
				if($term[0]==$today){
					$start=1;
					continue;
				}				
				if($start){
					$correctArr[]=$term[0];
					$count++;
				}
			}
		}
	}
}
echo "############################################################################\n"
    ."#######Please Make Sure That This Array Contains Exactly Last 3 Days.#######\n"
	."############################################################################\n";
print_r($correctArr);
echo "############################################################################\n\n";
//mode=predict代表正常的predict一個還沒發生的日子
//mode=simulate代表抓過去的一個日子，輸入的日期一定要存在
//預設是predict
for($i=1000;$i<=9999;$i++){
	$filename="$i.txt";
	if($mode=="simulate")
		$filename="$i-sim.txt";
	if(in_array($filename, $file_list)){//有這個檔案
		$fp = fopen($filename, "r");
		if($fp){
		
			$count=0;//因為只要抓今天和前三天，這用來計算要不要抓這天
					   //超過3就不要在抓了
			
			//這四天的這些值
			
			$data=array(
				"volumn" => array(),
				"open" => array(),
				"high" => array(),
				"low" => array(),
				"close" => array(),
			);				
			
			//$count=0;
			//$start=0;
			//while(! feof($fp) && $count<3){
			while(! feof($fp)){
				//read one line a time
				$buffer = fgets($fp);
				if(is_numeric($buffer[0])){
					$term = preg_split("/[,][\s]/", $buffer);
					$term = trimTerms($term);
					//$i:code, $term[0]:time, $term[1]:volumn, $term[2,3,4,5]:open, high, low, close
					
					if(in_array($term[0], $correctArr)){
						//echo $term[0]."\n";
						array_push($data["volumn"], $term[1]);
						array_push($data["open"], $term[2]);
						array_push($data["high"], $term[3]);
						array_push($data["low"], $term[4]);
						array_push($data["close"], $term[5]);
					}
					else{//discard it
					
					}
				}
			}	//這個file中的每一行資料已經成功抓完了
			
			
			//$theClass=computeClass($data["close"][0], $data["close"][1]);
			$theClass=0;
			//echo "$i\n";
			
			//echo count($data["high"])."\n";
			if(count($data["high"])==3){//這三天的資料都沒有掉
			
				//為了把怪異的股票去掉
				if($data["high"][0]>$data["close"][0] and $data["high"][0]> $data["open"][0] and $data["low"][0]<=$data["close"][0] and $data["low"][0]<=$data["open"][0]){
				
					$features=computeFeatures($data);//這是個array
				
					$stock[$i]=new Stock($i, $theClass, $features,$data["high"][0],$data["low"][0],$data["close"][0],$data["open"][0]);		
				}
			}
		}
		fclose($fp);
	}
	
}

echo "Totally $missingValueCount missing values and $zeroValueCount zero values.\n";

//Write to file "predict_data"
$fp = fopen("predict_data", "w");
foreach($stock as $s){
	//echo $s->toString()."\n";
	fwrite($fp, $s->toString());
	$codeArr[]=$s->getCode();//用來對照第幾筆資料的code是什麼
}
fclose($fp);

if(! $debug){
	shell_exec("./svm-scale -s predict_scale_model predict_data > predict_data.scale");
	shell_exec("./rvkde --best --predict --classify -b 40 --ks 60 --kt 170 -v train_data.scale -V predict_data.scale > predict_result");
}




$buyList=array();//要買的股票清單

addDVsToStock($stock,$codeArr, "predict_result");//將predict_result裡面的DV相關資訊寫進＄stock裡面

//read the prediction results
$fp = fopen("predict_result", "r");
if($fp){
	$count=0;//第幾筆資料
	
	while(! feof($fp)){
		//read one line a time
		$buffer = fgets($fp);
		
		if($buffer[0]=='0'){//這才是需要的資料
			$term = preg_split("/[\s]/", $buffer);
			$term = trimTerms($term);
			//$term[1]是預測分類的結果
			if( $s=isWorthBuying($stock, $codeArr, $count, $term[1])){

				
				$arr=$s->getFeatures();
				$high1=$arr[2];//前一天的最高價
				$target=$s->getTargetVal();
				$stop=$s->getStop();
				
				//                       code     volume  target  stop  life     type
				$buyList[]=new ToBuy($s->getCode(),1000, $target, $stop,  1  , $s->getType());				
			}		
			$count++;
		}
	}

}
fclose($fp);

$daylist[]=jasonOneDay($buyList, $today);
output($daylist,"$year$month$day");

echo $smallestTopDV."\n";


function output($daylist,$date){
	global $mode;
	$result= "{\n\t";
	$last=count($daylist)-1;
	foreach($daylist as $i=>$oneday){
		$result.=$oneday;
		if($i != $last)
			$result=$result.",";
		else
			$result.="\n";
	}
	$result.="\n}\n";
	echo $result;
	global $mode;
	$outname="buy-$date.buy";
	if($mode=="simulate")
		$outname="simulate-$date.buy";
	$fp = fopen($outname, "w");
	
	fwrite($fp, $result);
		
	fclose($fp);
}

function jasonOneDay($buyList, $date){
	$result="\"$date\":[";
	$last=count($buyList)-1;
	
	foreach($buyList as $i=>$buy){
		$result=$result."\n\t\t".$buy->toJason();
		if($i != $last)
			$result=$result.",";
		else
			$result.="\n";
	}
	$result.= "\n\t]";
	return $result;
}

function addDVsToStock($stock,$codeArr, $filename){
	$fp = fopen($filename, "r");
	global $topDV_bound,$topDVs, $smallestTopDV;
	if($fp){
		$count=0;//第幾筆資料
		
		
		
		while(! feof($fp)){
			//read one line a time
			$buffer = fgets($fp);
			
			if($buffer[0]=='0'){//這才是需要的資料
				$term = preg_split("/[\s]/", $buffer);
				$term = trimTerms($term);

				//[label] [PRED] [DV 2] [DV -1] [DV 1] [DV -2] [DV 0]
				//term0	   term1  term2  term3   term4  term5   term6
				//$codeArr[$count]是這筆資料(這支股票)的code
				
				$DV=	array(//class map to term
							-2 => $term[5],
							-1 => $term[3],
							0  => $term[6],
							1  => $term[4],
							2  => $term[2],
						);
				arsort($DV);//從大排到小
				
				$keys= array_keys($DV);//將key依照之前sort的順序取出來
				
				if($DV[$keys[1]]>0){
					$DV["diff"]= $DV[$keys[0]]/$DV[$keys[1]];//最高的是第二高的幾倍
					
				}
				else
					$DV["diff"]= -1;
				
				$DV["sign"]= $DV[$keys[0]]*$DV[$keys[1]];//同號或異號
				
				
				
				
				
				arsort($topDVs);
				$k= array_keys($topDVs);
				$smallestTopDV=$topDVs[$k[count($topDVs)-1]];
				
				if($DV[$keys[0]]>=$smallestTopDV and count($topDVs)<=$topDV_bound) {
					$topDVs[]=$DV[$keys[0]];
					
					if(count($topDVs)==$topDV_bound){
					//delete the smallest one
					
						if(($key=array_search($smallestTopDV,$topDVs)) !== false){
							unset($topDVs[$key]);
							//echo "  delete:$smallestTopDV\n";;
						}
					}
					//echo "*********".$codeArr[$count]."*********\n";;
					//print_r($topDVs);
					//echo count($topDVs)."  smallest:$smallestTopDV\n";;
				}
				
				
				/*
				echo "*********".$codeArr[$count]."*********\n";;
				
				foreach ($DV as $key => $val){
				echo "$key => $val\n";
			
				echo "****************************\n";;
				}
				*/
				
						
				foreach($stock as $s){
					if($s->getCode()==$codeArr[$count]){//從資料編號 取得Stock 物件
						$s->setClass($term[1]);//predicted class
						$s->setDVs($DV);
						
						
					}
				}
				
				$count++;
			}
		}
		arsort($topDVs);
		$k= array_keys($topDVs);
		$smallestTopDV=$topDVs[$k[count($topDVs)-1]];

	}
	fclose($fp);
}

function isWorthBuying($stock, $codeArr, $count, $class){//計算一些feature，看這個股票好不好
	//目前：是否預測結果分類為２，且前一天收盤價或成交量創再前兩天的新高的股票
	
	//如果不值得買，回傳0
	//如果值得買，回傳 $s
		
	$result=0;
	
	if($class=='2' or $class=="-2"){
		foreach($stock as $s){
			if($s->getCode()==$codeArr[$count]){//從資料編號 取得Stock 物件
				$feature=$s->getFeatures();
				
				$bound=count($feature);
				$index=array( "close1" => 4,
							  "close2" => 9,
							  "close3" => 14,
							  "volumn1"=> 0,
							  "volumn2"=> 5,
							  "volumn3"=> 10,
							  "low1"  =>  3,
							  "high1" =>  2,
							  "high2" =>  7,
							  "high3" =>  12,
							);//分別是1~3天的收盤價，1~3天的成交量
				$f=array();
				foreach($index as $key => $value){
					if($value<$bound){
						$f[$key]=$feature[$value];
					}				
					else{
					
						$f[$key]=PHP_INT_MAX;//沒有這筆資料，當作是無限大，總之算是不合格
					}
				}		
				
				$cond1 =		($f["close1"]>$f["close2"] and $f["close1"]> $f["close3"])
									or	($f["volumn1"]>$f["volumn2"] and $f["volumn1"]>$f["volumn3"]);//目前為只是助教要求的
				
				$cond2 = 		($f["close1"]>$f["close2"] and $f["close1"]> $f["close3"])
									and	($f["volumn1"]>$f["volumn2"] and $f["volumn1"]>$f["volumn3"]);
				$cond3=true;
				$cond4 = 		($f["close1"]>$f["close2"] )
									and	($f["volumn1"]>$f["volumn2"] );
				$cond5 = 		($f["close1"]>$f["close2"] and $f["close1"]> $f["close3"])
									and	($f["volumn1"]>$f["volumn2"]);
				$cond6 = 		($f["close1"]>$f["close2"])
									and	($f["volumn1"]>$f["volumn2"] and $f["volumn1"]>$f["volumn3"]);
				$cond7 = 		($f["close1"] >= $f["close2"] and $f["close1"]>= $f["close3"] )
									and	($f["volumn1"]>=$f["volumn2"]  and $f["volumn1"]>=$f["volumn3"]);
				
				//$additionCond = ( (($f["high1"]-$f["low1"])/$f["low1"]) >= 0.01)  ;
				$additionCond1 = ($f["high1"]> $f["high2"]  and $f["high1"]> $f["high3"]);
				$additionCond2 = ($f["high1"]>=$f["high2"] and $f["high1"]>=$f["high3"]);
				$additionCond3 = true;
				
				if($f["high3"] != 0 and $f["high2"] != 0){
					$aCond1=(($f["high1"] - $f["high2"] )/$f["high2"] >= ($f["high2"] - $f["high3"] )/$f["high3"]);
					
					$aCond2= (($f["high1"] - $f["high2"] )/$f["high2"] >= 0.03);
				}
				else
					$aCond1 =$aCond2=false;
				
				$useDV=1;
				
				//$aCond2=true;
				if(!$useDV	and $cond2	and $additionCond1 and $aCond1 and $aCond2 	){
									
					$result = $s;				
				}
				else{//use DVs to judge
					$DV=$s->getDVs();
				/*
				$DV=	array(//class map to term
							-2 => ,
							-1 => ,
							0  => ,
							1  => ,
							2  => ,
							"diff" => 最高和第二高的值相減
							"sign" => 用來判斷同號或異號
						);
				*/
				global $smallestTopDV;
				//echo "-----$smallestTopDV\n";
				//echo $DV["diff"]."  highest_diff=$highest_diff\n";
					//if($DV["diff"]>=2){
					$stop_factor = 0.6;
					if($DV["sign"]>0){
						if($class=="2" and $DV[2]>=$smallestTopDV){
							$s->setType("buy");
							$s->setTargetVal($s->getYesterdayClose() + ($s->getYesterdayHigh() - $s->getYesterdayClose())*0.8);
							$s->setStop($s->getYesterdayLow()+($s->getYesterdayClose() - $s->getYesterdayLow())*$stop_factor);
							$result = $s;
						}
						elseif($class=="-2" and $DV[-2]>=$smallestTopDV){
							$s->setType("short");
							$s->setTargetVal($s->getYesterdayLow() + ($s->getYesterdayClose() - $s->getYesterdayLow())*0.2);
							$s->setStop($s->getYesterdayClose()+($s->getYesterdayHigh() - $s->getYesterdayClose())*(1-$stop_factor));
							$result = $s;
						}
						
					
					}

				}
			}
		}
	}

	
	return $result;	
}

function trimTerms($target){
	for($i=0;$i<count($target);$i++){
		$str=$target[$i];
		preg_replace("/\s/", "", $str);
		preg_replace("/\n/", "", $str);
		preg_replace("/\r/", "", $str);
		$str = trim($str);
		$str = preg_replace('/\s(?=\s)/', '', $str);
		$str = preg_replace('/[\n\r\t]/', ' ', $str);
		$target[$i]=$str;
	}
	return $target;
}

function computeFeatures($data){//$data 是array

	global $missingValueCount,$zeroValueCount,$featuresCount;

	$bound=count($data["volumn"]);
	$feature=array();
	$f=array();
	for($i=0;$i<3;$i++){//本來是從1到3，因為去掉今天(0)，現在改成從0到2
		if($i>=$bound) break;
		$feature[]=$data["volumn"][$i];
		$feature[]=$data["open"][$i];
		$feature[]=$data["high"][$i];
		$feature[]=$data["low"][$i];
		$feature[]=$data["close"][$i];
		$f[]=$data["volumn"][$i];
		$f[]=$data["open"][$i];
		$f[]=$data["high"][$i];
		$f[]=$data["low"][$i];
		$f[]=$data["close"][$i];//0-14
	}
	
//我自創的
//$bound should be 3
	if($bound >=3){//只要更新這邊，predict.php的最終篩選的index也要改
		
		
		$high1=$data["high"][0] ;//yesterday
		$high2=$data["high"][1] ;//two days ago
		$high3=$data["high"][2] ;
		$close1=$data["close"][0];
		$close2=$data["close"][1];
		$close3=$data["close"][2];
		$close4=$data["open"][2];
		$volumn1=$data["volumn"][0];
		$volumn2=$data["volumn"][1];
		$volumn3=$data["volumn"][2];
		$low1=$data["low"][0];
		$low2=$data["low"][1];
		$low3=$data["low"][2];
		$open1=$data["open"][0];
		$open2=$data["open"][1];
		$open3=$data["open"][2];
		
		$hasMissingValue=false;
		$hasZeroValue=false;
		//覺得有Missing value的資料有問題，而有zero value的資料很爛而且會造成divide by zero
		if( $volumn1=='--'	or $volumn2=='--'	or $volumn3=='--' 	or
			$open1=='--' 	or $open2=='--' 	or $open3=='--' 	or
			$high1=='--' 	or $high2=='--' 	or $high3=='--' 	or
			$low1=='--' 	or $low2=='--' 		or $low3=='--' 		or
			$close1=='--' 	or $close2=='--' 	or $close3=='--'	){
		
			$hasMissingValue=true;
			//echo "Missing value\n";
			$missingValueCount++;
		}
		if( $volumn1==0 or $volumn2==0 	or $volumn3==0	or
			$open1==0 	or $open2==0 	or $open3==0 	or
			$high1==0 	or $high2==0 	or $high3==0 	or
			$low1==0 	or $low2==0 	or $low3==0 	or
			$close1==0 	or $close2==0 	or $close3==0	){
		
			$hasZeroValue=true;
			$zeroValueCount++;
		}
		
		if((! $hasZeroValue) and (!$hasMissingValue)){
		
			$close34=($close3-$close4)/$close4;
			$close23=($close2-$close3)/$close3;
			$close12=($close1-$close2)/$close2;
			$close14=($close1-$close4)/$close4;
			$close13=($close1-$close3)/$close3;
			$volumn12=($volumn1-$volumn2)/$volumn2;
			$volumn23=($volumn2-$volumn3)/$volumn3;
			$volumn13=($volumn1-$volumn3)/$volumn3;
			$high12=($high1-$high2)/$high2;
			$high13=($high1-$high3)/$high3;
			$high23=($high2-$high3)/$high3;
			$increase1=($high1-$open1)/$open1;
			$increase2=($high2-$open2)/$open2;
			$increase3=($high3-$close4)/$close4;
			$highlow1=$high1-$low1;
			$highlow2=$high2-$low2;
			$highlow3=$high3-$low3;
			
			//拿後面的feature取代掉前面的
			//23 24 31 8 32 18 1 2 14
			$f[10]=$highlow1;
			$f[8]=($high3+$high2+$high1)/3;
			$f[14]=$high12;			
			
			//加權重
			$f[1]=$f[12];
			$f[2]=$f[4];
			
			//start from 15
			$f[15]=($volumn1+$volumn2+$volumn3)/3;

			$f[16]=2*$close1-$close2-$close3;
			
			$f[17]=0;
			if($close1>$close2 and $close1>$close3)
				$f[17]=1;
				
			$f[18]=0;
			if($high1>$high2 and $high1 >$high3)
				$f[18]=1;
				
			$f[19]=$high1-$close1;
			$f[20]=$close14;
			$f[21]=$close13;
			$f[22]=$close12;
			$f[23]=$close1-$low1;
			$f[24]=2*$low1-$low2-$low3;
			$f[25]=true;
			

			
			$featuresCount=26;
			
		}
		else{
			$f=array_fill(0, 26, 0);//missing values
			$f[25]=false;//有missing value
			$featuresCount=26;
		}
	}
	else{
		$f=array_fill(0, 26, 0);//missing values
		$f[25]=false;
		$featuresCount=26;
	}
	//print_r($f);
	return $f;
}
/*
function computeClass($today, $yesterday){

	//echo "$today  ****  $yesterday   \n\n";	
	
	$class=0;
	
	if(is_numeric($today) and is_numeric($yesterday)){
		
		$increase=100*($today-$yesterday)/$yesterday;
		
		if($increase > 2     )   $class=2;
		elseif($increase >= 0 )  $class=1;
		elseif($increase >= -2)  $class=-1;
		elseif($increase < -2)   $class=-2;
	}	
	return $class;
}
*///一筆股票的資料
class Stock{
	//This is a data structure containing the data of dirrerent days of the same stock 
	private $code;
	private $myclass;
	private $type;
	private $features;
	private $decorated_features;
	private $targetVal;
	private $stop;
	private $price;
	private $yesterdayHigh;
	private $yesterdayLow;
	private $yesterdayClose;
	private $DVs;
	private $yesterdayOpen;
	
	function __construct($code, $class, $features,$yesterdayHigh,$yesterdayLow, $yesterdayClose, $yesterdayOpen){
		$this->code=$code;
		$this->myclass=$class;
		$this->features=$features;
		$this->yesterdayHigh=$yesterdayHigh;
		$this->yesterdayLow=$yesterdayLow;
		$this->yesterdayClose=$yesterdayClose;
		$this->yesterdayOpen=$yesterdayOpen;
	}
	
	function setDVs($d){
		$this->DVs=$d;
	}
	function getDVs(){
		return $this->DVs;
	}
	function setType($type){
		$this->type=$type;
	}
	function getType(){
		return $this->type;
	}
	
	function setClass($class){
		$this->myclass=$class;
	}
	
	function getCode(){
		return $this->code;
	}
	
	function getFeatures(){
		return $this->features;
	}
	function setTargetVal($targetVal){
		$this->targetVal=$targetVal;
	}
	function getTargetVal(){
		return $this->targetVal;
	}
	function setStop($stop){
		$this->stop=$stop;
	}
	function getStop(){
		return $this->stop;
	}
	function setPrice($price){
		$this->price=$price;
	}
	function getPrice(){
		return $this->price;
	}
	function getYesterdayHigh(){
		return $this->yesterdayHigh;
	}
	function getYesterdayLow(){
		return $this->yesterdayLow;
	}
	function getYesterdayClose(){
		return $this->yesterdayClose;
	}
	function getYesterdayOpen(){
		return $this->yesterdayOpen;
	}
	
	function showContent(){
		echo "$this->code  Class:$this->myclass  Features:".implode(",",$this->features)."\n";
	}
	
	function toString(){
	
		global $featuresCount;

		if(count($this->features)==$featuresCount){
			if($this->features[$featuresCount-1]){//最後一個feature是true，代表沒有missing value
				for($i=0;$i<$featuresCount-1;$i++){			//最後一個feature不是feature，而是代表有沒有missing value
					$this->decorated_features[$i] = ($i+1).":". $this->features[$i];
				}
				unset($this->features[$featuresCount-1]);
				return "$this->myclass\t".implode("\t",$this->decorated_features)."\n";
			}
			
		}
		return "";
	}
}

class ToBuy{//要買的股票
	

	private $code;
	private $vol;
	private $target;
	private $life;	
	private $type;
	
	function __construct($code, $vol,$target,$stop, $life, $type){
		$this->code=$code;
		$this->vol=$vol;
		$this->target=$target;
		$this->life=$life;
		$this->type=$type;
		//$this->price=$price;
		$this->stop=$stop;
	}
	function toJason(){
		return "{\"type\":\"$this->type\",\"vol\":\"$this->vol\",\"target\":\"$this->target\",\"stop\":\"$this->stop\",\"life\":\"$this->life\",\"code\":\"$this->code\"}";
	}
	
}

?>