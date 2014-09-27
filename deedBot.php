<?php
//deedBot.php 2014.Sep.19 MolokoDesk ... final bot wrapper for cryptocontracts/deed notarize document hash to blockchain project
//deedBot.php 2014.Sep.26 MolokoDesk ... revised/working

/*

http://pastebin.com/iuk6rard
Deeds bot : 

1. Bot idles in chan. Upon receipt of command including pastebin link, 
2. Bot separates all signed documents contained in pastebin into individual units. For each unit :
2.1. Bot extracts the signature keyid through a process homologuous to gpg -v -v
2.2. Bot makes a request to gribble via pm, of the format ;;gpg info --key 
2.3. If 2.2. results in a wot registered name, bot makes 2nd request to gribble via pm, of the format ;;gettrust assbot 
2.4. If 2.3. results in L1 or L2 trust > 0, bot includes the whole message (from begin headers to end headers inclusive) into a special, \n separated blob
3. Blob is hashed as a privkey for a Bitcoin address, obtaining that address.
4. A 0.0001 payment is made to that obtained address.
5. Once payment from 4 has received one confirmation, the bot uploads the blob on a website as a textfile, of the format blocknumber-bitcoinaddress.txt,
   where blocknumber = number of the block that included the tx and bitcoinaddress = the address that received the payment.
6. Bot announces the url of the textfile in channel.

For convenience, the sequence 2-6 should run once each hour, having all material obtained in the respective hour managed together.
http://nakamotoinstitute.org/gpg-contracts/

*/




putenv('PATH=/usr/local/bin:/usr/bin:/bin:/usr/bin/X11:/usr/games:/home/postalrocket/postalrocket.com/NEW:/home/postalrocket/postalrocket.com/TJ');
//this is required to make things function under cron at dreamhost's crappy and eccentric VPS
//apparently any submodules inherit it when invoked with ``

date_default_timezone_set('UTC');

require_once 'block_io.php'; //WE ARE GOING TO BE SENDING REAL BITCOIN


//###########################
$ERROR_COUNT=0;
$ERROR_LAST=false;
$ERROR_CHAN='MolokoDesk';
function ErrorHandler($errno, $errstr, $errfile, $errline){
	global $ERROR_CHAN;
	global $ERROR_COUNT,$ERROR_LAST;
	++$ERROR_COUNT;
	$ERROR_LAST=preg_replace('/[\r\n\t]+/',' ',preg_replace('/\[<.+?\>\]/',' ',$errstr).'  '.preg_replace('/^.+\//','',$errfile)."  LINE $errline  ".date('D Y-M-d H:i:s T',time())." ($ERROR_COUNT errors)");
	send("PRIVMSG $ERROR_CHAN :*** $ERROR_LAST");
	print "\n$ERROR_LAST\n";
	return(true);
}//END FUNCTION ERROR_HANDLER
$old_error_handler = set_error_handler("ErrorHandler");
//############################


//####################
function rdisp($r,$vardump=false){
 print "\n<pre>";
 if($vardump){var_dump($r);}else{print_r($r);}
 print "</pre>\n";
}//END FUNCTION
//####################



//######################
//## CHANNEL OR PM?
function GetChan($rawdata){
	if(preg_match("/:(.+?)!(.+?)\s*PRIVMSG\s*([^\s\r\n]+?)\s*:.*\r\n/",$rawdata,$mx)){
  		$NICK=$mx[1];
		$CHAN=preg_replace('/[\r\n\s\t]+/','',$mx[3]);
		if(preg_match('/^\#/',$CHAN)){return($CHAN);}else{return($NICK);}
	}//ENDIF MATCH CHAN & NICK
}//ENDIF GetChan
//######################

//###################################
##SEND FUNCTION
##Sends any data to the server
function send($data){
	global $FHI,$ECHO_MODE;
	if($ECHO_MODE){print "\nDATA=$data\n";}
	if($FHI){fputs($FHI,$data."\r\n");}
}//END FUNCTION SEND
//#####################################

//###################################
function CONNECT_TO_FREENODE(){
	global $ircaddress,$ircport;
	global $ircnick,$ircpassword;
	global $FHI,$JOINED;

	$ircaddress='chat.freenode.net';
	$ircport='6667';
	$ircnick='deedBot';
	$ircpassword='[deedBot Password Here]';
	$FHI = fsockopen($ircaddress,$ircport,$err_num_irc,$err_msg_irc,10);
	stream_set_timeout($FHI,1);
	print "\nerr_num_irc=$err_num_irc err_msg_irc=$err_msg_irc\n";

	$JOINED=false;

	print "second FHI=".rdisp($FHI)."\n\n";
	if($FHI){print "CONNECTED: chat.freenode.net\n";}
	else{print "NOT CONNECTED: chat.freenode.net\n";}
	sleep(3);

}//END FUNCTION CONNECT_TO_FREENODE
//###################################

//https://api.airwallet.me ...
//https://bkchain.org/static/api.txt
//https://bkchain.org/btc/api/v1/address/unspent/1JFnj6nAqPZHfAzhRKC7r9mfW348rkEHvP
//https://bkchain.org/btc/api/v1/address/balance/1JFnj6nAqPZHfAzhRKC7r9mfW348rkEHvP
//https://bkchain.org/btc/api/v1/tx/hash/c9972f909232d98f672e489defdcc4a45aac90edc77a064f3db677f6c1b75a2f
//https://bkchain.org/btc/api/v1/block/hash/0000000000000000166126aa9b0a0c64b6f319607376571b72117ccd729e0d04
//https://bkchain.org/btc/api/v1/block/hash/0000000000000000062a9a01b6a9708840802b9c01cbf9275f67612e2b4fc67e
//https://bkchain.org/btc/address/1NrsqtnJEk9Euom7dtZMwLKiBi432eBTug (not sure what this is for, I had the page open in the browser)
//1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu //this is the block.io wallet
//18AyhuG8VvLqJFvoRH5LGUZPZJ8eN5J6Fz //sends back to the block.io wallet account

//####################################
function SCAN_FOR_PENDING_BUNDLES(){
	global $PENDING_PATH;

	$Q=scandir($PENDING_PATH);
	$FARR=array();
	foreach($Q as $qk=>$qv){
		if(preg_match('/BUNDLE_PENDING\-([a-zA-Z0-9]+)\.txt/',$qv,$mx)){
			$FADDR=$mx[1];
			$FNAME=$PENDING_PATH.'/'.$qv;
			$FARR[$qv]=array('address'=>$FADDR,'mtime'=>filemtime($FNAME),'ctime'=>filectime($FNAME));
		}//ENDIF PENDING BUNDLE
	}//NEXT FILE
	//rdisp($FARR);
	return($FARR);
}//END FUNCTION SCAN_FOR_PENDING_BUNDLES
//###################################

//####################################
//### yes, I know.
function SCAN_FOR_UNSPENT_BUNDLES(){
	global $PENDING_PATH;

	$Q=scandir($PENDING_PATH);
	rdisp($Q);
	$FARR=array();
	foreach($Q as $qk=>$qv){
		if(preg_match('/BUNDLE_UNSPENT\-([a-zA-Z0-9]+)\.txt/',$qv,$mx)){
			$FADDR=$mx[1];
			$FNAME=$PENDING_PATH.'/'.$qv;
			$FARR[$qv]=array('address'=>$FADDR,'mtime'=>filemtime($FNAME),'ctime'=>filectime($FNAME));
		}//ENDIF PENDING BUNDLE
	}//NEXT FILE
	//rdisp($FARR);
	return($FARR);
}//END FUNCTION SCAN_FOR_UNSPENT_BUNDLES
//###################################

/*
Request Submitted. Please check your email shortly for an API code.
In order to prevent abuse some API methods require an api key approved with some basic contact information and a description of its intended use. Please fill in the form below and we will approve your request within 24 hours.

name: deedBot "This is the name of your website or application that needs to use the api."
email: [to MolokoDesk]
URL: http://www.postalrocket.com "Your contact email."
Permissions: "Increased Request Limits" (did not request "Create Wallets")

Description: "track a transaction to  blockchain. examine latest block as often as once every 90 - 30 seconds while transaction is pending. New transaction once per hour (possibly more during testing and development) (getting 403 FORBIDDEN now)" "A description of your website or application and its need for each permission requested."

Using the API Code: "Once approved the API Code can be passed to all requests in the "api_code" parameter e.g. https://blockchain.info?api_code=$your_code"

Blockchain <no-reply@blockchain.info>
A new api key has been issued for http://www.postalrocket.com
Code: [...]
*/

