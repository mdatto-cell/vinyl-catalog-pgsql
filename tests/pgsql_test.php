<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !in_array('--test-db',$argv,true)) exit('Use only a disposable DB: php tests/pgsql_test.php --test-db'.PHP_EOL);
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/tracks.php';
function ck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$p=db();
$v=(int)$p->query('SHOW server_version_num')->fetchColumn();
ck($v>=150000 && $v<190000,'Expected PostgreSQL 15-18');
$p->beginTransaction();
try {
 $s=$p->prepare('INSERT INTO records(artist,album_title,genre_name,updated_at) VALUES (?,?,?,?) RETURNING id');
 $s->execute(['ТЕСТ Artist','Album','Rock, Electronic','2000-01-01 00:00:00']);$id=(int)$s->fetchColumn();ck($id>0,'RETURNING id');
 $s=$p->prepare('SELECT id FROM records WHERE id=? AND artist ILIKE ?');$s->execute([$id,'%тест artist%']);ck((int)$s->fetchColumn()===$id,'Cyrillic/Latin search: check DB locale');
 $s=$p->prepare("SELECT id FROM records WHERE id=? AND lower(?)=ANY(string_to_array(lower(REPLACE(COALESCE(genre_name,''), ', ', ',')), ','))");
 $s->execute([$id,'electronic']);ck((int)$s->fetchColumn()===$id,'Genre');
 $s->execute([$id,'electro']);ck($s->fetchColumn()===false,'Whole genre token');
 $s=$p->prepare('UPDATE records SET notes=? WHERE id=? RETURNING updated_at');$s->execute(['changed',$id]);ck($s->fetchColumn()!=='2000-01-01 00:00:00','updated_at');
 save_record_tracks($id,validate_tracks([['position'=>'A1','track_title'=>'One','duration'=>'3:00'],['position'=>'A2','track_title'=>'Two','duration'=>'4:00']]));
 $r=tracks_for_record($id);ck(count($r)===2,'Tracks');$first=(int)$r[0]['id'];$second=(int)$r[1]['id'];
 save_record_tracks($id,validate_tracks(array_reverse($r)));$r=tracks_for_record($id);ck((int)$r[0]['id']===$second && (int)$r[1]['id']===$first,'Stable track IDs');
 $s=$p->prepare('INSERT INTO record_photos(record_id,file_name,file_path,is_primary) VALUES (?,?,?,?)');$s->execute([$id,'test.jpg','/uploads/records/test.jpg',1]);
 $s=$p->prepare("UPDATE record_tracks SET audio_path=?,audio_format='flac',audio_size=?,audio_uploaded_at=NOW() WHERE id=?");$s->execute(['/uploads/music/test/one.flac','5368709120',$first]);
 $s=$p->prepare('SELECT CAST(EXISTS(SELECT 1 FROM record_tracks WHERE record_id=? AND audio_path IS NOT NULL) AS integer)');$s->execute([$id]);ck((int)$s->fetchColumn()===1,'Audio badge');
 $s=$p->prepare('DELETE FROM records WHERE id=?');$s->execute([$id]);
 foreach(['record_tracks','record_photos'] as $t){$s=$p->prepare('SELECT COUNT(*) FROM '.$t.' WHERE record_id=?');$s->execute([$id]);ck((int)$s->fetchColumn()===0,'Cascade '.$t);}
 $p->rollBack();echo 'PASS PostgreSQL '.$v.PHP_EOL;
} catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
