<?php
/**
 * Plugin Name:       Database Cleaner
 * Plugin URI:        https://github.com/Azanumer/wp-database-cleaner
 * Description:       Scheduled database housekeeping: expired transients, orphaned postmeta, spam/trash comments, revisions and auto-drafts — with a dry-run report before anything is deleted.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wp-database-cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDC_VERSION', '1.0.0' );
define( 'WDC_HOOK', 'wdc_weekly_cleanup' );
define( 'WDC_OPTION', 'wdc_settings' );

/**
 * Default settings.
 *
 * @return array
 */
function wdc_get_settings() {
	$defaults = array(
		'expired_transients' => 1,
		'orphaned_postmeta'  => 1,
		'spam_comments'      => 1,
		'trash_comments'     => 1,
		'revision_age_days'  => 30,
		'auto_drafts'        => 1,
	);
	$saved = get_option( WDC_OPTION, array() );
	return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
}

/**
 * Schedule the weekly cleanup on activation; clear it on deactivation.
 */
function wdc_activate() {
	if ( ! wp_next_scheduled( WDC_HOOK ) ) {
		wp_schedule_event( time(), 'weekly', WDC_HOOK );
	}
}
register_activation_hook( __FILE__, 'wdc_activate' );

function wdc_deactivate() {
	wp_clear_scheduled_hook( WDC_HOOK );
}
register_deactivation_hook( __FILE__, 'wdc_deactivate' );

/**
 * Count how many rows each cleanup task would remove (dry-run).
 *
 * @return array Task => row count.
 */
function wdc_count_cleanup() {
	global $wpdb;
	$settings = wdc_get_settings();
	$counts = array();

	if ( $settings['expired_transients'] ) {
		$counts['expired_transients'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
		);
	}

	if ( $settings['orphaned_postmeta'] ) {
		$counts['orphaned_postmeta'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
		);
	}

	if ( $settings['spam_comments'] ) {
		$counts['spam_comments'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", 'spam' )
		);
	}

	if ( $settings['trash_comments'] ) {
		$counts['trash_comments'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", 'trash' )
		);
	}

	if ( $settings['revision_age_days'] > 0 ) {
		$counts['old_revisions'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$settings['revision_age_days']
			)
		);
	}

	if ( $settings['auto_drafts'] ) {
		$counts['auto_drafts'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'auto-draft' )
		);
	}

	return $counts;
}

/**
 * Run the cleanup. Returns rows deleted per task.
 *
 * @return array
 */
function wdc_run_cleanup() {
	global $wpdb;
	$settings = wdc_get_settings();
	$deleted = array();

	if ( $settings['expired_transients'] ) {
		// Delete the timeout rows first, then their matching value rows.
		$timeouts = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
		);
		$count = 0;
		foreach ( $timeouts as $timeout_name ) {
			$base = substr( $timeout_name, strlen( '_transient_timeout_' ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $timeout_name ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $base ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => '_site_transient_' . $base ) );
			$count++;
		}
		$deleted['expired_transients'] = $count;
	}

	if ( $settings['orphaned_postmeta'] ) {
		$deleted['orphaned_postmeta'] = $wpdb->query(
			"DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
		);
	}

	if ( $settings['spam_comments'] ) {
		$deleted['spam_comments'] = $wpdb->delete(
			$wpdb->comments,
			array( 'comment_approved' => 'spam' )
		);
	}

	if ( $settings['trash_comments'] ) {
		$deleted['trash_comments'] = $wpdb->delete(
			$wpdb->comments,
			array( 'comment_approved' => 'trash' )
		);
	}

	if ( $settings['revision_age_days'] > 0 ) {
		$deleted['old_revisions'] = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$settings['revision_age_days']
			)
		);
	}

	if ( $settings['auto_drafts'] ) {
		$deleted['auto_drafts'] = $wpdb->delete(
			$wpdb->posts,
			array( 'post_status' => 'auto-draft' )
		);
	}

	// Optimise the tables we touched — cheap and keeps autoload fast.
	$wpdb->query( "OPTIMIZE TABLE {$wpdb->options}, {$wpdb->postmeta}, {$wpdb->comments}, {$wpdb->posts}" );

	update_option( 'wdc_last_run', array( 'time' => time(), 'deleted' => $deleted ) );
	return $deleted;
}
add_action( WDC_HOOK, 'wdc_run_cleanup' );

/**
 * Tools page: dry-run report + one-click cleanup + settings.
 */