//######################
$BLOCKCHAIN_INFO_API_KEY='[blockchain.info API key Here]';	//api key took two days to arrive in email after request form submitted
function TRACK_PENDING_BUNDLES(){
	global $PENDING_BUNDLES;
	global $TRACKING_CHAN;
	global $TRACKING_VERBOSE;
	global $PENDING_PATH;
	global $PUBLISHED_PATH;
	global $PREVIOUS_BLOCK_HASH;
	global $USE_BLOCKCHAIN_INFO,$BLOCKCHAIN_INFO_API_KEY;
	global $DEEDBOT_CHAN,$ERROR_CHAN;
	global $DEBUG;


if($USE_BLOCKCHAIN_INFO){

	if($BLOCKCHAIN_INFO_API_KEY){
		$LHASH=file_get_contents('https://blockchain.info/q/latesthash?api_code='.$BLOCKCHAIN_INFO_API_KEY);	//use an API key from blockchain.info
	}else{
		$LHASH=file_get_contents('https://blockchain.info/q/latesthash');	//no api key - 403 Forbidden after a day or two
	}//ENDIF USE API KEY

	if(!preg_match('/^[0-9a-fA-F]+$/',$LHASH)){
		send("PRIVMSG $ERROR_CHAN :*** https://blockchain.info/q/latesthash  API call failed. ***");
		print "\n\n*** https://blockchain.info/q/latesthash  API call failed. ***\n\n";
		return(false);
	}//ENDIF BOGUS BLOCK HASH or BLOCK INDEX

}else{

	$QH=SCRAPE_BKCHAIN(); //replace this with an API call if bkchain.org ever implements "get latest block hash"
	$LHASH=$QH['blocks'][0];

	if(!preg_match('/^[0-9a-fA-F]+$/',$LHASH)){
		send("PRIVMSG $ERROR_CHAN :*** SCRAPE_BKCHAIN() failed. ***");
		print "\n\n*** SCRAPE_BKCHAIN() failed. ***\n\n";
		return(false);
	}//ENDIF BOGUS BLOCK HASH or BLOCK INDEX

}//ENDIF USE BLOCKCHAIN.INFO OR BKCHAIN.ORG


	if($LHASH == $PREVIOUS_BLOCK_HASH){
		print "\nBLOCK HASH NOT CHANGED: $LHASH\n";
		send("PRIVMSG $ERROR_CHAN :( no change in current block hash: $LHASH )");
		return(false);
	}//ENDIF CURRENT BLOCK NOT CHANGED


if($USE_BLOCKCHAIN_INFO){
	if($BLOCKCHAIN_INFO_API_KEY){
		$HARR=json_decode(file_get_contents("https://blockchain.info/block-index/$LHASH?format=json&api_code=$BLOCKCHAIN_INFO_API_KEY"),true);
	}else{
		$HARR=json_decode(file_get_contents("https://blockchain.info/block-index/$LHASH?format=json"),true);
	}//ENDIF USE API KEY

	if($DEBUG>1){rdisp($HARR);}

	$HHASH=$HARR['hash'];
	$HTIME=$HARR['time'];
//	$HBIDX=$HARR['block_index'];
	$HMAIN=$HARR['main_chain'];
	$HHEIGHT=$HARR['height'];
//	$HBLOCK['block_index']=$HBIDX;
//	$HBLOCK['hash']=$HHASH;
//	$HBLOCK['time']=$HTIME;
//	$HBLOCK['main_chain']=$HMAIN;

	$SINCE=time()-$HTIME;
	$MINUTES=sprintf('%.2f',$SINCE/60);

	foreach($HARR['tx'] as $tk=>$tv){
		$TXIDX=$tv['tx_index'];
		//$TXHASH=$tv['hash'];
		foreach($tv['out'] as $ok=>$ov){
			if(isset($ov['addr'])){
				$HoADDR=$ov['addr'];
				$HADDRS[$HoADDR]=$TXIDX;
			}//ENDIF ADDRESS PRESENT
		}//NEXT GET OUTPUT ADDRESSES
	}//NEXT TRANSACTION IN BLOCK


	foreach($PENDING_BUNDLES as $PENDING_FILENAME=>$pb){
		$PBADDR=$pb['address'];
		if(isset($HADDRS[$PBADDR])){
			print "\n\nTRACK_PENDING_BUNDLES: $PBADDR\n";
			$TRACKING_BUNDLE_FILENAME_OLD=$PENDING_PATH.'/'.$PENDING_FILENAME;
			$TRACKING_BUNDLE_FILENAME_NEW=$PUBLISHED_PATH.'/'.$HHEIGHT.'-'.$PBADDR.'.txt';
			$bogosity=rename($TRACKING_BUNDLE_FILENAME_OLD,$TRACKING_BUNDLE_FILENAME_NEW);
			unset($PENDING_BUNDLES[$PENDING_FILENAME]); //REMOVE PENDING BUNDLE FROM LIST
			//send("PRIVMSG $DEEDBOT_CHAN :ADDRESS $PBADDR FOUND AT BLOCK HEIGHT: $HHEIGHT");
			send("PRIVMSG $DEEDBOT_CHAN :Deed bundle published to filename: $HHEIGHT-$PBADDR.txt  ($BKCHAIN_ORG_HOST)");
		}//ENDIF PENDING BUNDLE ADDRESS MATCHES CURRENT BLOCK OUTPUT ADDRESS
	}//NEXT PENDING BUNDLE ADDRESS



	//$HBLOCK NOW CONTAINS INFO ABOUT ALL OUTPUT ADDRESSES IN THE MOST RECENT BLOCK

	foreach($HBLOCK['output_addrs'] as $HADDR=>$HTXID){
		foreach($PENDING_BUNDLES as $PENDING_FILENAME=>$pb){
			$PBADDR=$pb['address'];
			if($HADDR == $PBADDR){
				print "\n\nTRACK_PENDING_BUNDLES: $HADDR = $PBADDR\n";

				$TRACKING_BUNDLE_FILENAME_OLD=$PENDING_PATH.'/'.$PENDING_FILENAME;
				$TRACKING_BUNDLE_FILENAME_NEW=$PUBLISHED_PATH.'/'.$HHEIGHT.'-'.$PBADDR.'.txt';
				$bogosity=rename($TRACKING_BUNDLE_FILENAME_OLD,$TRACKING_BUNDLE_FILENAME_NEW);
				unset($PENDING_BUNDLES[$PENDING_FILENAME]); //REMOVE PENDING BUNDLE FROM LIST

				//send("PRIVMSG $TRACKING_CHAN :TRANSACTION $TRACK_TXID ADDRESS $TRACK_ADDR FOUND IN BLOCK: $BLOCKID");
				send("PRIVMSG $DEEDBOT_CHAN :Deed bundle published to filename: $HHEIGHT-$PBADDR.txt (BLOCKCHAIN.INFO)");
			}//ENDIF PENDING BUNDLE ADDRESS MATCHES CURRENT BLOCK OUTPUT ADDRESS
		}//NEXT PENDING BUNDLE ADDRESS
	}//NEXT CURRENT BLOCK OUTPUT ADDRESS


}else{

	$BKCHAIN_ORG_HOST='bkchain.org';
	//$BKCHAIN_ORG_HOST='api.airwallet.me';

	$HARR=json_decode(file_get_contents('https://'.$BKCHAIN_ORG_HOST.'/btc/api/v1/block/hash/'.$LHASH),true);

	$HHASH=$HARR['hash'];
	$HTIME=$HARR['time'];
	$HHEIGHT=$HARR['height'];
	$HMAIN=$HARR['main_chain'];

	$SINCE=time()-$HTIME;
	$MINUTES=sprintf('%.2f',$SINCE/60);

	foreach($HARR['transactions'] as $hk=>$hv){
		foreach($hv['outs'] as $ok=>$ov){
			$HADDRS[$ov['addr']]=1;
		}//NEXT OUTPUT
	}//NEXT TRANSACTION IN BLOCK
	//rdisp($HADDRS);
	$HARR_COUNT=count($HARR);
	print "\nTRACK_PENDING_BUNDLES count(HARR)=$HARR_COUNT output addresses in current block.\n";

	$SOMETHING_PUBLISHED=0;
	foreach($PENDING_BUNDLES as $PENDING_FILENAME=>$pb){
		$PBADDR=$pb['address'];
		if(isset($HADDRS[$PBADDR])){
			print "\n\nTRACK_PENDING_BUNDLES: $PBADDR\n";
			$TRACKING_BUNDLE_FILENAME_OLD=$PENDING_PATH.'/'.$PENDING_FILENAME;
			$TRACKING_BUNDLE_FILENAME_NEW=$PUBLISHED_PATH.'/'.$HHEIGHT.'-'.$PBADDR.'.txt';
			$bogosity=rename($TRACKING_BUNDLE_FILENAME_OLD,$TRACKING_BUNDLE_FILENAME_NEW);
			unset($PENDING_BUNDLES[$PENDING_FILENAME]); //REMOVE PENDING BUNDLE FROM LIST
			//send("PRIVMSG $DEEDBOT_CHAN :ADDRESS $PBADDR FOUND AT BLOCK HEIGHT: $HHEIGHT");
			send("PRIVMSG $DEEDBOT_CHAN :Deed bundle published to filename: $HHEIGHT-$PBADDR.txt  ($BKCHAIN_ORG_HOST)");
			$SOMETHING_PUBLISHED++;
		}//ENDIF PENDING BUNDLE ADDRESS MATCHES CURRENT BLOCK OUTPUT ADDRESS
	}//NEXT PENDING BUNDLE ADDRESS

	if($SOMETHING_PUBLISHED>0){send("PRIVMSG $DEEDBOT_CHAN :see: http://www.postalrocket.com/DEEDS/?FORM=x&SORT=x");}

}//ENDIF USE BLOCKCHAIN.INFO OR BKCHAIN.ORG


}//END FUNCTION TRACK_PENDING_BUNDLES
//######################



//##########################################
//desperation when blockchain.info is 403 Forbidden
//bkchain.org does not currently have an API call to return latest blochain block (used for tracking)
//or currently pending transactions (used for unit testing elsewhere)
function SCRAPE_BKCHAIN(){
	$Q=file_get_contents('https://bkchain.org/btc');

	$tx=array(array(),array());
	$bx=array(array(),array());
	$wx=array();

	if(preg_match_all('/<a href=\'\/btc\/tx\/([0-9a-f]+?)\'>/',$Q,$tx)){
		//rdisp($tx[1]);
		$w=$tx[1][0];
		//print $w;
		$QW=json_decode(file_get_contents('https://bkchain.org/btc/api/v1/tx/hash/'.$w),true);
		//rdisp($QW);
		foreach($QW['outs'] as $ok=>$ov){
			$wx[]=$ov['addr'];
		}//NEXT PENDING TRANSACTION OUTPUT
		//rdisp($wx);
	}//ENDIF MATCH LIVE TRANSACTIONS

	if(preg_match_all('/<a href=\'\/btc\/block\/([0-9a-f]+?)\'>/',$Q,$bx)){
		//rdisp($bx[1]);
	}//ENDIF MATCH BLOCKS

	return(array('tx'=>$tx[1],'blocks'=>$bx[1],'addresses'=>$wx));
}//END FUNCTION SCRAPE_BKCHAIN
//############################################






