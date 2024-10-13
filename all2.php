<?php
//	for parent
//	for child01
set_time_limit(0);
include("/home/systematic/systematic.xsrv.jp/public_html/hotp/tools.php");
$cols = array("name", "addr", "ido", "kdo", "tel", "budget", "news", "genre", "area", "access", "opening_time", "holiday", "seats");

$dbh = conn_db();
$url_list = get_url_list($dbh);
while(count($url_list)){
	foreach($url_list as $url){
		$html = get_html($url);
		$recs = get_items($html, $url, $cols);
//print_r($recs);exit;		
		my_write($dbh, $url, $cols, $recs);
	}
	$url_list = get_url_list($dbh);
}
function get_items($html, $url, $cols){
	foreach($cols as $c){
		$r[$c] = "";
	}
	$dom = new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);

	$tmp = explode('ld+json">', $html);
	$tmp = explode('</script>', $tmp[1]);
	$json = json_decode($tmp[0], true);

	$r["name"] = html_entity_decode($json["name"]);
	if (isset($json["address"])){
		$r["addr"] = html_entity_decode($json["address"]["addressRegion"]);
		$r["addr"] .= html_entity_decode($json["address"]["addressLocality"]);
		$r["addr"] .= html_entity_decode($json["address"]["streetAddress"]);
	}else{
		$column_name = '//td/address';
		if ($xpath->query($column_name)->length){
			$r["addr"] = trim($xpath->query($column_name)->item(0)->nodeValue);
		}
	}
	
	if (isset($json["geo"])){
		$r["ido"] = $json["geo"]["latitude"];
		$r["kdo"] = $json["geo"]["longitude"];
	}
	
//	$r["tel"] = $json["telephone"];
	
	$column_name = '//span[@class="telNumber"]';
	if ($xpath->query($column_name)->length){
		$r["tel"] = $xpath->query($column_name)->item(0)->nodeValue;
	}

	$column_name = '//dl[@class="shopInfoInnerSectionBlock cf"]';
	$list_num = $xpath->query($column_name)->length;
	$area = array();
	$genre = array();
	for($i=0; $i<$list_num; $i++){
		$children = $xpath->query($column_name)->item($i)->childNodes;
		foreach($children as $node){
			if ($node->nodeName == "dt"){
				$name = mb_trim($node->nodeValue);
			}else if ($node->nodeName == "dd"){
				if ($name == "予算"){
					$r["budget"] = mb_trim($node->nodeValue);
				}else if ($name == "ジャンル"){
					$children2 = $node->childNodes;
					foreach($children2 as $node2){
						if ($node2->nodeName == "p"){
							$genre[] = mb_trim($node2->nodeValue);
						}
					}
				}else if ($name == "エリア"){
					$children2 = $node->childNodes;
					foreach($children2 as $node2){
						if ($node2->nodeName == "p"){
							$area[] = mb_trim($node2->nodeValue);
						}
					}
				}else if ($name == "お知らせ"){
					$r["news"] = mb_trim($node->nodeValue);
				}
			}
		}
	}
	$r["genre"] = implode("　", $genre);
	$r["area"] = implode("　", $area);

	$column_name = '//table[@class="infoTable"]/tbody/tr/th';
	$column_name2 = '//table[@class="infoTable"]/tbody/tr/td';
	$list_num = $xpath->query($column_name)->length;
	for($i=0; $i<$list_num; $i++){
		$name = mb_trim($xpath->query($column_name)->item($i)->nodeValue);
		$val = mb_trim($xpath->query($column_name2)->item($i)->nodeValue);
		$children = $xpath->query($column_name2)->item($i)->childNodes;
		
		if ($name == "アクセス"){
			$r["access"] = $val;
		}else if ($name == "定休日"){
			$r["holiday"] = $val;
		}else if ($name == "総席数"){
			$r["seats"] = $val;
		}else if ($name == "営業時間"){
			$bh = array();
			foreach($children as $node){
				if ($node->nodeName == "#text" && $node->nodeValue){
					$bh[] = mb_trim($node->nodeValue);
				}
			}
			$r["opening_time"] = implode("　", $bh);
		}
	}

	$column_name = '//ul[@class="links"]/li/a[@rel="nofollow"]';
	$list_num = $xpath->query($column_name)->length;
	$link = array();
	for($i=0; $i<$list_num; $i++){
		$link[] = $xpath->query($column_name)->item($i)->getAttribute("href");
	}
	if (count($link)){
		$r["url"] = implode("　", $link);
	}
	return $r;
}
function get_max_page($html){
	$r = 0;
	
	$dom = new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);

	$column_name = '//li[@class="lh27"]';
	if ($xpath->query($column_name)->length){
		$tmp = $xpath->query($column_name)->item(0)->nodeValue;
		$tmp = str_replace("ページ", "", $tmp);
		$tmp = explode("/", $tmp);
		$r = $tmp[1] + 1;
	}
	return $r;
}
function get_url_list(&$dbh){
	$r = array();
	$sql = "select base_url from hot_pepper where name is null or name = '' order by id limit 1000";
	$tbl = $dbh->prepare( $sql);
	$tbl->execute();
	$bFind = false;
	while($result = $tbl->fetch(PDO::FETCH_ASSOC)){
		$r[] = $result["base_url"];
	}
	return $r;
}
function my_write($dbh, $url, $cols, $recs){
	$sql = "select * from hot_pepper where base_url = '" . $url . "'";
	$tbl = $dbh->prepare( $sql);
	$tbl->execute();

	$sql  = "update hot_pepper set ";
	$isFirst = true;
	foreach($cols as $c){
		if ($isFirst){
			$isFirst = false;
		}else{
			$sql .= ",";
		}
		$sql .= $c . "=:" . $c;
	}
	$sql .= " where base_url = '" . $url . "'";
//echo "\n\n" . $sql . "\n\n";		exit;
	$t = $dbh->prepare($sql);
	foreach($cols as $c){
		$t->bindValue(":" . $c, $recs[$c], PDO::PARAM_STR);
	}
	$t->execute();
	$arr = $t->errorInfo();
	if ($arr[0] != "00000"){
		print_r($arr);
	}
}
