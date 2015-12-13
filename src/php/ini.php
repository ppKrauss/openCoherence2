<?php
/**
 * Scans and charges CSV data to the SQL database, and get XML files.
 * @see scripts at "ini.sql" (run it first!).
 * @use with terminal,
 *      php openCoherence/src/php/ini.php
 */

// // // //
// CONFIGS:
$PG_USER = 'postgres';
$PG_PW   = 'pp@123456';
$dsn="pgsql:dbname=postgres;host=localhost";
$projects = [
	'licences'=>'/home/peter/gits/licenses',
	'openCoherence'=>   '/home/peter/gits/openCoherence'
];


// // // // //
// SQL PREPARE
$items = [
	'licences'=>[
		array('INSERT INTO oc.license_families (fam_name, fam_info) VALUES (:name::text, :json_info::JSON)',
			'families.csv'
		)
		,array('SELECT oc.licenses_upsert(
			:id_label::text, :id_version::text, :name::text, :family::text, NULL::text, :json_info::json
			)',
			'implieds.csv::bind','licenses.csv::bind'
		)
		,array('SELECT oc.licenses_upsert(
                               :id_label::text, :id_version::text, :name::text, :family::text, :name_of_equiv::text, :json_info::json
			)',
			'redundants.csv::bind'
		)
	],
	'openCoherence'=>[
		array("SELECT oc.cached_xpaths_upsert(:jkey_group::int, :jkey, :transducer::int, :xpath_str, :dtds, :xpath_ns)",
			'xpathTransducers.csv::bind'
		)
		/* testing  array("
		INSERT INTO oc.cached_xpaths (jkey_group,jkey,transducer,xpath_str,dtds,xpath_ns) 
		VALUES (:jkey_group::int, :jkey, :transducer::int, :xpath_str, lib.COALESCE2(:dtds,'{}')::int[] , lib.COALESCE2(:xpath_ns,'{}')::text[])",
				'xpathTransducers.csv::bind'
		)*/

		,array("INSERT INTO oc.repositories(repo_label,repo_name,repo_wikidataID,repo_url,repo_info) 
		              VALUES (:label, :name, :repo_wikidataID, :url, :json_info)",
			'sciRepos.csv', 'lawRepos.csv'
		)
		,array("SELECT oc.docs_upsert(
			'lex:'||:country, NULL, (:doc_type||':'||:doc_year||':'||:doc_code)::text, NULL::xml, :json_info::JSON
			)",
			'lawDocs.csv::bind','samples'
		)
	]
];
$sql_delete = '
	DELETE FROM oc.cached_xpaths;
	DELETE FROM oc.licenses;
	DELETE FROM oc.license_families;
	DELETE FROM oc.docs;
	DELETE FROM oc.repositories;
';

// // //
// INITS:
$db = new pdo($dsn,$PG_USER,$PG_PW);
$stmt = $db->exec($sql_delete); 