//#####################################
$GRIBBLE_DATA=array();
$SIGSDONE=array();
$ALL_SIGS_DONE=false;
$ALL_SIGS_RECEIVED=false;
//
function BUNDLE_TRUST_DONE_YET(){
	global $GRIBBLE_DATA;
	global $SIGSDONE,$ALL_SIGS_DONE,$ALL_SIGS_RECEIVED;
	global $ASKING_KEYID,$ASKING_TRUST;

	$DONE_COUNTER=0;
	$KEYID_COUNTER=0;

	foreach($SIGSDONE as $sdk=>$sdv){
		print "\nsdk=$sdk ASKING_KEYID=$ASKING_KEYID ASKING_TRUST=$ASKING_TRUST sdv=\n";
		rdisp($sdv);
		if(!$sdv['asked_KEYID']){
			$ASKING_KEYID=$sdk;
			$SIGSDONE[$sdk]['asked_KEYID']=microtime(true);
			send('PRIVMSG gribble :;;gpg info --key '.$sdk);
			return(false);
		}//ENDIF ASK ALL KEYIDs ONE AT A TIME FIRST PASS

		if($sdv['asked_KEYID'] && $sdv['done_KEYID'] && !$sdv['asked_TRUST'] && $sdv['nick'] && !isset($sdv['unknown_KEYID']) ){
			$ASKING_TRUST=$sdk;
			$SIGSDONE[$sdk]['asked_TRUST']=microtime(true);
			send('PRIVMSG gribble :;;gettrust assbot '.$sdv['nick']);
			return(false);
		}//ENDIF ASK TRUST SECOND PASS UNLESS ALREADY MARKED UNKNOWN

		if($SIGSDONE[$sdk]['done_TRUST'] || isset($SIGSDONE[$sdk]['unknown_KEYID']) ){
			$DONE_COUNTER++;
		}//ENDIF DONE WITH THIS KEYID
		$KEYID_COUNTER++;

	}//NEXT KEY AND TRUST QUERY TALLY

	print "\nBUNDLE_TRUST_DONE_YET = NO UNHANDLED PENDING KEYIDs FOUND -- PRESUMED DONE\n";
	print "\nBUNDLE_TRUST_DONE_YET ... ASKING_KEYID=$ASKING_KEYID ASKING_TRUST=$ASKING_TRUST";
	print "\nDONE_COUNTER = $DONE_COUNTER ... KEYID_COUNTER $KEYID_COUNTER\n";
	if(!$ASKING_KEYID && !$ASKING_TRUST && ($KEYID_COUNTER == $DONE_COUNTER)){
		print "\nNO UNHANDLEDS PENDING, NO ASKINGS DONE_COUNTER = $DONE_COUNTER = KEYID_COUNTER $KEYID_COUNTER -- PRESUMED DONE\n";
		$ALL_SIGS_DONE=true;
		$ALL_SIGS_RECEIVED=true;
	}//ENDIF FLAG STUFF DONE
	rdisp($SIGSDONE);

}//END FUNCTION BUNDLE_TRUST_DONE_YET
//######################################


/*
$GRIBBLE_DATA=Array
(
    [KEYS] => Array
        (
            [35D2E1A0457E6498] => Array
                (
                    [NICK] => RagnarDanneskjol
                    [TRUST] => 3
                    [TIME] => 1411710633.172
                )

            [C58B15FA3E19CE9B] => Array
                (
                    [NICK] => MolokoDesk
                    [TRUST] => 0
                    [TIME] => 1411710633.172
                )

            [999BBBBBBBBBBBBB] => Array
                (
                    [NICK] =>
                    [TRUST] =>
                    [TIME] => 1411710633.172
                )

        )

    [NICKS] => Array
        (
            [RagnarDanneskjol] => Array
                (
                    [KEY] => 35D2E1A0457E6498
                    [TRUST] => 3
                    [TIME] => 1411710635.396
                )

            [MolokoDesk] => Array
                (
                    [KEY] => C58B15FA3E19CE9B
                    [TRUST] => 0
                    [TIME] => 1411710635.9976
                )

            [No such user registered.] => Array
                (
                    [TRUST] =>
                    [KEY] =>
                    [COUNT] => 1
                    [TIME] => 1411710634.7915
                )

        )

)
*/












































//#*********************************
//#****** BITCOIN SPENDING *********


/*
http://php.net/manual/en/function.hash.php
http://stackoverflow.com/questions/19233053/hashing-from-a-public-key-to-a-bitcoin-address-in-php
*/

//####################################################
//### takes hex string public key as input
//### returns bitcoin address string
//### requires bcmath functions for abitrary precision arithmetic
//###
function PublicKey2BitcoinAddr($publickey=false){
	global $DEBUG;

	$step1=hexStringToByteString($publickey);
	$step2=hash("sha256",$step1);
	$step3=hash('ripemd160',hexStringToByteString($step2));
	$step4="00".$step3;
	$step5=hash("sha256",hexStringToByteString($step4));
	$step6=hash("sha256",hexStringToByteString($step5));
	$checksum=substr($step6,0,8);
	$step8=$step4.$checksum;
	//$step9="1".bc_base58_encode(bc_hexdec($step8));
	$step8b=bc_hex_decode($step8);
	$step9="1".bc_base58_encode($step8b);


	if($DEBUG){
		print "<p>publickey=$publickey<br>";
		print "step1 ".$step1." (byte string of hex string public key)<br>";
		print "<br>step2 ".$step2." (sha256 hash of binary public key)<br>";
		print "step3 ".$step3."<br>";
		print "step4 ".$step4."<br>";
		print "step5 ".$step5."<br>";
		print "step6 ".$step6."<br>";
		print "step7 ".$checksum."<br>";
		print "step8 ".$step8."<br>";
		print "step8b ".$step8b." (hex decode of step8)<br>";

		print "step9 ".$step9." (valid but unspendable bitcoin address of sha256 hash of byte string public key)<br><br>";
	}//ENDIF DEBUG


	return($step9);
}//END FUNCTION PublicKey2BitcoinAddr
//#################################################


//#######################################
//### HEX REPRESENTATION TO BYTE STRING
//### (bitcoin hash of publickey uses actual binary string value of hex representation);
//###
function hexStringToByteString($hexString){
	$LString=strlen($hexString);
	$byteString='';
	for($i=0;$i<$LString;$i=$i+2){
		$chnum=hexdec(substr($hexString,$i,2));
		$byteString.=chr($chnum);
	}//NEXT DIGIT
	return($byteString);
}//END FUNCTION hexStringToByteString
//#######################################


//############################################
// BCmath version for huge numbers
function bc_base58_encode($num){
	if(!function_exists('bcadd')){
		Throw new Exception('BCmath extension missing.');
	}//ENDIF ERROR

	//print "<p>num=$num<p>";

	$symbols='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'; //bitcoin specific base58 encoding
	$base=strlen($symbols);
	$b58='';

	while(true){
		if(strlen($num)<2){
			if(intval($num)<=0){
				break;
			}//ENDIF OUT OF DIGITS
		}//ENDIF FEW DIGITS LEFT
		$mod58=bcmod($num,$base);
		$b58=$symbols[intval($mod58)].$b58;
		$num=bcdiv(bcsub($num,$mod58),$base);
		//print "<br>(num=$num mod58=$mod58 b58=$b58)";
	}//WEND NEXT DIGIT

	return($b58);
}//END FUNCTION bc_base58_encode
//###########################################


//##############################################
function bc_hex_decode($hexstr) {
    if(!function_exists('bcadd')){
        Throw new Exception('BCmath extension missing.');
    }//ENDIF ERROR MESSAGE

	$symbols='0123456789abcdef';
	$base=strlen($symbols);
	$dec='0';

	$NARR=str_split((string)$hexstr);
	$cnt=strlen($hexstr);
	for($i=0;$i<$cnt;$i++){
		$pos=strpos($symbols,$NARR[$i]);
		//print " pos=$pos ";
		if($pos===false){
			Throw new Exception(sprintf('Unknown character %s at offset %d',$NARR[$i],$i));
		}//NEXT DIGIT
		$dec=bcadd(bcmul($dec,$base),$pos);
	}//NEXT DIGIT
	//print "<br>dec=$dec<br>";
	return($dec);
}//END FUNCTION bc_hex_decode
//##############################################


//###########################################################
function docFile2bitcoinAddr($docFileName){
	global $DEBUG;

	$doc=file_get_contents($docFileName);
	$step2=hash('sha256',$doc);
	$step3=hash('ripemd160',hexStringToByteString($step2));
	$step4="00".$step3;
	$step5=hash("sha256",hexStringToByteString($step4));
	$step6=hash("sha256",hexStringToByteString($step5));
	$checksum=substr($step6,0,8);
	$step8=$step4.$checksum;
	//print "<br>hex($step8)=".bc_hex_decode($step8)."\n<p>\n";
	$step9="1".bc_base58_encode(bc_hex_decode($step8));

	if($DEBUG){
		print "step1 (document $docFileName)<div style=\"border-style:solid;border-width:1px;font-size:9px;font-family:courier new,courier;\"><pre>".$doc."</pre></div><br>";
		print "<br>step2 ".$step2." (sha256 hash of text document)<br>";
		print "step3 ".$step3."<br>";
		print "step4 ".$step4."<br>";
		print "step5 ".$step5."<br>";
		print "step6 ".$step6."<br>";
		print "step7 ".$checksum."<br>";
		print "step8 ".$step8."<br>";
		print "step9 ".$step9." (valid but unspendable bitcoin address of sha256 hash of text document)<br><br>";
	}//ENDIF DEBUG

	return($step9);
}//END FUNCTION doc2bitcoinAddr
//######################################################



