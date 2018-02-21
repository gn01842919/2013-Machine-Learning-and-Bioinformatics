<?php  /*這個程式的功能是將train_data.scale的格式修正成用random forest可以接受的格式*/
	$file_read=$argv[1];
	$file_write=$argv[2];

	$fp1=fopen($file_read,"r");
	$fp2=fopen($file_write,"w");
	if($fp1 and $fp2){
		while(! feof($fp1)){
			$buffer=fgets($fp1);
			$term=preg_split("/\s/",$buffer);
			
			
			
			for($i=1;$i<count($term)-1;$i++){//the last one (index 27 ) is empty
				
				$term[$i]=preg_replace('/[\d]+[:]/', ',',$term[$i]);
			}
			//print_r($term);
			//echo implode("",$term)."\n\n\n";
			fwrite($fp2,implode("",$term)."\n");
			
		}
	fclose($fp1);
	fclose($fp2);
	}
	
	
?>