<?php
/*
Plugin Name: Zero Analytics
Plugin URI: https://github.com/webguyio/zero-analytics
Description: Lightweight, GDPR-compliant analytics. No cookies, no personal data, no consent banner required.
Version: 0.1
Author: Web Guy
Author URI: https://webguy.io/
Requires at least: 6.0
Requires PHP: 8.0
License: CC0
License URI: https://creativecommons.org/public-domain/cc0/
Text Domain: zero-analytics
*/

if ( !defined( 'ABSPATH' ) ) {
	status_header( 404 );
	exit;
}

define( 'ZEROA_VERSION', '0.1' );
define( 'ZEROA_TABLE',   'zero_analytics' );

// Endpoint file
require_once plugin_dir_path( __FILE__ ) . 'zero-analytics-endpoint.php';

// Installation / Uninstallation
register_activation_hook( __FILE__, 'zeroa_activate' );
register_uninstall_hook( __FILE__, 'zeroa_uninstall' );

function zeroa_activate(): void {
	global $wpdb;
	$table   = $wpdb->prefix . ZEROA_TABLE;
	$charset = $wpdb->get_charset_collate();
	$sql     = "CREATE TABLE IF NOT EXISTS {$table} (
		id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		path         VARCHAR(500)    NOT NULL DEFAULT '',
		referrer_type VARCHAR(20)    NOT NULL DEFAULT 'direct',
		referrer_name VARCHAR(50)    DEFAULT NULL,
		device_type  TINYINT         NOT NULL DEFAULT 0,
		country_code CHAR(2)         DEFAULT NULL,
		status_code  SMALLINT        NOT NULL DEFAULT 200,
		is_bot       TINYINT(1)      NOT NULL DEFAULT 0,
		is_unique    TINYINT(1)      NOT NULL DEFAULT 0,
		recorded_at  DATETIME        NOT NULL,
		PRIMARY KEY (id),
		KEY idx_path (path(191)),
		KEY idx_recorded_at (recorded_at),
		KEY idx_is_bot (is_bot)
	) {$charset};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	// Daily cron: rotate unique-visitor salt
	if ( !wp_next_scheduled( 'zeroa_rotate_salt' ) ) {
		wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'zeroa_rotate_salt' );
	}
}

function zeroa_uninstall(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uninstall cleanup; table name is our own prefixed constant, not user input
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . ZEROA_TABLE );
	delete_option( 'zeroa_salt' );
	delete_option( 'zeroa_salt_date' );
	wp_clear_scheduled_hook( 'zeroa_rotate_salt' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uninstall cleanup of transients by prefix; no WP API exists for wildcard transient deletion
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_zeroa_seen_%' OR option_name LIKE '_transient_timeout_zeroa_seen_%'" );
}

// Salt Management
add_action( 'zeroa_rotate_salt', 'zeroa_rotate_salt' );
function zeroa_get_salt(): string {
	$today = gmdate( 'Y-m-d' );
	if ( get_option( 'zeroa_salt_date' ) !== $today ) {
		zeroa_rotate_salt();
	}
	return (string) get_option( 'zeroa_salt', '' );
}

function zeroa_rotate_salt(): void {
	update_option( 'zeroa_salt', bin2hex( random_bytes( 16 ) ), false );
	update_option( 'zeroa_salt_date', gmdate( 'Y-m-d' ), false );
}

// Tracking
add_action( 'template_redirect', 'zeroa_track_request', 999 );
add_action( 'wp_footer', 'zeroa_output_pixel', 1 );

function zeroa_track_request(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( zeroa_get_path() === '/zeroa' ) {
		return;
	}
	$status = http_response_code();
	if ( !is_int( $status ) || $status < 400 ) {
		return;
	}
	if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === $_SERVER['HTTP_SEC_GPC'] ) {
		return;
	}
	global $wpdb;
	$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$path = zeroa_get_path();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- performance-critical insert, no caching layer appropriate
	$wpdb->insert(
		$wpdb->prefix . ZEROA_TABLE,
		[
			'path'          => $path,
			'referrer_type' => zeroa_get_referrer_type(),
			'referrer_name' => zeroa_get_referrer_name(),
			'device_type'   => zeroa_get_device_type( $ua ),
			'country_code'  => zeroa_get_country(),
			'status_code'   => $status,
			'is_bot'        => zeroa_is_bot( $ua ) ? 1 : 0,
			'is_unique'     => 0,
			'recorded_at'   => current_time( 'mysql', true ),
		],
		[ '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ]
	);
	$GLOBALS['zeroa_tracked'] = true;
}

