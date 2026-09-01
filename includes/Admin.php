<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

class Admin {

	private $catalog;
	private $generator;
	private $images;
	private $hook;

	public function __construct( Catalog $catalog, Generator $generator, ImageStore $images ) {
		$this->catalog   = $catalog;
		$this->generator = $generator;
		$this->images    = $images;
	}

	public function hooks() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function menu() {
		$this->hook = add_menu_page(
			__( 'North Mock', 'north-commerce-mock' ),
			__( 'North Mock', 'north-commerce-mock' ),
			'edit_posts',
			NC_MOCK_SLUG,
			[ $this, 'render' ],
			'dashicons-database',
			58
		);
	}

	public function assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook ) {
			return;
		}

		wp_enqueue_style(
			'nc-mock-admin',
			NC_MOCK_URL . 'admin/css/admin.css',
			[],
			NC_MOCK_VERSION
		);

		wp_enqueue_script(
			'nc-mock-admin',
			NC_MOCK_URL . 'admin/js/admin.js',
			[],
			NC_MOCK_VERSION,
			true
		);

		wp_localize_script( 'nc-mock-admin', 'ncMock', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'nc-mock' ),
			'target'      => NC_MOCK_TARGET_COUNT,
			'productsUrl' => admin_url( 'admin.php?page=north-commerce-products' ),
		] );
	}

	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to generate mock products.', 'north-commerce-mock' ) );
		}

		$view = NC_MOCK_DIR . '/admin/views/page.php';
		include $view;
	}

	public function status_payload( $source_ids = null ) {
		$meta    = $this->catalog->meta();
		$sources = $this->catalog->counts_by_source();
		$remaining = 0;
		foreach ( $sources as $source ) {
			if ( $source_ids && ! in_array( $source['id'], $source_ids, true ) ) {
				continue;
			}
			$remaining += (int) $source['unused'];
		}

		return [
			'total'     => $this->generator->product_count(),
			'mock'      => $this->generator->mock_count(),
			'target'    => NC_MOCK_TARGET_COUNT,
			'remaining' => $remaining,
			'sources'   => $sources,
			'hasCache'  => $this->catalog->has_cache(),
			'fetchedAt' => isset( $meta['fetched_at'] ) ? (int) $meta['fetched_at'] : 0,
			'errors'    => isset( $meta['errors'] ) ? $meta['errors'] : [],
		];
	}
}
