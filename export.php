#!/usr/bin/env php
<?php

$atomEndpoint = $_SERVER['argv'][1];
$basePath = dirname(__FILE__) . '/text';

$page = 1;
while (true) {
	$xml = simplexml_load_file($atomEndpoint . '/page/' . $page);
	if (isset($xml->entry)) {
		echo "Exporting page $page...\n";
		foreach ($xml->entry as $entry) {
			$entryData = array();
			foreach ($entry->link as $link) {
				if ('alternate' == $link['rel']) {
					$entryData['url'] = (string)$link['href'];
					$entryData['slug'] = substr(
						$entryData['url'],
						strrpos($entryData['url'], '/') + 1
					);
				} elseif ('edit' == $link['rel']) {
					$entryData['edit_url'] = (string)$link['href']; 
				}
			}
			$entryData['title'] = (string)$entry->title;
			echo "Exporting entry {$entryData['slug']}... ";
			$dir = "{$basePath}/{$entryData['slug']}";
			if (!file_exists($dir)) {
				mkdir($dir);
			}

			$contentFile = "{$dir}/{$entryData['slug']}.txt";
			if (file_exists($contentFile)) {
				$contentFileBak = "{$dir}/{$entryData['slug']}.bak";
				copy($contentFile, $contentFileBak);
			}
			file_put_contents($contentFile, (string)$entry->content);

			$tagsFile = "{$dir}/tags.txt";
			$tags = array();
			foreach ($entry->category as $category) {
				$tags[] = (string)$category['term'];
			}
			file_put_contents($tagsFile, implode("\n", $tags));

			$entryData['mtime'] = filemtime($contentFile);
			file_put_contents("{$dir}/data.json", json_encode($entryData));
			touch($dir, strtotime((string)$entry->updated));
			echo "OK\n";
		}
		++$page;
	} else {
		break;
	}
}
