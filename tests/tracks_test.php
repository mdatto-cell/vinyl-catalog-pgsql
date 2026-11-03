<?php
declare(strict_types=1);
require_once __DIR__.'/../app/discogs.php';
$checks=0;
function same(mixed $actual,mixed $expected,string $message): void {
    global $checks; $checks++;
    if($actual!==$expected) throw new RuntimeException($message."\n".var_export($actual,true));
}
foreach([
    ['A1 - Неприкаянный - 7:41',['position'=>'A1','track_title'=>'Неприкаянный','duration'=>'7:41']],
    ['B3 - Кладбище Сент-Женевьев-Де-Буа - 5:24',['position'=>'B3','track_title'=>'Кладбище Сент-Женевьев-Де-Буа','duration'=>'5:24']],
    ['1 - Love - Hate - 4:32',['position'=>'1','track_title'=>'Love - Hate','duration'=>'4:32']],
    ['A2 Название (3:15)',['position'=>'A2','track_title'=>'Название','duration'=>'3:15']],
    ['1. Название',['position'=>'1','track_title'=>'Название','duration'=>'']],
    ['99 Luftballons',['position'=>'','track_title'=>'99 Luftballons','duration'=>'']],
    ["B2\tНазвание - Remix\t1:03:15",['position'=>'B2','duration'=>'1:03:15','track_title'=>'Название - Remix']],
] as [$input,$expected]) {
    $result=parse_track_line($input);
    foreach($expected as $key=>$value) same($result[$key],$value,'Parsing '.$input.' / '.$key);
}
$raw=[['position'=>'','track_title'=>'A1 - Реальное название - 4:32','duration'=>''],['position'=>'B1','track_title'=>'Трек','duration'=>'9:59']];
same(tracklist_to_text(parse_tracklist(tracklist_to_text($raw))),tracklist_to_text($raw),'Tab roundtrip');
same(normalize_legacy_track(['position'=>'','duration'=>'','track_title'=>$raw[0]['track_title']])['track_title'],$raw[0]['track_title'],'Do not reparse a structured title');
same(normalize_legacy_track(['id'=>99,'position'=>null,'duration'=>null,'track_title'=>'A1 - Первый - 3:14'])['position'],'A1','Read legacy rows');
same(validate_tracks([['track_title'=>'Первый','position'=>'A1','duration'=>'3:14'],['track_title'=>'']])[0]['sort_order'],1,'Ignore empty rows');
foreach(['3:99','1:65:00','no','-3:00'] as $duration) {
    try {validate_tracks([['track_title'=>'Title','duration'=>$duration]]);throw new RuntimeException('Invalid duration accepted: '.$duration);}
    catch(InvalidArgumentException $e){$checks++;}
}
try{validate_tracks([['position'=>'A1']]);throw new RuntimeException('Missing title accepted');}catch(InvalidArgumentException $e){$checks++;}
$fixture=[
    ['type_'=>'heading','title'=>'Side A'],
    ['type_'=>'track','position'=>'A1','title'=>'Название - версия','duration'=>'4:32'],
    ['type_'=>'index','position'=>'B1','title'=>'Suite','duration'=>'10:00','sub_tracks'=>[
        ['type_'=>'track','position'=>'B1a','title'=>'Part I','duration'=>'3:00'],
        ['type_'=>'track','position'=>'B1b','title'=>'Part II','duration'=>''],
    ]],
];
$tracks=discogs_tracklist_to_tracks($fixture);
same(count($tracks),3,'Discogs headings and index');
same($tracks[1]['position'],'B1a','Discogs subtrack position');
same($tracks[2]['duration'],'','Do not invent duration');
same(tracklist_duration($tracks),'7:32 · указана не для всех треков','Partial duration');
$form=discogs_release_to_form_data(['id'=>123,'title'=>'Album','artists'=>[['name'=>'Artist']],'tracklist'=>$fixture]);
same($form['tracks'],$tracks,'Mapper keeps structured tracks');
same(parse_tracklist($form['tracklist_text'])[0]['track_title'],'Название - версия','Mapper canonical text');

if(in_array('--db',$argv,true)) {
    require_once __DIR__.'/../app/db.php';
    $before=db()->query('SELECT COUNT(*) FROM records')->fetchColumn();
    db()->beginTransaction();
    try {
        $insert=db()->query("INSERT INTO records (artist,album_title,storage_status) VALUES ('__LOCAL_TEST__','Test','in_stock') RETURNING id");
        $id=(int)$insert->fetchColumn();
        $rows=validate_tracks($raw);save_record_tracks($id,$rows);
        $stored=tracks_for_record($id);
        same($stored[0]['track_title'],$raw[0]['track_title'],'DB title not reparsed');
        $firstId=(int)$stored[0]['id'];
        $secondId=(int)$stored[1]['id'];
        $stored=array_reverse($stored);$stored[0]['duration']='3:45';
        save_record_tracks($id,validate_tracks($stored));
        $read=tracks_for_record($id);
        same((int)$read[0]['id'],$secondId,'Reorder preserves ID');
        same($read[0]['duration'],'3:45','Duration updated');
        same((int)$read[1]['id'],$firstId,'Other track ID stable');
        save_record_tracks($id,validate_tracks([$read[0]]));
        same(count(tracks_for_record($id)),1,'Explicit removed row');
        db()->rollBack();
        same(db()->query('SELECT COUNT(*) FROM records')->fetchColumn(),$before,'Rollback leaves collection unchanged');
    } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
}
echo "PASS: {$checks} checks\n";
