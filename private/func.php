<?php

function createPasswd($passwd = ''){
	if(empty($passwd)){
		$passwd = genRandStr(8);
	}
	return [$passwd, password_hash($passwd, PASSWORD_DEFAULT)];
}

function genRandStr($length = 10) {
	$str = ''; $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ~!@#$%^&*()_+-=';
	for ($i = 0; $i < $length; $i++) {
		$str .= $chars[random_int(0 ,strlen($chars)-1)];
	}
    return $str;
}

function _mail($email, $subject, $message){
	global $conf;
	$headers  = "MIME-Version: 1.0\r\n";
	$headers .= "Content-type: text/html; charset=utf-8\r\n";
	$headers .= "Content-Transfer-Encoding: base64\r\n";
	$subject  = "=?utf-8?B?".base64_encode($subject)."?=";
	$headers .= "From: {$conf['email_from']} <{$conf['email']}>\r\n";
	mail($email, $subject, rtrim(chunk_split(base64_encode($message))), $headers);
}

function _message($key, $err = 'ok'){
	global $var;
	die(json_encode(['err' => $err, 'mes' => $var['error'][$key], 'key' => $key]));
}

function _message2($mes){
	die(json_encode(['err' => 'ok', 'mes' => $mes]));
}

function half_string($s){
	return substr($s, round(strlen($s)/2));
}

function session_hash($login, $passwd, $rand = '', $time = ''){
	global $conf, $var;
	if(empty($rand)){
		$rand = genRandStr(8);
	}
	if(empty($time)){
		$time = $var['time']+86400;
	}
	return [$rand.hash($conf['hash_algo'], $rand.$var['ip'].$var['user_agent'].$time.$login.sha1(half_string($passwd))), $time];
}

function coinhive_proof(){
	global $conf;
	if(empty($_POST['coinhive-captcha-token'])){
		return false;	
	}
	$post_data = [
		'secret' => $conf['coinhive_secret'],
		'token' => $_POST['coinhive-captcha-token'],
		'hashes' => 1024
	];
	$post_context = stream_context_create([
		'http' => [
			'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			'method'  => 'POST',
			'content' => http_build_query($post_data)
		]
	]);
	$url = 'https://api.coinhive.com/token/verify';
	$response = json_decode(file_get_contents($url, false, $post_context));
	if($response && $response->success) {
		return true;
	}
	return false;
}

function _exit(){
	global $db;
	if(session_status() != PHP_SESSION_NONE){
		if(!empty($_SESSION['sess'])){
			$query = $db->prepare("DELETE FROM `session` WHERE `hash` = :hash");
			$query->bindParam(':hash', $_SESSION["sess"]);
			$query->execute();
		}
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
		session_unset();
		session_destroy();
		header("Location: https://".$_SERVER['SERVER_NAME']);	
	}
}

function login(){
	global $db, $var, $user;
	if($user){
		_message('authorized', 'error');
	}
	if(empty($_POST['login']) || empty($_POST['passwd'])){
		_message('empty', 'error');
	}
	if(strlen($_POST['login']) > 20){
		_message('long', 'error');
	}
	if(preg_match('/[^0-9A-Za-z]/', $_POST['login'])){
		_message('wrongLogin', 'error');
	}
	if(strlen($var['user_agent']) > 256){
		_message('wrongUserAgent', 'error');
	}
	$query = $db->prepare("SELECT `id`, `login`, `passwd`, `2fa` FROM `users` WHERE `login` = :login");
	$query->bindValue(':login', $_POST['login']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('Invalid user', 'error');
	}
	$row = $query->fetch();
	if(!empty($row['2fa'])){
		if(empty($_POST['fa2code'])){
			_message('empty', 'error');
		}
		if(oathHotp($row['2fa'], floor(microtime(true) / 30)) != $_POST['fa2code']){
			_message('wrong2FA', 'error');
		}
	}
	if(!password_verify($_POST['passwd'], $row['passwd'])){
		_message('wrongPasswd', 'error');
	}
	if(password_needs_rehash($row['passwd'], PASSWORD_DEFAULT)){
		$passwd = createPasswd($_POST['passwd']);
		$query = $db->prepare("UPDATE `users` SET `passwd` = :passwd WHERE `id` = :id");
		$query->bindParam(':passwd', $passwd[1]);
		$query->bindParam(':id', $row['id']);
		$query->execute();
		$row['passwd'] = $passwd[1];
	}
	$hash = session_hash($row['login'], $row['passwd']);
	$query = $db->prepare("INSERT INTO `session` (`uid`, `hash`, `time`, `ip`, `info`) VALUES (:uid, :hash, :time, INET6_ATON(:ip), :info)");
	$query->bindParam(':uid', $row['id']);
	$query->bindParam(':hash', $hash[0]);
	$query->bindParam(':time', $hash[1]);
	$query->bindParam(':ip', $var['ip']);
	$query->bindParam(':info', $var['user_agent']);
	$query->execute();
	$sid = $db->lastInsertId();
	$query = $db->prepare("SELECT `id` FROM `session` WHERE `uid` = :uid ORDER BY `time`");
	$query->bindParam(':uid', $row['id']);
	$query->execute();
	if($query->rowCount() > 10){
		$row = $query->fetch();
		$query = $db->prepare("DELETE FROM `session` WHERE `id` = :id");
		$query->bindParam(':id', $row['id']);
		$query->execute();
	}
	$_SESSION['sess'] = $hash[0];
	$query = $db->prepare("UPDATE `users` SET `last_activity` = :time WHERE `id` = :id");
	$query->bindParam(':time', $var['time']);
	$query->bindParam(':id', $row['id']);
	$query->execute();
	$query = $db->prepare("INSERT INTO `log_ip` (`uid`, `sid`, `ip`, `time`, `info`) VALUES (:uid, :sid, INET6_ATON(:ip), :time, :info)");
	$query->bindParam(':uid', $row['id']);
	$query->bindParam(':sid', $sid);
	$query->bindParam(':ip', $var['ip']);
	$query->bindParam(':time', $var['time']);
	$query->bindParam(':info', $var['user_agent']);
	$query->execute();
	_message('success');
}

function password_link(){
	global $conf, $db, $var;
	if(empty($_GET['id']) || empty($_GET['time']) || empty($_GET['hash'])){
		_message('Empty get value', 'error');
	}
	if(!ctype_digit($_GET['id']) || !ctype_digit($_GET['time'])){
		_message('Wrong id or time', 'error');	
	}
	$query = $db->prepare("SELECT `id`, `mail`, `passwd` FROM `users` WHERE `id` = :id");
	$query->bindParam(':id', $_GET['id']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('No such user', 'error');
	}
	$row = $query->fetch();
	$hash = hash($conf['hash_algo'], $var['ip'].$_GET['id'].$_GET['time'].sha1(half_string($row['passwd'])));
	if($_GET['hash'] != $hash){
		_message('Wrong hash', 'error');
	}
	if($var['time'] > $_GET['time']){
		_message('Invalid link', 'error');
	}
	$passwd = createPasswd();
	$query = $db->prepare("UPDATE `users` SET `passwd` = :passwd WHERE `id` = :id");
	$query->bindValue(':id', $row['id']);
	$query->bindParam(':passwd', $passwd[1]);
	$query->execute();
	_mail($row['mail'], "Новый пароль", "Ваш пароль: $passwd[0]");
	_message('Success');
}

function testRecaptcha(){
	$v = 3;
	if(!empty($_POST['recaptcha']) && $_POST['recaptcha'] == 2){
		$v = $_POST['recaptcha'];
	}
	$result = recaptcha($v);
	if(!$result['success']){
		_message('reCaptcha test failed', 'error');
	}
	if($v == 3 && $result['score'] < 0.5){
		_message('reCaptcha test failed: score too low', 'error');
	}
}

function testCoinhive(){
	if(!coinhive_proof()){
		_message('Coinhive captcha error', 'error');
	}
}

function password_recovery(){
	global $conf, $db, $var;
	testRecaptcha();
	if(empty($_POST['mail'])){
		_message('empty', 'error');
	}
	if(strlen($_POST['mail']) > 254){
		_message('long', 'error');
	}
	if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)){
		_message('wrongEmail', 'error');
	}
	$query = $db->prepare("SELECT `id`, `mail`, `passwd` FROM `users` WHERE `mail` = :mail");
	$query->bindParam(':mail', $_POST['mail']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('noUser', 'error');
	}
	$row = $query->fetch();
	$time = $var['time']+43200;
	$hash = hash($conf['hash_algo'], $var['ip'].$row['id'].$time.sha1(half_string($row['passwd'])));
	$link = "https://" . $_SERVER['SERVER_NAME'] . "/public/password_link.php?id={$row['id']}&time={$time}&hash={$hash}";
	_mail($row['mail'], "Восстановление пароля", "Запрос отправили с IP {$var['ip']}<br/>Чтобы восстановить пароль <a href='$link'>перейдите по ссылке</a>.");
	_message('checkEmail');
}

function registration(){
	global $db, $user;
	if($user){
		_message('registered', 'error');
	}
	testRecaptcha();
	if(empty($_POST['login']) || empty($_POST['mail'])){
		_message('empty', 'error');
	}
	if(strlen($_POST['login']) > 20 || strlen($_POST['mail']) > 254){
		_message('long', 'error');
	}
	if(preg_match('/[^0-9A-Za-z]/', $_POST['login'])){
		_message('wrongLogin', 'error');
	}
	if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)){
		_message('wrongEmail', 'error');
	}
	$_POST['mail'] = mb_strtolower($_POST['mail']);
	$query = $db->prepare("SELECT `id` FROM `users` WHERE `login` = :login");
	$query->bindValue(':login', $_POST['login']);
	$query->execute();
	if($query->rowCount() > 0){
		_message('registered', 'error');
	}
	$query = $db->prepare("SELECT `id` FROM `users` WHERE `mail`= :mail");
	$query->bindParam(':mail', $_POST['mail']);
	$query->execute();
	if($query->rowCount() > 0){
		_message('registered', 'error');
	}
	$passwd = createPasswd();
	$query = $db->prepare("INSERT INTO `users` (`login`, `mail`, `passwd`, `register_date`) VALUES (:login, :mail, :passwd, unix_timestamp(now()))");
	$query->bindValue(':login', $_POST['login']);
	$query->bindParam(':mail', $_POST['mail']);
	$query->bindParam(':passwd', $passwd[1]);
	$query->execute();
	_mail($_POST['mail'], "Регистрация", "Вы успешно зарегистрировались на сайте!<br/>Ваш пароль: $passwd[0]");
	_message('success');
}

