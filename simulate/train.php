<?php
//指令格式範例： php train.php 2013 10 02

/*************************************************
***                                            ***
***    不防呆，輸入的 [那一天] 一定要有資料    ***
***                                            ***
**************************************************/

$s_year=$argv[1];
$s_month=$argv[2];
$s_day=$argv[3];

$e_year=$argv[4];
$e_month=$argv[5];
$e_day=$argv[6];

$missingValueCount=0;
$zeroValueCount=0;
$featuresCount=0;

$debug=0;

//修正格式，前面加一個0
if(1<=$s_month && $s_month<=9 && strlen($s_month)==1) $s_month ="0".$s_month;
if(1<=$s_day && $s_day<=9 && strlen($s_day)==1) $s_day = "0".$s_day;
if(1<=$e_month && $e_month<=9 && strlen($e_month)==1) $e_month ="0".$e_month;
if(1<=$e_day && $e_day<=9 && strlen($e_day)==1) $e_day = "0".$e_day;

$file_list = shell_exec('ls');
$file_list = preg_split("/[\n]/", $file_list);//把它切成array

$today= "$e_year-$e_month-$e_day";

$stock = array();//存所有的股票的class和feature，屬於Stock這個class

if(!$debug){
	echo "Grabbing data from $s_year $s_month $s_day to $e_year $e_month $e_day...\n";
	shell_exec("php grab.php $s_year $s_month $s_day $e_year $e_month $e_day train");
}
echo "Reading files....\n";

for($i=1000;$i<=9999;$i++){
	$filename="$i-train.txt";
	if(in_array($filename, $file_list)){//有這個檔案
		$fp = fopen($filename, "r");
		if($fp){
					
			//這四天的這些值
			
			$data=array(
				"volumn" => array(),
				"open" => array(),
				"high" => array(),
				"low" => array(),
				"close" => array(),
				"isStart" => array(),
			);				
			
			
			$start=0;//  $start=1表示從現在開始可以抓feature了
			$count=0;//$count==1代表正在抓起始日前一天的資料，以此類推三天
			while(! feof($fp) ){
				//read one line a time
				$buffer = fgets($fp);
				if(is_numeric($buffer[0])){
					$term = preg_split("/[,][\s]/", $buffer);
					$term = trimTerms($term);
					//$i:code, $term[0]:time, $term[1]:volumn, $term[2,3,4,5]:open, high, low, close
					
					$time=preg_split("/[-]/",$term[0]);//get time
			
					if($term[0]==$today){
							
						//有今天存在
						//而且表示從現在開始可以抓feature了
						$start=1;
						
						//其實只要抓close，其他是順便抓的，不要用
						
						array_push($data["volumn"], $term[1]);
						array_push($data["open"], $term[2]);
						array_push($data["high"], $term[3]);
						array_push($data["low"], $term[4]);
						array_push($data["close"], $term[5]);
						array_push($data["isStart"], false);
					}
					elseif($start==1 and $time[0]==$s_year and $time[1]==$s_month and $time[2]==$s_day){
					//起始日
						
						array_push($data["volumn"], $term[1]);
						array_push($data["open"], $term[2]);
						array_push($data["high"], $term[3]);
						array_push($data["low"], $term[4]);
						array_push($data["close"], $term[5]);
						array_push($data["isStart"], true);
						$count=1;//再抓三天就結束
					}					                                                                                                  //抓從起始日以前的三天，到昨天為止
					elseif($start==1 and (IsAfterStartDay($s_year, $s_month, $s_day, $time[0], $time[1], $time[2]) or $count>0)){					
						
						//抓所有資料
						array_push($data["volumn"], $term[1]);
						array_push($data["open"], $term[2]);
						array_push($data["high"], $term[3]);
						array_push($data["low"], $term[4]);
						array_push($data["close"], $term[5]);
						array_push($data["isStart"], false);
						
						if($count>0){//正在抓倒數三天
							$count++;
							if($count==4)
								break;
						}						
					}
									
					elseif($start==1)
						break;
				}
			}	//這個file中的每一行資料已經成功抓完了
			
			if(! $start)//檔案當中沒有「今天」存在
				continue;
			
			//現在$data裡面有這支股票每一天的所有資訊
			
			$total=count($data["volumn"]);//總共抓的天數
			
			for($n=0;$n+1 <$total;$n++){
				if($data["isStart"][$n]==false){
					$theClass=computeClass($data["close"][$n], $data["close"][$n+1]);
					$features=computeFeatures($data, $n);
					$stock[]=new Stock($i, $theClass, $features);				
				}
				else{
					$theClass=computeClass($data["close"][$n], $data["close"][$n+1]);
					$features=computeFeatures($data, $n);
					$stock[]=new Stock($i, $theClass, $features);	
					break;
				}
			}
		}
		fclose($fp);
	}	
}

