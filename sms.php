<?php
	ini_set("implicit_flush", 1);
	ini_set("zlib.output_compression", 0);

	# Headers
	header('X-Accel-Buffering: no');
	header("Cache-Control: no-store");
	header('Content-Type: text/html');

	# Limit sending to numbers from a file
	if (file_exists("allowed_numbers.txt")) {
		$trusted_phones = file("allowed_numbers.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	}

	# Path and names
	$spool_dir = "/var/spool/sms/outgoing/";
	$sent_dir = "/var/spool/sms/sent/";
	$failed_dir = "/var/spool/sms/failed/";
	$tmp_dir = "/tmp/";
	$tmp_file_prefix = "sms_web_";

	# Messages
	$error_400 = "400 Bad Request" . PHP_EOL;
	$error_500 = "500 Internal Server Error" . PHP_EOL;
	$error_504 = "504 Gateway Timeout" . PHP_EOL;
	$message_sent = "The message was successfully sent." . PHP_EOL;

	# Code page for SMS, valid UTF-8 or UCS-2BE
	$code_page = "UCS-2BE";

	#Timeout
	$timeout = 30;

	# Some HTML tags for browsers
	if (isset($_GET['from_index'])) {
		echo("<html><body><head><style>body {font-size:22px}</style></head>");
	}

	# Get params, from POST first
	if (isset($_POST['num']) && preg_match("/^(\+|\s|%2B)?[1-9]{1}[0-9]{3,14}$/", $_POST['num'])) {
		$num = $_POST['num'];
		if (isset($_POST['subj'])) { $subj = $_POST['subj']; }
		$text = $_POST['text'];
	} else if (isset($_GET['num']) && preg_match("/^(\+|\s|%2B)?[1-9]{1}[0-9]{3,14}$/", $_GET['num'])) { 
		$num = $_GET["num"];
		if (isset($_GET['subj'])) { $subj = $_GET['subj']; }
		$text = $_GET["text"];
	} else {
		http_response_code(400);
		echo($error_400);
		exit;
	}

	# Some crutches for "+" in phone number
	$num = urlencode($num);
	if (str_starts_with($num, '+')) {
		$num = str_replace("+", "%2B",$num);
		$num = urldecode($num);
	} else if (str_starts_with($num, '%2B')) {
		$num = urldecode($num);
	} else {
		$num = urldecode($num);
		$num = "+" . $num;
	}

	if (isset($trusted_phones) && !in_array($num, $trusted_phones)) {
		http_response_code(400);
		echo($error_400);
		exit;
	}

	if (isset($_GET['from_index'])) {
		echo('Sendind'); flush();
	}

	# Prepeare SMS file data
	$tmp_file = tempnam($tmp_dir, $tmp_file_prefix);
	if (strcasecmp($code_page, "UCS-2BE") == 0) {
		$sms_header = "To: $num". PHP_EOL . "Alphabet: UCS2" . PHP_EOL . "UDH: false" . PHP_EOL . PHP_EOL;
		if (isset($subj)) {
			$sms_body = "$subj" . PHP_EOL . "$text";
			$sms_body = iconv("UTF-8", "UCS-2BE", $sms_body);
		} else {
			$sms_body = iconv("UTF-8", "UCS-2BE", $text);
		}
	} else if (strcasecmp($code_page, "UTF-8") == 0) {
		$sms_header = "To: $num". PHP_EOL . "Alphabet: UTF-8" . PHP_EOL . "UDH: false" . PHP_EOL . PHP_EOL;
		if (isset($subj)) {
			$sms_body = "$subj" . PHP_EOL . "$text";
		} else {
			$sms_body = $text;
		}
	}

	# Write to file
	$file_handle = fopen($tmp_file, "w");
	fwrite($file_handle, $sms_header);
	fwrite($file_handle, $sms_body);
	fflush($file_handle);
	fclose($file_handle);
	chmod($tmp_file, 0666);

	# Move SMS file to spool folder
	rename($tmp_file, $spool_dir.basename($tmp_file));

	# Sending Result Check
	$count = 0;
	do {
		$count++;
		if (isset($_GET['from_index'])) {
			echo('.'); flush();
		}
		if (file_exists($sent_dir.basename($tmp_file))) {
			if (isset($_GET['from_index'])) {echo('<br>');}
			echo($message_sent);
			# Redirect to index.html
			#if (isset($_GET['from_index'])) {
			#	header('Location: index.html');
			#}
			exit;
		} elseif (file_exists($failed_dir.basename($tmp_file))) {
			http_response_code(500);
			if (isset($_GET['from_index'])) {echo('<br>');}
			echo($error_500);
			exit;
		}
		sleep(1);
	} while(true && $count < $timeout);

	if ($count = $timeout) {
		http_response_code(504);
		if (isset($_GET['from_index'])) {echo('<br>');}
		echo($error_504);
		exit;		
	}
?>