function auth(){
	global $conf, $db, $var, $user;
	if(!empty($_SESSION['sess'])){
		$query = $db->prepare("SELECT `id`, `uid`, `hash`, `time` FROM `session` WHERE `hash` = :hash AND `time` > unix_timestamp(now())");
		$query->bindParam(':hash', $_SESSION['sess']);
		$query->execute();
		if($query->rowCount() != 1){
			_exit();
		}
		$session = $query->fetch();
		$query = $db->prepare("SELECT * FROM `users` WHERE `id` = :id");
		$query->bindParam(':id', $session['uid']);
		$query->execute();
		if($query->rowCount() != 1){
			_exit();
		}
		$row = $query->fetch();
		if($_SESSION['sess'] != session_hash($row['login'], $row['passwd'], substr($session['hash'], 0, 8), $session['time'])[0]){
			_exit();
		}
		if($var['time'] > $session['time']){			
			$hash = session_hash($row['login'], $row['passwd']);
			$query = $db->prepare('UPDATE `session` set `hash` = :hash, `time` = :time WHERE `id` = :id');
			$query->bindParam(':hash', $hash[0]);
			$query->bindParam(':time', $hash[1]);
			$query->bindParam(':id', $session['id']);
			$query->execute();
			$_SESSION['sess'] = $hash[0];
		}
		$user = [	'id' => $row['id'], 
					'login' => $row['login'], 
					'nickname' => $row['nickname'],
					'avatar' => $row['avatar'],
					'passwd' => $row['passwd'], 
					'mail' => $row['mail'], 
					'2fa' => $row['2fa'],
					'access' => $row['access'],
					'register_date' => $row['register_date'],
					'last_activity' => $row['last_activity'],
					'dir' => substr(md5($row['id']), 0, 2),
				];
		if(!empty($row['user_values'])){			
			$user['user_values'] = json_decode($row['user_values'], true);
		}
		$query = $db->prepare("SELECT `downloaded`, `uploaded` FROM `xbt_users` WHERE `uid` = :id");
		$query->bindParam(':id', $user['id']);
		$query->execute();
		if($query->rowCount() == 1){
			$row = $query->fetch();
			$user['downloaded'] = $row['downloaded'];
			$user['uploaded'] = $row['uploaded'];
			if(empty($user['uploaded'])) $user['uploaded'] = 1;
			if(empty($user['downloaded'])) $user['downloaded'] = 1;
			// upload/ download/ 1024, limit 100
			// if uploaded > 5 TB and rating > 1 hide advertising
			$user['rating'] = round($user['uploaded']/$user['downloaded']/1024, 2);
			if($user['rating'] > 100) $user['rating'] = 100;
		}
	}
}

function base32_map($i, $do = 'encode'){
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	if( $do == 'encode'){
		return $chars[$i];
	}else{
		return array_search($i, str_split($chars));
	}
}

function base32_bits($v){
	$value = ord($v);
	return vsprintf(str_repeat('%08b', count($value)), $value);
}

function base32_encode($data){
	$result = ''; $s = 0;
	$j = [4 => 1, 3 => 3, 2 => 4, 1 => 6];
	$arr = explode('|', substr(chunk_split($data, 5, '|'), 0, -1));
	foreach($arr as $val){
		$s++;
		$arr2 = str_split($val);
		$x = ['00000000', '00000000', '00000000', '00000000', '00000000'];
		foreach($arr2 as $key => $val2){
			$x[$key] = base32_bits($val2);	
		}
		$arr3 = explode('|', substr(chunk_split(implode('', $x), 5, '|'), 0, -1));
		foreach($arr3 as $key => $val3){	
			$result .= base32_map(bindec($val3));
		}
		if($s == count($arr) && isset($j[strlen($val)])){
			$result = str_pad(substr($result, 0, -$j[strlen($val)]), 8*$s, '=', STR_PAD_RIGHT);
		}
	}
	return $result;
}

function base32_decode($data){ // thx Sanasol
	$x = '';
	$arr = str_split($data);
	foreach($arr as $val){
		$x .= str_pad(decbin(base32_map($val, 'decode')), 5, '0', STR_PAD_LEFT);
	}
	$chunks = str_split($x, 8);
	$string = array_map(function($chr){
		return chr(bindec($chr));
	}, $chunks);
	return implode("", $string);
}

function generate_secret(){
	return base32_encode(genRandStr());
}

function oathTruncate($hash){
	$offset = ord($hash[19]) & 0xf;
	$temp = unpack('N', substr($hash, $offset, 4));
	return substr($temp[1] & 0x7fffffff, -6);
}

function oathHotp($secret, $time){
	$secret = base32_decode($secret);
	$time = pack('N*', 0, $time);
	$hash = hash_hmac('sha1', $time, $secret, true);
	return str_pad(oathTruncate($hash), 6, '0', STR_PAD_LEFT);
}

function getQRCodeGoogleUrl($name, $secret){
	$urlencoded = urlencode('otpauth://totp/'.$name.'?secret='.$secret.'');
	return 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl='.$urlencoded.'';
}

function auth2FA(){
	global $db, $user;
	if(!$user){
		_message('unauthorized', 'error');
	}
	if(empty($_POST['do'])){
		_message('empty', 'error');
	}
	switch($_POST['do']){
		default: return 'empty'; break;
		case 'gen':
			if(!empty($user['2fa'])){
				_message('2FA', 'error');				
			}
			$base32_key = generate_secret();
			_message2("<img src=".getQRCodeGoogleUrl($user['login']."@anilibria.tv", $base32_key)."><br>Secret key: $base32_key<br/>Сохраните секретный ключ в надежном месте.<input type=\"hidden\" id=\"2fa\" value=\"$base32_key\">");
		break;
		case 'save':
			if(empty($_POST['passwd']) || empty($_POST['code'])){
				_message('empty', 'error');
			}
			if(empty($user['2fa'])){
				if(empty($_POST['2fa'])){
					_message('empty', 'error');
				}
				$check = $_POST['2fa'];
			}else{
				$check = $user['2fa'];
			}
			if(strlen($check) != 16 || !ctype_alnum($check) || ctype_lower($check)){
				_message('wrong2FA', 'error');
			}
			if(oathHotp($check, floor(microtime(true) / 30)) != $_POST['code']){
				_message('wrong2FA', 'error');
			}
			if(!password_verify($_POST['passwd'], $user['passwd'])){
				_message('wrongPasswd', 'error');
			}
			if(!empty($user['2fa'])){
				$query = $db->prepare("UPDATE `users` SET `2fa` = :code WHERE `id` = :uid");
				$query->bindValue(':code', null, PDO::PARAM_INT);
				$query->bindParam(':uid', $user['id']);
				$query->execute();
				_message('2FAdisabled');
			}else{
				$query = $db->prepare("UPDATE `users` SET `2fa` = :code WHERE `id` = :uid");
				$query->bindParam(':code', $_POST['2fa']);
				$query->bindParam(':uid', $user['id']);
				$query->execute();
				_message('2FAenabled');
			}
		break;
	}
}

