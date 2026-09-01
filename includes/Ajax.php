<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

class Ajax {

	private $catalog;
	private $generator;
	private $images;

	public function __construct( Catalog $catalog, Generator $generator, ImageStore $images ) {
		$this->catalog   = $catalog;
		$this->generator = $generator;
		$this->images    = $images;
	}

	public function hooks() {
		add_action( 'wp_ajax_nc_mock_status', [ $this, 'status' ] );
		add_action( 'wp_ajax_nc_mock_refresh', [ $this, 'refresh' ] );
		add_action( 'wp_ajax_nc_mock_generate', [ $this, 'generate' ] );
		add_action( 'wp_ajax_nc_mock_remove', [ $this, 'remove' ] );
	}

	public function status() {
		$this->guard();
		wp_send_json_success( Plugin::instance()->admin->status_payload( $this->source_ids() ) );
	}

	public function refresh() {
		$this->guard();
		@set_time_limit( 180 );

		try {
			$meta = $this->catalog->refresh();
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}

		$payload          = Plugin::instance()->admin->status_payload( $this->source_ids() );
		$payload['meta']  = $meta;
		wp_send_json_success( $payload );
	}

	public function generate() {
		$this->guard();
		@set_time_limit( 120 );

		$source_ids = $this->source_ids();

		if ( ! $this->catalog->has_cache() ) {
			try {
				$this->catalog->refresh();
				wp_send_json_success( [
					'refreshed' => true,
					'exhausted' => false,
					'product'   => null,
					'status'    => Plugin::instance()->admin->status_payload( $source_ids ),
				] );
			} catch ( \Exception $e ) {
				wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
			}
		}

		try {
			$result = $this->generator->generate_next( $source_ids );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}

		if ( ! $result ) {
			wp_send_json_success( [
				'exhausted' => true,
				'product'   => null,
				'status'    => Plugin::instance()->admin->status_payload( $source_ids ),
			] );
		}

		wp_send_json_success( [
			'exhausted' => false,
			'product'   => $result,
			'status'    => Plugin::instance()->admin->status_payload( $source_ids ),
		] );
	}

	public function remove() {
		$this->guard();
		@set_time_limit( 60 );

		$result = $this->generator->archive_mock_batch( 8 );
		wp_send_json_success( [
			'archived' => $result['archived'],
			'more'     => $result['more'],
			'status'   => Plugin::instance()->admin->status_payload( $this->source_ids() ),
		] );
	}

	private function guard() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		check_ajax_referer( 'nc-mock', 'nonce' );
	}

	private function source_ids() {
		$raw = isset( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : '';
		if ( is_string( $raw ) ) {
			$raw = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );
		}

		$allowed = array_keys( Catalog::sources() );
		$ids     = array_values( array_intersect( (array) $raw, $allowed ) );

		return $ids ? $ids : $allowed;
	}
}
