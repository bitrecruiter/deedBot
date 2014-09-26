<?php
//### split_bundled_documents.php Sept-2014 MolokoDesk
//### required for: deedBot.php
//###
//###this is still a separate module (returns a json data structure), but could be integrated into deedBot.php


// allow PHP to report errors to HTML output, ignoring non-fatal/recoverable errors
if(!isset($_SERVER['argv'])){
 error_reporting(E_ALL);
 ini_set('display_errors', '1');
 ini_set("gd.jpeg_ignore_warning", 1);
}//ENDIF NO ERRORS IN SHELL

date_default_timezone_set('UTC');



//####################
//#GET FORM PARAMETERS
//# FROM _SERVER OR argv
//##
if(!function_exists('getparms')){
function getparms($PWORD,$PDEFAULT){
  if( array_key_exists($PWORD, $_REQUEST) ){
    return(preg_replace('/[\`\;]+/','_',$_REQUEST[$PWORD]));  
  }elseif(array_key_exists('argv',$_SERVER)){
   foreach($_SERVER['argv'] as $ak=>$av){
    $AQ=split("=",$av);
    if($AQ[0]==$PWORD){
     return(preg_replace('/[\`\;]+/','_',$AQ[1]));
    }//ENDIF RETURN MATCHING ARG
   }//NEXT ARGV
   return($PDEFAULT);
  }else{
   return($PDEFAULT);
  }//#ENDIF
}
}//END IF FUNCTION GETPARMS
///#####################



//####################
function rdisp($r,$vardump=false){
 print "\n<pre>";
 if($vardump){var_dump($r);}else{print_r($r);}
 print "</pre>\n";
}//END FUNCTION
//####################







//################################
// http://blockchain.info/stats
function VERIFY_TEST($DOCFILE){
	global $DEBUG;
	global $CDEX;
	global $NICK;


	$descs=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));

	$FHV=proc_open("gpg --keyid-format long --verify -v $DOCFILE",$descs,$pipes);

	fclose($pipes[0]);
	$STDOUT=stream_get_contents($pipes[1]);
	$STDERR=stream_get_contents($pipes[2]);

	if($DEBUG){
		print "\n<hr noshade size=\"2\" color=\"#000000\"><pre>\n";
		print $STDERR;
		print "\n<hr width=\"66%\">\n";	
		print $STDOUT;
	}//ENDIF DEBUG

	if(preg_match('/key\s+([A-Za-z0-9]+)/',$STDERR,$kx)){
		if($DEBUG){print "\n<h2>".$kx[1]."</h2>\n";}
		$KEY_ID=$kx[1];
	}else{
		if($DEBUG){print "\n<h2>(no key ID found - not verified)</h2>\n";}
		$KEY_ID=false;
	}//ENDIF GET KEYID


	if($DEBUG){
		print "\n</pre>\n";
		print "\n<!--\n\n<pre>\n\n";
		print file_get_contents($DOCFILE);
	}//ENDIF DEBUG

	$DOCTEXT=file_get_contents($DOCFILE);
	if(preg_match_all('/([\ \-]+BEGIN.+[\r\n])/',$DOCTEXT,$mx)){
		if($DEBUG){rdisp($mx[1]);}
	}//ENDIF SKIM FOR MULTIPLE SIGS
	$NSIGS=count($mx[1])/2;

	if($DEBUG){
		print "\n\n</pre>\n\n -->\n";
		print "\n\n<h3>$NSIGS signatures</h3>\n\n";
	}//ENDIF DEBUG



	proc_close($FHV);

	//rdisp($pipes);


	return(array('keyID'=>$KEY_ID,'nsigs'=>$NSIGS));


}//END FUNCTION VERIFY
//################################



//#######################
function DOC_SPLIT($DOXFILE){
	global $DEBUG;

	$RAW=file_get_contents($DOXFILE);


	$DASHES='[\\x97\-\\x96]';  //works... but why?

	$REGEXP='/('.$DASHES.'+BEGIN\sPGP\sSIGNED\sMESSAGE[\s\S.]*?[\r\n]+'.$DASHES.'+END\sPGP\sSIGNATURE'.$DASHES.'+)/';

	//print "<p>REGEXP = $REGEXP<br>";

	if(preg_match_all($REGEXP,$RAW,$mx)){
		return($mx[1]);
	}else{
		if($DEBUG){print "<h1>no signed documents found</h1>";}
		return(array());
	}//ENDIF DOCUMENTS EXIST

	return(false);

}//END FUNCTION DOC_SPLIT
//#################################