function recaptcha($v = 3){
	global $conf, $var;
	if(empty($_POST['g-recaptcha-response'])){
		_message('Empty post recaptcha', 'error');
	}
	$secret = 'recaptcha_secret';
	if($v != 3){
		$secret = 'recaptcha2_secret';
	}
	$data = ['secret' => $conf[$secret], 'response' => $_POST['g-recaptcha-response'], 'remoteip' => $var['ip']];
	$verify = curl_init();
	curl_setopt($verify, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
	curl_setopt($verify, CURLOPT_POST, true);
	curl_setopt($verify, CURLOPT_POSTFIELDS, $data);
	curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
	$result = json_decode(curl_exec($verify), true);
	curl_close($verify);
	return $result;
}

function xSpiderBot($name){
	$arr = ['Google' => '/\.googlebot\.com$/i', 'Yandex' => '/\.spider\.yandex\.com$/i'];
	if(strpos($_SERVER['HTTP_USER_AGENT'], $name) !== false){
		return preg_match($arr["$name"], gethostbyaddr($_SERVER['REMOTE_ADDR']));
	}
	return false;
}

function secret_cookie(){
	global $conf, $var;
	$rand = genRandStr(8);
	$hash = hash($conf['hash_algo'], $var['ip'].$rand.$conf['sign_secret']);
	setcookie("ani_test", $hash.$_SERVER['REMOTE_ADDR'].$rand, $var['time'] + 86400, '/');
}

function simple_http_filter(){
	global $conf, $var;
	$flag = false;
	if(!empty($_COOKIE['ani_test'])){
		$string = $_COOKIE['ani_test'];
		$hash = substr($string, 0, $conf['hash_len']);
		$rand = substr($string, $conf['hash_len']+strlen($var['ip']));
		$test = hash($conf['hash_algo'], $var['ip'].$rand.$conf['sign_secret']);
		if($hash == $test){ 
			$flag = true;
		}
	}
	$list = ['RU', 'UA', 'BY', 'LV', 'EE', 'LT', 'TM', 'KG', 'KZ', 'MD', 'UZ', 'AZ', 'AM', 'GE'];
	if(!in_array(geoip_country_code_by_name($_SERVER['REMOTE_ADDR']), $list) && !$flag){
		if(xSpiderBot('Google') == false || xSpiderBot('Yandex') == false){			
			$tmpFilter = str_replace("{coinhive}", $conf['coinhive_public'], getTemplate('filter'));
			$tmpFilter = str_replace("{recaptcha}", $conf['recaptcha_public'], $tmpFilter);
			echo $tmpFilter;
			die;
		}
	}
}

function torrentHashExist($hash){
	global $db;
	$query = $db->prepare("SELECT `fid` FROM xbt_files WHERE `info_hash` = :hash");
	$query->bindParam(':hash', $hash);
	$query->execute();
	if($query->rowCount() == 0){
		return false;
	}
	return true;
}

function torrentExist($id){
	global $db;
	$query = $db->prepare("SELECT `fid`, `completed`, `info` FROM `xbt_files` WHERE `fid`= :id");
	$query->bindParam(':id', $id);
	$query->execute();
	if($query->rowCount() == 0){
		return false;
	}
	return $query->fetch();
}

function torrentAdd($hash, $rid, $json, $completed = 0){
	global $db;
	$query = $db->prepare("INSERT INTO `xbt_files` (`info_hash`, `mtime`, `ctime`, `flags`, `completed`, `rid`, `info`) VALUES( :hash , UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, :completed, :rid, :info)");
	$query->bindParam(':hash', $hash);
	$query->bindParam(':rid', $rid);
	$query->bindParam(':completed', $completed);
	$query->bindParam(':info', $json);
	$query->execute();
	return $db->lastInsertId();	
}

// https://github.com/shakahl/xbt/wiki/XBT-Tracker-(XBTT)
// flags - This field is used to communicate with the tracker. Usable values: 0 - No changes. 1 - Torrent should be deleted. 2 - Torrent was updated.
// flag 1 work		https://github.com/OlafvdSpek/xbt/blob/master/Tracker/server.cpp#L183-L187
// source code		https://img.poiuty.com/img/6e/f01f40eaa783018fe12e5649315b716e.png
// flag 2 not work	https://img.poiuty.com/img/7c/a5479067a6e3a272d66bb92c0416797c.png
// Also I dont find it in source.
function torrentDelete($id){
	global $db;
	$query = $db->prepare("UPDATE `xbt_files` SET `flags` = 1 WHERE `fid` = :id");
	$query->bindParam(':id', $id);
	$query->execute();
	deleteFile($_SERVER['DOCUMENT_ROOT'].'/upload/torrents/'.$id.'.torrent');
}

function isJson($string) {
	json_decode($string);
	return (json_last_error() == JSON_ERROR_NONE);
}

function torrent(){
	global $conf, $db, $user, $var;
	function checkTD($key, $val){
		if(empty($val)){
			return false;
		}
		switch($key){
			case 'rid':		if(!ctype_digit($val))	return false;	break;
			case 'fid':		if(!ctype_digit($val))	return false;	break;
			case 'ctime':	if(!strtotime($val))	return false;	break;
			case 'quality': if(strlen($val) > 20)	return false;	break;
			case 'series':	if(strlen($val) > 10)	return false;	break;
		}
		return true;
	}
	if(empty($_POST['data']) || !isJson($_POST['data'])){
		_message('empty');
	}
	if(!$user || $user['access'] < 2){
		_message('access', 'error');
	}
	$data = json_decode($_POST['data'], true);
	foreach($data as $key => $val){
		if(!checkTD('rid', $val['rid']) || !checkTD('quality', $val['quality']) || !checkTD('series', $val['series'])){
			continue;
		}
		if(checkTD('ctime', $val['ctime'])){
			$ctime = strtotime($val['ctime']);
		}else{
			$ctime = time();
		}
		$ctime = htmlspecialchars($ctime, ENT_QUOTES, 'UTF-8');
		$val['quality'] = htmlspecialchars($val['quality'], ENT_QUOTES, 'UTF-8');
		$val['series'] = htmlspecialchars($val['series'], ENT_QUOTES, 'UTF-8');
		switch($val['do']){
			case 'change':
				if(!checkTD('fid', $val['fid'])){
					continue;
				}
				$old = torrentExist($val['fid']);
				if(!$old){
					continue;
				}
				$tmp = json_decode($old['info'], true);
				$tmp = json_encode([$val['quality'], $val['series'], $tmp['2']]);
				$query = $db->prepare("UPDATE `xbt_files` SET `ctime` = :ctime, `info` = :info WHERE `fid` = :fid");
				$query->bindParam(':ctime', $ctime);
				$query->bindParam(':info', $tmp);
				$query->bindParam(':fid', $val['fid']);
				$query->execute();
				if(!empty($val['delete'])){
					torrentDelete($val['fid']);
				}
			break;
			case 'add':
				if(empty($_FILES['torrent'])){
					_message('noUploadFile', 'error');
				}
				if($_FILES['torrent']['error'] != 0){
					_message('uploadError', 'error');
				}
				if($_FILES['torrent']['type'] != 'application/x-bittorrent'){
					_message('wrongData', 'error');	
				}
				$torrent = new Torrent($_FILES['torrent']['tmp_name']);
				if(empty($torrent->hash_info())){
					_message('wrongData', 'error');
				}
				$pack_hash = pack('H*', $torrent->hash_info());
				if(torrentHashExist($pack_hash)){
					_message('exitTorrent', 'error');
				}
				$old = false;
				$size = $torrent->size();
				$json = json_encode([$val['quality'], $val['series'], $size]);
				if(!empty($val['fid'])){
					$old = torrentExist($val['fid']);
				}
				if($old){
					torrentDelete($val['fid']);
					$name = torrentAdd($pack_hash, $val['rid'], $json, $old['completed']);	
				}else{
					$name = torrentAdd($pack_hash, $val['rid'], $json);
				}
				$torrent->announce(false);
				$torrent->announce($conf['torrent_announce']);
				$torrent->save($_SERVER['DOCUMENT_ROOT'].'/upload/torrents/'.$name.'.torrent');
				die(json_encode(['err' => 'ok', 'mes' => $var['error']['success'], 'id' => $name, 'size' => formatBytes($size), 'date' => date('d.m.Y', time())]));
			break;
		}
	}
	_message('success');
}

function downloadTorrent(){
	global $db, $user, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_GET['id'])){
		_message('Empty $_GET', 'error');
	}
	if(!ctype_digit($_GET['id'])){
		_message('Wrong id', 'error');
	}
	$query = $db->prepare("SELECT `info_hash` FROM `xbt_files` WHERE `fid` = :id");
	$query->bindParam(':id', $_GET['id']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('Wrong id', 'error');
	}
	$info_hash = $query->fetch()['info_hash'];

	$query = $db->prepare("SELECT `uid` FROM `xbt_users` WHERE `torrent_pass_version` = :id");
	$query->bindParam(':id', $user['id']);
	$query->execute();
	if($query->rowCount() == 0){
		$query = $db->prepare("INSERT INTO `xbt_users` (`torrent_pass_version`) VALUES (:id)");
		$query->bindParam(':id', $user['id']);
		$query->execute();
		$uid = $db->lastInsertId();
	}else{
		$uid = $query->fetch()['uid'];
	}
	$key = sprintf('%08x%s', $uid, substr(sha1("{$conf['torrent_secret']} {$user['id']} $uid $info_hash"), 0, 24));
	$torrent = new Torrent($_SERVER['DOCUMENT_ROOT']."/upload/torrents/{$_GET['id']}.torrent");
	$torrent->announce(false);
	$torrent->announce(str_replace('/announce', "/$key/announce", $conf['torrent_announce']));
	$torrent->send();
}

function upload_avatar() {
	global $db, $user;
	if(!$user){
		_message('unauthorized', 'error');
	}
	
	if(empty($_FILES['avatar'])){
		_message('noUploadFile', 'error');
	}
	
	if($_FILES['avatar']['error'] != 0){
		_message('uploadError', 'error');
	}
	
	if(!in_array(exif_imagetype($_FILES['avatar']['tmp_name']), [IMAGETYPE_PNG, IMAGETYPE_JPEG])){
		_message('wrongType', 'error');	
	}
	if($_FILES['avatar']['size'] > 150000){
		_message('maxSize', 'error');
	}
	
	$img = new Imagick($_FILES['avatar']['tmp_name']);
	$img->setImageFormat('jpg');
	
	$crop = true;
	foreach($_POST as $k => $v){
		if(!in_array($k, ['w', 'h', 'x1', 'y1']))
			$crop = false;
		
		if(empty($v) && $v != 0)
			$crop = false;

		if(!ctype_digit($v))
			$crop = false;

		if($crop == false)
			break;
	}
	
	if($crop) $img->cropImage($_POST['w'], $_POST['h'], $_POST['x1'], $_POST['y1']);
	$img->resizeImage(160,160,Imagick::FILTER_LANCZOS, 1, false);
	$img->setImageCompression(Imagick::COMPRESSION_JPEG);
	$img->setImageCompressionQuality(90);
	$img->stripImage();
	
	$name = hash('crc32', $img);
	$tmp = $dir = '/upload/avatars/'.$user['dir'];
	$dir = $_SERVER['DOCUMENT_ROOT'].$dir;
	$file = "$dir/$name.jpg";
	if(!file_exists($dir)) {
		mkdir($dir, 0755, true);
	}
	file_put_contents($file, $img);
	if(!empty($user['avatar']) && $user['avatar'] != $name){
		deleteFile("$dir/{$user['avatar']}.jpg");
	}
	$query = $db->prepare("UPDATE `users` SET `avatar` = :avatar WHERE `id` = :id");
	$query->bindParam(':avatar', $name);
	$query->bindParam(':id', $user['id']);
	$query->execute();
	_message2("$tmp/$name.jpg");
}