function zeroa_output_pixel(): void {
	if ( is_admin() || current_user_can( 'edit_posts' ) || !empty( $GLOBALS['zeroa_tracked'] ) ) {
		return;
	}
	$url = esc_url( add_query_arg( [
		'path' => zeroa_get_path(),
	], home_url( 'zeroa' ) ) );
	$js = '(function(){var r="";try{r=new URL(document.referrer).hostname}catch(e){}var u=' . wp_json_encode( $url ) . '+"&ref="+encodeURIComponent(r)+"&t="+Date.now()+Math.random().toString(36).slice(2);if(navigator.sendBeacon){navigator.sendBeacon(u);}else{new Image().src=u;}})();';
	wp_register_script( 'zeroa-tracker', false, [], ZEROA_VERSION, [ 'in_footer' => true ] );
	wp_enqueue_script( 'zeroa-tracker' );
	wp_add_inline_script( 'zeroa-tracker', $js );
}

// Country Detection
function zeroa_get_country(): ?string {
	$headers = [
		'HTTP_CF_IPCOUNTRY',
		'GEOIP_COUNTRY_CODE',
		'MM_COUNTRY_CODE',
		'HTTP_X_COUNTRY_CODE',
		'HTTP_CF_IPV6_COUNTRY',
	];
	foreach ( $headers as $header ) {
		$val = isset( $_SERVER[ $header ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) : '';
		if ( $val && preg_match( '/^[A-Z]{2}$/', $val ) && 'XX' !== $val && 'T1' !== $val ) {
			return $val;
		}
	}
	// Some hosts inject this directly into the PHP environment rather than as an HTTP header
	$env = function_exists( 'getenv' ) ? getenv( 'GEOIP_COUNTRY_CODE' ) : false;
	if ( $env && preg_match( '/^[A-Z]{2}$/', strtoupper( $env ) ) ) {
		return strtoupper( $env );
	}
	return null;
}

// Path
function zeroa_get_path(): string {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	$path = strtok( $path, '?' );
	$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
	if ( $base && '/' !== $base && str_starts_with( (string) $path, $base ) ) {
		$path = substr( (string) $path, strlen( $base ) );
	}
	return '/' . ltrim( substr( (string) $path, 0, 500 ), '/' );
}

// Device Type
function zeroa_get_device_type( string $ua ): int {
	if ( empty( $ua ) ) {
		return 0;
	}
	$ua_lower = strtolower( $ua );
	if ( preg_match( '/tablet|ipad|playbook|silk|(android(?!.*mobile))/i', $ua_lower ) ) {
		return 3; // tablet
	}
	if ( preg_match( '/mobile|iphone|ipod|android|blackberry|mini|windows\sce|palm/i', $ua_lower ) ) {
		return 2; // mobile
	}
	return 1; // desktop
}

// Bot Detection
function zeroa_is_bot( string $ua ): bool {
	if ( empty( $ua ) ) {
		return true;
	}
	return (bool) preg_match(
		'/adsbot|anthropic|applebot|axios|bingpreview|bot|bytespider|chatgpt|check|claude|cohere|crawl|curl|discord|embed|facebookexternalhit|feed|fetch|go-http|gptbot|googleother|headless|http|java|libwww|linkedinbot|lwp|mediapartners|monitor|node|openai|perplexity|phantom|ping|playwright|preload|preview|probe|puppeteer|python|reader|request|rss|ruby|scan|scrape|selenium|slack|slurp|spider|telegram|twitterbot|wget|whatsapp|wordpress\/|wp-cron/i',
		$ua
	);
}

// Referrer Handling

/**
 * Referrer whitelist. Keys are the stored name, values are domain fragments to match.
 * Only these referrers are classified; all others become 'direct'.
 */
function zeroa_referrer_map(): array {
	return [
		// Search engines
		'Google'     => [ 'type' => 'search', 'domains' => [ 'google.' ] ],
		'Bing'       => [ 'type' => 'search', 'domains' => [ 'bing.com' ] ],
		'DuckDuckGo' => [ 'type' => 'search', 'domains' => [ 'duckduckgo.com' ] ],
		'Yahoo'      => [ 'type' => 'search', 'domains' => [ 'yahoo.com' ] ],
		'Baidu'      => [ 'type' => 'search', 'domains' => [ 'baidu.com' ] ],
		'Yandex'     => [ 'type' => 'search', 'domains' => [ 'yandex.' ] ],
		'Ecosia'     => [ 'type' => 'search', 'domains' => [ 'ecosia.org' ] ],
		'Brave'      => [ 'type' => 'search', 'domains' => [ 'search.brave.com' ] ],
		'Kagi'       => [ 'type' => 'search', 'domains' => [ 'kagi.com' ] ],
		// Social
		'Facebook'   => [ 'type' => 'social', 'domains' => [ 'facebook.com', 'fb.com', 'm.facebook.com', 'l.facebook.com' ] ],
		'X'          => [ 'type' => 'social', 'domains' => [ 'x.com', 'twitter.com', 't.co' ] ],
		'Instagram'  => [ 'type' => 'social', 'domains' => [ 'instagram.com' ] ],
		'LinkedIn'   => [ 'type' => 'social', 'domains' => [ 'linkedin.com', 'lnkd.in' ] ],
		'Reddit'     => [ 'type' => 'social', 'domains' => [ 'reddit.com', 'redd.it' ] ],
		'Pinterest'  => [ 'type' => 'social', 'domains' => [ 'pinterest.com', 'pinterest.' ] ],
		'YouTube'    => [ 'type' => 'social', 'domains' => [ 'youtube.com', 'youtu.be' ] ],
		'TikTok'     => [ 'type' => 'social', 'domains' => [ 'tiktok.com' ] ],
		'Threads'    => [ 'type' => 'social', 'domains' => [ 'threads.net' ] ],
		'Mastodon'   => [ 'type' => 'social', 'domains' => [ 'mastodon.social', 'mastodon.online' ] ],
		'Bluesky'    => [ 'type' => 'social', 'domains' => [ 'bsky.app' ] ],
	];
}

function zeroa_get_referrer_host(): string {
	$ref = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	if ( empty( $ref ) ) {
		return '';
	}
	$host = wp_parse_url( $ref, PHP_URL_HOST );
	return $host ? strtolower( (string) $host ) : '';
}

function zeroa_classify_referrer( string $host ): ?array {
	if ( empty( $host ) ) {
		return null;
	}
	// Internal referrer
	$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( $own && str_contains( $host, $own ) ) {
		return [ 'type' => 'internal', 'name' => null ];
	}
	foreach ( zeroa_referrer_map() as $name => $entry ) {
		foreach ( $entry['domains'] as $domain ) {
			if ( str_contains( $host, $domain ) ) {
				return [ 'type' => $entry['type'], 'name' => $name ];
			}
		}
	}
	return null; // unknown treated as direct
}

function zeroa_get_referrer_classification(): ?array {
	static $classification = null;
	if ( null === $classification ) {
		$classification = zeroa_classify_referrer( zeroa_get_referrer_host() );
	}
	return $classification;
}

function zeroa_get_referrer_type(): string {
	$classify = zeroa_get_referrer_classification();
	return $classify['type'] ?? 'direct';
}

function zeroa_get_referrer_name(): ?string {
	$classify = zeroa_get_referrer_classification();
	return $classify['name'] ?? null;
}

function zeroa_pagination( string $section, int $page, bool $has_next, string $range ): void {
	?>
	<div class="zeroa-pagination">
		<?php if ( $page > 1 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'range' => $range, $section . '_paged' => $page - 1 ] ) ); ?>" class="button button-small">&larr;</a>
		<?php else : ?>
			<span class="button button-small zeroa-pagination-disabled">&larr;</span>
		<?php endif; ?>
		<span class="zeroa-pagination-page"><?php echo esc_html( $page ); ?></span>
		<?php if ( $has_next ) : ?>
			<a href="<?php echo esc_url( add_query_arg( [ 'range' => $range, $section . '_paged' => $page + 1 ] ) ); ?>" class="button button-small">&rarr;</a>
		<?php else : ?>
			<span class="button button-small zeroa-pagination-disabled">&rarr;</span>
		<?php endif; ?>
	</div>
	<?php
}

