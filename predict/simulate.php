<?php
//指令格式範例： php simulate.php 2013 10 02 2013 11 25
//效果等同於從 php predict.php 2013 10 02 依序執行到 php predict.php 2013 11 25

$debug=1;

$s_year=$argv[1];
$s_month=$argv[2];
$s_day=$argv[3];

$e_year=$argv[4];
$e_month=$argv[5];
$e_day=$argv[6];
$seq_num=$argv[7];


//計算保險起見要抓幾天前的資料
if($s_day>8)
	$grabStartDay="$s_year $s_month ".($s_day-8);
elseif($s_month>1)
	$grabStartDay="$s_year ".($s_month-1)." 21";
else
	$grabStartDay=($s_year-1)." 12 21";

if(! $debug){
	echo "Grabbing data from $grabStartDay to $e_year $e_month $e_day...\n";
	shell_exec("php grab.php $grabStartDay $e_year $e_month $e_day simulate");
}
$daylist=array();

$filename="1101-sim.txt";//其實我不確定這支股票是不是夠穩定（很多股票常常漏東漏西）

$fp=fopen($filename,"r");
if($fp){
	
	while(! feof($fp)){
		$buffer = fgets($fp);
		if(is_numeric($buffer[0])){
			$term = preg_split("/[,][\s]/", $buffer);
			$term = trimTerms($term);
			//$i:code, $term[0]:time, $term[1]:volumn, $term[2,3,4,5]:open, high, low, close
			
			$time=preg_split("/[-]/",$term[0]);//get time
			
			echo "=================================\n===== Predicting $time[0] $time[1] $time[2] =====\n=================================\n";
			echo "php predict.php $time[0] $time[1] $time[2] simulate\n";
			shell_exec("php predict.php $time[0] $time[1] $time[2] simulate");
			$daylist[]="$time[0]$time[1]$time[2]";
			
			if("$time[0] $time[1] $time[2]"=="$s_year $s_month $s_day")
				break;
		}
	}
}
echo "############################################################################\n"
    ."####### Please Make Sure That This Array Contains Exactly Past Days. #######\n"
	."############################################################################\n";
print_r($daylist);
echo "############################################################################\n\n";



$outname="simulate$seq_num-$s_year$s_month$s_day-$e_year$e_month$e_day.buy";
$fp1=fopen($outname,"w");
if($fp1){
	fwrite($fp1, "{\n");
	foreach($daylist as $day){
		$inname="simulate-$day.buy";
		$fp2=fopen($inname,"r");
		if($fp2){
			while(!feof($fp2)){
				$buffer = fgets($fp2);
				if($buffer[0]=='{' or $buffer[0]=='}'){
					$buffer="";
				}
				elseif(strpos($buffer, ']') !== false){
					

					if($day !="$s_year$s_month$s_day")
						$buffer="\t],\n";
					
				}
				echo $buffer;
				fwrite($fp1, $buffer);
			}
			fclose($fp2);
		}
		
	}
	fwrite($fp1, "}\n");
	fclose($fp1);
}
function trimOneTerm($str){
		preg_replace("/\s/", "", $str);
		preg_replace("/\n/", "", $str);
		preg_replace("/\r/", "", $str);
		$str = trim($str);
		$str = preg_replace('/\s(?=\s)/', '', $str);
		$str = preg_replace('/[\n\r\t]/', ' ', $str);
	return $str;
}
function trimTerms($target){
	for($i=0;$i<count($target);$i++){
		$str=$target[$i];
		
		
		$target[$i]=trimOneTerm($str);
	}
	return $target;
}
?>