function getUserAvatar($id = ''){
	global $user;
	if(empty($id) && !empty($user['id'])){
		$id = $user['id'];
	}
	if(empty($id) || !ctype_digit($id)){
		return ['err' => true, 'mes' => 'Wrong ID'];
	}
	$img = "https://".$_SERVER['SERVER_NAME']."/upload/avatars/noavatar.png";
	$dir = substr(md5($id), 0, 2);
	$path = "/upload/avatars/$dir/$id.jpg";
	if(file_exists($_SERVER['DOCUMENT_ROOT'].$path)){
		$img = "https://".$_SERVER['SERVER_NAME'].$path;
	}
	return $img;
}

function userInfo($id){
	global $db, $user, $var; $result = [];
	if(empty($id) || !ctype_digit($id)){
		return ['err' => true, 'mes' => 'Wrong ID'];
	}
	if(!empty($user['id']) && $user['id'] == $id){
		$result = [
			'id' => $user['id'],
			'mail' => $user['mail'],
			'login' => $user['login'],
			'nickname' => $user['nickname'] ?? $user['login'],
			'access' => $user['access'],
			'register_date' => $user['register_date'],
			'last_activity' => $user['last_activity'],
			'user_values' => @$user['user_values']
		];
	}
	if(empty($result)){
		$query = $db->prepare("SELECT `id`, `login`, `nickname`, `access`, `register_date`, `last_activity`, `user_values` FROM `users` WHERE `id` = :id");
		$query->bindValue(':id', $id);
		$query->execute();
		if($query->rowCount() == 0){
			return ['err' => true, 'mes' => 'К сожалению, такого пользователя не существует.'];
		}
		$row = $query->fetch();
		$result = [
			'id' => $row['id'],
			'nickname' => $row['nickname'] ?? $row['login'],
			'access' => $row['access'],
			'register_date' => $row['register_date'],
			'last_activity' => $row['last_activity'],
			'user_values' => $row['user_values']
		];
		if(!empty($result['user_values'])){
			$result['user_values'] = json_decode($result['user_values'], true);
		}
	}
	return ['err' => false, 'mes' => $result];
}

function userInfoShow($id){
	global $var;
	if(!empty($id) && ctype_digit($id)){
		$profile = userInfo($id);
	}else{
		$profile = ['err' => true, 'mes' => 'К сожалению, такого пользователя не существует.'];
	}
	if($profile['err']) {	
		return str_replace('{error}', $profile['mes'],  getTemplate('error'));
	}else{
		$a = $b = '';
		foreach($profile['mes'] as $key => $val){
			if($key == 'user_values'){
				if(empty($val)){
					continue;
				}
				foreach($val as $k => $v){
					if($k == 'sex'){
						$v = $var['sex'][$v];
					}
					if($k == 'age'){
						$v = floor(($var['time'] - $v) / 31556926);
					}
					$a .= "<b>{$var['user_values'][$k]}</b><span>&nbsp;$v</span><br/>";
				}
				continue;
			}
			if($key == 'register_date' || $key == 'last_activity'){
				$val = date('Y-m-d', $val);
			}
			if($key == 'access'){
				$val = $var['group'][$val];
			}
			$a .= "<b>{$var['user_values'][$key]}:</b><span>&nbsp;$val</span><br/>";
		}
		$b = "<img class=\"rounded\" id=\"avatar\" src=\"".getUserAvatar($id)."\" alt=\"avatar\">";
		$a = str_replace('{userinfo}', $a,  getTemplate('user_info'));
		$b = str_replace('{avatar}', $b,  getTemplate('user_avatar'));
		return $a.$b;
	}
}

function getTemplate($template){
	$file = $_SERVER['DOCUMENT_ROOT']."/private/template/$template.html";
	if(!file_exists($file)){
		return ['err' => true, 'mes' => 'Template not exists'];
	}
	return file_get_contents($file);
}

// {"name":"","age":"","sex":"","vk":"","telegram":"","steam":"","phone":"","skype":"","facebook":"","instagram":"","youtube":"","twitch":"","twitter":""}
// sex	int 0, 1, 2
// age	strtotime
function saveUserValues(){
	global $db, $user, $var; $arr = [];
	if(!$user){
		_message('authorized', 'error');
	}
    if(empty($_POST)){
		_message('empty', 'error');	
	}
	if(count($_POST) > 20){		
		_message('maxarg', 'error');
	}
	if(!empty($_POST['reset'])){
		$query = $db->prepare("UPDATE `users` SET `user_values` = :user_values WHERE `id` = :id");
		$query->bindParam(':user_values', $var['default_user_values']);
		$query->bindParam(':id', $user['id']);
		$query->execute();
		_message2('Data saved');
	}
    foreach($_POST as $key => $val){		
		if(empty($val) || !array_key_exists($key, $var['user_values'])){
			continue;
		}
		if(!preg_match('/^[А-Яа-яA-Za-z0-9_.-]+$/u', $val)){
			_message('wrongData', 'error');
		}
		if(mb_strlen($val) > 30){
			_message('long', 'error');
		}
		$arr[$key] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
	}
	if(!empty($arr['sex']) && (!ctype_digit($arr['sex']) || ($arr['sex'] < 0 || $arr['sex'] > 2))){
		_message('wrongData', 'error');
	}
    if(!empty($arr['age'])){
		$time = strtotime($arr['age']);
		if(!$time || $time > $var['time'] || date('Y', $time) < date('Y', $var['time'])-80){
			_message('wrongData', 'error');
		}
		$arr['age'] = $time;
	}
    foreach($user['user_values'] as $k => $v){
		if(!empty($arr[$k])){
			$user['user_values'][$k] = $arr[$k];
		}
	}
    $json = json_encode($user['user_values']);
    if(strlen($json) > 1024){
		_message('long', 'error');
	}
	$query = $db->prepare("UPDATE `users` SET `user_values` = :user_values WHERE `id` = :id");
	$query->bindParam(':user_values', $json);
	$query->bindParam(':id', $user['id']);
	$query->execute();
	_message2('Data saved');
}

function cryptAES($text, $key, $do = 'encrypt'){
	$key = hash('sha256', $key, true);
	$iv_size = openssl_cipher_iv_length($cipher = 'AES-256-CBC');
	$iv = random_bytes($iv_size);
	if($do == 'encrypt'){
		$ciphertext_raw = openssl_encrypt($text, $cipher, $key, OPENSSL_RAW_DATA, $iv);
		$hmac = hash_hmac('sha512', $ciphertext_raw, $key, true);		
		$ciphertext = base64_encode($iv.$hmac.$ciphertext_raw);
		return $ciphertext;
	}else{
		$c = base64_decode($text);
		$iv_dec = substr($c, 0, $iv_size);
		$hmac = substr($c, $iv_size, $sha2len=64);
		$ciphertext_raw = substr($c, $iv_size+$sha2len);
		$original = openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv_dec);
		$calcmac = hash_hmac('sha512', $ciphertext_raw, $key, true);
		if(hash_equals($hmac, $calcmac)){
			return $original;
		}
	}
}

function change_mail(){
	global $db, $user, $var, $conf;
	if(!$user){
		_message('unauthorized', 'error');
	}
	if(empty($_POST['mail']) || empty($_POST['passwd'])){
		_message('empty', 'error');	
	}
	if(!password_verify($_POST['passwd'], $user['passwd'])){
		_message('wrongPasswd', 'error');
	}
	if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)){
		_message('wrongEmail', 'error');
	}
	if($_POST['mail'] == $user['mail']){
		_message('same', 'error');
	}
    $_POST['mail'] = mb_strtolower($_POST['mail']);
    $query = $db->prepare("SELECT `id` FROM `users` WHERE `mail` = :mail");
    $query->bindParam(':mail', $_POST['mail']);
	$query->execute();
	if($query->rowCount() > 0){
		_message('used', 'error');
	}
    $time = $var['time'] + 43200;
    $hash = hash($conf['hash_algo'], $var['ip'] . $user['id'] . $user['mail'] . $_POST['mail'] . $time . sha1(half_string($user['passwd'])));
    $link = "https://" . $_SERVER['SERVER_NAME'] . "/public/mail_link.php?time=$time&mail=" . urlencode($_POST['mail']) . "&hash=$hash";
    _mail($user['mail'], "Изменение email", "Запрос отправили с IP {$var['ip']}<br/>Если вы хотите изменить email на {$_POST['mail']} - <a href='$link'>перейдите по ссылке</a>.");
    _message('checkEmail');
}

function mail_link(){
	global $db, $user, $var, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_GET['time']) || empty($_GET['mail']) || empty($_GET['hash'])){
		_message('Empty $_GET', 'error');
	}
	if($var['time'] > $_GET['time']){
		_message('Too late $_GET', 'error');	
	}
	$_GET['mail'] = urldecode($_GET['mail']);
	if(!filter_var($_GET['mail'], FILTER_VALIDATE_EMAIL)){
		_message('Wrong email', 'error');
	}
	$hash = hash($conf['hash_algo'], $var['ip'].$user['id'].$user['mail'].$_GET['mail'].$_GET['time'].sha1(half_string($user['passwd'])));
	if($hash != $_GET['hash']){
		_message('Wrong hash', 'error');
	}
	$query = $db->prepare("SELECT `id` FROM `users` WHERE `mail` = :mail");
	$query->bindParam(':mail', $_GET['mail']);
	$query->execute();
	if($query->rowCount() > 0){
		_message('Email already use', 'error');
	}
	$query = $db->prepare("UPDATE `users` SET `mail` = :mail WHERE `id` = :id");
	$query->bindParam(':mail', $_GET['mail']);
	$query->bindParam(':id', $user['id']);
	$query->execute();
	_message('Success');
}