//##################################
//array get_class_vars ( string $class_name )
//array get_object_vars ( object $object )
function CHECK_BLOCKIO_BTC_BALANCE($chan=false){
	global $DEEDBOT_CHAN;
	global $DEBUG;
	if(!$chan){$chan=$DEEDBOT_CHAN;}
	$block_io=new BlockIo(); //fresh object
	$REAL_FROM_ADDR='1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu'; //this is the block.io wallet
	$apiKeyBTC='[block.io BTC api key Here]'; //BITCOIN REAL NEW ACCOUNT 0.0016 BTC = $0.76 ON 15-SEP-2014
	$Q=$block_io->set_key($apiKeyBTC);
	$BALANCE=$block_io->get_balance();
	$ABALANCE=get_object_vars($BALANCE);
	$QBAL1=$block_io->get_address_balance(array('address' => $REAL_FROM_ADDR));
	$AQBAL1=get_object_vars($QBAL1);
	$BALANCE_BTC_DATA=get_object_vars($ABALANCE['data']);
	if($DEBUG){print "\nREAL BITCOIN BALANCE $REAL_FROM_ADDR\n";rdisp($ABALANCE);rdisp($AQBAL1);rdisp($BALANCE_BTC_DATA);}
	//rdisp($ABALANCE);
	rdisp($BALANCE_BTC_DATA);
	$BALANCE_BTC_AVAILABLE=$BALANCE_BTC_DATA['available_balance'];
	$BALANCE_BTC_SENT=$BALANCE_BTC_DATA['unconfirmed_sent_balance'];
	$BALANCE_BTC_RECEIVING=$BALANCE_BTC_DATA['unconfirmed_received_balance'];
	send("PRIVMSG $chan :deedBot BTC available balance: $BALANCE_BTC_AVAILABLE in wallet $REAL_FROM_ADDR");
	if(floatval($BALANCE_BTC_SENT)!=0 && floatval($BALANCE_BTC_RECEIVING)==0){
		send("PRIVMSG $chan :deedBot unconfirmed sent: $BALANCE_BTC_SENT");
	}elseif(floatval($BALANCE_BTC_SENT)==0 && floatval($BALANCE_BTC_RECEIVING)!=0){
		send("PRIVMSG $chan :deedBot unconfirmed received: $BALANCE_BTC_RECEIVING");
	}elseif(floatval($BALANCE_BTC_SENT)!=0 && floatval($BALANCE_BTC_RECEIVING)!=0){
		send("PRIVMSG $chan :deedBot unconfirmed sent: $BALANCE_BTC_SENT   unconfirmed received: $BALANCE_BTC_RECEIVING");
	}//ENDIF SPEW BALANCE MESSAGE



	unset($block_io); //this calls the object destructor.
}//END FUNCTION CHECK_BLOCKIO_BTC_BALANCE
//##################################


/*
stdClass Object
(
    [status] => success
    [data] => stdClass Object
        (
            [network] => BTC
            [available_balance] => 0.00128000
            [unconfirmed_sent_balance] => 0.00000000
            [unconfirmed_received_balance] => 0.00000000
        )

)
*/



//#################
function SEND_BTC($ADDR=false){
	global $block_io;
	global $DEEDBOT_CHAN,$ERROR_CHAN;
	global $DEBUG;
	global $block_io;

	$apiKeyBTC='[block.io API BTC key HERE again]'; //BITCOIN REAL NEW ACCOUNT 0.0016 BTC = $0.76 ON 15-SEP-2014
	$apiKeyBTCTEST='a3d4-d6eb-5077-a806'; //use testnet for debugging

	if(strlen($ADDR)!=34 || ($ADDR[0]!='m' && $ADDR[0]!='1') ){
		send("PRIVMSG $ERROR_CHAN :SEND_BTC: bogus address: $ADDR");
		print "<hr>bogus address: $ADDR\n";
		return(false);
	}//ENDIF BOGUS-LOOKING BITCOIN ADDRESS


	$block_io=new BlockIo(); //fresh object


	$SEND_AMOUNT=0.0001; //minimum BTC spend

	$TEST_FROM_ADDR='mg68NmsW7gfUgAV8LSLJoiFq44sQnYUC56';
	$TEST_TO_ADDR='mqpeMfETdcR1BWHLfVCb53JVjrSxcTQkfg';

	//$ADDR=$TEST_TO_ADDR; //force TESTNET test

	$REAL_FROM_ADDR='1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu'; //this is the block.io wallet
	$REAL_TO_ADDR='18AyhuG8VvLqJFvoRH5LGUZPZJ8eN5J6Fz'; //sends back to the block.io wallet account

	$SECRET_PIN='[secret PIN number to spend BTC Here]';
	
	$COIN_VERSION_DIGIT=$ADDR[0];
	if($COIN_VERSION_DIGIT=='m'){
		$source_addr=$TEST_FROM_ADDR;
		$Q=$block_io->set_key($apiKeyBTCTEST);
		$BALANCE=$block_io->get_balance();
		if($DEBUG){print "<hr>TESTNET BITCOIN BALANCE";rdisp($BALANCE);}

	}elseif($COIN_VERSION_DIGIT=='1'){
		$source_addr=$REAL_FROM_ADDR;
		$Q=$block_io->set_key($apiKeyBTC);
		$BALANCE=$block_io->get_balance();
		if($DEBUG){print "<hr>REAL BITCOIN BALANCE";rdisp($BALANCE);}

	}else{
		print "<hr>bogus coin address: not BTC or BTC TESTNET";
		return(false);
	}//ENDIF REAL BTC OR TESTNET BTC

	//$destination_addr=$TEST_TO_ADDR;
	$destination_addr=$ADDR;

	
	$ORDER=array('amount' => $SEND_AMOUNT, 'from_addresses' => $source_addr, 'payment_address' => $destination_addr, 'pin' => '***********************');
	if($DEBUG){print "\n<hr>SPEND ORDER\n";rdisp($ORDER);}
	$ORDER['pin']=$SECRET_PIN;

	//$QSEND=array('error'=>'testing not sent');send("PRIVMSG $DEEDBOT_CHAN :(spending is turned off inside SEND_BTC() for testing.)"); //to skip sending
	$QSEND=$block_io->withdraw_from_addresses($ORDER); //REALLY SEND
	if($DEBUG){print "\n<hr>RESULT OF SEND API\n";rdisp($QSEND);}

	sleep(5);
	
	$QBAL1=$block_io->get_address_balance(array('address' => $source_addr));
	if($DEBUG){print "\n<hr>RESULTING BALANCES (PENDING USUALLY)\n";rdisp($QBAL1);}

	//$QBAL2=$block_io->get_address_balance(array('address' => $destination_addr));
	//rdisp($QBAL2);


	unset($block_io); //this calls the object destructor.

	return(array('source'=>$source_addr,'destination'=>$destination_addr,'amount'=>$SEND_AMOUNT,'spend'=>$QSEND,'balance'=>$QBAL1));
}//END FUNCTION SEND_BTC
//#################


//#********** END BITCOIN SPENDING ***********
//#*******************************************











//#****************************
//#******** BUNDLING **********


$BUNDLE_QUEUE_PATH='./DEEDS/BUNDLE_QUEUE';
$BUNDLE_DONE_PATH='./DEEDS/BUNDLE_DONE';

//#######################
function FIND_AND_BUNDLE_PENDING_DEEDS(){
	global $BUNDLE_QUEUE_PATH;
	global $BUNDLE_DONE_PATH;
	global $DEEDBOT_CHAN,$ERROR_CHAN;

	//search for unbundled validated deeds

	$DARR=scandir($BUNDLE_QUEUE_PATH);
	rdisp($DARR);
	if(count($DARR)<3){
		print("DEED_BUNDLER: Nothing in Deeds to Bundle Queue.\n");
		send("PRIVMSG $ERROR_CHAN : Nothing in Deeds to Bundle Queue.");
		return(false);
	}//ENDIF NOTHING IN QUEUE


	$BUNDLE_TEMP_FILE=$BUNDLE_DONE_PATH.'/BUNDLE_TEMP.txt';
	$vuu=`cat $BUNDLE_QUEUE_PATH/*.txt > $BUNDLE_TEMP_FILE`;
	$step9=docFile2bitcoinAddr($BUNDLE_TEMP_FILE);
	$BUNDLE_UNSPENT_FILE=$BUNDLE_DONE_PATH.'/BUNDLE_UNSPENT-'.$step9.'.txt';
	rename($BUNDLE_TEMP_FILE,$BUNDLE_UNSPENT_FILE);
	foreach($DARR as $dk=>$dv){
		if(preg_match('/\.txt$/',$dv)){
			unlink($BUNDLE_QUEUE_PATH.'/'.$dv);
		}//ENDIF DELETE A NOW-BUNDLED CONTRACT
	}//NEXT DELETE NOW-BUNDLED CONTRACTS
	print `ls -al $BUNDLE_QUEUE_PATH/*.txt`;

	return(false);

}//END FUNCTION FIND_AND_BUNDLE_PENDING_DEEDS
//#######################





//#######################################
function SPEND_TO_UNSPENT_BUNDLES(){
	global $DEEDBOT_CHAN,$ERROR_CHAN;
	global $SPENDING_ENABLED;
	global $PENDING_PATH;

	$SPENDING_ENABLED=true;


	$UARR=SCAN_FOR_UNSPENT_BUNDLES();
	rdisp($UARR);

	if($UARR){

		if($SPENDING_ENABLED){
			send("PRIVMSG $ERROR_CHAN :SPEND_TO_UNSPENT_BUNDLES: SPENDING ENABLED =  $SPENDING_ENABLED");
			send("PRIVMSG $DEEDBOT_CHAN :SPEND_TO_UNSPENT_BUNDLES: SPENDING ENABLED = $SPENDING_ENABLED");
			foreach($UARR as $UNSPENT_BUNDLE_FILENAME=>$uv){
				if(preg_match('/^BUNDLE_UNSPENT\-([a-zA-Z0-9]+)\.txt$/',$UNSPENT_BUNDLE_FILENAME,$sx)){
					$BUNDLE_SPEND_ADDRESS=$sx[1];
					$SPENT_BUNDLE_FILENAME=preg_replace('/\_UNSPENT\-/','_PENDING-',$UNSPENT_BUNDLE_FILENAME);
					send("PRIVMSG $ERROR_CHAN :would spend for: $UNSPENT_BUNDLE_FILENAME = $SPENT_BUNDLE_FILENAME = $BUNDLE_SPEND_ADDRESS");
					$SPEND_RESULT=SEND_BTC($BUNDLE_SPEND_ADDRESS);
					rdisp($SPEND_RESULT);
					$SPEND_RESULT_SPEND=get_object_vars($SPEND_RESULT['spend']);
					$SPEND_RESULT_STATUS=$SPEND_RESULT_SPEND['status'];
					if($SPEND_RESULT_STATUS=='success'){
						$SPEND_RESULT_DATA=get_object_vars($SPEND_RESULT_SPEND['data']);
						$AMOUNT_WITHDRAWN=$SPEND_RESULT_DATA['amount_withdrawn'];
						rdisp($SPEND_RESULT_DATA);
						rename($PENDING_PATH.'/'.$UNSPENT_BUNDLE_FILENAME,$PENDING_PATH.'/'.$SPENT_BUNDLE_FILENAME);
						send("PRIVMSG $ERROR_CHAN :BTC SPENT: amount_withdrawn = $AMOUNT_WITHDRAWN   TRACKING: $SPENT_BUNDLE_FILENAME");
						send("PRIVMSG $DEEDBOT_CHAN :BTC SPENT: amount_withdrawn = $AMOUNT_WITHDRAWN   TRACKING: $SPENT_BUNDLE_FILENAME");
					}else{
						send("PRIVMSG $ERROR_CHAN :BTC SPEND FAILED -- PENDING: $UNSPENT_BUNDLE_FILENAME");
						send("PRIVMSG $DEEDBOT_CHAN :BTC SPEND FAILED -- PENDING: $UNSPENT_BUNDLE_FILENAME");			
					}//ENDIF SPEND SUCCEEDED
				}else{
					send("PRIVMSG $ERROR_CHAN :bogus BUNDLE_SPEND_ADDRESS for: $UNSPENT_BUNDLE_FILENAME = $uv");
				}//ENDIF ADDRESS MAKES SENSE
			}//NEXT UNSPENT BUNDLE FOUND
			return(false);
		}else{
			send("PRIVMSG $ERROR_CHAN :SPEND_TO_UNSPENT_BUNDLES: SPENDING IS OFF. SPENDING_ENABLED=$SPENDING_ENABLED");
			send("PRIVMSG $DEEDBOT_CHAN :(BTC spending is off.)");
		}//ENDIF SPENDING NOT ENABLED

	}else{

		print "\nSPEND_TO_UNSPENT_BUNDLES: no unspent bundles.\n";
		send("PRIVMSG $ERROR_CHAN :SPEND_TO_UNSPENT_BUNDLES: no unspent bundles.");

	}//ENDIF BUNDLES TO SPEND EXIST

}//END FUNCTION SPEND_TO_UNSPENT_BUNDLES
//##########################################

