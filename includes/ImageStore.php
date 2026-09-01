<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

/**
 * Download product images onto this site so the catalog keeps working
 * after the source store removes them.
 *
 * Files live in wp-content/uploads/nc-mock/{10-char-hash}.{ext}.
 * Variant image_url is VARCHAR(128), so public URLs stay short.
 */
class ImageStore {

	const DIR_NAME = 'nc-mock';
	const HASH_LEN = 10;
	const INDEX_FILE = 'index.json';
	const VARIANT_URL_MAX = 128;
	const PRODUCT_URL_MAX = 255;

	private $index;
	private $index_loaded = false;

	public function hooks() {
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
	}

	public static function directory() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	public static function ensure_directory() {
		$dir = self::directory();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" );
		}

		return $dir;
	}

	/**
	 * Sideload a remote image and return a local URL that fits $max_length.
	 * Returns null if the download fails.
	 */
	public function localize( $source_url, $max_length = self::PRODUCT_URL_MAX ) {
		$source_url = $this->normalize_source( $source_url );
		if ( ! $source_url ) {
			return null;
		}

		$cached = $this->lookup( $source_url );
		if ( $cached ) {
			return $this->public_url( $cached['hash'], $cached['ext'], $max_length );
		}

		$saved = $this->download( $source_url );
		if ( ! $saved ) {
			return null;
		}

		$this->remember( $source_url, $saved['hash'], $saved['ext'] );

		return $this->public_url( $saved['hash'], $saved['ext'], $max_length );
	}

	public function maybe_serve() {
		if ( empty( $_GET['ncmimg'] ) ) {
			return;
		}

		$hash = preg_replace( '/[^a-f0-9]/', '', (string) $_GET['ncmimg'] );
		if ( strlen( $hash ) !== self::HASH_LEN ) {
			status_header( 404 );
			exit;
		}

		$matches = glob( self::directory() . '/' . $hash . '.*' );
		if ( ! $matches ) {
			status_header( 404 );
			exit;
		}

		$file = $matches[0];
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$mime = $this->mime_for_ext( $ext );

		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		readfile( $file );
		exit;
	}

	private function download( $source_url ) {
		self::ensure_directory();

		$host = (string) wp_parse_url( $source_url, PHP_URL_HOST );
		$args = [
			'timeout'     => 30,
			'redirection' => 5,
			'sslverify'   => true,
			'user-agent'  => $this->user_agent(),
			'headers'     => [
				'Accept'  => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
				'Referer' => 'https://' . $host . '/',
			],
		];

		$response = wp_remote_get( $this->sized_url( $source_url ), $args );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			$response = wp_remote_get( $source_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return null;
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( $content_type && 0 !== strpos( explode( ';', $content_type )[0], 'image/' ) ) {
			return null;
		}

		$ext = $this->extension_from( $source_url, $content_type );
		if ( ! $ext ) {
			return null;
		}

		$hash = $this->hash_for( $source_url );
		$path = self::directory() . '/' . $hash . '.' . $ext;

		if ( false === file_put_contents( $path, $body ) ) {
			return null;
		}

		return [
			'hash' => $hash,
			'ext'  => $ext,
		];
	}

	private function public_url( $hash, $ext, $max_length ) {
		$uploads = wp_upload_dir();
		$full    = trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME . '/' . $hash . '.' . $ext;

		if ( strlen( $full ) <= $max_length ) {
			return $full;
		}

		$short = home_url( '/?ncmimg=' . $hash );
		if ( strlen( $short ) <= $max_length ) {
			return $short;
		}

		return substr( $short, 0, $max_length );
	}

	private function normalize_source( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/\.svg$/i', $path ) ) {
			return null;
		}

		return $url;
	}

	private function sized_url( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( false === strpos( $host, 'shopify.com' ) && false === strpos( $host, 'shopifycdn.com' ) ) {
			return $url;
		}

		$parts = explode( '?', $url, 2 );
		return $parts[0] . '?width=1200';
	}

	private function hash_for( $url ) {
		$parts = explode( '?', $url, 2 );
		return substr( sha1( $parts[0] ), 0, self::HASH_LEN );
	}

	private function extension_from( $url, $content_type ) {
		$type = strtolower( trim( explode( ';', (string) $content_type )[0] ) );
		$map  = [
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		];

		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}

		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( preg_match( '/\.(jpe?g|png|webp|gif)$/', $path, $m ) ) {
			return 'jpeg' === $m[1] ? 'jpg' : $m[1];
		}

		return 'jpg';
	}

	private function mime_for_ext( $ext ) {
		$map = [
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
		];

		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
	}

	private function lookup( $source_url ) {
		$this->load_index();
		$key = $this->hash_for( $source_url );
		return isset( $this->index[ $key ] ) ? $this->index[ $key ] : null;
	}

	private function remember( $source_url, $hash, $ext ) {
		$this->load_index();
		$key                 = $this->hash_for( $source_url );
		$this->index[ $key ] = [
			'hash'   => $hash,
			'ext'    => $ext,
			'source' => $source_url,
		];
		$this->save_index();
	}

	private function load_index() {
		if ( $this->index_loaded ) {
			return;
		}

		$this->index_loaded = true;
		$this->index        = [];
		$path               = self::directory() . '/' . self::INDEX_FILE;

		if ( ! is_readable( $path ) ) {
			return;
		}

		$json = json_decode( (string) file_get_contents( $path ), true );
		if ( is_array( $json ) ) {
			$this->index = $json;
		}
	}

	private function save_index() {
		self::ensure_directory();
		$path = self::directory() . '/' . self::INDEX_FILE;
		file_put_contents(
			$path,
			wp_json_encode( $this->index ),
			LOCK_EX
		);
	}

	private function user_agent() {
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 NorthMock/1.0';
	}
}