function change_passwd(){
	global $db, $user, $var, $conf;
	if(!$user){
		_message('unauthorized', 'error');
	}
	if(empty($_POST['passwd'])){
		_message('empty', 'error');
	}
	if(!password_verify($_POST['passwd'], $user['passwd'])){
		_message('wrongPasswd', 'error');
	}
	$passwd = createPasswd();
	$query = $db->prepare("UPDATE `users` SET `passwd` = :passwd WHERE `id` = :id");
	$query->bindParam(':passwd', $passwd[1]);
	$query->bindParam(':id', $user['id']);
	$query->execute();
	_mail($user['mail'], "Изменение пароля", "Запрос отправили с IP {$var['ip']}<br/>Ваш новый пароль: {$passwd[0]}");
	_message('checkEmail');
}

function pageStat(){
	global $conf;
	return "Page generated in ".round((microtime(true) - $conf['start']), 4)." seconds. Peak memory usage: ".round(memory_get_peak_usage()/1048576, 2)." MB";
}

function close_sess(){
	global $db, $user, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_POST['id']) || !ctype_digit($_POST['id'])){
		_message('Wrong sess id', 'error');
	}
	$query = $db->prepare("DELETE FROM `session` WHERE `id` = :id AND `uid` = :uid");
	$query->bindParam(':id', $_POST['id']);
	$query->bindParam(':uid', $user['id']);
	$query->execute();
	if($query->rowCount() != 1){
		_message('Cant close session', 'error');
	}
	_message2('Success');
}

function formatBytes($size, $precision = 2){
    $base = log($size, 1024);
    $suffixes = ['', 'KB', 'MB', 'GB', 'TB', 'PB'];
    return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
}

function showRelease(){
	global $db, $user, $var;
	$status = ['0' => 'В работе', '1' => 'Завершен'];
	$query = $db->prepare("SELECT * FROM `xrelease` WHERE `id` = :id");
	$query->bindParam(':id', $_GET['id']);
	$query->execute();
	if($query->rowCount() != 1){
		header('HTTP/1.0 404 Not Found');
		return str_replace('{error}', '<center><img src="/img/404.png"></center>',  getTemplate('error'));
	}
	$release = $query->fetch();
	
	if(mb_strlen($release['name'].$release['ename']) > 60){
		$name = "{$release['name']}<br/>{$release['ename']}";
	}else{
		$name = "{$release['name']} / {$release['ename']}";
	}
	
	$moon = str_replace('{moon}', $release['moonplayer'], getTemplate('moon'));
	$page = str_replace('{name}', $release['name'], getTemplate('release'));
	$page = str_replace('{ename}', $release['ename'], $page);
	$page = str_replace('{fullname}', $name, $page);
	
	$xtmp =  explode(',', $release['genre']);
	$str = '';
	foreach($xtmp as $key => $val){
		$val = trim($val);
		$str .= "\"$val\",";
	}
	$str = rtrim($str, ',');
	$page = str_replace('{chosen-genre}', $str, $page);
	$page = str_replace('{genre}', $release['genre'], $page);
	$page = str_replace('{chosen}', getGenreList(), $page);
	$page = str_replace('{voice}', $release['voice'], $page);
	$page = str_replace('{year}', "{$release['year']}", $page);
	$page = str_replace('{type}', $release['type'], $page);
	$page = str_replace('{other}', $release['other'], $page);
	$page = str_replace('{description}', $release['description'], $page);
	
	if($release['status'] == '2'){
		$page = str_replace('{announce}', 'Релиз завершен', $page);
	}elseif(!empty($release['announce'])){
		$page = str_replace('{announce}', $release['announce'], $page);
	}else{
		$a = $var['day']['1'];
		if(array_key_exists($release['day'], $var['day'])){
			$a = $var['day'][$release['day']];
		}
		$page = str_replace('{announce}', 'Новая серия каждый '.mb_strtolower($a), $page);
		unset($a);
	}
	
	$page = str_replace('{id}', $release['id'], $page);
	$page = str_replace('{moon}', $moon, $page);
	$page = str_replace('{xmoon}', $release['moonplayer'], $page);
	$poster = $_SERVER['DOCUMENT_ROOT'].'/upload/release/'.$release['id'].'.jpg';
	if(!file_exists($poster)){
		$page = str_replace('{img}', '/upload/release/default.jpg', $page);
	}else{
		$page = str_replace('{img}', fileTime($poster), $page);
	}
	if($user){		
		if(isFavorite($user['id'], $release['id'])){
			$page = str_replace('{favorites}', 'class="favorites"', $page);
		}
	}
	$page = str_replace('{favorites}', '', $page);
	$query = $db->prepare("SELECT * FROM `xbt_files` WHERE `rid` = :id");
	$query->bindParam(':id', $release['id']);
	$query->execute();
	if($query->rowCount() == 0){
		$page = str_replace('{torrent}', '', $page);
	}else{
		$torrent = '';
		while($row = $query->fetch()){
			$torrent .= getTemplate('torrent');
			$tmp = json_decode($row['info'], true);
			$torrent = str_replace('{ctime}', date('d.m.Y', $row['ctime']), $torrent);
			$torrent = str_replace('{seeders}', $row['seeders'], $torrent);
			$torrent = str_replace('{leechers}', $row['leechers'], $torrent);
			$torrent = str_replace('{completed}', $row['completed'], $torrent);
			$torrent = str_replace('{id}', $row['fid'], $torrent);
			if($user){
				$link = "/public/torrent_download.php?id={$row['fid']}";
			}else{
				$link = "/upload/torrents/{$row['fid']}.torrent";
			}
			$torrent = str_replace('{link}', $link, $torrent);
			$torrent = str_replace('{rtype}', $tmp['0'], $torrent);
			$torrent = str_replace('{rnum}', $tmp['1'], $torrent);
			$torrent = str_replace('{rsize}', formatBytes($tmp['2']), $torrent);
		}
		$page = str_replace('{torrent}', $torrent, $page);
	}
	return $page;
}

function uploadPoster($id){	
	if(empty($_FILES['poster'])){
		return;
	}
	if($_FILES['poster']['error'] != 0){
		return;
	}
	if(!in_array(exif_imagetype($_FILES['poster']['tmp_name']), [IMAGETYPE_PNG, IMAGETYPE_JPEG])){
		return;
	}
	if($_FILES['poster']['size'] > 1000000){
		return;
	}
	$img = new Imagick($_FILES['poster']['tmp_name']);
	$img->setImageFormat('jpg');
	$img->resizeImage(350,500,Imagick::FILTER_LANCZOS, 1, false);
	$img->setImageCompression(Imagick::COMPRESSION_JPEG);
	$img->setImageCompressionQuality(80);
	$img->stripImage();
	$file = $_SERVER['DOCUMENT_ROOT'].'/upload/release/'.$id.'.jpg';
	deleteFile($file);
	file_put_contents($file, $img);
	$img->resizeImage(240,350,Imagick::FILTER_LANCZOS, 1, false);
	$file = $_SERVER['DOCUMENT_ROOT'].'/upload/poster/'.$id.'.jpg';
	deleteFile($file);
	file_put_contents($file, $img);
}

function xrelease(){
	global $db, $user, $var;
	$data = []; $sql = ['col' => '', 'val' => '', 'update' => ''];
	if(!$user || $user['access'] < 2){
		_message('access', 'error');
	}
	if(empty($_POST['data'])){
		_message('empty', 'error');
	}
	$arr = ['name', 'ename', 'year', 'type', 'genre', 'voice', 'other', 'announce', 'status', 'moonplayer', 'description', 'day'];
	$post = json_decode($_POST['data'], true);
	foreach($arr as $key){
		if(array_key_exists($key, $post)){
			if(empty($post["$key"])){
				continue;
			}
			$data[$key] = htmlspecialchars($post["$key"], ENT_QUOTES, 'UTF-8');
			$sql['col'] .= "`$key`,";
			$sql['val'] .= ":$key,";
			$sql['update'] .= "`$key` = :$key,";
		}
	}
	if(!empty($data['status']) && array_key_exists($data['status'], $var['status'])){
		$data['search_status'] = $var['status'][$data['status']];
	}else{
		$data['search_status'] = $var['status']['3'];
	}
	$sql['col'] .= '`search_status`,';
	$sql['val'] .= ':search_status,';
	$sql['update'] .= '`search_status` = :search_status,';
	if(!empty($sql['col'])){
		$sql['col'] = rtrim($sql['col'], ',');
		$sql['val'] = rtrim($sql['val'], ',');
		$sql['update'] = rtrim($sql['update'], ',');
		$id = '';
		if(!empty($post['update'])){
			$id = intval($post['update']);
		}
		if(!empty($id)){
			$query = $db->prepare("SELECT * FROM `xrelease` WHERE `id` = :id");
			$query->bindParam(':id', $id);
			$query->execute();
			if($query->rowCount() != 1){
				_message('wrongRelease');
			}
			uploadPoster($id);
			$query = $db->prepare("UPDATE `xrelease` SET {$sql['update']} WHERE `id` = :id");
			$query->bindParam(':id', $id);
		}else{
			$query = $db->prepare("INSERT INTO `xrelease` ({$sql['col']}) VALUES ({$sql['val']})");
		}
		foreach($data as $k => &$v){ // https://stackoverflow.com/questions/12144557/php-pdo-bindparam-was-falling-in-a-foreach
			$query->bindParam(':'.$k, $v);
		}
		$query->execute();
		if(empty($id)){
			uploadPoster($db->lastInsertId());
		}
		_message('success');
	}
}

