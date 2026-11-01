<?php
// Exit if uninstall was not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'wdc_weekly_cleanup' );
delete_option( 'wdc_settings' );
delete_option( 'wdc_last_run' );
delete_transient( 'wdc_last_result' );