$SEP = ',';
$nmax = 0;   // 0 or debug with ex. 10
$n=0;
foreach($items as $prj=>$r) 
   foreach ($r as $dataset) {
	$folder = $projects[$prj];
	$sql = array_shift($dataset);
	print "\n\n---- PRJ $prj (($sql))";
	$stmt = $db->prepare($sql);
	$jpack = json_decode( file_get_contents("$folder/datapackage.json"), true );
	$ds = array(); // only for "bind check".
	foreach($dataset as $i) {
		$i = str_replace('::bind','',$i,$bind);
		$ds["data/$i"] = $bind;
	}
	$ds_keys = array_keys($ds);
	foreach($jpack['resources'] as $pack)
	  if ( in_array($pack['path'],$ds_keys) ) {
		print "\n\t-- reding dataset '$pack[path]' ";
		$fields = $pack['schema']['fields'];
		list($sql_fields,$json_fields) = fields_to_parts($fields,false);
		$n=$n2=0;
		$nsql = count($sql_fields);
		$file = "$folder/$pack[path]";
		$h = fopen($file,'r');
		while( $h && !feof($h) && (!$nmax || $n<$nmax) ) 
		  if (($lin0=$lin = fgetcsv($h,0,$SEP)) && $n++>0  && isNotEmpty($lin) ) {
			$jsons = array_slice($lin,$nsql); 
			$sqls  = array_slice($lin,0,$nsql);
			$info = json_encode( array_combine($json_fields,$jsons) );
			if ($ds[$pack['path']]) { // to do: bind string/int/no PDO::datatypes
				$tmp = $sqls;
				//print "\n debug23: $n2 = ".join('|',$lin0);
				foreach($sql_fields as $i) $stmt->bindParam(":$i", array_shift($tmp));
				if (count($json_fields) && $info) $stmt->bindParam(":json_info", $info);
				$ok = $stmt->execute();
			} else // implicit bind (by array order and no datatype parsing)
				$ok = $stmt->execute( array_merge($sqls,array($info)) );
			if (!$ok) {
				print "\n ---- ERROR (at line-$n with error info) ----\n";
				print_r($lin0);
				print_r($stmt->errorInfo());
				die("\n");
			} else $n2++;
		  } elseif ($nsql>0 && count($sql_fields) && isNotEmpty($lin)) {
			$lin_check = fields_to_parts( array_slice($lin,0,$nsql) );
			if ($sql_fields!= $lin_check){
				var_dump($lin_check);
				var_dump($sql_fields);
				die("\n --- ERROR: CSV header-basic not matches SQL/datapackage field names ---\n");
			}
		  }
		print " $n lines scanned, $n2 used.";
		unset($ds[$pack['path']]);
	  } // if $pack
	$sampath = "data/samples";
	if (isset($ds[$sampath])) { // a kind of XML dataset
		unset($ds[$sampath]);
		$folder2 = "$folder/$sampath";
		foreach (scandir($folder2) as $ft) if (strlen($ft)>2) // folder-types sci and law
			foreach (scandir("$folder2/$ft") as $rpfolder) if (strlen($rpfolder)>2)
				intoDb_XMLs("$folder2/$ft/$rpfolder",$db,$rpfolder); // scans repository's folder
	}
	foreach ($ds as $k=>$v) print "\n --WARNING: pack '$k' (bind=$v) not used";
  } // for $r



// // // //
// LIB

function isNotEmpty($x) {
	return $x && count($x) && $x!=array() && $x!=array('') && $x!=array(null);
}

function fields_to_parts($fields,$only_names=true) {
	$sql_fields = array();
	$json_fields = array();
	if (count($fields)) { // isNotEmpty
	  foreach($fields as $ff) {
		$name = str_replace('-','_',strtolower($only_names? $ff: $ff['name']));
		if ( !$only_names && isset($ff['role']) ) {   // e outros recursos do prepare
			$sql_fields[]  = $name;
		} else
			$json_fields[] = $name;
	   } // for
	} // else return ... 
	return ($only_names? $json_fields: array($sql_fields,$json_fields));
}


function intoDb_XMLs($pasta,$db,$repo_name,$n_limit=0,$verbose=0) {
	print "\n\n\t --- scanning folder ($pasta) of $repo_name ---\n";
	$rgx_doctype = '/\s*<!DOCTYPE\s([^>]+)>/s';  // needs ^
	$stmt = $db->prepare( "SELECT oc.docs_upsert('$repo_name',:dtd::text,:pid::text,:xcontent::xml,NULL::JSON)" );
	$n=0;
	foreach (scandir($pasta) as $file) 
	  if (strlen($file)>2  && (!$n_limit || $n<=$n_limit)) {
		$n++;
		if ($verbose) print "\n--$n-- $pasta / $file ";
		$pid = preg_replace('/\.xml/i','',$file);
		$f = "$pasta/$file";
		$cont = file_get_contents($f);
		if (!$cont) 
			die("\n-- empty file: $f");
		$doctype = preg_match($rgx_doctype,$cont,$m)? $m[1]: '';
		$doctype = preg_replace('/\s+/s',' ',$doctype);
		if ($doctype) 
			$cont = preg_replace($rgx_doctype, '', $cont);
		$stmt->bindParam(':pid',     $pid,PDO::PARAM_STR);
		$stmt->bindParam(':dtd',     $doctype,PDO::PARAM_STR);
		$stmt->bindParam(':xcontent',$cont,PDO::PARAM_STR);
		$re = $stmt->execute();
		if (!$re) {
			print_r($stmt->errorInfo());
			die("\n-- ERROR at $file, not saved\n");
		} //else $n2++;
	  } // scan xml files
	print "\n$n XML docs of '$repo_name' inserted\n\n";
} // func

?>



