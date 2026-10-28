<?php namespace ProcessWire;

/**
 * Command-line queue runner for PdfAutoTag.
 *
 * The module configuration screen shows a crontab line for this file.
 *
 * The script continues PDFs that a person already requested. It does not
 * send newly uploaded files to Adobe on its own.
 */

if(PHP_SAPI !== 'cli') {
	if(!headers_sent()) header('HTTP/1.1 403 Forbidden');
	echo "PDF Auto-Tag cron only runs from the command line.\n";
	exit(1);
}

$root = dirname(__DIR__, 3);
include $root . DIRECTORY_SEPARATOR . 'index.php';

if(function_exists('set_time_limit')) set_time_limit(300);

$modules = wire('modules');
$pdfAutoTag = $modules ? $modules->get('PdfAutoTag') : null;

if(!$pdfAutoTag) {
	echo "PdfAutoTag is not installed.\n";
	exit(1);
}

$result = $pdfAutoTag->executeQueue();

if(!empty($result['locked'])) {
	echo "Queue is already running.\n";
	exit(0);
}

echo 'Started: ' . (int) $result['started'] . "\n";
echo 'Finished: ' . (int) $result['finished'] . "\n";
echo 'Failed: ' . (int) $result['failed'] . "\n";
echo 'Reports removed: ' . (int) $result['reportsRemoved'] . "\n";
echo 'Originals removed: ' . (int) $result['backupsRemoved'] . "\n";
exit(0);
