<?php
/**
 * Plugin Name: GT - FluentCart Direct R2 Updater Packages
 * Description: Gives valid licensed updater clients a time-limited R2 package URL so same-site WordPress maintenance mode cannot block plugin downloads.
 * Author: Gaurav Tiwari
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GT_FluentCart_Direct_R2_Updater_Packages {

	/**
	 * Register the licensed updater response filter.
	 */
	public static function init() {
		add_filter(
			'fluent_cart/license/get_version_response',
			array( __CLASS__, 'use_direct_r2_package' ),
			20,
			3
		);
	}

	/**
	 * Replace the same-site package redirect with its signed R2 destination.
	 *
	 * FluentCart has already validated the license before this filter runs. Invalid
	 * requests retain the empty package response and never receive an R2 URL.
	 *
	 * @param array  $response Updater response.
	 * @param object $product  FluentCart product model.
	 * @param array  $request  Sanitized updater request.
	 * @return array
	 */
	public static function use_direct_r2_package( $response, $product, $request = array() ) {
		if ( ! is_array( $response ) || 'valid' !== ( $response['license_status'] ?? '' ) ) {
			return $response;
		}

		if ( ! class_exists( 'FluentCart\\App\\Models\\ProductDownload' )
			|| ! is_object( $product )
			|| ! method_exists( $product, 'getProductMeta' ) ) {
			return $response;
		}

		// Page Blocks Builder through 3.0.0 pins downloads to this license
		// host. Remote legacy clients can use FluentCart's existing redirect:
		// their own site's maintenance mode does not block this separate host.
		// New clients and this store's own installation use direct R2 URLs.
		$client_version = is_array( $request ) ? (string) ( $request['current_version'] ?? '' ) : '';
		$client_host = is_array( $request ) ? strtolower( (string) wp_parse_url( $request['site_url'] ?? '', PHP_URL_HOST ) ) : '';
		$license_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$client_host = preg_replace( '/^www\./', '', $client_host );
		$license_host = preg_replace( '/^www\./', '', $license_host );
		if ( 1152523 === (int) $product->ID && '' !== $client_version
			&& version_compare( $client_version, '3.0.0', '<=' )
			&& '' !== $client_host && $client_host !== $license_host ) {
			return $response;
		}

		$license_settings = $product->getProductMeta( 'license_settings' );
		if ( ! is_array( $license_settings ) ) {
			return $response;
		}

		$download_id = absint( $license_settings['global_update_file'] ?? 0 );
		if ( ! $download_id ) {
			return $response;
		}

		$download = FluentCart\App\Models\ProductDownload::find( $download_id );
		if ( ! $download
			|| 'r2' !== $download->driver
			|| (int) $product->ID !== (int) $download->post_id ) {
			return $response;
		}

		try {
			$package_url = $download->getSignedDownloadUrl();
		} catch ( Throwable $exception ) {
			return $response;
		}
		if ( ! is_string( $package_url ) || ! wp_http_validate_url( $package_url ) ) {
			return $response;
		}

		$home_host    = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$package_host = strtolower( (string) wp_parse_url( $package_url, PHP_URL_HOST ) );
		if ( '' === $package_host || $home_host === $package_host ) {
			return $response;
		}

		$response['package']       = $package_url;
		$response['download_link'] = $package_url;
		$response['trunk']         = $package_url;

		return $response;
	}
}

GT_FluentCart_Direct_R2_Updater_Packages::init();