function edit_release(){
	global $db, $user, $var;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if($user['access'] < 4){
		_message('Access deny', 'error');
	}
	if(empty($_POST['id']) || !ctype_digit($_POST['id'])){
		_message('Wrong release id', 'error');
	}
	$query = $db->prepare("SELECT * FROM `page` WHERE `id` = :id");
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	if($query->rowCount() != 1){
		_message('Release not exists', 'error');
	}
	$check = check_poster();
	if($check['err']){
		deleteFile($_SERVER['DOCUMENT_ROOT']."/upload/torrent/{$_POST['id']}.jpg");
		move_uploaded_file($_FILES['poster']['tmp_name'], $file);
	}
	$data = []; $sql = '';
	$arr = ['name', 'ename', 'genre', 'voice', 'translator', 'timing', 'design', 'year', 'season', 'type', 'description'];
	foreach($arr as $key){
		if(!empty($_POST[$key])){
			$sql .= "`$key` = :$key,";
			$data[] = $key;
		}
	}
	if(!empty($sql)){
		$sql = rtrim($sql, ',');
		$query = $db->prepare("UPDATE `page` SET $sql WHERE `id` = :id");
		foreach($data as $k => $v){
			$_POST[$v] = htmlspecialchars($_POST[$v], ENT_QUOTES, 'UTF-8');
			$query->bindParam(":$v", $_POST[$v]);
		}
		$query->bindParam(':id', $_POST['id']);
		$query->execute();
	}
	_message('Success');
}

function set_nickname(){
	global $db, $user;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(!empty($user['nickname'])){
		_message('Already set nickname', 'error');
	}
	if(empty($_POST['nickname'])){
		_message('Empty nickname', 'error');
	}
	if(mb_strlen($_POST['nickname']) > 20){
		_message('Nickname max len 20', 'error');
	}
	$_POST['nickname'] = htmlspecialchars($_POST['nickname'], ENT_QUOTES, 'UTF-8');
	$query = $db->prepare("SELECT `id` FROM `users` WHERE `nickname` = :nickname");
	$query->bindParam(':nickname', $_POST['nickname']);
	$query->execute();
	if($query->rowCount() > 0){
		_message('Nickname already use', 'error');
	}
	$query = $db->prepare("UPDATE `users` SET `nickname` = :nickname WHERE `id` = :id");
	$query->bindParam(':nickname', $_POST['nickname']);
	$query->bindParam(':id', $user['id']);
	$query->execute();
	_message('Success');
}

function getAge($time){
	return date('Y', time()) - date('Y', $time);
}

function auth_history(){ // test it
	global $db, $user, $var; $data = [];
	$query = $db->prepare("SELECT * FROM `log_ip` WHERE `uid` = :uid ORDER BY `id` DESC LIMIT 100");
	$query->bindParam(':uid', $user['id']);
	$query->execute();
	while($row = $query->fetch()){
		$status = false;
		$tmp = $db->prepare("SELECT * FROM `session` WHERE `id` = :id AND `time` > UNIX_TIMESTAMP()");
		$tmp->bindParam(':id', $row['sid']);
		$tmp->execute();
		if($tmp->rowCount() == 1){
			$status = true;
		}
		$data[$row['time']] = [inet_ntop($row['ip']), base64_encode($row['info']), $status, $row['sid']];
	}
	return array_reverse($data, true);
}

function footerJS(){
	global $var, $user, $conf; $result = '';
	$tmplJS =  '<script src="{url}"></script>';
	$tmplCSS =  '<link rel="stylesheet" type="text/css" href="{url}" />';
	switch($var['page']){
		default: break;
		case 'login': 
			if(!$user){
				$result = str_replace('{url}', 'https://www.google.com/recaptcha/api.js?render='.$conf['recaptcha_public'], $tmplJS); 
			}
		break;
		case 'cp':
			if($user){
				$result .= str_replace('{url}', fileTime('/js/jquery.Jcrop.min.js'), $tmplJS);
				$result .= str_replace('{url}', fileTime('/css/jquery.Jcrop.min.css'), $tmplCSS);
				$result .= str_replace('{url}', fileTime('/js/uploadAvatar.js'), $tmplJS);
				$result .= str_replace('{url}', fileTime('/css/dataTables.bootstrap.min.css'), $tmplCSS);
				$result .= str_replace('{url}', fileTime('/js/jquery.dataTables.min.js'), $tmplJS);
				$result .= str_replace('{url}', fileTime('/js/dataTables.bootstrap.min.js'), $tmplJS);
				$result .= str_replace('{url}', fileTime('/js/tables.js'), $tmplJS);
			}
		break;
		case 'new':
			$result .= str_replace('{url}', fileTime('/css/dataTables.bootstrap.min.css'), $tmplCSS);
			$result .= str_replace('{url}', fileTime('/js/jquery.dataTables.min.js'), $tmplJS);
			$result .= str_replace('{url}', fileTime('/js/dataTables.bootstrap.min.js'), $tmplJS);
			$result .= str_replace('{url}', fileTime('/js/tables.js'), $tmplJS);
		case 'catalog':
			$result .= str_replace('{url}', fileTime('/css/chosen.min.css'), $tmplCSS);
			$result .= str_replace('{url}', fileTime('/css/simplePagination.css'), $tmplCSS);
			$result .= str_replace('{url}', fileTime('/css/chosen-bootstrap-theme.css'), $tmplCSS);
			$result .= str_replace('{url}', fileTime('/js/chosen.jquery.min.js'), $tmplJS);
			$result .= str_replace('{url}', fileTime('/js/jquery.simplePagination.js'), $tmplJS);
			$result .='<script>$(".chosen").chosen();</script>';
			$result .= str_replace('{url}', fileTime('/js/catalog.js'), $tmplJS);
		break;
		case 'release':
			if($user && $user['access'] >= 2){
				$result .= str_replace('{url}', fileTime('/css/chosen.min.css'), $tmplCSS);
				$result .= str_replace('{url}', fileTime('/css/chosen-bootstrap-theme.css'), $tmplCSS);
				$result .= str_replace('{url}', fileTime('/js/chosen.jquery.min.js'), $tmplJS);
				$result .='<script>$(".chosen").chosen();</script>';
				$result .='<style>.chosen-container { min-width:100%; }</style>';
			}
			$tmp = getReleaseVideo($var['release']['id']);
			if(!empty($tmp['0'])){
				$result .= str_replace('{playlist}', $tmp['0'], getTemplate('playerjs'));
			}
			unset($tmp);
			$result .= wsInfo($var['release']['name']);

		break;
		case 'chat':
			if(!empty($_SESSION['sex']) || !empty($_SESSION['want'])){
				$result .= str_replace('{url}', fileTime('/js/chat.js'), $tmplJS);
			}
		break;
	}
	return $result;
}

function wsInfo($name){
	global $conf;
	if(!empty($name)){
		$url = base64_encode(mb_strtolower(htmlspecialchars(explode('?', $_SERVER['REQUEST_URI'], 2)[0], ENT_QUOTES, 'UTF-8')));
		$hash = hash('sha256', $name.$url.$conf['stat_secret']);
		$result = str_replace('{ws}', $conf['stat_url'], getTemplate('stat'));
		$result = str_replace('{hash}', $hash, $result);
		$result = str_replace('{name}', $name, $result);
		$result = str_replace('{url}', $url, $result);
		return $result;
	}
}

function getRemote($url, $key){
	global $cache;
	$data = $cache->get('anilibria'.$key);
	if(empty($data)){
		if(!$data = file_get_contents($url)){
			return false;
		}
		if(!isJson($data)){
			return false;
		}	
		$cache->set('anilibria'.$key, $data, 300);
	}
	return $data;
}

function wsInfoShow(){
	$result = '';
	$data = getRemote('https://www.anilibria.tv/api/api.php?action=top', 'top');
	if($data){
		$arr = json_decode($data, true);
		$all = array_sum($arr);
		$arr = array_slice($arr, 0, 20);
		foreach($arr as $key => $val){
			$result .= "<tr><td style=\"display:inline-block; width:390px;overflow:hidden;white-space:nowrap; text-overflow: ellipsis;\"><a href=\"https://www.anilibria.tv/search/index.php?q=$key&where=iblock_Tracker\">$key</a></td><td class=\"tableCenter\">$val</a></td></tr>";
		}
		$result .= "<tr style=\"border-top: 3px solid #ddd; border-bottom: 3px solid #ddd;\"><td style=\"display:inline-block; width:390px;overflow:hidden;white-space:nowrap; text-overflow: ellipsis;\">Всего зрителей</td><td class=\"tableCenter\">$all</a></td></tr>";
	}
	return $result;
}

function mp4_link($value){
	global $conf, $var;
	$time = time()+60*60*48;
	$key = str_replace("=", "", strtr(base64_encode(md5("{$time}/videos/{$value}".$var['ip']." {$conf['nginx_secret']}", true)), "+/", "-_"));
	$url = htmlspecialchars("{$conf['nginx_domain']}/get/$key/$time/$value", ENT_QUOTES, 'UTF-8');
	return $url;
}

function getReleaseVideo($id){
	global $conf;
	$playlist = '';
	$data = getRemote($conf['nginx_domain'].'/?id='.$id, 'video'.$id);
	if($data){
		$arr = json_decode($data, true);
		if(!empty($arr) && !empty($arr['updated'])){
			unset($arr['updated']);
			$arr = array_reverse($arr, true);
			foreach($arr as $key => $val) {
				$download = '';
				if(!empty($val['file'])){
					$download = mp4_link($val['file'].'.mp4');
				}
				$playlist .= "{'title':'Серия $key', 'file':'{$val['new']}', download:\"$download\", 'id': 's$key'},";
			}
		}
	}
	return [$playlist, $download];
}

function youtubeVideoExists($id) {
	global $db;
	$x = get_headers("http://www.youtube.com/oembed?url=http://www.youtube.com/watch?v=$id&format=json")[0];
	if($x == 'HTTP/1.0 404 Not Found'){
		$query = $db->prepare("DELETE FROM `youtube` WHERE `vid` = :vid");
		$query->bindParam(':vid', $id);
		$query->execute();
		deleteFile($_SERVER['DOCUMENT_ROOT'].'/upload/youtube/'.hash('crc32', $id).'.jpg');
	}
	if($x == 'HTTP/1.0 200 OK'){
		return true;
	}
	return false;
}

