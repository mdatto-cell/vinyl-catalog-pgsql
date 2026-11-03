<?php
declare(strict_types=1);
// php tests/audio_test.php [dir with t.flac t.wav t.mp3]
require_once __DIR__ . '/../app/audio.php';
$fail = 0; $n = 0;
$eq = function ($a, $b, string $m) use (&$fail, &$n) { $n++; if ($a !== $b) { $fail++; echo "FAIL $m: ", var_export($a, true), ' !== ', var_export($b, true), "\n"; } };
$eq(music_dir_name(['artist'=>'Александр Малинин','album_title'=>'Неприкаянный','album_year'=>1990,'release_year'=>1991]), 'Александр_Малинин_-_[1990]_-_Неприкаянный', 'dir name');
$eq(music_dir_name(['artist'=>'AC/DC','album_title'=>'Back In Black','album_year'=>null,'release_year'=>1980]), 'AC_DC_-_[1980]_-_Back_In_Black', 'dir name fallback year, slash');
$eq(music_dir_name(['artist'=>'a-ha','album_title'=>'Lifelines','album_year'=>null,'release_year'=>null]), 'a-ha_-_Lifelines', 'dir name no year');
$eq(track_audio_file_name(3, 'Кладбище Сент-Женевьев-Де-Буа', 'flac'), '03_Кладбище_Сент-Женевьев-Де-Буа.flac', 'file name');
$eq(track_audio_file_name(12, 'What?! / Why: "x"', 'mp3'), '12_What_Why_x.mp3', 'file name special');
$eq(audio_slug('../../etc'), 'etc', 'no traversal');
$eq(audio_slug('   '), 'Без_названия', 'empty');
$eq(audio_public_url('/uploads/music/A_[1990]-_Б/01_В Г.flac'), '/uploads/music/A_%5B1990%5D-_%D0%91/01_%D0%92%20%D0%93.flac', 'url');
$eq(audio_fs_path('/uploads/music/../config.php'), null, 'fs traversal');
$eq(audio_fs_path('/etc/passwd'), null, 'fs outside');
$eq(music_dir_fs_path('../x'), null, 'dir traversal');
$dir = $argv[1] ?? null;
if ($dir) {
    $eq(detect_audio_format("$dir/t.flac"), 'flac', 'flac');
    $eq(detect_audio_format("$dir/t.wav"), 'wav', 'wav');
    $eq(detect_audio_format("$dir/t.mp3"), 'mp3', 'mp3');
    $eq(detect_audio_format("$dir/fake.flac"), null, 'fake');
}
echo $fail ? "FAILED $fail of $n\n" : "PASS: $n checks\n";
exit($fail ? 1 : 0);
