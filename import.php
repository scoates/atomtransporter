#!/usr/bin/env php
<?php

// process command line options
$shortOpts = array(
	'help' => 'h',
	'force' => 'f',
	'user' => 'u:',
	'pass' => 'p:',
	'atomurl' => 'a:',
	'entry' => 'e:',
	'basepath' => 'b:',
);

$longOpts = array(
	'help' => 'help',
	'force' => 'force',
	'user' => 'user:',
	'pass' => 'pass:',
	'atomurl' => 'atomurl:',
	'entry' => 'entry:',
	'basepath' => 'basepath:',
);

$parsedOpts = getopt(implode('', array_values($shortOpts)), array_values($longOpts));

// trim the colons
$trimFunc = function (&$val) { $val = rtrim($val, ':'); };
array_walk($shortOpts, $trimFunc); 
array_walk($longOpts, $trimFunc); 

// default options
$opts = array(
	'force' => false,
	'user' => getenv('ATOM_USER'), // default from ENV
	'pass' => getenv('ATOM_PASS'), // default from ENV
	'basepath' => dirname(__FILE__) . '/text',
);

// normalize short and long options
foreach (array_keys($longOpts) as $name) {
	if (isset($parsedOpts[$longOpts[$name]])) {
		if (is_bool($parsedOpts[$longOpts[$name]])) {
			$parsedOpts[$longOpts[$name]] = true;
		}
		$opts[$name] = $parsedOpts[$longOpts[$name]];
	} elseif (isset($parsedOpts[$shortOpts[$name]])) {
		if (is_bool($parsedOpts[$shortOpts[$name]])) {
			$parsedOpts[$shortOpts[$name]] = true;
		}
		$opts[$name] = $parsedOpts[$shortOpts[$name]];
	}
}

// display help if requested, or if missing credentials
if (isset($opts['help']) || !isset($opts['user']) || !isset($opts['pass'])) {
	echo <<<ENDHELP
Usage: {$_SERVER['argv'][0]} [options]
	-h --help         Help (this message)
	-u <username>     Username to use for Atom authentication
	--user=<username>  (will also retrieve from \$ATOM_USER if available)
	-p <password>     Password to use for Atom authentication
	--pass=<password>  (will also retrieve from \$ATOM_USER if available)
	-f --force        Force import (normally skips unchanged entries)
	-a <url>          The default URL to use for the Atom endpoint
	--atomurl=<url>    (only necessary for additions, not edits)
	-e <name>         The entry name to import
	--entry=<name>     (defaults to all entries in basepath; implies --force)
	-b <path>         The base path to scan if --entry is not provided
	--basepath=<path>

ENDHELP;
	exit(1);
}

// validate the base path
if (isset($opts['basepath'])) {
	if (!file_exists($opts['basepath'])) {
		echo "Invalid base path: {$opts['basepath']}\n";
		exit(2);
	}
}

// if the user supplies an entry, validate, imply force, prep array
if (isset($opts['entry'])) {
	$opts['force'] = true;
	$path = $opts['basepath'] . DIRECTORY_SEPARATOR . $opts['entry'];
	if (!file_exists($path . DIRECTORY_SEPARATOR . $opts['entry'] . '.txt')) {
		echo "Entry does not exist: {$opts['entry']}\n";
		exit(3);
	}
	$entries = array($path);
} else {
	$entries = glob($opts['basepath'] . '/*');
}

// counters
$imported = $skipped = 0;

echo 'Importing ' . count($entries) . " entries\n";

// iterate the found entries
foreach ($entries as $entry) {

	$slug = substr($entry, strrpos($entry, '/') + 1);
	echo "Reading $slug... ";

	$dataFile = $entry . '/data.json';
	$contentFile = "{$entry}/{$slug}.txt";
	if (is_readable($dataFile)) {
		// file was exported, so this operation is an update/edit
		$data = json_decode(file_get_contents($dataFile));
		$mtime = filemtime($contentFile);
		if (!$opts['force'] && $mtime == $data->mtime) {
			echo "no change; not importing\n";
			++$skipped;
			continue;
		}

		$method = "PUT";
		$atomUrl = $data->edit_url;

		$xml = simplexml_load_file($data->edit_url);

	} else {
		// data file doesn't exist, so this must be a new entry
		// but skip it if we're not in force mode
		
		if (!$opts['force']) {
			echo "Not importing new entry {$entry}; use --force\n";
			++$skipped;
			continue;
		}

		$xml = simplexml_load_string('<?xml version="1.0"?><entry xmlns="http://www.w3.org/2005/Atom"></entry>');

		$titleFile = $entry . '/title.txt';
		if (!is_readable($titleFile)) {
			echo "Can't import new entry {$entry}: missing title.txt\n";
			continue;
		}

		$xml->title = trim(file_get_contents($titleFile));

		// tags
		$tagsFile = $entry . '/tags.txt';
		if (is_readable($tagsFile)) {
			foreach (file($tagsFile) as $tag) {
				$cat = $xml->addChild('category');
				$cat['term'] = trim($tag);
			}
		}

		$method = "POST";
		// set the base URL
		$atomUrl = $opts['atomurl'];

	}

	// add username/password to the URL
	$url = parse_url($atomUrl);
	$serviceUrl = $url['scheme'] . '://' .
	$opts['user'] . ':' . $opts['pass'] . '@' .
	$url['host'] . $url['path'];
	if (isset($url['query']) && $url['query']) {
		$serviceUrl .= '?' . $url['query'];
	}


	// replace the content and the updated date
	$xml->content = file_get_contents($contentFile);
	$xml->updated = date('c');

	// set up the stream context
	$strXml = $xml->asXml();
	$streamopts = array(
		'http' => array(
			'method' => $method,
			'header' => "Content-type: application/atom+xml\r\n" .
						"Content-length: " . strlen($strXml) . "\r\n" .
						"Connection: close\r\n",
			'content' => $strXml,
		)
	);
	$context = stream_context_create($streamopts);

	echo "Writing data to {$serviceUrl}\n";
	$result = file_get_contents($serviceUrl, 0, $context);

	if (false === $result) {
		echo "FAIL\n";
		echo "URL: {$serviceUrl};\n";
		var_dump($result);
		die;
	}

	$data = array();

	if ('PUT' == $method) {
		// reload...
		$result = file_get_contents($serviceUrl);
	}

	$result = simplexml_load_string($result);

	if (!$result) {
		die("Can't parse XML\n");
	}
	foreach ($result->link as $link) {
		if ('alternate' == $link['rel']) {
			$data['url'] = (string)$link['href'];
			$data['slug'] = substr(
				$data['url'],
				strrpos($data['url'], '/') + 1
			);
		} elseif ('edit' == $link['rel']) {
			$data['edit_url'] = (string)$link['href']; 
		}
	}
	$data['title'] = (string)$result->title;
	$contentFileBak = "{$entry}/{$slug}.bak";
	copy($contentFile, $contentFileBak);

	file_put_contents($contentFile, (string)$result->content);
	$data['mtime'] = filemtime($contentFile);
	file_put_contents("{$entry}/data.json", json_encode($data));

	if (isset($titleFile) && file_exists($titleFile)) {
		unlink($titleFile);
	}

	echo "OK\n";
	++$imported;

	file_put_contents($dataFile, json_encode($data));
}

echo "Imported {$imported}; Skipped {$skipped}\n";
echo "Done.\n";

