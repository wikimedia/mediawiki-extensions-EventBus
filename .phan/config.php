<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// phan takes "= null" on type hinted arguments as optionl, but it declares the nullable
$cfg['suppress_issue_types'][] = 'PhanParamReqAfterOpt';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/CentralNotice',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/CentralNotice',
	]
);

return $cfg;
