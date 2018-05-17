<?php

require_once "const.php";

function auth($subdomain, $login, $hash){
	$user = array(
		'USER_LOGIN' => $login,
		'USER_HASH' => $hash
	);
	$url = '/private/api/auth.php?type=json';
	$result = init($subdomain, $url, $user);
	if (is_array($result) && $result['response']['auth'] == TRUE) {
		return TRUE;
	} else {
		return $result;
	}
}

function get_leads($subdomain, $rows, $offset) {
	$url = '/api/v2/leads?limit_rows='.$rows.'&limit_offset='.$offset;
	$result = init($subdomain, $url);
	return $result;
}

function del_tags($subdomain, $data) {
	$url = '/api/v2/leads/';
	$result = init($subdomain, $url, $data);
	return $result;
}

function notes_add($subdomain, $data) {
	$url = '/api/v2/notes/';
	$result = init($subdomain, $url, $data);
	return $result;
}

function make_updates_files($subdomain, $rows, $offset, $tags_names) {
    $leads_result = true;
	$files_put_result = 0;
    $i = 0;

    while ($leads_result) {
        sleep(1);
		$update_array = []; // Массив массивов сделок к апдейту и примечаний к добавлению
        $notes_add_array = [];
        $leads_update_array = [];
        $limit_offset = $i*$offset;
        $i++;

        // Получение списка сделок
        $leads_result = get_leads($subdomain, $rows, $limit_offset);

        if (!is_array($leads_result)) {
        	echo "Список сделок пуст ".$leads_result."\n\n";
            break;
        }

        $leads = $leads_result['_embedded']['items']; // Массив сделок
		echo "Сделок: ".count($leads)."\n";

        foreach ($leads as $lead) {
            $lead_id = $lead['id'];
            $lead_tags_names = [];
            $leave_tags = '';
            $lead_tags = $lead['tags'];

            foreach ($lead_tags as $lead_tag) {
                $lead_tags_names[] = $lead_tag['name'];
            }
            $tags_to_del = array_intersect($lead_tags_names, $tags_names); // Теги к удалению в сделке
            $leave_tags_arr = array_diff($lead_tags_names, $tags_names); // Теги - остаются в сделке
            foreach ($leave_tags_arr as $leave_tag) {
                $leave_tags .= $leave_tag . ',';
            }

            $note_text = "";
            foreach ($tags_to_del as $tag_to_del) {
                $note_text .= $tag_to_del . ' ';
            }

            if (count($tags_to_del) > 0) {
                $leads_update_array[] = array('id' => $lead_id, 'tags' => $leave_tags); // Массив для апдейта сделок
                $notes_add_array[] = array('element_id' => $lead_id, 'element_type' => 2, 'note_type' => 25, 'params' => array('text' => $note_text,'service' => 'Удалены теги')); // Массив для добавления примечаний об удаленных тегах
            }
        }
        if (count($leads_update_array) > 0) {
        	echo "Сделок к апдейту: ".count($leads_update_array)."\n";
			$update_array[] = array($leads_update_array, $notes_add_array);
			$files_put_result = file_put_contents(APP_DIR."/files/updates".$i.".json", json_encode($update_array));
		} else {
        	echo "Сделок к апдейту: 0\n";
		}
    }
    return $files_put_result;
}

function init($subdomain, $url, $data=null) {
	$link = 'https://' . $subdomain . '.amocrm.ru' . $url;

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERAGENT, 'amoCRM-API-client/2.0');
	curl_setopt($ch, CURLOPT_URL, $link);
	if (!empty($data)){
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	}
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_COOKIEFILE, dirname(__FILE__).'/cookie.txt');
	curl_setopt($ch, CURLOPT_COOKIEJAR, dirname(__FILE__).'/cookie.txt');
	$out = curl_exec ($ch);
	$code = curl_getinfo ($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$code=(int)$code;
	if($code != 200) {
		return 'Error: '.$code;
	} else {
		$out = json_decode($out, TRUE);
		return $out;
	}
}
