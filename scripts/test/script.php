<?php

echo 'test';

$open = fopen('update-data.txt', 'rt');
$result = true;
$update_ids = [];

while ($result) {
    $str = json_decode(fgets($open), true);
//    var_dump($str);
    if (!$str) {
        $result = false;
        break;
    }
    $update_ids[] = key($str);
}

echo count($update_ids) . "\n";
$update_ids_unique = array_unique($update_ids);
echo count($update_ids_unique) . "\n";

$sec_open = fopen('willnot-update.json', 'rt');
$willnot_ids = [];
$result = true;

while ($result) {
    $str = json_decode(fgets($sec_open), true);
//    var_dump($str);
    if (!$str) {
        $result = false;
        break;
    }
    $willnot_ids[] = (int)$str;
}

$counts = array_count_values($willnot_ids);
echo '<pre>';
var_dump($counts);
echo '</pre>';

echo count($willnot_ids) . "\n";
$willnot_unique = array_unique($willnot_ids);
echo count($willnot_unique) . "\n";

//echo count($willnot_ids);
//var_dump($willnot_ids);

$same = array_intersect($update_ids, $willnot_ids);
//var_dump($same);
echo count($same);