<?php

/*
// +--------------------------------------------------------------------------+
// | Project:    TBDevYSE - TBDev Yuna Scatari Edition                        |
// +--------------------------------------------------------------------------+
// | This file is part of TBDevYSE. TBDevYSE is based on TBDev,               |
// | originally by RedBeard of TorrentBits, extensively modified by           |
// | Gartenzwerg.                                                             |
// |                                                                          |
// | TBDevYSE is free software; you can redistribute it and/or modify         |
// | it under the terms of the GNU General Public License as published by     |
// | the Free Software Foundation; either version 2 of the License, or        |
// | (at your option) any later version.                                      |
// |                                                                          |
// | TBDevYSE is distributed in the hope that it will be useful,              |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
// | GNU General Public License for more details.                             |
// |                                                                          |
// | You should have received a copy of the GNU General Public License        |
// | along with TBDevYSE; if not, write to the Free Software Foundation,      |
// | Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA            |
// +--------------------------------------------------------------------------+
// |                                               Do not remove above lines! |
// +--------------------------------------------------------------------------+
*/

// �� ������� ���� ���� �� ��� ��������, �� ��� �� �������� � 23:59

require_once('include/bittorrent.php');

// Dirty hack to prevent ghost Guests on website
$old_us = $use_sessions;
$use_sessions = 0;
dbconn();
$use_sessions = $old_us;

if (!$_GET['token'])
	loggedinorreturn(); // � ����� ���� ������� �� ����� ��������? �� ����...

require_once('include/scraper/httptscraper.php');
require_once('include/scraper/udptscraper.php');

function scrape($tid, $url, $info_hash) {
	$timeout = 5;
	$udp = new udptscraper($timeout);
	$http = new httptscraper($timeout);

	try {
		if (substr($url, 0, 6) == 'udp://')
			$data = $udp->scrape($url, $info_hash);
		else
			$data = $http->scrape($url, $info_hash);
		$data = $data[$info_hash];
		sql_query('UPDATE torrents_scrape SET state = "ok", error = "", seeders = '.intval($data['seeders']).', leechers = '.intval($data['leechers']).' WHERE tid = '.$tid.' AND url = '.sqlesc($url)) or print(mysql_error()."\n");
		return true;
	} catch (ScraperException $e) {
		sql_query('UPDATE torrents_scrape SET state = "error", error = '.sqlesc($e->getMessage()).', seeders = 0, leechers = 0 WHERE tid = '.$tid.' AND url = '.sqlesc($url)) or print(mysql_error()."\n");
		return false;
	}
}

function generate_token($tid, $url, $info_hash) {
	return md5(implode('', array($tid, $url, $info_hash, COOKIE_SALT)));
}

function check_token($token, $tid, $url, $info_hash) {
	return $token === md5(implode('', array($tid, $url, $info_hash, COOKIE_SALT)));
}

$tid = intval($_GET['id']);

if (!$tid)
	die('WTF?!');

if ($_GET['info_hash'] && $_GET['url']) {
	$token = strval($_GET['token']);
	$url = strval($_GET['url']);
	$info_hash = strval($_GET['info_hash']);
	if (strlen($info_hash) != 40)
		die('Invalid len info_hash supplied');
	if (!check_token($token, $tid, $url, $info_hash))
		die('Invalid token');
	echo scrape($tid, $url, $info_hash);
	exit;
}

list($name, $cur_visible, $multitracker, $last_mt_update) = mysql_fetch_row(sql_query('SELECT name, visible, multitracker, last_mt_update FROM torrents WHERE id = '.$tid));
if ($name == '' || $multitracker == 'no')
	stderr($tracker_lang['error'], "������ �������� ���, ��� �� �� ���������������.");

if (strtotime($last_mt_update) > (TIMENOW - 3600))
	stderr($tracker_lang['error'], "�� ��������� �������� ������������ ������� �����. ��������� ��� ������ �� ���� 1 ���� � ���.");

$anns_r = sql_query('SELECT info_hash, url FROM torrents_scrape WHERE tid = '.$tid);

$s_sum = $l_sum = $errors = $success = 0;
$pids = $works = array();

while ($ann = mysql_fetch_array($anns_r))
	$works[] = $ann;

if (function_exists('curl_multi_init')) {
	$multi = curl_multi_init();
	$channels = array();
	foreach ($works as $work) {
		$url = $work['url'];
		$info_hash = $work['info_hash'];
		$url = $DEFAULTBASEURL.'/update_multi.php?id='.$tid.'&url='.urlencode($url).'&info_hash='.urlencode($info_hash).'&token='.generate_token($tid, $url, $info_hash);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
		curl_multi_add_handle($multi, $ch);
		$channels[$url] = $ch;
	}

	$running = null;
	do {
	    while (CURLM_CALL_MULTI_PERFORM === curl_multi_exec($multi, $running));
	    if (!$running)
	    	break;
	    while (($res = curl_multi_select($multi)) === 0)
	    	{};
	    if ($res === false) {
	        echo "<h1>select error</h1>";
	        break;
	    }
	} while (true);

	$success = 0;
	foreach ($channels as $url => $channel) {
	    $success += intval(curl_multi_getcontent($channel));
	    curl_multi_remove_handle($multi, $channel);
	}

	curl_multi_close($multi);
} else
	foreach ($works as $work)
		scrape($tid, $work['url'], $work['info_hash']);

sql_query('UPDATE torrents AS t INNER JOIN (SELECT ts.tid, SUM(ts.seeders) AS sum_seeders, SUM(ts.leechers) AS sum_leechers FROM torrents_scrape AS ts WHERE ts.tid = '.$tid.' GROUP BY ts.tid) AS ts ON ts.tid = t.id SET t.remote_seeders = ts.sum_seeders, t.remote_leechers = ts.sum_leechers, t.last_action = NOW(), t.last_mt_update = NOW(), visible = IF(t.remote_seeders > 0, "yes", visible) WHERE t.id = '.$tid) or sqlerr(__FILE__,__LINE__);

header('Refresh: 3;url=details.php?id='.$tid);
$errors = count($works) - $success;
stderr($tracker_lang['success'], "���������� ������������� ��������� �������. �������: $success ������: $errors");

?>