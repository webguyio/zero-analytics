<?php
/*
Zero Analytics Endpoint
*/

if ( !defined( 'ABSPATH' ) ) {
	http_response_code( 404 );
	exit;
}

// IP detection
function zeroa_ep_get_ip(): string {
	foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $h ) {
		if ( !empty( $_SERVER[ $h ] ) ) {
			$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $ip;
			}
		}
	}
	return '';
}

// Unique visitor detection
function zeroa_ep_is_unique( string $ip ): bool {
	if ( empty( $ip ) ) {
		return false;
	}
	$salt = get_option( 'zeroa_salt' );
	if ( !$salt ) {
		$salt = bin2hex( random_bytes( 16 ) );
		update_option( 'zeroa_salt', $salt, false );
		update_option( 'zeroa_salt_date', gmdate( 'Y-m-d' ), false );
	}
	$hash = hash( 'sha256', $salt . $ip . home_url() );
	$key  = 'zeroa_seen_' . $hash;
	if ( get_transient( $key ) ) {
		return false;
	}
	$ttl = strtotime( 'tomorrow midnight UTC' ) - time();
	set_transient( $key, 1, max( 1, $ttl ) );
	return true;
}

// Country detection
function zeroa_ep_get_country(): ?string {
	foreach ( [ 'HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'MM_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_CF_IPV6_COUNTRY' ] as $h ) {
		$val = isset( $_SERVER[ $h ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) ) : '';
		if ( $val && preg_match( '/^[A-Z]{2}$/', $val ) && 'XX' !== $val && 'T1' !== $val ) {
			return $val;
		}
	}
	$env = function_exists( 'getenv' ) ? getenv( 'GEOIP_COUNTRY_CODE' ) : false;
	if ( $env && preg_match( '/^[A-Z]{2}$/', strtoupper( $env ) ) ) {
		return strtoupper( $env );
	}
	return null;
}

// Device detection
function zeroa_ep_get_device( string $ua ): int {
	if ( empty( $ua ) ) {
		return 0;
	}
	if ( preg_match( '/tablet|ipad|playbook|silk|(android(?!.*mobile))/i', $ua ) ) {
		return 3;
	}
	if ( preg_match( '/mobile|iphone|ipod|android|blackberry|mini|windows\sce|palm/i', $ua ) ) {
		return 2;
	}
	return 1;
}

// Referrer classification
function zeroa_ep_classify_referrer( string $host ): array {
	if ( empty( $host ) ) {
		return [ 'type' => 'direct', 'name' => null ];
	}
	$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( $own && str_contains( $host, $own ) ) {
		return [ 'type' => 'internal', 'name' => null ];
	}
	$map = [
		'Google'     => [ 'type' => 'search', 'domains' => [ 'google.' ] ],
		'Bing'       => [ 'type' => 'search', 'domains' => [ 'bing.com' ] ],
		'DuckDuckGo' => [ 'type' => 'search', 'domains' => [ 'duckduckgo.com' ] ],
		'Yahoo'      => [ 'type' => 'search', 'domains' => [ 'yahoo.com' ] ],
		'Baidu'      => [ 'type' => 'search', 'domains' => [ 'baidu.com' ] ],
		'Yandex'     => [ 'type' => 'search', 'domains' => [ 'yandex.' ] ],
		'Ecosia'     => [ 'type' => 'search', 'domains' => [ 'ecosia.org' ] ],
		'Brave'      => [ 'type' => 'search', 'domains' => [ 'search.brave.com' ] ],
		'Kagi'       => [ 'type' => 'search', 'domains' => [ 'kagi.com' ] ],
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
	foreach ( $map as $name => $entry ) {
		foreach ( $entry['domains'] as $domain ) {
			if ( str_contains( $host, $domain ) ) {
				return [ 'type' => $entry['type'], 'name' => $name ];
			}
		}
	}
	return [ 'type' => 'direct', 'name' => null ];
}

function zeroa_gif(): never {
	header( 'Content-Type: image/gif' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'X-Robots-Tag: noindex' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary GIF output, escaping not applicable
	echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
	exit;
}

add_action( 'template_redirect', function() {
	$zeroa_request = strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), '?' );
	if ( substr( $zeroa_request, -6 ) !== '/zeroa' ) {
		return;
	}
	// GPC opt-out
	if ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === $_SERVER['HTTP_SEC_GPC'] ) {
		zeroa_gif();
	}
	$zeroa_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	// Bot detection
	$zeroa_is_bot = empty( $zeroa_ua ) || (bool) preg_match(
		'/adsbot|anthropic|applebot|axios|bingpreview|bot|bytespider|chatgpt|check|claude|cohere|crawl|curl|discord|embed|facebookexternalhit|feed|fetch|go-http|gptbot|googleother|headless|http|java|libwww|linkedinbot|lwp|mediapartners|monitor|node|openai|perplexity|phantom|ping|playwright|preload|preview|probe|puppeteer|python|reader|request|rss|ruby|scan|scrape|selenium|slack|slurp|spider|telegram|twitterbot|wget|whatsapp|wordpress\/|wp-cron/i',
		$zeroa_ua
	);
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public tracking endpoint; no nonce used by design, see plugin readme
	$zeroa_raw_path = isset( $_REQUEST['path'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['path'] ) ) : '/';
	if ( !preg_match( '/^[\/?#\[\]@!$&\'()*+,;=\-._~%:a-zA-Z0-9\/]+$/', $zeroa_raw_path ) ) {
		zeroa_gif();
	}
	$zeroa_path     = '/' . ltrim( substr( $zeroa_raw_path, 0, 500 ), '/' );
	$zeroa_ref_host = '';
	if ( !empty( $_REQUEST['ref'] ) ) {
		$zeroa_ref_host = strtolower( sanitize_text_field( wp_unslash( $_REQUEST['ref'] ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$zeroa_classify  = zeroa_ep_classify_referrer( $zeroa_ref_host );
	$zeroa_ip        = zeroa_ep_get_ip();
	$zeroa_is_unique = $zeroa_is_bot ? false : zeroa_ep_is_unique( $zeroa_ip );
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- performance-critical insert, no caching layer appropriate
	$wpdb->insert(
		$wpdb->prefix . 'zeroa_analytics',
		[
			'path'          => $zeroa_path,
			'referrer_type' => $zeroa_classify['type'],
			'referrer_name' => $zeroa_classify['name'],
			'device_type'   => zeroa_ep_get_device( $zeroa_ua ),
			'country_code'  => zeroa_ep_get_country(),
			'status_code'   => 200,
			'is_bot'        => $zeroa_is_bot ? 1 : 0,
			'is_unique'     => $zeroa_is_unique ? 1 : 0,
			'recorded_at'   => current_time( 'mysql', true ),
		],
		[ '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ]
	);
	zeroa_gif();
}, 1 );