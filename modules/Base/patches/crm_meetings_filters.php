<?php
defined("_VALID_ACCESS") || die('Direct access forbidden');
if (ModuleManager::is_installed('CRM_Meeting')==-1) return;

Utils_RecordBrowserCommon::new_filter('crm_meeting', 'Date');

?>
