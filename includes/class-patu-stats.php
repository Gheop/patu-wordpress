<?php
/**
 * Site-wide optimization totals, kept in a single option.
 *
 * @package Patu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Patu_Stats {

	const OPTION = 'patu_stats';

	/** @return array{ images:int, saved:int } */
	public static function get() {
		$s = get_option( self::OPTION, array() );
		return array(
			'images' => isset( $s['images'] ) ? (int) $s['images'] : 0,
			'saved'  => isset( $s['saved'] ) ? (int) $s['saved'] : 0,
		);
	}

	/** Record that $images files were optimized, saving $bytes total. */
	public static function add( $images, $bytes ) {
		$s           = self::get();
		$s['images'] += (int) $images;
		$s['saved']  += (int) $bytes;
		update_option( self::OPTION, $s, false );
	}

	/** Subtract on restore (never goes below zero). */
	public static function subtract( $images, $bytes ) {
		$s           = self::get();
		$s['images'] = max( 0, $s['images'] - (int) $images );
		$s['saved']  = max( 0, $s['saved'] - (int) $bytes );
		update_option( self::OPTION, $s, false );
	}
}