function wdc_register_tools_page() {
	add_management_page(
		__( 'Database Cleaner', 'wp-database-cleaner' ),
		__( 'Database Cleaner', 'wp-database-cleaner' ),
		'manage_options',
		'wdc-cleaner',
		'wdc_render_tools_page'
	);
}
add_action( 'admin_menu', 'wdc_register_tools_page' );

/**
 * Handle the "Run cleanup now" button.
 */
function wdc_handle_manual_run() {
	if ( ! isset( $_POST['wdc_run'] ) || ! check_admin_referer( 'wdc_run_cleanup' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions.', 'wp-database-cleaner' ) );
	}
	$deleted = wdc_run_cleanup();
	set_transient( 'wdc_last_result', $deleted, 60 );
	wp_safe_redirect( admin_url( 'tools.php?page=wdc-cleaner&ran=1' ) );
	exit;
}
add_action( 'admin_init', 'wdc_handle_manual_run' );

/**
 * Render the Tools page.
 */
function wdc_render_tools_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions.', 'wp-database-cleaner' ) );
	}

	// Save settings from the same page.
	if ( isset( $_POST['wdc_save_settings'] ) && check_admin_referer( 'wdc_save_settings' ) ) {
		$input = isset( $_POST[ WDC_OPTION ] ) && is_array( $_POST[ WDC_OPTION ] ) ? wp_unslash( $_POST[ WDC_OPTION ] ) : array();
		$clean = array(
			'expired_transients' => ! empty( $input['expired_transients'] ) ? 1 : 0,
			'orphaned_postmeta'  => ! empty( $input['orphaned_postmeta'] ) ? 1 : 0,
			'spam_comments'      => ! empty( $input['spam_comments'] ) ? 1 : 0,
			'trash_comments'     => ! empty( $input['trash_comments'] ) ? 1 : 0,
			'auto_drafts'        => ! empty( $input['auto_drafts'] ) ? 1 : 0,
			'revision_age_days'  => max( 0, (int) ( $input['revision_age_days'] ?? 30 ) ),
		);
		update_option( WDC_OPTION, $clean );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'wp-database-cleaner' ) . '</p></div>';
	}

	$settings = wdc_get_settings();
	$counts = wdc_count_cleanup();
	$labels = array(
		'expired_transients' => __( 'Expired transients', 'wp-database-cleaner' ),
		'orphaned_postmeta'  => __( 'Orphaned postmeta', 'wp-database-cleaner' ),
		'spam_comments'      => __( 'Spam comments', 'wp-database-cleaner' ),
		'trash_comments'     => __( 'Trashed comments', 'wp-database-cleaner' ),
		'old_revisions'      => __( 'Old revisions', 'wp-database-cleaner' ),
		'auto_drafts'        => __( 'Auto-drafts', 'wp-database-cleaner' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Database Cleaner', 'wp-database-cleaner' ); ?></h1>
		<p><?php esc_html_e( 'Runs automatically every week via WP-Cron. Nothing is deleted without appearing in the dry-run report below first.', 'wp-database-cleaner' ); ?></p>

		<?php if ( isset( $_GET['ran'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Cleanup complete.', 'wp-database-cleaner' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Dry-run report', 'wp-database-cleaner' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Item', 'wp-database-cleaner' ); ?></th><th><?php esc_html_e( 'Rows that would be removed', 'wp-database-cleaner' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $labels as $key => $label ) : ?>
				<?php if ( isset( $counts[ $key ] ) ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></td></tr>
				<?php endif; ?>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top: 1em;">
			<?php wp_nonce_field( 'wdc_run_cleanup' ); ?>
			<?php submit_button( __( 'Run cleanup now', 'wp-database-cleaner' ), 'primary', 'wdc_run' ); ?>
		</form>

		<h2><?php esc_html_e( 'Settings', 'wp-database-cleaner' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'wdc_save_settings' ); ?>
			<table class="form-table">
				<?php foreach ( array( 'expired_transients', 'orphaned_postmeta', 'spam_comments', 'trash_comments', 'auto_drafts' ) as $key ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $labels[ $key ] ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( WDC_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $settings[ $key ], 1 ); ?>> <?php esc_html_e( 'Clean on schedule', 'wp-database-cleaner' ); ?></label></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Delete revisions older than (days, 0 = keep all)', 'wp-database-cleaner' ); ?></th>
					<td><input type="number" min="0" name="<?php echo esc_attr( WDC_OPTION ); ?>[revision_age_days]" value="<?php echo esc_attr( $settings['revision_age_days'] ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'wp-database-cleaner' ), 'secondary', 'wdc_save_settings' ); ?>
		</form>
	</div>
	<?php
}