function updateYoutubeStat(){
	global $db;
	$query = $db->query("SELECT * FROM `youtube`");
	$query->execute();
	
	while($row = $query->fetch()){
		$stat = youtubeStat($row['vid']);
		if(!$stat){
			continue;
		}
		$tmp = $db->prepare("UPDATE `youtube` SET `view` = :view, `comment` = :comment WHERE `id` = :id");
		$tmp->bindParam(':view', $stat['0']);
		$tmp->bindParam(':comment', $stat['1']);
		$tmp->bindParam(':id', $row['id']);
		$tmp->execute();
	}
}

function youtubeStat($id){
	global $db, $conf;	
	if(youtubeVideoExists($id)){
		$json = file_get_contents("https://www.googleapis.com/youtube/v3/videos?part=statistics&id=$id&key={$conf['youtube_secret']}");
		if(!empty($json)){
			$arr = json_decode($json, true);
			if(!empty($arr['items']['0']['statistics']['viewCount'])) $view = $arr['items']['0']['statistics']['viewCount'];
			if(!empty($arr['items']['0']['statistics']['commentCount'])) $comment = $arr['items']['0']['statistics']['commentCount'];
		}
		return [$view, $comment];
	}
	return false;
}

function youtubeGetImage($id){
	$data = fopen("https://img.youtube.com/vi/$id/maxresdefault.jpg", 'rb');
	$img = new Imagick();
	$img->readImageFile($data);
	$img->resizeImage(840,400,Imagick::FILTER_LANCZOS, 1, false);
	$img->setImageCompression(Imagick::COMPRESSION_JPEG);
	$img->setImageCompressionQuality(85);
	$img->stripImage();
	file_put_contents($_SERVER['DOCUMENT_ROOT'].'/upload/youtube/'.hash('crc32', $id).'.jpg', $img);
}

function updateYoutube(){
	global $db, $conf;
	$data = [];
	$arr = json_decode(file_get_contents("https://www.googleapis.com/youtube/v3/search?order=date&part=snippet&channelId={$conf['youtube_chanel']}&maxResults=5&key={$conf['youtube_secret']}"), true);
	$arr['items'] = array_reverse($arr['items']);
	foreach($arr['items'] as $key => $val){
		if(empty($val['id']['videoId'])){
			continue;
		}
		$query = $db->prepare("SELECT * FROM `youtube` WHERE `vid` = :vid");
		$query->bindParam(':vid', $val['id']['videoId']);
		$query->execute();
		if($query->rowCount() == 1){
			continue;
		}
		$query = $db->prepare("INSERT INTO `youtube` (`title`, `vid`) VALUES (:title, :vid)");
		$query->bindParam(':title', $val['snippet']['title']);
		$query->bindParam(':vid', $val['id']['videoId']);
		$query->execute();
		youtubeGetImage($val['id']['videoId']);
	}	
}

function youtubeShow(){
	global $db;
	$result = '';
	$query = $db->query("SELECT * FROM `youtube` ORDER BY `id` DESC  LIMIT 4");
	$query->execute();
	while($row = $query->fetch()){
		$youtube = getTemplate('youtube');
		$youtube = str_replace('{url}', "https://www.youtube.com/watch?v={$row['vid']}", $youtube);
		$youtube = str_replace('{title}', $row['title'], $youtube);
		$youtube = str_replace('{img}', '/upload/youtube/'.hash('crc32', $row['vid']).'.jpg', $youtube);
		$youtube = str_replace('{comment}', $row['comment'], $youtube);
		$youtube = str_replace('{view}', $row['view'], $youtube);
		$result .= $youtube;
		unset($youtube);
	}
	return $result;
}

function updateReleaseAnnounce(){
	global $db, $user, $var;
	if(!$user || $user['access'] < 2){
		_message('access', 'error');
	}
	if(empty($_POST['id'])){
		_message('empty', 'error');
	}
	if(mb_strlen($_POST['announce']) > 200){
		_message('long', 'error');
	}
	if(!ctype_digit($_POST['id'])){
		_message('wrong', 'error');
	}
	$query = $db->prepare("SELECT * FROM `xrelease` WHERE `id` = :id");
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('wrongRelease', 'error');
	}
	$_POST['announce'] = htmlspecialchars($_POST['announce'], ENT_QUOTES, 'UTF-8');
	$query = $db->prepare("UPDATE `xrelease` SET `announce` = :announce WHERE `id` = :id");
	$query->bindParam(':announce', $_POST['announce']);
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	_message('success');
}

function showEditTorrentTable(){
	global $db, $var; $result = ''; $arr = [];
	$query = $db->prepare("SELECT * FROM `xbt_files` WHERE `rid` = :rid");
	$query->bindParam(':rid', $var['release']['rid']);
	$query->execute();
	while($row = $query->fetch()){
		$date = date('d.m.Y', $row['ctime']);
		$info = json_decode($row['info'], true);
		$tmp = getTemplate('edit_torrent');
		$tmp = str_replace('{id}', $row['fid'], $tmp);
		$tmp = str_replace('{quality}', $info['0'], $tmp);
		$tmp = str_replace('{series}', $info['1'], $tmp);
		$tmp = str_replace('{date}', $date, $tmp);
		$result .= $tmp;
		
		$arr[] = ['do' => 'change', 'fid' => $row['fid'], 'rid' => $row['rid'], 'series' => $info['1'], 'quality' => $info['0'], 'ctime' => $date, 'delete' => ''];
	}
	return $result;
}

function deleteFile($f){
	if(file_exists($f)){
		unlink($f);
	}
}

function removeRelease(){
	global $db, $user;
	if(!$user || $user['access'] < 2){
		_message('access', 'error');
	}
	if(empty($_POST['id'])){
		_message('empty', 'error');
	}
	$query = $db->prepare("SELECT `id` FROM `xrelease` WHERE `id` = :id");
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('wrongRelease', 'error');
	}
	$query = $db->prepare("DELETE FROM `xrelease` WHERE `id` = :id");
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	$query = $db->prepare("SELECT * FROM `xbt_files` WHERE `rid` = :id");
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	if($query->rowCount() > 0){
		while($row = $query->fetch()){
			torrentDelete($row['fid']);
		}
	}
	$query = $db->prepare("DELETE FROM `favorites` WHERE `rid` = :rid");
	$query->bindParam(':rid', $_POST['id']);
	$query->execute();
	deleteFile($_SERVER['DOCUMENT_ROOT'].'/upload/poster/'.$_POST['id'].'.jpg');
	deleteFile($_SERVER['DOCUMENT_ROOT'].'/upload/release/'.$_POST['id'].'.jpg');
	_message('success');
}

function releaseTable(){
	global $db, $user, $var; $result = '';
	if(!$user || $user['access'] < 2){
		_message('access', 'error');
	}
	$data = []; $order = 'DESC'; $column = 'id'; $search = '';
	$arr = ['draw' => 1, 'start' => 0, 'length' => 10];
	foreach($arr as $key => $val){
		if(array_key_exists($key, $_POST)){
			$_POST["$key"] = intval($_POST["$key"]);
			if(!empty($_POST["$key"])){
				$arr[$key] = $_POST["$key"];	
			}
		}	
	}
	if(!empty($_POST['order']['0']['dir'])){
		if($_POST['order']['0']['dir'] == 'asc'){
			$order = 'ASC';
		}
	}
	if(!empty($_POST['order']['0']['column'])){
		if($_POST['order']['0']['column'] == 1){
			$column = 'name';
		}
		if($_POST['order']['0']['column'] == 2){
			$column = 'status';
		}
	}
	if($arr['length'] > 100){
		$arr['length'] = 100;
	}
	if(!empty($_POST['search']['value'])){
		$search = $_POST['search']['value'];
	}
	if(empty($search)){
		$query = $db->query("SELECT count(*) OVER (), c.* FROM `xrelease` c ORDER BY `$column` $order LIMIT {$arr['start']}, {$arr['length']}");
	}else{
		$search = "*$search*";
		$query = $db->prepare("SELECT count(*) OVER (), c.* FROM `xrelease` c WHERE MATCH(`name`, `ename`, `search_status`) AGAINST (:search IN BOOLEAN MODE) ORDER BY `$column` $order LIMIT {$arr['start']}, {$arr['length']}");
		$query->bindParam(':search', $search);
	}
	$query->execute();
	$total = 0;
	while($row = $query->fetch()){
		if(empty($total)){
			$total = $row['count(*) OVER ()'];
		}
		$tmp['id'] = "<a href='/pages/release.php?id={$row['id']}'>{$row['id']}</a>";
		$tmp['name'] = $row['name'];
		$tmp['status'] = $var['status'][$row['status']];
		$tmp['last'] = "<a data-admin-release-delete='{$row['id']}' href='#'<span class='glyphicon glyphicon-remove'></span></a>";
		$data[] = array_values($tmp);
	}
	return ['draw' => $row['draw'], 'start' => $row['start'], 'length' => $row['length'], 'recordsTotal' => $total, 'recordsFiltered' => $total, 'data' => $data];
}

function fileTime($f){
	if(!file_exists($f)){
		$f = $_SERVER['DOCUMENT_ROOT'].$f;
		if(!file_exists($f)){
			return false;
		}
	}
	$time = filemtime($f);
	$f = str_replace($_SERVER['DOCUMENT_ROOT'], '', $f);
	return $f.'?'.$time;
}

function sphinxPrepare($x){
	// https://github.com/yiisoft/yii2/issues/3668
	// https://github.com/yiisoft/yii2/commit/603127712bb5ec90ddc4c461257dab4a92c7178f
	return str_replace(
		['\\', '/', '"', '(', ')', '|', '-', '!', '@', '~', '&', '^', '$', '=', '>', '<', "\x00", "\n", "\r", "\x1a"],
		['\\\\', '\\/', '\\"', '\\(', '\\)', '\\|', '\\-', '\\!', '\\@', '\\~', '\\&', '\\^', '\\$', '\\=', '\\>', '\\<', "\\x00", "\\n", "\\r", "\\x1a"],
		$x
	);	
}