/*
$SPEND_RESULT=
Array
(
    [source] => 1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu
    [destination] => 1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7
    [amount] => 0.0001
    [spend] => stdClass Object
        (
            [status] => success
            [data] => stdClass Object
                (
                    [network] => BTC
                    [txid] => 779bb8d540c55054cc365e9bfdc0098ed91aac1d84d94ae76f351d4d35e271f5
                    [amount_withdrawn] => 0.00011000
                    [amount_sent] => 0.00010000
                    [network_fee] => 0.00001000
                    [blockio_fee] => 0.00000000
                )

        )
*/























































































/*

<pre>Array
(
    [address] => 1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7
    [bundle_file] => ./DEEDS/BUNDLE_DONE/BUNDLE_PENDING-1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7.txt
    [bundle_url] => http://www.postalrocket.com/TJ/DEEDS/BUNDLE_DONE/BUNDLE_PENDING-1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7.txt
    [SPENT] => Array
        (
            [source] => 1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu
            [destination] => 1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7
            [amount] => 0.0001
            [spend] => Array
                (
                    [error] => testing not sent
                )

            [balance] => Array
                (
                    [status] => success
                    [data] => Array
                        (
                            [network] => BTC
                            [available_balance] => 0.00105000
                            [unconfirmed_sent_balance] => -0.00044000
                            [unconfirmed_received_balance] => 0.00000000
                            [user_id] => 0
                            [address] => 1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu
                            [label] => default
                        )

                )

        )

)
</pre>



{"address":"1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7","bundle_file":".\/DEEDS\/BUNDLE_DONE\/BUNDLE_PENDING-1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7.txt","bundle_url":"http:\/\/www.postalrocket.com\/TJ\/DEEDS\/BUNDLE_DONE\/BUNDLE_PENDING-1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7.txt","SPENT":{"source":"mg68NmsW7gfUgAV8LSLJoiFq44sQnYUC56","destination":"mqpeMfETdcR1BWHLfVCb53JVjrSxcTQkfg","amount":0.0001,"spend":{"status":"success","data":{"network":"BTCTEST","txid":"d14b70f09c9188a374214b9e1638ce95a24a4d7f70af97dfc5313862556b34d4","amount_withdrawn":"0.00011000","amount_sent":"0.00010000","network_fee":"0.00001000","blockio_fee":"0.00000000"}},"balance":{"status":"success","data":{"network":"BTCTEST","available_balance":"0.00978000","unconfirmed_sent_balance":"-0.00011000","unconfirmed_received_balance":"0.00000000","user_id":0,"address":"mg68NmsW7gfUgAV8LSLJoiFq44sQnYUC56","label":"default"}}}}
*/






//##################
//##################
//#### M A I N #####
//##################
//##################




if(isset($_SERVER['REMOTE_ADDR'])){die('No Web Interface.');}


$FHI=false;	//IRC socket handle

$VERBOSE=false;

//initialize various quote display related items
$C255=chr(255);
$BOLD=chr(2);
$BOLDX='';





$ECHO_MODE=false;

$COMPATIBILITY_MODE=false;

$LAST_PING_TIME=time();



//#### CONFIGURE AND INITIALIZE ####

//$USE_BLOCKCHAIN_INFO=true;  //use blockchain.io API
$USE_BLOCKCHAIN_INFO=false;  //resort to scraping bkchain.org


$DEEDBOT_CHAN='#cex-squawk'; //testing
//$DEEDBOT_CHAN='#bitcoin-assets';

$ERROR_CHAN='MolokoDesk';

$PENDING_BUNDLES=array(); //pending bundle files

$DEBUG=false;


//$PENDING_PATH='./DEEDS/TESTS';
$PENDING_PATH='./DEEDS/BUNDLE_DONE';

//$PUBLISHED_PATH='../postalrocket.com/DEEDS_TEST';
$PUBLISHED_PATH='../postalrocket.com/DEEDS';

$TRACKING_LAST_BUNDLE_TIME=intval(time()/3600)*3600; //defaults to previous top of hour on startup
if(time()-$TRACKING_LAST_BUNDLE_TIME>(3600-300)){$TRACKING_LAST_BUNDLE_TIME+=300;} //make it at least 5 minutes until the next bundle

$PREVIOUS_BLOCK_HASH='initialize';

$BUNDLE_SPENDING_INTERVAL=3600; //seconds

$ASKING_KEYID=false;
$ASKING_TRUST=false;

$BUNDLE_HANDLED=false;
$BUNDLE_ACTIVE=false;
$ALL_SIGS_DONE=false;
$ALL_SIGS_RECEIVED=false;
$TRACKING_ACTIVE=false;
$TRACK_TXID=false;
$TRACK_ADDR=false;
$TRACKING_CHAN=$DEEDBOT_CHAN;
$TRACKING_VERBOSE=true; //announce all tracking events



//### END CONFIGURE AND INITIALIZE ###









$rawdata='';



if(!$FHI){
	CONNECT_TO_FREENODE();
}//CONNECT TO IRC IF DISCONNECTED

print "first FHI=".rdisp($FHI)."\n\n";

sleep(10);