/*


$ gpg --version
gpg (GnuPG) 1.4.9
Copyright (C) 2008 Free Software Foundation, Inc.
License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>
This is free software: you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.

Home: ~/.gnupg
Supported algorithms:
Pubkey: RSA, RSA-E, RSA-S, ELG-E, DSA
Cipher: 3DES, CAST5, BLOWFISH, AES, AES192, AES256, TWOFISH
Hash: MD5, SHA1, RIPEMD160, SHA256, SHA384, SHA512, SHA224
Compression: Uncompressed, ZIP, ZLIB, BZIP2




*/



$DEBUG=getparms('DEBUG',false);

$DOCUMENT_BUNDLE_URL=getparms('DOC',false);
$DOCUMENT_BUNDLE_URL=urldecode(getparms('URL',$DOCUMENT_BUNDLE_URL));


if($DOCUMENT_BUNDLE_URL=='test'){$DOCUMENT_BUNDLE_URL='signed_CONCAT_DOX.txt';}

$DOCUMENT_BUNDLE_URL_FORM=$DOCUMENT_BUNDLE_URL;

print "\n\nsplit_bundled_documents: $DOCUMENT_BUNDLE_URL_FORM = $DOCUMENT_BUNDLE_URL\n\n";

if(preg_match('/pastebin.com\/raw\.php\?i\=([a-zA-Z0-9]+)/',$DOCUMENT_BUNDLE_URL,$bx)){
	print "\n\nRAW  $DOCUMENT_BUNDLE_URL = ";
	$PBCODE=$bx[1];
	$DOCUMENT_BUNDLE_URL='http://pastebin.com/raw.php?i='.$PBCODE;
	print " $DOCUMENT_BUNDLE_URL = $PBCODE\n\n";

}elseif(preg_match('/pastebin.com\/([a-zA-Z0-9]+)/',$DOCUMENT_BUNDLE_URL,$bx)){
	print "\n\nPLAIN $DOCUMENT_BUNDLE_URL = ";
	$PBCODE=$bx[1];
	$DOCUMENT_BUNDLE_URL='http://pastebin.com/raw.php?i='.$PBCODE;
	print " $DOCUMENT_BUNDLE_URL = $PBCODE\n\n";

}else{
	$PBCODE=$DOCUMENT_BUNDLE_URL;
}//ENDIF PASTEBIN


$INCOMING_SPLIT_PATH='./DEEDS/INCOMING_SPLIT/';
$INCOMING_NEW_PATH='./DEEDS/INCOMING_NEW/';



if($DOCUMENT_BUNDLE_URL){

	$QARR=array();

	if($DEBUG){print "\n<h2>split bundled documents</h2>\n";}
	$SPLIT_TIME=microtime(true);

	$INCOMING_NEW_FILE=$INCOMING_NEW_PATH.$SPLIT_TIME.'_NEW.txt';
	file_put_contents($INCOMING_NEW_FILE,file_get_contents($DOCUMENT_BUNDLE_URL));
	$DARR=DOC_SPLIT($INCOMING_NEW_FILE);

	foreach($DARR as $dk=>$dv){

		$INCOMING_SPLIT_FILE=$INCOMING_SPLIT_PATH.$SPLIT_TIME.'_'.$dk.'.txt';
		file_put_contents($INCOMING_SPLIT_FILE,"\n".$dv."\n");
		$QARR[$dk]['splitfile']=$INCOMING_SPLIT_FILE;
		$QARR[$dk]['sourcefile']=$INCOMING_NEW_FILE;
		$KDATA=VERIFY_TEST($INCOMING_SPLIT_FILE);
		$QARR[$dk]['keyID']=$KDATA['keyID'];
		$QARR[$dk]['nsigs']=$KDATA['nsigs'];


		if($DEBUG){
		print "\n<p>\n<div style=\"border:solid;border-width:1px;border-color:#000000;padding:6px;background-color:#FCFCFC;\">\n<h3>$INCOMING_SPLIT_FILE</h3><pre>";
		print $dv;
		print "</pre>\n</div>\n\n";
		}//ENDIF DEBUG

	}//NEXT DOC FOUND

	if($DEBUG){rdisp($QARR);}

	print "PASTEBIN BUNDLE RESPONSE: ($PBCODE)\n";
	$JDATA=json_encode($QARR);
	print $JDATA;
	print "\n";

	if($DEBUG){rdisp(json_decode($JDATA,true));}


}//ENDIF DOCUMENT URL EXISTS






?>