function xSearch(){
	global $sphinx, $db; $result = ''; $limit = '';
	$data = []; $arr = ['search', 'key'];
	$keys = ['name,ename', 'genre', 'year'];
	foreach($arr as $key){
		if(!empty($_POST["$key"])){
			$data["$key"] = trim($_POST["$key"]);
		}
	}
	if(empty($data['search'])){
		die;
	}
	if(empty($data['key']) || !in_array($data['key'], $keys)){
		$data['key'] = $keys['0'];
	}
	$data['search'] = sphinxPrepare($data['search']);
	if(!empty($_POST['small'])){
		$limit = 'LIMIT 10';
	}
	$query = $sphinx->prepare("SELECT * FROM anilibria WHERE MATCH(:search) $limit");
	$query->bindValue(':search', "@({$data['key']}) ({$data['search']})");
	$query->execute();
	$tmp = $query->fetchAll();
	foreach($tmp as $k => $v){
		$query = $db->prepare("SELECT * FROM `xrelease` WHERE `id` = :id");
		$query->bindParam(':id', $v['id']);
		$query->execute();
		if($query->rowCount() != 1){
			continue;
		}
		$row = $query->fetch();
		$result .= "<tr><td><a href='/pages/release.php?id={$row['id']}'>{$row['name']}</a>";
	}
	_message2($result);
}

function showPosters(){
	global $db; $result = '';
	$query = $db->query("SELECT `id` FROM `xrelease` ORDER BY `last` DESC LIMIT 5");
	while($row=$query->fetch()){	
		$img = fileTime('/upload/poster/'.$row['id'].'.jpg');
		if(!$img){
			$img = '/upload/poster/default.jpg';
		}
		$tmp = getTemplate('torrent-block');
		$tmp = str_replace("{id}", $row['id'], $tmp);
		$tmp = str_replace("{img}", $img, $tmp);
		$result .= $tmp;
	}
	return $result;
}

function getGenreList(){
	global $db; $arr = []; $result = ''; $total = 0;
	$tmpl = '<option value="{name}">{name}</option>';
	$query = $db->query("SELECT `name` from `genre`");
	while($row = $query->fetch()){
		$arr[] = $row['name'];
	}
	sort($arr);
	foreach($arr as $k => $v){
		$result .= str_replace('{name}', $v, $tmpl);
	}
	return $result;
}

function showCatalog(){
	global $sphinx, $db, $user; $i=0; $arr = []; $result = ''; $page = 0;
	$tmplTR = '<tr>{td}<tr>';
	function aSearch($db, $page){
		$query = $db->query("SELECT count(*) as total FROM `xrelease`");
		$total =  $query->fetch()['total'];
		$query = $db->query("SELECT `id` FROM `xrelease` ORDER BY `last` DESC LIMIT $page, 12");
		$data = $query->fetchAll();
		return ['data' => $data, 'total' => $total];
	}
	function bSearch($sphinx, $page){
		if(!empty($_POST['search'])){
			$search = '';
			$data = json_decode($_POST['search'], true);
			foreach($data as $k => $v){
				if(!empty($v)){
					$search .= $v.',';
				}
			}
			$search = rtrim($search, ',');
			if(!empty($search)){
				$search = sphinxPrepare($search);
				$query = $sphinx->prepare("SELECT count(*) as total FROM anilibria WHERE MATCH(:search)");
				$query->bindValue(':search', "@(genre,year) ($search)");
				$query->execute();
				$total =  $query->fetch()['total'];
				
				$query = $sphinx->prepare("SELECT * FROM anilibria WHERE MATCH(:search) ORDER BY `last` DESC LIMIT $page, 12");
				$query->bindValue(':search', "@(genre,year) ($search)");
				$query->execute();
				$data = $query->fetchAll();
				return ['data' => $data, 'total' => $total];
			}
		}
		return false;
	}
	
	function cSearch($db, $user, $page){
		$data = []; $total = 0;
		$query = $db->prepare("SELECT count(*) as total FROM `favorites` WHERE `uid` = :uid");
		$query->bindParam(':uid', $user['id']);
		$query->execute();
		$total = $query->fetch()['total'];
		$query = $db->prepare("SELECT `rid` FROM `favorites` WHERE `uid` = :uid ORDER BY `id` DESC LIMIT $page, 12");
		$query->bindParam(':uid', $user['id']);
		$query->execute();
		while($row = $query->fetch()){
			$data[]['id'] = $row['rid']; 
		}
		return ['data' => $data, 'total' => $total];
	}
	
	function prepareSearchResult($data){
		$arr = []; $i = 0;
		$tmplTD = '<td><a href="/pages/release.php?id={id}"><img class="torrent_pic" border="0" src="{img}" width="270" height="390" alt="" title=""></a></td>';
		foreach($data as $key => $val){
			$poster = $_SERVER['DOCUMENT_ROOT'].'/upload/release/'.$val['id'].'.jpg';
			if(!file_exists($poster)){
				$img = '/upload/release/default.jpg';
			}else{
				$img = fileTime($poster);
			}
			$arr[$i][] = str_replace('{id}', $val['id'], str_replace('{img}', $img, $tmplTD));  
			if(count($arr[$i]) == 3){
				$i++;
			}
		}
		return $arr;
	}
	if(!empty($_POST['page'])){
		$page = intval($_POST['page']);
		if(empty($page) || $page == 1){
			$page = 0;
		}else{
			$page = ($page-1) * 12;
		}
	}
	
	if(empty($_POST['xpage'])){
		_message('empty', 'error');
	}

	if($_POST['xpage'] == 'favorites'){
		if(!$user){
			_message('access', 'error');
		}
		$arr = cSearch($db, $user, $page);
	}else{
	
		$arr = bSearch($sphinx, $page);
		if(!$arr){
			$arr = aSearch($db, $page);
		}
	}
	$arr['data'] = prepareSearchResult($arr['data']);
	foreach($arr['data'] as $key => $val){
		$tmp = '<tr>';
		foreach($val as $k => $v){
			$tmp .= $v;
		}
		$tmp .= '</tr>';		
		$result .= $tmp;
	}
	die(json_encode(['err' => 'ok', 'table' => $result, 'total' => $arr['total'], 'update' => md5($arr['total'].$_POST['search']) ]));
}

function isFavorite($uid, $rid){
	global $db;
	$query = $db->prepare("SELECT `id` FROM `favorites` WHERE `uid` = :uid AND `rid` = :rid");
	$query->bindParam(':uid', $uid);
	$query->bindParam(':rid', $rid);
	$query->execute();
	if($query->rowCount() == 0){
		return false;
	}
	return true;
}

function releaseFavorite(){
	global $db, $user;
	if(!$user){
		_message('access', 'error');
	}
	if(empty($_POST['rid'])){
		_message('empty', 'error');
	}
	$query = $db->prepare("SELECT `id` FROM `xrelease` WHERE `id` = :id");
	$query->bindParam(':id', $_POST['rid']);
	$query->execute();
	if($query->rowCount() != 1){
		_message('empty', 'error');
	}
	if(!isFavorite($user['id'], $_POST['rid'])){
		$query = $db->prepare("INSERT INTO `favorites` (`uid`, `rid`) VALUES (:uid, :rid)");
		$query->bindParam(':uid', $user['id']);
		$query->bindParam(':rid', $_POST['rid']);
		$query->execute();
	}else{
		$query = $db->prepare("DELETE FROM `favorites` WHERE `uid` = :uid AND `rid` = :rid");
		$query->bindParam(':uid', $user['id']);
		$query->bindParam(':rid', $_POST['rid']);
		$query->execute();
	}
	_message('success');
}

function releaseUpdateLast(){ // todo add announce to pushall and telegram
	global $db, $user, $var;
	if(!$user || $user['access'] < 2){
		_message('access', 'error');
	}
	if(empty($_POST['id'])){
		_message('empty', 'error');
	}
	$query = $db->prepare("SELECT `id` FROM `xrelease` WHERE `id` = :id");
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	if($query->rowCount() == 0){
		_message('wrongRelease', 'error');
	}
	$query = $db->prepare("UPDATE `xrelease` SET `last` = :time WHERE `id` = :id");
	$query->bindParam(':time', $var['time']);
	$query->bindParam(':id', $_POST['id']);
	$query->execute();
	_message('success');
}

function showSchedule(){
	global $db, $var; $arr = []; $result = ''; $i = 0;
	$tmpl1 = '<div class="day">{day}</div>';
	$tmpl2 = '<td class="goodcell"><a href="/pages/release.php?id={id}"> <img width="200" height="280" src="{img}"></a></td>';
	foreach($var['day'] as $key => $val){
		$query = $db->prepare("SELECT `id` FROM `xrelease` WHERE `day` = :day AND `status` = '1'");
		$query->bindParam(':day', $key);
		$query->execute();
		while($row=$query->fetch()){
			$poster = $_SERVER['DOCUMENT_ROOT']."/upload/poster/{$row['id']}.jpg";
			if(!file_exists($poster)){
				$img = '/upload/poster/default.jpg';
			}else{
				$img = fileTime($poster);
			}
			$arr["$key"][$i][] = [ 
				str_replace('{id}', $row['id'], str_replace('{img}', $img, $tmpl2))
			];
			if(count($arr["$key"][$i]) == 4){
				$i++;
			}
		}
	}
	foreach($arr as $key => $val){
		$result .= str_replace('{day}', $var['day']["$key"], $tmpl1);
		$result .= '<table class="test"><tbody>';
		foreach($val as $v){
			$result .= '<tr>';
			foreach($v as $item){
				$result .= $item['0'];
			}
			$result .= '</tr>';	
		}
		$result .='</tbody></table>';
	}
	return $result;
}