//RECONNECT LOOP
while(true){




if(!$FHI || feof($FHI) ){
	CONNECT_TO_FREENODE();
}//CONNECT TO IRC IF DISCONNECTED


$REGISTERED=false;


if(!feof($FHI) && $FHI){

	$rawdata=fgets($FHI,4082);	//this reads one line at a time...

	if($rawdata && ($ECHO_MODE || !true)){print $rawdata;print "\n\n";}
	if(!$rawdata){sleep(1);}


	if(preg_match('/Found your hostname|using your IP address instead/',$rawdata)){
		send("PASS $ircpassword");
		sleep(3);
		send("NICK $ircnick");
		sleep(3);
		send("USER $ircnick jones.anarchy.mil mv.nowhere.net :$ircnick");
		sleep(3);
	}//ENDIF HOSTNAME



	if(preg_match('/443 Tao_Jr \#love \:is already on channel/',$rawdata)){
		send("PRIVMSG nickserv :IDENTIFY $ircpassword");
		sleep(2);
	}//ENDIF IDENTIFY NICK



	if(preg_match('/ :You have not registered/',$rawdata)){
		send('NICK '.$ircnick);
		sleep(2);
		send("USER $ircnick jones.anarchy.mil mv.nowhere.net :$ircnick");
		send("PRIVMSG nickserv :IDENTIFY $ircpassword");
		sleep(2);
		send("JOIN $DEEDBOT_CHAN");
		sleep(2);
		//send('PRIVMSG '.$DEEDBOT_CHAN.' :'.$BOLD.'deedBot Incoming Bitcoin Address:'.$BOLD.' 1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu'."\n");
	}//ENDIF IDENTIFY NICK


	if(preg_match('/^:(\S+)\!.+?\sPRIVMSG deedBot :.{0,3}VERSION.{0,3}[\r\n]*/i',$rawdata,$px)){
		$NICK=$px[1];
		$INFO_STRING=date('D Y-M-d H:i:s T',filemtime('deedBot.php'));
		$CTCP_TOKEN='';
		send("NOTICE $NICK :".$CTCP_TOKEN."VERSION deedBot.php by MolokoDesk, Last Updated: $INFO_STRING".$CTCP_TOKEN);
	}//ENDIF CTCP VERSION


	if(preg_match('/^PING\s*:(.+)[\r\n\s]+/',$rawdata,$px)){
		$PINGTOKEN=$px[1];
		send("PONG :".$PINGTOKEN);
		send("PONG ".$PINGTOKEN);
		print "\nSENDING: PONG :$PINGTOKEN\n";
		$LAST_PING_TIME=time();
		//if($TRACKING_ACTIVE){TRACK_TRANSACTION();} //*** TRACKING DEED BUNDLE ADDRESS TO BLOCKCHAIN ***
		$PENDING_BUNDLES=SCAN_FOR_PENDING_BUNDLES();
		if($PENDING_BUNDLES){TRACK_PENDING_BUNDLES();}else{print "\n(PING FROM SERVER) NO PENDING BUNDLES\n";}
	}//ENDIF PING/PONG






	//PONG TIME ACCELLERATED to 63 seconds FOR TRANSACTION TRACKING WAS 300seconds
	if(time()-$LAST_PING_TIME > 63){
		send("PING $ircaddress");
		//send("PONG :".$PINGTOKEN);
		$LAST_PING_TIME=time();
		//if($TRACKING_ACTIVE){TRACK_TRANSACTION();} //*** TRACKING DEED BUNDLE ADDRESS TO BLOCKCHAIN ***
		$PENDING_BUNDLES=SCAN_FOR_PENDING_BUNDLES();
		if($PENDING_BUNDLES){TRACK_PENDING_BUNDLES();}else{print "\n(PONG 63 seconds) NO PENDING BUNDLES\n";}
	}//ENDIF LAST PING TOO STALE, SAY SOMETHING


	//BUNDLE CYCLE TIME 3600 seconds = 1 hour
	if(time()-$TRACKING_LAST_BUNDLE_TIME > $BUNDLE_SPENDING_INTERVAL){
		send("PRIVMSG $DEEDBOT_CHAN :(One hour since last deed bundle scan: ".date('D Y-M-d H:i:s T',time()).')');

		FIND_AND_BUNDLE_PENDING_DEEDS();
		SPEND_TO_UNSPENT_BUNDLES();

		$TRACKING_LAST_BUNDLE_TIME=time();
	}//ENDIF HOUR SINCE LAST BUNDLE TO BLOCKCHAIN


	//DUMP DIAGNOSIC REQUEST
	if(preg_match('/(\#\S+)\s+:([\?\+\!\.\=])(dump)/i',$rawdata,$px)){
		$chan=$px[1];
		$tokenoid=$px[2];
		$cmdstring=$px[3];
		$xtime=time();
		$xdiff=$xtime-$TRACKING_LAST_BUNDLE_TIME;
		send("PRIVMSG $chan :time=$xtime LAST_BUNDLE_TIME=$TRACKING_LAST_BUNDLE_TIME elapsed=$xdiff");
		print "\n\n-------DUMP-----------\n";
		rdisp($PENDING_BUNDLES);
		foreach($PENDING_BUNDLES as $bk=>$bv){
			$TQMSG=$bk.' = ';
			foreach($bv as $vk=>$vv){
				$TQMSG.='('.$vk.' = '.$vv.') ';
			}//NEXT ITEM
			send("PRIVMSG $chan :TRACKING: $TQMSG");
		}//NEXT TRACKABLES
		//send("PRIVMSG $chan :TRACKING_ACTIVE=$TRACKING_ACTIVE TRACKING_CHAN=$TRACKING_CHAN TRACK_TXID=$TRACK_TXID TRACK_ADDR=$TRACK_ADDR");
		//send("PRIVMSG $chan :TRACKING_BUNDLE_FILENAME=$TRACKING_BUNDLE_FILENAME TRACKING_VERBOSE=$TRACKING_VERBOSE");


		if($USE_BLOCKCHAIN_INFO){$USING_API='blockchain.info API';}else{$USING_API='bkchain.org scraping/API';}
		send("PRIVMSG $chan :DEEDBOT_CHAN=$DEEDBOT_CHAN ERROR_CHAN=$ERROR_CHAN USING_API=$USING_API INTERVAL=$BUNDLE_SPENDING_INTERVAL");


		if($ERROR_LAST){send("PRIVMSG $chan :$ERROR_LAST");}

		$DEEDBOT_CHAN_X=$DEEDBOT_CHAN;
		$DEEDBOT_CHAN=$chan;
		if($DEEDBOT_CHAN_X != $DEEDBOT_CHAN){send("PRIVMSG $chan :(switching DEEDBOT_CHAN from $DEEDBOT_CHAN_X to $DEEDBOT_CHAN)");}

		$DARR=scandir($BUNDLE_QUEUE_PATH);
		foreach($DARR as $dk=>$dv){
			if(preg_match('/\.txt$/',$dv)){
				$WOULD_DELETE=$BUNDLE_QUEUE_PATH.'/'.$dv;
				//unlink($WOULD_DELETE);
				print "\nWOULD DELETE: $WOULD_DELETE\n";
			}//ENDIF WOULD DELETE
		}//NEXT DELETE NOW-BUNDLED CONTRACTS
		print `ls -al $BUNDLE_QUEUE_PATH/*.txt`;
		rdisp($DARR);

		print "\nGRIBBLE_DATA=";
		rdisp($GRIBBLE_DATA);
		print "\nSIGSDONE ASKING_KEYID=$ASKING_KEYID ASKING_TRUST=$ASKING_TRUST";
		rdisp($SIGSDONE);

		print "\n\n-------END DUMP-----------\n";
	}//ENDIF DUMP VARIABLES FOR TESTING







	if(preg_match('/(\#\S+)\s+:[\?\+\!\.\=]interval\s+([\d]+)/i',$rawdata,$px)){
		$chan=GetChan($rawdata);
		print "\n".'NEW INTERVAL = '.$px[2]."\n";
		$BUNDLE_SPENDING_INTERVAL=min(3600,max(300,intval($px[2])));
		send('PRIVMSG '.$chan.' :BUNDLE_SPENDING_INTERVAL = '.$BUNDLE_SPENDING_INTERVAL.' seconds.');
	}//ENDIF SET BUNDLE INTERVAL...


	if(preg_match('/INVITE deedBot :(\#.+)/i',$rawdata,$px)){
		if($VERBOSE){send('PRIVMSG #love2 :JOIN '.$px[1]);}
		send('JOIN '.$px[1]);
	}//ENDIF INVITE

	if(preg_match('/:[+!.=]join\s*(.*)/i',$rawdata,$px)){
		if($VERBOSE){send('PRIVMSG #love2 :JOIN '.$px[1]);}
		send('JOIN '.$px[1]);
	}//ENDIF JOIN






	if(preg_match('/Your nick isn.t registered/',$rawdata) || !$JOINED){
		send('NICK '.$ircnick);
		sleep(2);
		send('PRIVMSG nickserv :IDENTIFY '.$ircpassword);
		send('JOIN '.$DEEDBOT_CHAN);
		$JOINED=true;
		sleep(1);
		//send('PRIVMSG chanserv :op');
	}//ENDIF NICK NOT REGISTERED



	if(preg_match('/NOTICE deedBot :This nickname is registered and protected/',$rawdata)){
		send('PRIVMSG nickserv :IDENTIFY '.$ircpassword);
		sleep(3);
		send('JOIN '.$DEEDBOT_CHAN);
		$JOINED=true;
		sleep(1);
		//send('PRIVMSG chanserv :op');
	}//ENDIF NICK NOT REGISTERED










//####### GRIBBLE RESPONSES ########
/*
:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG deedBot :User 'RagnarDanneskjol', with keyid 35D2E1A0457E6498, fingerprint B4AF6458D7D8A2846F91807935D2E1A0457E6498, and bitcoin address 14ixghmHMcB4szGL3ue5WJ1qnjnWnQXiP6, registered on Thu Jun  5 04:07:21 2014, last authed on Tue Sep 23 18:15:51 2014. http://b-otc.com/vg?nick=RagnarDanneskjol . Currently not authenticated.

:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG deedBot :WARNING: Currently not authenticated. Trust relationship from user assbot to user RagnarDanneskjol: Level 1: 0, Level 2: 3 via 3 connections. Graph: http://b-otc.com/stg?source=assbot&dest=RagnarDanneskjol | WoT data: http://b-otc.com/vrd?nick=RagnarDanneskjol | Rated since: Thu Jun 26 20:35:44 2014

:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG deedBot :No such user registered.


*/



	//### GRIBBLE KEYID+NICK RESPONSE
	if(preg_match('/:gribble\!.*?PRIVMSG\s*deedBot\s+:User\s+\'(.+?)\'.*?with\s+keyid\s+([A-F0-9a-f]+)/i',$rawdata,$knx)){
		print "\n\n-------KEYID+NICK--------\n".$rawdata;
		print "ASKING_KEYID=$ASKING_KEYID ASKING_TRUST=$ASKING_TRUST\n";
		rdisp($knx);
		$KID_NICK=$knx[1];
		$KID_KEY=$knx[2];
		$GRIBBLE_DATA['NICKS'][$KID_NICK]['KEY']=$KID_KEY;
		$GRIBBLE_DATA['KEYS'][$KID_KEY]['NICK']=$KID_NICK;
		$SIGSDONE[$KID_KEY]['done_KEYID']=microtime(true);
		$SIGSDONE[$KID_KEY]['nick']=$KID_NICK;
		rdisp($GRIBBLE_DATA);
		if($KID_KEY==$ASKING_KEYID){$ASKING_KEYID=false;}else{print "\nKID_KEY $KID_KEY != ASKING_KEYID $ASKING_KEYID\n";}
		BUNDLE_TRUST_DONE_YET(); //this asks gribble for the next appropriate thing
	}//ENDIF GRIBBLE KEYID+NICK RESPONSE


	//### GRIBBLE NICK+ASSBOT_TRUST RESPONSE
	if(preg_match('/:gribble\!.*?PRIVMSG\s*deedBot\s+:.*?Trust.*?user\s+assbot\s+to\s+user\s+([^\s]+):.+?Level\s+1:\s*([0-9]+).+?Level\s+2:\s*([0-9]+)/i',$rawdata,$knt)){
		print "\n\n-------NICK+ASSBOT_TRUST--------\n".$rawdata;
		print "ASKING_KEYID=$ASKING_KEYID ASKING_TRUST=$ASKING_TRUST\n";
		rdisp($SIGSDONE);
		rdisp($knt);
		$TRUST_NICK=$knt[1];
		$TRUST_LEVEL1=intval($knt[2]);
		$TRUST_LEVEL2=intval($knt[3]);
		$TRUST_SUM=$TRUST_LEVEL1+$TRUST_LEVEL2;
		$GRIBBLE_DATA['NICKS'][$TRUST_NICK]['TRUST']=$TRUST_SUM;
		$GRIBBLE_DATA['NICKS'][$TRUST_NICK]['TIME']=microtime(true);
		$TRUST_KEY=$GRIBBLE_DATA['NICKS'][$TRUST_NICK]['KEY'];
		$GRIBBLE_DATA['KEYS'][$TRUST_KEY]['TRUST']=$TRUST_SUM;
		$SIGSDONE[$ASKING_TRUST]['done_TRUST']=microtime(true);
		$SIGSDONE[$ASKING_TRUST]['trust']=$TRUST_SUM;
		rdisp($GRIBBLE_DATA);
		if($TRUST_NICK=$ASKING_TRUST){$ASKING_TRUST=false;}else{print "\nTRUST_NICK $TRUST_NICK != ASKING_TRUST $ASKING_TRUST\n";}
		BUNDLE_TRUST_DONE_YET(); //this asks gribble for the next appropriate thing
	}//ENDIF GRIBBLE NICK+ASSBOT_TRUST RESPONSE




	//:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG deedBot :No such user registered.
	//### GRIBBLE NO SUCH USER REGISTERED RESPONSE
	if(preg_match('/:gribble\!.*?PRIVMSG\s*deedBot\s+:.*?(No\s+such\s+user\s+registered)\./i',$rawdata,$kut)){
		print "\n\n-------NO SUCH USER REGISTERED--------\n".$rawdata;
		print "ASKING_KEYID=$ASKING_KEYID ASKING_TRUST=$ASKING_TRUST\n";
		rdisp($SIGSDONE);
		rdisp($kut);
		$NOSUCH_NICK='No such user registered.';
		$GRIBBLE_DATA['NICKS'][$NOSUCH_NICK]['TRUST']=false;
		$GRIBBLE_DATA['NICKS'][$NOSUCH_NICK]['KEY']=false;
		if(!isset($GRIBBLE_DATA['NICKS']['No such user registered.']['COUNT'])){$GRIBBLE_DATA['NICKS']['No such user registered.']['COUNT']=0;}
		$GRIBBLE_DATA['NICKS']['No such user registered.']['COUNT']++;
		$GRIBBLE_DATA['NICKS']['No such user registered.']['TIME']=microtime(true);
		rdisp($GRIBBLE_DATA);
		if($ASKING_KEYID){
			$SIGSDONE[$ASKING_KEYID]['done_KEYID']=microtime(true);
			$SIGSDONE[$ASKING_KEYID]['nick']=false;
			$SIGSDONE[$ASKING_KEYID]['unknown_KEYID']=microtime(true);
			$ASKING_KEYID=false;
		}//ENDIF ASKING ABOUT NICK FOR KEYID
		if($ASKING_TRUST){
			$SIGSDONE[$ASKING_TRUST]['done_TRUST']=microtime(true);
			$ASKING_TRUST=false;
			$SIGSDONE[$ASKING_KEYID]['trust']=false;
			$SIGSDONE[$ASKING_KEYID]['unknown_TRUST']=microtime(true);
		}//ENDIF ASKING ABOUT NICK FOR KEYID
		BUNDLE_TRUST_DONE_YET(); //this asks gribble for the next appropriate thing
	}//ENDIF GRIBBLE NO SUCH USER REGISTERED RESPONSE













	//#####
	//##### IF ALL SIGNATURES CHECKED... QUEUE VALID DEEDS FOR NEXT BUNDLE PICKUP ####
	//##### files written to /INCOMING_SPLIT/ and /INCOMING_NEW/ direcdtories must be writeable (files must be deletable/movable)
	//#####
	if($ALL_SIGS_DONE && $ALL_SIGS_RECEIVED && isset($QARR) && isset($SIGSDONE) && !$BUNDLE_HANDLED){
		print "\n\n--------------------------\nOK. ALL SIGS DONE ... HANDLING VALIDATION FOR PENDING DEEDS...\n\n";
		rdisp($SIGSDONE);
		rdisp($QARR);
		foreach($QARR as $qk=>$qv){
			$DEED_KEYID=$qv['keyID'];
			if(isset($SIGSDONE[$DEED_KEYID])){
				$SIG_NICK=$SIGSDONE[$DEED_KEYID]['nick'];
				$SIG_TRUST=$SIGSDONE[$DEED_KEYID]['trust'];
				$SIG_CHAN=$SIGSDONE[$DEED_KEYID]['chan'];
				if($SIGSDONE[$DEED_KEYID]['trust']>0){
					$QARR[$qk]['trust']=$SIG_TRUST;
					$QARR[$qk]['nick']=$SIG_NICK;
					$QARR[$qk]['chan']=$SIG_CHAN;
					$QUEUED_SPLITFILE=preg_replace('/\/INCOMING_SPLIT\//','/BUNDLE_QUEUE/',$qv['splitfile']);
					rename($qv['splitfile'],$QUEUED_SPLITFILE);
					print "\nQUEUED_SPLITFILE=$QUEUED_SPLITFILE\n\n";
					send("PRIVMSG $SIG_CHAN :deed keyID: $DEED_KEYID trust: $SIG_TRUST nick: $SIG_NICK (valid and scheduled for next bundle)");
				}else{
					$QARR[$qk]['trust']=$SIG_TRUST;
					$QARR[$qk]['nick']=$SIG_NICK;
					$QARR[$qk]['chan']=$SIG_CHAN;
					$BOGUS_SPLITFILE=preg_replace('/\/INCOMING_SPLIT\//','/BOGUS_SPLIT/',$qv['splitfile']);
					rename($qv['splitfile'],$BOGUS_SPLITFILE);
					print "\nBOGUS_SPLITFILE=$BOGUS_SPLITFILE\n\n";
					send("PRIVMSG $SIG_CHAN :deed keyID: $DEED_KEYID trust: $SIG_TRUST nick: $SIG_NICK (seems bogus - Discarded)");
				}//ENDIF VALID OR BOGUS SIGNATURE TRUST
			}//ENDIF TRUST KEY FOR METRIC EXISTS
		}//NEXT PENDING DEED
		rdisp($QARR);
		//assuming that all split files are from the same source file...
		$DONE_SOURCEFILE=preg_replace('/\/INCOMING_NEW\//','/INCOMING_DONE/',$QARR[0]['sourcefile']);
		rename($QARR[0]['sourcefile'],$DONE_SOURCEFILE);
		print "\nALL INCOMING DEEDS VALIDATED, SCANNED AND DONE...\n\n";
		$BUNDLE_HANDLED=true;
		$BUNDLE_ACTIVE=false;
		print `ls -alR ./DEEDS`;
	}//ENDIF ALL SIGS DONE HANDLE PENDING VALID DEEDS


//requires newline delimiters between signed documents...
//append \n\n after each split file...
//cat ./DEEDS/BUNDLE_QUEUE/*.txt > ./DEEDS/BUNDLE_DONE/BUNDLE_TEST.txt


/*

QARR=
Array
(
    [0] => Array
        (
            [splitfile] => ./DEEDS/INCOMING_SPLIT/1410433154.9385_0.txt
            [sourcefile] => ./DEEDS/INCOMING_NEW/1410433154.9385_NEW.txt
            [keyID] => 35D2E1A0457E6498
            [nsigs] => 1
            [trust] => 1
            [nick] => RagnarDanneskjol
            [chan] => #love2
        )

    [1] => Array
        (
            [splitfile] => ./DEEDS/INCOMING_SPLIT/1410433154.9385_1.txt
            [sourcefile] => ./DEEDS/INCOMING_NEW/1410433154.9385_NEW.txt
            [keyID] => 35D2E1A0457E6498
            [nsigs] => 1
            [trust] => 1
            [nick] => RagnarDanneskjol
            [chan] => #love2
        )

    [2] => Array
        (
            [splitfile] => ./DEEDS/INCOMING_SPLIT/1410433154.9385_2.txt
            [sourcefile] => ./DEEDS/INCOMING_NEW/1410433154.9385_NEW.txt
            [keyID] => C58B15FA3E19CE9B
            [nsigs] => 6
            [trust] => 0
            [nick] => MolokoDesk
            [chan] => #love2
        )

)

SIGSDONE=
Array
(
    [35D2E1A0457E6498] => Array
        (
            [done] => 1
            [nick] => RagnarDanneskjol
            [trust] => 1
            [chan] => #love2
        )

    [C58B15FA3E19CE9B] => Array
        (
            [done] => 1
            [nick] => MolokoDesk
            [trust] => 0
            [chan] => #love2
        )

)

SIGSDONE=
Array
(
    [72F18AA55B8D4EBE] => Array
        (
            [asked_KEYID] => 1411788775.1633
            [asked_TRUST] =>
            [done_KEYID] => 1411788777.1823
            [done_TRUST] =>
            [done] =>
            [nick] =>
            [trust] =>
            [chan] => #love2
            [unknown_KEYID] => 1411788777.1824
        )

    [35D2E1A0457E6498] => Array
        (
            [asked_KEYID] => 1411788777.1828
            [asked_TRUST] => 1411788777.5836
            [done_KEYID] => 1411788777.5824
            [done_TRUST] => 1411788778.3334
            [done] =>
            [nick] => RagnarDanneskjol
            [trust] => 3
            [chan] => #love2
        )

)


from DEED_BUNDLER.php:

{"address":"1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7","bundle_file":".\/DEEDS\/BUNDLE_DONE\/BUNDLE_PENDING-1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7.txt","bundle_url":"http:\/\/www.postalrocket.com\/TJ\/DEEDS\/BUNDLE_DONE\/BUNDLE_PENDING-1CRFaGmALMgK45ggLnrQXqyGyGoFXi7yc7.txt","SPENT":{"source":"mg68NmsW7gfUgAV8LSLJoiFq44sQnYUC56","destination":"mqpeMfETdcR1BWHLfVCb53JVjrSxcTQkfg","amount":0.0001,"spend":{"status":"success","data":{"network":"BTCTEST","txid":"d14b70f09c9188a374214b9e1638ce95a24a4d7f70af97dfc5313862556b34d4","amount_withdrawn":"0.00011000","amount_sent":"0.00010000","network_fee":"0.00001000","blockio_fee":"0.00000000"}},"balance":{"status":"success","data":{"network":"BTCTEST","available_balance":"0.00978000","unconfirmed_sent_balance":"-0.00011000","unconfirmed_received_balance":"0.00000000","user_id":0,"address":"mg68NmsW7gfUgAV8LSLJoiFq44sQnYUC56","label":"default"}}}}


*/











	//PRIVMSG gribble :;;eauth MolokoDesk
	//:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG MolokoDesk :Request successful for user MolokoDesk, hostmask MolokoDesk!~moloko@cpe-173-174-89-188.austin.res.rr.com. Get your encrypted OTP from http://bitcoin-otc.com/otps/C58B15FA3E19CE9B
	// PRIVMSG gribble :;;gpg everify freenode:#bitcoin-otc:1c64f7b2a973a1f86dedbf1e7d85ac2124715094f932ea7e56482baa
	//:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG MolokoDesk :Error: In order to authenticate, you must be present in one of the following channels: #bitcoin-otc;#bitcoin-otc-foyer;#bitcoin-otc-ru;#bitcoin-otc-eu;#bitcoin-otc-uk;#bitcoin-otc-bans;#bitcoin-dev;#gribble;#bitcoin-assets;#bitcoin-fr
	//JOIN #bitcoin-otc
	//PRIVMSG gribble :;;gpg everify freenode:#bitcoin-otc:1c64f7b2a973a1f86dedbf1e7d85ac2124715094f932ea7e56482baa
	//:gribble!~gribble@unaffiliated/nanotube/bot/gribble PRIVMSG MolokoDesk :You are now authenticated for user MolokoDesk with key C58B15FA3E19CE9B


/*
<MolokoDeck> ;;gettrust assbot MolokoDesk
<gribble> WARNING: Currently not authenticated. Trust relationship from user assbot to user MolokoDesk: Level 1: 0, Level 2: 0 via 0 connections. Graph: http://b-otc.com/stg?source=assbot&dest=MolokoDesk | WoT data: http://b-otc.com/vrd?nick=MolokoDesk | Rated since: Wed Sep  3 07:34:49 2014
<MolokoDeck> ;;gpg -info --key C58B15FA3E19CE9B
<gribble> User 'MolokoDesk', with keyid C58B15FA3E19CE9B, fingerprint 4D4769A7EF6BB23D399DAA7BC58B15FA3E19CE9B, and bitcoin address None, registered on Sun Aug 31 12:08:16 2014, last authed on Fri Sep  5 00:59:05 2014. http://b-otc.com/vg?nick=MolokoDesk . Currently not authenticated.
<MolokoDeck> ;;gettrust assbot MolokoDesk
<gribble> WARNING: Currently not authenticated. Trust relationship from user assbot to user MolokoDesk: Level 1: 0, Level 2: 0 via 0 connections. Graph: http://b-otc.com/stg?source=assbot&dest=MolokoDesk | WoT data: http://b-otc.com/vrd?nick=MolokoDesk | Rated since: Wed Sep  3 07:34:49 2014
<MolokoDeck> ;;gettrust assbot mircea_popescu
<gribble> Currently authenticated from hostmask mircea_popescu!~Mircea@pdpc/supporter/silver/mircea-popescu. Trust relationship from user assbot to user mircea_popescu: Level 1: 1, Level 2: 26 via 26 connections. Graph: http://b-otc.com/stg?source=assbot&dest=mircea_popescu | WoT data: http://b-otc.com/vrd?nick=mircea_popescu | Rated since: Fri Jul 22 11:04:26 2011
*/




//TEST CASES:
//http://pastebin.com/UxAmPFvL (RD,MD)
//http://pastebin.com/QzPGJbj6 (ALF,RD)
//http://pastebin.com/BrkxYBrh (TJ,RD,MD)?

	//SPLIT PASTEBIN DOCUMENT BUNDLE OF DEEDS...
	if(preg_match('/([\#\S]+)\s+:([\+\!\.])(deeds*|notary|notarize|contracts*)\s+(https*:\/\/[^\r\n\s]+)[\s\r\n]*/i',$rawdata,$px)){
		$BUNDLE_HANDLED=false;
		//print "\n\n(1)assbot_key=$assbot_key\nassbot_nick=$assbot_nick\nassbot_timeout=$assbot_timeout\n\n";
		$chan=GetChan($rawdata);
		$token=$px[2];
		$cmdword=$px[3];
		$param=$px[4];
		$paramUE=urlencode($param);
		if(preg_match('/^https*:\/\/pastebin\.com\//',$param)){
			$SPEW=preg_split('/[\r\n]+/',`php split_bundled_documents.php 'URL=$paramUE' 'TEXT=TJ'`,-1,1);
			rdisp($SPEW);
			foreach($SPEW as $sk=>$sv){
				if(!preg_match('/warning|postalrocket\.com|failed/i',$sv)){
					//send("PRIVMSG $chan :$sv");
					//print "\n\nsv=$sv\n\n";
					if(preg_match('/^\[.*\]/',$sv)){
						$QARR=json_decode($sv,true); //this is the bundle of document pointers and associated data...
						//rdisp($QARR);
						if(count($QARR)>0){
							//these may need to be indexed also by the PASTEBIN HASH $param
							$SIGSDONE=array(); //this is the non-redundant list of deed signatures to verify...
							$GRIBBLE_DATA=array(); //reset this at your peril? vulnerable to .deed pastebin flooding attack
							print "\nDEEDS PASTEBIN $param SEEN ... RESETTING SIGSDONE and GRIBBLE_DATE\n";
							send("PRIVMSG $ERROR_CHAN :DEEDS PASTEBIN $param SEEN ... RESETTING SIGSDONE and GRIBBLE_DATE");
							$ALL_SIGS_DONE=false;
							foreach($QARR as $qk=>$qv){
								$KEYID=$qv['keyID'];
								$NSIGS=intval($qv['nsigs']);
								if($NSIGS==1){$SIGZ='signatory';}elseif($NSIGS>1){$SIGZ='signatories';}else{}
								send("PRIVMSG $chan :KeyID: $KEYID deed with $NSIGS $SIGZ.");
								$SIGSDONE[$KEYID]=array('asked_KEYID'=>false,'asked_TRUST'=>false,'done_KEYID'=>false,'done_TRUST'=>false,'done'=>false,'nick'=>false,'trust'=>false,'chan'=>$chan);
							}//ENDIF DECODE JSON RESULT


							if(count($SIGSDONE)>0){
								print "\n.deeds invoked ... first call to: BUNDLE_TRUST_DONE_YET\n";
								rdisp($SIGSDONE);
								BUNDLE_TRUST_DONE_YET();
							}else{
								print "\n.deeds invoked ... NO SIGSDONE?\n";
								rdisp($SIGSDONE);
							}//ENDIF START ASKING GRIBBLE

						}else{
							send("PRIVMSG $chan :No signed deeds were found in: $param");
						}//ENDIF DEEDS PRESENT						
					}else{
						send("PRIVMSG $chan :$sv");
					}//ENDIF JSON ARRAY OR TEXT MESSAGE
				}//ENDIF NO ERROR

			}//NEXT SPEW

			//print "\n\nPASTEBIN BUNDLE CHAN =  $chan\nparam=$param\n\n";
			//rdisp($SPEW);

		}else{
			send('PRIVMSG $chan :not a pastebin link: '.$param);
		}//ENDIF PASTEBIN LINK
	}//ENDIF notarize deeds contracts















	if(preg_match('/([\#\S]+)\s+:[\+\!\.\=](wolfram|alpha)\s*(.*)/i',$rawdata,$px)){
		$chan=GetChan($rawdata);
		$param=urlencode(preg_replace('/\`/','_',$px[3]));
		$SPEW=preg_split('/[\r\n]+/',`php wolfram.php 'KW=$param' 'TEXT=TJ'`,-1,1);
		foreach($SPEW as $sk=>$sv){
			send('PRIVMSG '.$chan.' :'.$sv);
		}//NEXT SPEW
	}//ENDIF WOLFRAM

	if(preg_match('/(\#\S+)\s+:[\?\+\!\.\=]calc\s+(.+)/i',$rawdata,$px)){
		$chan=$px[1];
		//$param=urlencode($px[2]);
		$param=preg_replace('/[\r\n]+/','',$px[2]);
		$SPEW=`php gcalc.php 'KW=$param' 'TEXT=TJ'`;
		send('PRIVMSG '.$chan.' :'.$SPEW);
	}//ENDIF CALC







	if(preg_match('/(\#\S+)\s+:[\+\!\.\=\?](wallet|donate|support)/i',$rawdata,$px)){
		$chan=GetChan($rawdata);
		send('PRIVMSG '.$chan.' :'.$BOLD.'deedBot Incoming Bitcoin Address:'.$BOLD.' 1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu'."\n");
		CHECK_BLOCKIO_BTC_BALANCE($chan);
		send('PRIVMSG '.$chan.' :'.$BOLD.'Tao_Jones Incoming Bitcoin Address:'.$BOLD.' 1TaoJrnsQzfyBto2PmwPAssce9DGzsHg3'."\n");
	}//ENDIF WALLET DEEDBOT

	if(preg_match('/(\#\S+)\s+:[\+\!\.\=\?](balance)/i',$rawdata,$px)){
		$chan=GetChan($rawdata);
		CHECK_BLOCKIO_BTC_BALANCE($chan);
	}//ENDIF BALANCE


	if(preg_match('/([\#\S+])\s+:[\+\!\.\=\?]help.*/i',$rawdata,$px)){
		$chan=GetChan($rawdata);
		$param=urlencode(preg_replace('/\`/','_',$px[2]));
		send('PRIVMSG '.$chan.' :deedBot functions are: .deed pastebin_url_of_cryptosigned_contract');
		send('PRIVMSG '.$chan.' :/invite Tao_Jones   to channel');
		send('PRIVMSG '.$chan.' :use  .dump   to set the verbose dialog from the bot to the current channel');
		//send('PRIVMSG '.$chan.' :Tao_Jones Incoming Bitcoin Address: 1TaoJrnsQzfyBto2PmwPAssce9DGzsHg3'."\n");
		send('PRIVMSG '.$chan.' :'.$BOLD.'deedBot Incoming Bitcoin Address:'.$BOLD.' 1NTSD9jVvumurTotaW7Crqe5DfRxBrJzqu'."\n");
	}//ENDIF HELP


	if(strlen($rawdata)>3){print $rawdata."\n";}




}//ENDIF MAINTAIN IRC LOOP




usleep(1000);
if($ECHO_MODE){print ".";}



}//WEND END BIG LOOP



?>