echo "Totally $missingValueCount missing values and $zeroValueCount zero values.\n";

echo "Writing to \"train_data\"....\n";

//Write to file "train_data_日期.txt"
$fp = fopen("train_data", "w");
foreach($stock as $s){
	fwrite($fp, $s->toString());
	echo $s->toString();
}
fclose($fp);

/*呼叫外部套件*/
shell_exec("./svm-scale -s train_scale_model train_data > train_data.scale");
shell_exec("./rvkde --best --cv --classify  -b 40 --ks 60 --kt 170 -n 5 -v train_data.scale > train_result");


function IsAfterStartDay($s_year, $s_month, $s_day, $t_year, $t_month, $t_day){
//如果target_date比start還要晚，就是OK的
	$v1=(($s_year-1900)*12+$s_month)*30+$s_day;
	$v2=(($t_year-1900)*12+$t_month)*30+$t_day;
	return $v1 <= $v2;
}

function trimTerms($target){
	for($i=0;$i<count($target);$i++){
		$str=$target[$i];
		//前三個的用法疑似是錯的？應該要像下面兩個那樣吧？
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

function computeFeatures($data, $this){//$data 是array
//index $this 是當天的，都不可以用

	global $missingValueCount,$zeroValueCount,$featuresCount;
	
	$feature=array();//這是原先助教指定的
	$f=array();//這是我自創的
	$bound=count($data["volumn"]);
	for($i=$this+1;$i<=$this+3;$i++){
	
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
	
	if($this+3<$bound){//只要更新這邊，不但predict.php的這個部份要改，predict.php的最終篩選的index也要改
		
		
		$high1=$data["high"][$this+1] ;//yesterday
		$high2=$data["high"][$this+2] ;//two days ago
		$high3=$data["high"][$this+3] ;
		$close1=$data["close"][$this+1];
		$close2=$data["close"][$this+2];
		$close3=$data["close"][$this+3];
		$close4=$data["open"][$this+3];
		$volumn1=$data["volumn"][$this+1];
		$volumn2=$data["volumn"][$this+2];
		$volumn3=$data["volumn"][$this+3];
		$low1=$data["low"][$this+1];
		$low2=$data["low"][$this+2];
		$low3=$data["low"][$this+3];
		$open1=$data["open"][$this+1];
		$open2=$data["open"][$this+2];
		$open3=$data["open"][$this+3];
		
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

function computeClass($today, $yesterday){

	 if($today==0 or $yesterday==0) return 0;
	
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

//一筆股票的資料
class Stock{
	//This is a data structure containing the data of dirrerent days of the same stock 
	protected $code;
	protected $myclass;
	
	protected $features;
	
	function __construct($code, $class, $features){
		$this->code=$code;
		$this->myclass=$class;
		$this->features=$features;
	}
	/*
	function showContent(){
		echo "$this->code  Class:$this->myclass  Features:".implode(",",$this->features)."\n";
	}
	*/
	function toString(){
	
		global $featuresCount;
			
		if(count($this->features)==$featuresCount){		
			if($this->features[$featuresCount-1]){//最後一個feature是true，代表沒有missing value
				for($i=0;$i<$featuresCount-1;$i++){			//最後一個feature不是feature，而是代表有沒有missing value
					if($this->features[$i]==null) 
						$this->features[$i]=0;
					$this->features[$i] = ($i+1).":". $this->features[$i];
				}
				unset($this->features[$featuresCount-1]);
				return "$this->myclass\t".implode("\t",$this->features)."\n";
			}
		}
		return "";
	}
}

?>