<?php
/**
 * index.php : Entry point aplikasi web HRIS MPP berbasis PHP.
 * Buka di browser via XAMPP: http://localhost/solihin_appsrcipt/
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';

$appName = htmlspecialchars(get_setting('app_nama', APP_NAME), ENT_QUOTES, 'UTF-8');
$styles = file_get_contents(__DIR__ . '/Styles.html');
$app = file_get_contents(__DIR__ . '/App.html');
$index = file_get_contents(__DIR__ . '/Index.html');

$output = str_replace(['<?= appName ?>', 'HRIS MPP — IEG'], $appName, $index);
$output = str_replace(["<?!= include('Styles'); ?>", '<!-- INCLUDE_STYLES -->'], $styles, $output);
$output = str_replace(["<?!= include('App'); ?>", '<!-- INCLUDE_APP -->'], $app, $output);

echo $output;