// Admin
add_action( 'admin_menu', 'zeroa_admin_menu', 999 );
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( 'dashboard_page_zero-analytics' === $hook || 'index.php' === $hook ) {
		zeroa_admin_styles();
	}
} );
add_action( 'admin_init', 'zeroa_handle_export' );
add_action( 'admin_init', 'zeroa_handle_clear' );

function zeroa_admin_menu(): void {
	add_submenu_page(
		'index.php',
		__( 'Analytics', 'zero-analytics' ),
		__( 'Analytics', 'zero-analytics' ),
		'manage_options',
		'zero-analytics',
		'zeroa_render_page'
	);
}

function zeroa_handle_export(): void {
	if ( !isset( $_GET['zeroa_export'] ) || !isset( $_GET['zeroa_export_nonce'] ) ) {
		return;
	}
	if ( !wp_verify_nonce( sanitize_key( $_GET['zeroa_export_nonce'] ), 'zeroa_export' ) ) {
		return;
	}
	if ( !current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . ZEROA_TABLE;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant, full export with no user-supplied interpolation
	$rows = $wpdb->get_results( "SELECT path, referrer_type, referrer_name, device_type, country_code, status_code, is_bot, is_unique, recorded_at FROM {$table} ORDER BY recorded_at DESC", ARRAY_A );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	header( 'Content-Type: text/csv; charset=utf-8' );
	$site_slug = sanitize_title( get_bloginfo( 'name' ) );
	header( 'Content-Disposition: attachment; filename="' . $site_slug . '-views-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fputcsv( $out, [ 'path', 'referrer_type', 'referrer_name', 'device_type', 'country_code', 'status_code', 'is_bot', 'is_unique', 'recorded_at' ] );
	foreach ( $rows as $row ) {
		fputcsv( $out, $row );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

function zeroa_handle_clear(): void {
	if ( !isset( $_POST['zeroa_clear_nonce'] ) || !wp_verify_nonce( sanitize_key( $_POST['zeroa_clear_nonce'] ), 'zeroa_clear_data' ) ) {
		return;
	}
	if ( !current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- truncate of our own table, no caching needed
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . ZEROA_TABLE );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- clear unique visitor transients; no WP API exists for wildcard transient deletion
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_zeroa_seen_%' OR option_name LIKE '_transient_timeout_zeroa_seen_%'" );
	wp_safe_redirect( add_query_arg( [ 'page' => 'zero-analytics', 'zeroa_cleared' => '1' ], admin_url( 'index.php' ) ) );
	exit;
}

// Dashboard Widget
add_action( 'wp_dashboard_setup', 'zeroa_register_widget' );
function zeroa_register_widget(): void {
	wp_add_dashboard_widget(
		'zeroa_dashboard_widget',
		__( 'Analytics', 'zero-analytics' ),
		'zeroa_render_widget'
	);
}

function zeroa_render_widget(): void {
	$stats = zeroa_get_summary( 30 );
	$url   = admin_url( 'index.php?page=zero-analytics' );
	?>
	<div class="zeroa-widget">
		<p style="margin-top:0;float:right"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View More', 'zero-analytics' ); ?> &rarr;</a></p>
		<p><?php esc_html_e( 'Last 30 Days...', 'zero-analytics' ); ?></p>
		<p style="margin-bottom:0"><strong style="font-size:30px"><?php echo esc_html( number_format_i18n( $stats['unique'] ) ); ?></strong> <?php esc_html_e( 'Unique Visitors', 'zero-analytics' ); ?>
		<strong style="font-size:30px"><?php echo esc_html( number_format_i18n( $stats['pageviews'] ) ); ?></strong> <?php esc_html_e( 'Pageviews', 'zero-analytics' ); ?></p>
	</div>
	<?php
}

// Analytics Page
function zeroa_render_page(): void {
	if ( !current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'zero-analytics' ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
	$range          = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30';
	$range          = in_array( $range, [ '7', '30', '90', 'all' ], true ) ? $range : '30';
	$days           = 'all' === $range ? 0 : (int) $range;
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only paged params, no state change
	$pages_paged     = max( 1, absint( wp_unslash( $_GET['pages_paged']     ?? 1 ) ) );
	$refs_paged      = max( 1, absint( wp_unslash( $_GET['refs_paged']      ?? 1 ) ) );
	$countries_paged = max( 1, absint( wp_unslash( $_GET['countries_paged'] ?? 1 ) ) );
	$devices_paged   = max( 1, absint( wp_unslash( $_GET['devices_paged']   ?? 1 ) ) );
	$errors_paged    = max( 1, absint( wp_unslash( $_GET['errors_paged']    ?? 1 ) ) );
	$bots_paged      = max( 1, absint( wp_unslash( $_GET['bots_paged']      ?? 1 ) ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$stats     = zeroa_get_summary( $days );
	$chart     = zeroa_get_chart_data( $range );
	$pages     = zeroa_get_top_pages( $days, $pages_paged );
	$refs      = zeroa_get_referrers( $days, $refs_paged );
	$countries = zeroa_get_countries( $days, $countries_paged );
	$devices   = zeroa_get_devices( $days, $devices_paged );
	$errors    = zeroa_get_error_pages( $days, $errors_paged );
	$bots      = zeroa_get_bot_summary( $days, $bots_paged );
	$device_labels = [
		0 => __( 'Unknown', 'zero-analytics' ),
		1 => __( 'Desktop', 'zero-analytics' ),
		2 => __( 'Mobile', 'zero-analytics' ),
		3 => __( 'Tablet', 'zero-analytics' ),
	];
	?>
	<div class="wrap zeroa-wrap">
		<h1><?php esc_html_e( 'Analytics', 'zero-analytics' ); ?></h1>
		<?php if ( isset( $_GET['zeroa_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All analytics data has been cleared.', 'zero-analytics' ); ?></p></div>
		<?php endif; ?>
		<div class="zeroa-range-nav">
			<?php
			foreach ( [
				'7'   => __( 'Last 7 days', 'zero-analytics' ),
				'30'  => __( 'Last 30 days', 'zero-analytics' ),
				'90'  => __( 'Last 90 days', 'zero-analytics' ),
				'all' => __( 'All time', 'zero-analytics' ),
			] as $key => $label ) :
				$active = ( $range === (string) $key ) ? ' zeroa-active' : '';
				$url    = add_query_arg( 'range', $key );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="zeroa-range-btn<?php echo esc_attr( $active ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<div class="zeroa-summary-cards">
			<div class="zeroa-card">
				<span class="zeroa-card-value"><?php echo esc_html( number_format_i18n( $stats['unique'] ) ); ?></span>
				<span class="zeroa-card-label"><?php esc_html_e( 'Unique Visitors', 'zero-analytics' ); ?></span>
			</div>
			<div class="zeroa-card">
				<span class="zeroa-card-value"><?php echo esc_html( number_format_i18n( $stats['pageviews'] ) ); ?></span>
				<span class="zeroa-card-label"><?php esc_html_e( 'Pageviews', 'zero-analytics' ); ?></span>
			</div>
			<div class="zeroa-card">
				<span class="zeroa-card-value"><?php echo esc_html( number_format_i18n( $stats['errors'] ) ); ?></span>
				<span class="zeroa-card-label"><?php esc_html_e( 'Errors', 'zero-analytics' ); ?></span>
			</div>
			<div class="zeroa-card">
				<span class="zeroa-card-value"><?php echo esc_html( number_format_i18n( $stats['bots'] ) ); ?></span>
				<span class="zeroa-card-label"><?php esc_html_e( 'Bot Visits', 'zero-analytics' ); ?></span>
			</div>
		</div>

		<?php if ( $chart ) : ?>
		<div class="zeroa-chart">
			<?php
			$max = max( array_map( fn( $d ) => $d['pageviews'], $chart ) );
			$max = max( 1, $max );
			?>
			<div class="zeroa-chart-bars">
				<?php foreach ( $chart as $day => $data ) : ?>
				<div class="zeroa-chart-bar-wrap<?php echo $data['pageviews'] > 0 ? ' has-data' : ''; ?>">
					<?php
					/* translators: 1: number of pageviews, 2: number of unique visitors */
					$zeroa_tip = esc_attr( sprintf( __( '%1$s pageviews, %2$s unique', 'zero-analytics' ), number_format_i18n( $data['pageviews'] ), number_format_i18n( $data['uniques'] ) ) );
					?>
					<div class="zeroa-chart-bar-views" style="height:<?php echo esc_attr( round( ( $data['pageviews'] / $max ) * 100 ) ); ?>%" title="<?php echo $zeroa_tip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_attr() above ?>"></div>
					<div class="zeroa-chart-bar-uniques" style="height:<?php echo esc_attr( round( ( $data['uniques'] / $max ) * 100 ) ); ?>%" title="<?php echo $zeroa_tip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_attr() above ?>"></div>
					<span class="zeroa-chart-label"><?php echo esc_html( $data['label'] ); ?></span>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="zeroa-chart-legend">
				<span class="legend-uniques"><?php esc_html_e( 'Unique Visitors', 'zero-analytics' ); ?></span>
				<span class="legend-views"><?php esc_html_e( 'Pageviews', 'zero-analytics' ); ?></span>
			</div>
		</div>
		<?php endif; ?>

		<div class="zeroa-grid">

			<div class="zeroa-section">
				<h2><?php esc_html_e( 'Pages', 'zero-analytics' ); ?></h2>
				<?php
				$pages_has_next = count( $pages ) > 10;
				$pages_display  = array_slice( $pages, 0, 10 );
				?>
				<div class="zeroa-table-wrap">
					<?php if ( $pages_display ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Unique', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Pageviews', 'zero-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $pages_display as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( home_url( $row->path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row->path ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->unique_visitors ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->pageviews ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No data yet.', 'zero-analytics' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $pages_display ) : ?>
				<?php zeroa_pagination( 'pages', $pages_paged, $pages_has_next, $range ); ?>
				<?php endif; ?>
			</div>

			<div class="zeroa-section">
				<h2><?php esc_html_e( 'Referrers', 'zero-analytics' ); ?></h2>
				<?php
				$refs_has_next = count( $refs ) > 10;
				$refs_display  = array_slice( $refs, 0, 10 );
				?>
				<div class="zeroa-table-wrap">
					<?php if ( $refs_display ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Type', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'zero-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $refs_display as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->referrer_name ?? __( 'Direct / Other', 'zero-analytics' ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $row->referrer_type ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->visits ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No data yet.', 'zero-analytics' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $refs_display ) : ?>
				<?php zeroa_pagination( 'refs', $refs_paged, $refs_has_next, $range ); ?>
				<?php endif; ?>
			</div>

			<div class="zeroa-section">
				<h2><?php esc_html_e( 'Countries', 'zero-analytics' ); ?></h2>
				<?php
				$countries_has_next = count( $countries ) > 10;
				$countries_display  = array_slice( $countries, 0, 10 );
				?>
				<div class="zeroa-table-wrap">
					<?php if ( $countries_display ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Country', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'zero-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $countries_display as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->country_code ?? __( 'Unavailable', 'zero-analytics' ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->visits ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No data yet.', 'zero-analytics' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $countries_display ) : ?>
				<?php zeroa_pagination( 'countries', $countries_paged, $countries_has_next, $range ); ?>
				<?php endif; ?>
			</div>

			<div class="zeroa-section">
				<h2><?php esc_html_e( 'Devices', 'zero-analytics' ); ?></h2>
				<?php
				$devices_has_next = count( $devices ) > 10;
				$devices_display  = array_slice( $devices, 0, 10 );
				?>
				<div class="zeroa-table-wrap">
					<?php if ( $devices_display ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Device', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'zero-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $devices_display as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $device_labels[ (int) $row->device_type ] ?? __( 'Unknown', 'zero-analytics' ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->visits ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No data yet.', 'zero-analytics' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $devices_display ) : ?>
				<?php zeroa_pagination( 'devices', $devices_paged, $devices_has_next, $range ); ?>
				<?php endif; ?>
			</div>

			<div class="zeroa-section">
				<h2><?php esc_html_e( 'Errors', 'zero-analytics' ); ?></h2>
				<?php
				$errors_has_next = count( $errors ) > 10;
				$errors_display  = array_slice( $errors, 0, 10 );
				?>
				<div class="zeroa-table-wrap">
					<?php if ( $errors_display ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Status', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'zero-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $errors_display as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->path ); ?></td>
								<td><?php echo esc_html( $row->status_code ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->visits ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No data yet.', 'zero-analytics' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $errors_display ) : ?>
				<?php zeroa_pagination( 'errors', $errors_paged, $errors_has_next, $range ); ?>
				<?php endif; ?>
			</div>

			<div class="zeroa-section">
				<h2><?php esc_html_e( 'Bots', 'zero-analytics' ); ?></h2>
				<?php
				$bots_has_next = count( $bots ) > 10;
				$bots_display  = array_slice( $bots, 0, 10 );
				?>
				<div class="zeroa-table-wrap">
					<?php if ( $bots_display ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'zero-analytics' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'zero-analytics' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $bots_display as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->path ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->bot_visits ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No data yet.', 'zero-analytics' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $bots_display ) : ?>
				<?php zeroa_pagination( 'bots', $bots_paged, $bots_has_next, $range ); ?>
				<?php endif; ?>
			</div>

		</div><!-- .zeroa-grid -->

		<div class="zeroa-actions">
			<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'zero-analytics', 'zeroa_export' => '1', 'zeroa_export_nonce' => wp_create_nonce( 'zeroa_export' ) ], admin_url( 'index.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Export All Data', 'zero-analytics' ); ?></a>
			<form method="post">
				<?php wp_nonce_field( 'zeroa_clear_data', 'zeroa_clear_nonce' ); ?>
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all analytics data? This cannot be undone.', 'zero-analytics' ) ); ?>')"><?php esc_html_e( 'Clear All Data', 'zero-analytics' ); ?></button>
			</form>
		</div>

	</div><!-- .zeroa-wrap -->
	<?php
}

// Queries
function zeroa_date_clause( int $days ): string {
	if ( 0 === $days ) {
		return '';
	}
	// Using gmdate for UTC consistency; recorded_at is stored as UTC
	$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	return $GLOBALS['wpdb']->prepare( ' AND recorded_at >= %s', $since );
}

function zeroa_get_chart_data( string $range ): array {
	global $wpdb;
	$table    = $wpdb->prefix . ZEROA_TABLE;
	$monthly  = in_array( $range, [ '90', 'all' ], true );
	if ( $monthly ) {
		$start = $range === 'all' ? '2000-01-01' : gmdate( 'Y-m-d', strtotime( '-89 days' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date is generated server-side
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT( recorded_at, '%%Y-%%m' ) AS period,
					SUM( CASE WHEN is_bot = 0 AND status_code < 400 THEN 1 ELSE 0 END ) AS pageviews,
					SUM( CASE WHEN is_bot = 0 AND is_unique = 1 AND status_code < 400 THEN 1 ELSE 0 END ) AS uniques
				FROM {$table}
				WHERE is_bot = 0 AND status_code < 400 AND DATE( recorded_at ) >= %s
				GROUP BY period
				ORDER BY period ASC",
				$start
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$data = [];
		foreach ( $rows as $row ) {
			$data[ $row->period ] = [ 'pageviews' => (int) $row->pageviews, 'uniques' => (int) $row->uniques, 'label' => gmdate( 'M Y', strtotime( $row->period . '-01' ) ) ];
		}
		return $data;
	}
	$days  = $range === '7' ? 7 : 30;
	$start = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date is generated server-side
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE( recorded_at ) AS period,
				SUM( CASE WHEN is_bot = 0 AND status_code < 400 THEN 1 ELSE 0 END ) AS pageviews,
				SUM( CASE WHEN is_bot = 0 AND is_unique = 1 AND status_code < 400 THEN 1 ELSE 0 END ) AS uniques
			FROM {$table}
			WHERE is_bot = 0 AND status_code < 400 AND DATE( recorded_at ) >= %s
			GROUP BY DATE( recorded_at )
			ORDER BY period ASC",
			$start
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$db_data = [];
	foreach ( $rows as $row ) {
		$db_data[ $row->period ] = [ 'pageviews' => (int) $row->pageviews, 'uniques' => (int) $row->uniques ];
	}
	$data = [];
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$day          = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		$data[ $day ] = array_merge( $db_data[ $day ] ?? [ 'pageviews' => 0, 'uniques' => 0 ], [ 'label' => gmdate( 'M j', strtotime( $day ) ) ] );
	}
	return $data;
}

function zeroa_get_summary( int $days ): array {
	global $wpdb;
	$table = $wpdb->prefix . ZEROA_TABLE;
	$date  = zeroa_date_clause( $days );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	$row = $wpdb->get_row(
		"SELECT
			SUM( CASE WHEN is_bot = 0 AND status_code < 400 THEN 1 ELSE 0 END ) AS pageviews,
			SUM( CASE WHEN is_bot = 0 AND is_unique = 1 AND status_code < 400 THEN 1 ELSE 0 END ) AS `unique`,
			SUM( CASE WHEN is_bot = 0 AND status_code >= 400 THEN 1 ELSE 0 END ) AS errors,
			SUM( CASE WHEN is_bot = 1 THEN 1 ELSE 0 END ) AS bots
		FROM {$table} WHERE 1=1 {$date}"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	return [
		'pageviews' => (int) ( $row->pageviews ?? 0 ),
		'unique'    => (int) ( $row->unique ?? 0 ),
		'errors'    => (int) ( $row->errors ?? 0 ),
		'bots'      => (int) ( $row->bots ?? 0 ),
	];
}

function zeroa_get_top_pages( int $days, int $page = 1, int $per_page = 10 ): array {
	global $wpdb;
	$table  = $wpdb->prefix . ZEROA_TABLE;
	$date   = zeroa_date_clause( $days );
	$offset = ( $page - 1 ) * $per_page;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT path,
				COUNT(*) AS pageviews,
				SUM( is_unique ) AS unique_visitors
			FROM {$table} WHERE is_bot = 0 AND status_code < 400 {$date}
			GROUP BY path ORDER BY pageviews DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function zeroa_get_referrers( int $days, int $page = 1, int $per_page = 10 ): array {
	global $wpdb;
	$table  = $wpdb->prefix . ZEROA_TABLE;
	$date   = zeroa_date_clause( $days );
	$offset = ( $page - 1 ) * $per_page;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT referrer_type, referrer_name, COUNT(*) AS visits
			FROM {$table} WHERE is_bot = 0 {$date}
			GROUP BY referrer_type, referrer_name ORDER BY visits DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function zeroa_get_countries( int $days, int $page = 1, int $per_page = 10 ): array {
	global $wpdb;
	$table  = $wpdb->prefix . ZEROA_TABLE;
	$date   = zeroa_date_clause( $days );
	$offset = ( $page - 1 ) * $per_page;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT country_code, COUNT(*) AS visits
			FROM {$table} WHERE is_bot = 0 {$date}
			GROUP BY country_code ORDER BY visits DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function zeroa_get_devices( int $days, int $page = 1, int $per_page = 10 ): array {
	global $wpdb;
	$table  = $wpdb->prefix . ZEROA_TABLE;
	$date   = zeroa_date_clause( $days );
	$offset = ( $page - 1 ) * $per_page;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT device_type, COUNT(*) AS visits
			FROM {$table} WHERE is_bot = 0 {$date}
			GROUP BY device_type ORDER BY visits DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function zeroa_get_error_pages( int $days, int $page = 1, int $per_page = 10 ): array {
	global $wpdb;
	$table  = $wpdb->prefix . ZEROA_TABLE;
	$date   = zeroa_date_clause( $days );
	$offset = ( $page - 1 ) * $per_page;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT path, status_code, COUNT(*) AS visits
			FROM {$table} WHERE is_bot = 0 AND status_code >= 400 {$date}
			GROUP BY path, status_code ORDER BY visits DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

function zeroa_get_bot_summary( int $days, int $page = 1, int $per_page = 10 ): array {
	global $wpdb;
	$table  = $wpdb->prefix . ZEROA_TABLE;
	$date   = zeroa_date_clause( $days );
	$offset = ( $page - 1 ) * $per_page;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a constant, date clause is already prepared via $wpdb->prepare() in zeroa_date_clause()
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT path, COUNT(*) AS bot_visits
			FROM {$table} WHERE is_bot = 1 {$date}
			GROUP BY path ORDER BY bot_visits DESC LIMIT %d OFFSET %d",
			$per_page + 1,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

// Admin Styles
function zeroa_admin_styles(): void {
	wp_register_style( 'zeroa-admin', false, [], ZEROA_VERSION );
	wp_enqueue_style( 'zeroa-admin' );
	$css = '
		.zeroa-wrap { max-width: 1200px; }
		.zeroa-range-nav { margin: 16px 0; display: flex; gap: 8px; }
		.zeroa-range-btn { padding: 6px 14px; border: 1px solid #c3c4c7; border-radius: 4px; text-decoration: none; color: #1d2327; background: #fff; font-size: 13px; }
		.zeroa-range-btn:hover { border-color: var(--wp-admin-theme-color); color: var(--wp-admin-theme-color); }
		.zeroa-range-btn.zeroa-active { background: var(--wp-admin-theme-color); border-color: var(--wp-admin-theme-color); color: #fff; }
		.zeroa-summary-cards { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
		.zeroa-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px 24px; min-width: 160px; display: flex; flex-direction: column; gap: 4px; }
		.zeroa-card-value { font-size: 28px; font-weight: 600; line-height: 1; color: #1d2327; }
		.zeroa-card-label { font-size: 12px; color: #50575e; text-transform: uppercase; letter-spacing: 0.04em; }
		.zeroa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
		.zeroa-grid .zeroa-section { display: flex; flex-direction: column; }
		.zeroa-section h2 { font-size: 14px; font-weight: 600; margin-bottom: 8px; }
		.zeroa-pagination { display: flex; gap: 8px; margin-top: 8px; min-height: 28px; align-items: center; }
		.zeroa-pagination-disabled { opacity: 0.4; cursor: default; pointer-events: none; }
		.zeroa-pagination-page { font-size: 12px; color: #50575e; }
		.zeroa-actions { display: flex; gap: 8px; margin-top: 48px; align-items: center; }
		.zeroa-chart { margin-bottom: 24px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; overflow-x: auto; }
		.zeroa-chart-bars { display: flex; align-items: flex-end; gap: 2px; height: 160px; }
		.zeroa-chart-bar-wrap { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; flex: 1; height: 100%; gap: 0; position: relative; }
		.zeroa-chart-bar { width: 100%; border-radius: 2px 2px 0 0; min-height: 2px; }
		.zeroa-chart-bar-views { background: var(--wp-editor-canvas-background); position: absolute; bottom: 0; width: 100%; border-radius: 2px 2px 0 0; z-index: 1; }
		.zeroa-chart-bar-uniques { background: var(--wp-admin-theme-color); position: absolute; bottom: 0; width: 100%; border-radius: 2px 2px 0 0; z-index: 2; }
		.zeroa-chart-label { font-size: 9px; color: #50575e; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: clip; text-align: center; width: 100%; z-index: 3; }
		.zeroa-chart-bar-wrap.has-data .zeroa-chart-label { color: #fff; }
		.zeroa-chart-legend { display: flex; gap: 16px; margin-top: 10px; font-size: 12px; color: #50575e; }
		.zeroa-chart-legend span { display: inline-flex; align-items: center; gap: 4px; }
		.zeroa-chart-legend span::before { content: ""; display: inline-block; width: 12px; height: 12px; border-radius: 2px; }
		.zeroa-chart-legend .legend-uniques::before { background: var(--wp-admin-theme-color); }
		.zeroa-chart-legend .legend-views::before { background: var(--wp-editor-canvas-background); }
		.zeroa-widget-stats { display: flex; gap: 20px; margin-bottom: 8px; }
		.zeroa-stat { display: flex; flex-direction: column; }
		.zeroa-stat-value { font-size: 22px; font-weight: 600; line-height: 1; }
		.zeroa-stat-label { font-size: 11px; color: #50575e; text-transform: uppercase; letter-spacing: 0.04em; }
		.zeroa-widget-period { color: #50575e; font-size: 12px; margin: 4px 0 8px; }
		@media ( max-width: 782px ) { .zeroa-grid { grid-template-columns: 1fr; } }
	';
	wp_add_inline_style( 'zeroa-admin', $css );
}