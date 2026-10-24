<?php
/** Offline local-search fixtures; no account or network is used. */
define('DOKU_INC', __DIR__);
require dirname(__DIR__) . '/modules/memos/Search.php';
$notes = [
 ['name'=>'memos/body','content'=>"# A measurement\n\nThe ohmic region is discussed here.",'createTime'=>'2026-01-01'],
 ['name'=>'memos/title','content'=>"## Ohmic **region**\n\nThermal measurements.",'tags'=>['electronics'],'createTime'=>'2025-01-01'],
 ['name'=>'memos/archive','content'=>"Archived measurement\nThe ohmic test in a different region.",'state'=>'ARCHIVED','createTime'=>'2026-02-01'],
 ['name'=>'memos/unicode','content'=>'Ölçüm: Überprüfung des Geräts.'],
 ['name'=>'memos/deleted','content'=>'ohmic region','state'=>'DELETED'],
 ['name'=>'../../private','content'=>'ohmic region'],
];
$checks=0;
function test($condition,$message) {global $checks;if (!$condition) throw new RuntimeException($message);$checks++;}
function search($q,$offset=0,$limit=20,$mode='words') {global $notes;return FokusButler_Memos_Search::query($notes,$q,$offset,$limit,$mode);}
$r=search('ohmic region');test($r['total']===3,'Words should match across the body and include archived notes');
test($r['results'][0]['key']==='memos/title','A title match should rank first');
test(search('ohmic region',0,20,'phrase')['total']===2,'Phrase matching must require adjacent text after Markdown formatting is stripped');
test(search('electronics')['total']===1,'Tags must be searchable');
test(search('überprüfung')['total']===1,'Search must support Unicode case folding');
test(search('.*')['total']===0,'Regex metacharacters must be literal');
test(search('')['results']===[],'Empty searches do not return private notes');
test(search('ohmic',0,1)['hasMore'] && count(search('ohmic',0,1)['results'])===1,'Pagination must retain total and more state');
test(!search('ohmic',2,1)['hasMore'],'The final page must stop pagination');
test(search('deleted')['total']===0,'Deleted and invalid resource names are excluded');
foreach ([['x',0,20,'bad'],[str_repeat('x',513),0,20,'words']] as $args) {
 try {search(...$args);throw new RuntimeException('Invalid search accepted');} catch (InvalidArgumentException $expected) {$checks++;}
}
test(!isset($r['results'][0]['_score']),'Internal ranking data should not be returned');
echo "PASS: $checks local Jots search checks (words, phrases, Markdown, tags, Unicode, literal queries, pagination and resource guards).\n";
