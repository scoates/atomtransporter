#!/usr/bin/env php
<?php
if (count($argv) != 3) {
	echo "Usage: {$argv[0]} from_url to_url\n";
	exit(1);
}

$fromUrl = $argv[1];
$toUrl = $argv[2];


$textPath = __DIR__ . DIRECTORY_SEPARATOR . 'text';
$it = new DirectoryIterator($textPath);
foreach ($it as $f) {
	if ($f->isDot()) {
		// skip "." and ".."
		continue;
	}
	$dataFile = $textPath . DIRECTORY_SEPARATOR . $f->getFilename() . DIRECTORY_SEPARATOR . 'data.json';
	if (!file_exists($dataFile)) {
		echo "Skipping {$textPath}" . DIRECTORY_SEPARATOR . "{$f} (no data.json)\n";
		continue;
	}
	$data = json_decode(file_get_contents($dataFile));
	if (!$data) {
		echo "Error decoding data file in {$dataFile}\n";
		exit;
	}
	if (strpos($data->edit_url, $fromUrl) !== 0) {
		echo "from_url not found in {$f}; skipping\n";
		continue;
	}
	$data->edit_url = str_replace($fromUrl, $toUrl, $data->edit_url);
	file_put_contents($dataFile, json_encode($data));
	echo "Replaced in {$f}\n";
}
