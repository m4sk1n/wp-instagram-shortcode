<?php
/*
Plugin Name: WP Instagram shortcode
Plugin URI: https://github.com/scottsweb/wp-instagram-widget
Description: A WordPress shortcode for showing your latest Instagram photos based on plugin by Scott Evans.
Version: 1.0.0
Author: Marcin Mikołajczak
Author URI: https://mkljczk.pl
Text Domain: wp-instagram-shortcode
Domain Path: /assets/languages/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Copyright © 2013-2019 Scott Evans
Copyright © 2019 Marcin Mikołajczak

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

function wpis_init() {

	// define some constants.
	define( 'WP_INSTAGRAM_SHORTCODE_JS_URL', plugins_url( '/assets/js', __FILE__ ) );
	define( 'WP_INSTAGRAM_SHORTCODE_CSS_URL', plugins_url( '/assets/css', __FILE__ ) );
	define( 'WP_INSTAGRAM_SHORTCODE_IMAGES_URL', plugins_url( '/assets/images', __FILE__ ) );
	define( 'WP_INSTAGRAM_SHORTCODE_PATH', dirname( __FILE__ ) );
	define( 'WP_INSTAGRAM_SHORTCODE_BASE', plugin_basename( __FILE__ ) );
	define( 'WP_INSTAGRAM_SHORTCODE_FILE', __FILE__ );

	// load language files.
	load_plugin_textdomain( 'wp-instagram-shortcode', false, dirname( WP_INSTAGRAM_SHORTCODE_BASE ) . '/assets/languages/' );
}
add_action( 'init', 'wpis_init' );

function shortcode( $atts = array(), $instance ) {

	extract(shortcode_atts(array(
		'username' => 'mkljczk_',
		'number' => 4
		), $atts));

	if ( '' !== $username ) {

		$media_array = scrape_instagram( $username );

		if ( is_wp_error( $media_array ) ) {

			echo wp_kses_post( $media_array->get_error_message() );

		} else {

			// slice list down to required limit.
			$media_array = array_slice( apply_filters( 'wpiw_media_array', $media_array ), 0, $number );

			foreach( $media_array as $item ) {
				echo '<a href="' . esc_url( $item['link'] ) . '" target="_blank"  class="instagram__photo"><img src="' . esc_url( $item['small'] ) . '"  alt="' . esc_attr( $item['description'] ) . '" title="' . esc_attr( $item['description'] ) . '" /></a>';
			}
		}
	}

	$linkclass = apply_filters( 'wpiw_link_class', 'clear' );
	$linkaclass = apply_filters( 'wpiw_linka_class', '' );

	switch ( substr( $username, 0, 1 ) ) {
		case '#':
			$url = '//instagram.com/explore/tags/' . str_replace( '#', '', $username );
			break;

		default:
			$url = '//instagram.com/' . str_replace( '@', '', $username );
			break;
	}

	if ( '' !== $link ) {
		?><p class="<?php echo esc_attr( $linkclass ); ?>"><a href="<?php echo trailingslashit( esc_url( $url ) ); ?>"
    rel="me" target="<?php echo esc_attr( $target ); ?>"
    class="<?php echo esc_attr( $linkaclass ); ?>"><?php echo wp_kses_post( $link ); ?></a></p><?php
	}
}

// based on https://gist.github.com/cosmocatalano/4544576.
function scrape_instagram( $username ) {

	$username = trim( strtolower( $username ) );

	switch ( substr( $username, 0, 1 ) ) {
		case '#':
			$url              = 'https://instagram.com/explore/tags/' . str_replace( '#', '', $username );
			$transient_prefix = 'h';
			break;

		default:
			$url              = 'https://instagram.com/' . str_replace( '@', '', $username );
			$transient_prefix = 'u';
			break;
	}

	if ( false === ( $instagram = get_transient( 'insta-a10-' . $transient_prefix . '-' . sanitize_title_with_dashes( $username ) ) ) ) {

		$remote = wp_remote_get( $url );

		if ( is_wp_error( $remote ) ) {
			return new WP_Error( 'site_down', esc_html__( 'Unable to communicate with Instagram.', 'wp-instagram-widget' ) );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $remote ) ) {
			return new WP_Error( 'invalid_response', esc_html__( 'Instagram did not return a 200.', 'wp-instagram-widget' ) );
		}

		$shards      = explode( 'window._sharedData = ', $remote['body'] );
		$insta_json  = explode( ';</script>', $shards[1] );
		$insta_array = json_decode( $insta_json[0], true );

		if ( ! $insta_array ) {
			return new WP_Error( 'bad_json', esc_html__( 'Instagram has returned invalid data.', 'wp-instagram-widget' ) );
		}

		if ( isset( $insta_array['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'] ) ) {
			$images = $insta_array['entry_data']['ProfilePage'][0]['graphql']['user']['edge_owner_to_timeline_media']['edges'];
		} elseif ( isset( $insta_array['entry_data']['TagPage'][0]['graphql']['hashtag']['edge_hashtag_to_media']['edges'] ) ) {
			$images = $insta_array['entry_data']['TagPage'][0]['graphql']['hashtag']['edge_hashtag_to_media']['edges'];
		} else {
			return new WP_Error( 'bad_json_2', esc_html__( 'Instagram has returned invalid data.', 'wp-instagram-widget' ) );
		}

		if ( ! is_array( $images ) ) {
			return new WP_Error( 'bad_array', esc_html__( 'Instagram has returned invalid data.', 'wp-instagram-widget' ) );
		}

		$instagram = array();

		foreach ( $images as $image ) {
			if ( true === $image['node']['is_video'] ) {
				$type = 'video';
			} else {
				$type = 'image';
			}

			$caption = __( 'Instagram Image', 'wp-instagram-widget' );
			if ( ! empty( $image['node']['edge_media_to_caption']['edges'][0]['node']['text'] ) ) {
				$caption = wp_kses( $image['node']['edge_media_to_caption']['edges'][0]['node']['text'], array() );
			}

			$instagram[] = array(
				'description' => $caption,
				'link'        => trailingslashit( '//instagram.com/p/' . $image['node']['shortcode'] ),
				'time'        => $image['node']['taken_at_timestamp'],
				'comments'    => $image['node']['edge_media_to_comment']['count'],
				'likes'       => $image['node']['edge_liked_by']['count'],
				'thumbnail'   => preg_replace( '/^https?\:/i', '', $image['node']['thumbnail_resources'][0]['src'] ),
				'small'       => preg_replace( '/^https?\:/i', '', $image['node']['thumbnail_resources'][2]['src'] ),
				'large'       => preg_replace( '/^https?\:/i', '', $image['node']['thumbnail_resources'][4]['src'] ),
				'original'    => preg_replace( '/^https?\:/i', '', $image['node']['display_url'] ),
				'type'        => $type,
			);
		} // End foreach().

		// do not set an empty transient - should help catch private or empty accounts.
		if ( ! empty( $instagram ) ) {
			$instagram = base64_encode( serialize( $instagram ) );
			set_transient( 'insta-a10-' . $transient_prefix . '-' . sanitize_title_with_dashes( $username ), $instagram, apply_filters( 'null_instagram_cache_time', HOUR_IN_SECONDS * 2 ) );
		}
	}

	if ( ! empty( $instagram ) ) {

		return unserialize( base64_decode( $instagram ) );

	} else {

		return new WP_Error( 'no_images', esc_html__( 'Instagram did not return any images.', 'wp-instagram-widget' ) );

	}
}

add_shortcode('instagram-recent-posts', 'shortcode');