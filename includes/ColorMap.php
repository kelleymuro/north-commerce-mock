<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

/**
 * Map a color / finish name to a hex swatch for North Commerce color options.
 */
class ColorMap {

	/**
	 * Lowercase name => hex. Longer / more specific keys are checked first
	 * via substring match after the exact lookup.
	 */
	private static $names = [
		'natural black'     => '#1a1a1a',
		'dark grey'         => '#4a4a4a',
		'light grey'        => '#c8c8c8',
		'heather grey'      => '#9a9a9a',
		'natural white'     => '#f3f0e8',
		'natural grey'      => '#8a8580',
		'natural'           => '#e8e0d4',
		'off white'         => '#f5f1ea',
		'walnut / copper'   => '#8b5a2b',
		'oak / nickel'      => '#c4a574',
		'ebony / bronze'    => '#2b2118',
		'walnut'            => '#5c3a21',
		'oak'               => '#c4a574',
		'ebony'             => '#1c1410',
		'copper'            => '#b87333',
		'nickel'            => '#8a8d8f',
		'bronze'            => '#8c6239',
		'butterscotch'      => '#d4a017',
		'mohair'            => '#c9a66b',
		'blush'             => '#de8f92',
		'nude'              => '#e3c6b0',
		'rose'              => '#c76b7a',
		'berry'             => '#7a3045',
		'coral'             => '#e07060',
		'champagne'         => '#f3e5ab',
		'ivory'             => '#fffff0',
		'linen'             => '#e9e1d3',
		'cream'             => '#f5f0e1',
		'bone'              => '#e3dac9',
		'sand'              => '#c8b89a',
		'tan'               => '#d2b48c',
		'beige'             => '#d8c3a5',
		'camel'             => '#c19a6b',
		'khaki'             => '#c3b091',
		'olive'             => '#5b6046',
		'forest'            => '#2e4a32',
		'hunter'            => '#355e3b',
		'sage'              => '#9caf88',
		'mint'              => '#98d0b9',
		'teal'              => '#367588',
		'turquoise'         => '#40e0d0',
		'navy'              => '#1f2a44',
		'midnight'          => '#191970',
		'indigo'            => '#3f00ff',
		'cobalt'            => '#0047ab',
		'royal'             => '#4169e1',
		'sky'               => '#87ceeb',
		'baby blue'         => '#89cff0',
		'light blue'        => '#add8e6',
		'dark blue'         => '#00008b',
		'blue'              => '#2f5d8a',
		'purple'            => '#6b3fa0',
		'lilac'             => '#c8a2c8',
		'lavender'          => '#e6e6fa',
		'plum'              => '#8e4585',
		'magenta'           => '#ca1f7b',
		'fuchsia'           => '#ff00ff',
		'pink'              => '#e8a0bf',
		'hot pink'          => '#ff69b4',
		'red'               => '#b22222',
		'crimson'           => '#dc143c',
		'burgundy'          => '#800020',
		'wine'              => '#722f37',
		'maroon'            => '#800000',
		'rust'              => '#b7410e',
		'terracotta'        => '#c66a4a',
		'orange'            => '#e07a3d',
		'peach'             => '#ffcba4',
		'apricot'           => '#fbceb1',
		'gold'              => '#d4af37',
		'mustard'           => '#e1ad01',
		'yellow'            => '#e6c229',
		'lemon'             => '#fff44f',
		'chartreuse'        => '#7fff00',
		'green'             => '#3b6b3b',
		'emerald'           => '#50c878',
		'brown'             => '#6b4226',
		'chocolate'         => '#3d1c02',
		'coffee'            => '#4b3621',
		'espresso'          => '#3c2f2f',
		'charcoal'          => '#36454f',
		'slate'             => '#3b4252',
		'graphite'          => '#41424c',
		'anthracite'        => '#383e42',
		'grey'              => '#808080',
		'gray'              => '#808080',
		'silver'            => '#c0c0c0',
		'steel'             => '#9aa0a6',
		'white'             => '#f7f7f7',
		'black'             => '#1a1a1a',
		'clear'             => '#f2f2f2',
		'multi'             => '#888888',
	];

	public static function hex( $name ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) {
			return '#888888';
		}

		if ( preg_match( '/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $name ) ) {
			$hex = ltrim( $name, '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return '#' . strtolower( $hex );
		}

		$key = strtolower( $name );
		if ( isset( self::$names[ $key ] ) ) {
			return self::$names[ $key ];
		}

		$best      = null;
		$best_len  = 0;
		foreach ( self::$names as $needle => $hex ) {
			if ( false !== strpos( $key, $needle ) && strlen( $needle ) > $best_len ) {
				$best     = $hex;
				$best_len = strlen( $needle );
			}
		}

		if ( $best ) {
			return $best;
		}

		return self::hash_hex( $key );
	}

	private static function hash_hex( $key ) {
		$hash = abs( crc32( $key ) );
		$h    = $hash % 360;
		$s    = 38 + ( $hash % 18 );
		$l    = 38 + ( ( $hash >> 8 ) % 16 );

		return self::hsl_to_hex( $h, $s, $l );
	}

	private static function hsl_to_hex( $h, $s, $l ) {
		$s = $s / 100;
		$l = $l / 100;
		$c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
		$x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m = $l - $c / 2;

		if ( $h < 60 ) {
			$r = $c; $g = $x; $b = 0;
		} elseif ( $h < 120 ) {
			$r = $x; $g = $c; $b = 0;
		} elseif ( $h < 180 ) {
			$r = 0; $g = $c; $b = $x;
		} elseif ( $h < 240 ) {
			$r = 0; $g = $x; $b = $c;
		} elseif ( $h < 300 ) {
			$r = $x; $g = 0; $b = $c;
		} else {
			$r = $c; $g = 0; $b = $x;
		}

		return sprintf(
			'#%02x%02x%02x',
			(int) round( ( $r + $m ) * 255 ),
			(int) round( ( $g + $m ) * 255 ),
			(int) round( ( $b + $m ) * 255 )
		);
	}
}
