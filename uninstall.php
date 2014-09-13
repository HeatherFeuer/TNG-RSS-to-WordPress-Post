<?php
if(!defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}

delete_option('tng_wp_rss_last_read');
wp_clear_scheduled_hook('tng_wp_rss_update');