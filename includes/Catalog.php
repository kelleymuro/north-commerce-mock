<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

/**
 * Public storefront catalogs used as the mock product source.
 *
 * Allbirds, Alice Lane Home, and Rhode Skin expose Shopify's public
 * /products.json. Ayocin is a single-SKU marketing site, so its product is
 * bundled in data/ayocin.json.
 */
class Catalog {

	const CACHE_FILE = 'catalog.json';
	const META_OPTION = 'nc_mock_catalog_meta';
	const MAX_PER_SOURCE = 120;
	const PAGE_LIMIT = 250;

	private $images;
	private $items;
	private $loaded = false;

	public function __construct( ImageStore $images ) {
		$this->images = $images;
	}

	public static function sources() {
		return [
			'allbirds'   => [
				'id'          => 'allbirds',
				'name'        => 'Allbirds',
				'kind'        => 'shopify',
				'note'        => 'Shoes & apparel',
				'home'        => 'https://www.allbirds.com',
				'bases'       => [ 'https://www.allbirds.com', 'https://allbirds.com' ],
			],
			'ayocin'     => [
				'id'          => 'ayocin',
				'name'        => 'Ayocin',
				'kind'        => 'bundled',
				'note'        => 'ATMOS lamp',
				'home'        => 'https://ayocin.com',
				'data_file'   => NC_MOCK_DIR . '/data/ayocin.json',
			],
			'alice-lane' => [
				'id'          => 'alice-lane',
				'name'        => 'Alice Lane Home',
				'kind'        => 'shopify',
				'note'        => 'Furniture & home',
				'home'        => 'https://alicelanehome.com',
				'bases'       => [ 'https://alicelanehome.com', 'https://www.alicelanehome.com' ],
			],
			'rhode'      => [
				'id'          => 'rhode',
				'name'        => 'Rhode Skin',
				'kind'        => 'shopify',
				'note'        => 'Skincare & beauty',
				'home'        => 'https://www.rhodeskin.com',
				'bases'       => [ 'https://www.rhodeskin.com', 'https://rhodeskin.com' ],
			],
		];
	}

	public function refresh( $source_ids = null ) {
		@set_time_limit( 180 );

		$sources = self::sources();
		if ( is_array( $source_ids ) && $source_ids ) {
			$sources = array_intersect_key( $sources, array_flip( $source_ids ) );
		}

		$items  = [];
		$counts = [];
		$errors = [];

		foreach ( $sources as $id => $source ) {
			try {
				$fetched       = $this->fetch_source( $source );
				$counts[ $id ] = count( $fetched );
				$items         = array_merge( $items, $fetched );
			} catch ( \Exception $e ) {
				$errors[ $id ] = $e->getMessage();
				$counts[ $id ] = 0;
			}
		}

		$this->save_cache( $items );
		$this->items  = $items;
		$this->loaded = true;

		$meta = [
			'fetched_at' => time(),
			'counts'     => $counts,
			'errors'     => $errors,
			'total'      => count( $items ),
		];
		update_option( self::META_OPTION, $meta, false );

		return $meta;
	}

	public function all() {
		$this->ensure_loaded();
		return $this->items;
	}

	public function meta() {
		$meta = get_option( self::META_OPTION, [] );
		return is_array( $meta ) ? $meta : [];
	}

	public function has_cache() {
		return is_readable( $this->cache_path() );
	}

	/**
	 * Next unused specs, round-robin across the selected sources so a batch
	 * of 10 is a mix of shoes, furniture, skincare, and the ATMOS lamp.
	 */
	public function take( $count, $source_ids = null ) {
		$this->ensure_loaded();

		$allowed = $source_ids ? array_values( $source_ids ) : array_keys( self::sources() );
		$buckets = [];
		foreach ( $allowed as $id ) {
			$buckets[ $id ] = [];
		}

		$imported = $this->imported_slugs();

		foreach ( $this->items as $spec ) {
			$id = $spec['source_id'];
			if ( ! isset( $buckets[ $id ] ) ) {
				continue;
			}
			if ( isset( $imported[ $spec['slug'] ] ) ) {
				continue;
			}
			if ( empty( $spec['images'] ) ) {
				continue;
			}
			$buckets[ $id ][] = $spec;
		}

		// Rotate the starting source so take(1) (one AJAX product at a time)
		// still mixes brands instead of draining Allbirds first.
		$start = count( $imported ) % max( 1, count( $allowed ) );
		$ordered = array_merge(
			array_slice( $allowed, $start ),
			array_slice( $allowed, 0, $start )
		);

		$picked = [];
		while ( count( $picked ) < $count ) {
			$added = false;
			foreach ( $ordered as $id ) {
				if ( empty( $buckets[ $id ] ) ) {
					continue;
				}
				$picked[] = array_shift( $buckets[ $id ] );
				$added    = true;
				if ( count( $picked ) >= $count ) {
					break;
				}
			}
			if ( ! $added ) {
				break;
			}
		}

		return $picked;
	}

	public function remaining_count( $source_ids = null ) {
		return count( $this->take( 10000, $source_ids ) );
	}

	public function counts_by_source() {
		$this->ensure_loaded();
		$imported = $this->imported_slugs();
		$out      = [];

		foreach ( self::sources() as $id => $source ) {
			$total   = 0;
			$unused  = 0;
			foreach ( $this->items as $spec ) {
				if ( $spec['source_id'] !== $id ) {
					continue;
				}
				$total++;
				if ( ! isset( $imported[ $spec['slug'] ] ) && ! empty( $spec['images'] ) ) {
					$unused++;
				}
			}
			$out[ $id ] = [
				'id'     => $id,
				'name'   => $source['name'],
				'note'   => $source['note'],
				'home'   => $source['home'],
				'total'  => $total,
				'unused' => $unused,
			];
		}

		return $out;
	}

	private function fetch_source( array $source ) {
		if ( 'bundled' === $source['kind'] ) {
			return $this->load_bundled( $source );
		}

		$last_error = 'No storefront JSON endpoint responded.';

		foreach ( $source['bases'] as $base ) {
			try {
				$raw = $this->fetch_shopify_pages( $base );
				if ( $raw ) {
					return $this->normalize_shopify_list( $raw, $source );
				}
			} catch ( \Exception $e ) {
				$last_error = $e->getMessage();
			}
		}

		throw new \Exception( $last_error );
	}

	private function fetch_shopify_pages( $base ) {
		$products = [];

		for ( $page = 1; $page <= 8; $page++ ) {
			$url      = rtrim( $base, '/' ) . '/products.json?limit=' . self::PAGE_LIMIT . '&page=' . $page;
			$response = wp_remote_get(
				$url,
				[
					'timeout'     => 45,
					'redirection' => 5,
					'sslverify'   => true,
					'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 NorthMock/1.0',
					'headers'     => [
						'Accept' => 'application/json',
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				throw new \Exception( 'HTTP ' . $code . ' from ' . $url );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$page_products = isset( $body['products'] ) && is_array( $body['products'] )
				? $body['products']
				: [];

			if ( ! $page_products ) {
				break;
			}

			$products = array_merge( $products, $page_products );

			if ( count( $products ) >= self::MAX_PER_SOURCE ) {
				break;
			}

			if ( count( $page_products ) < self::PAGE_LIMIT ) {
				break;
			}
		}

		return array_slice( $products, 0, self::MAX_PER_SOURCE );
	}

	private function normalize_shopify_list( array $raw, array $source ) {
		$out = [];
		foreach ( $raw as $product ) {
			$spec = $this->normalize_shopify_product( $product, $source );
			if ( $spec ) {
				$out[] = $spec;
			}
		}
		return $out;
	}

	private function normalize_shopify_product( array $product, array $source ) {
		$handle = sanitize_title( isset( $product['handle'] ) ? $product['handle'] : '' );
		if ( ! $handle ) {
			return null;
		}

		$variants = isset( $product['variants'] ) && is_array( $product['variants'] )
			? $product['variants']
			: [];
		$options = isset( $product['options'] ) && is_array( $product['options'] )
			? $product['options']
			: [];
		$images = isset( $product['images'] ) && is_array( $product['images'] )
			? $product['images']
			: [];

		$image_urls = [];
		foreach ( $images as $image ) {
			if ( ! empty( $image['src'] ) ) {
				$image_urls[] = $image['src'];
			}
		}

		$option_names = [];
		foreach ( $options as $option ) {
			$name = isset( $option['name'] ) ? trim( $option['name'] ) : '';
			if ( $name && ! $this->is_default_title_option( $name, $option ) ) {
				$option_names[] = $name;
			}
		}

		$normalized_variants = [];
		foreach ( $variants as $variant ) {
			$normalized_variants[] = $this->normalize_shopify_variant( $variant, $option_names, $images );
		}

		if ( ! $normalized_variants ) {
			return null;
		}

		$prices = array_map(
			function ( $v ) {
				return (float) $v['price'];
			},
			$normalized_variants
		);
		$base_price = min( $prices );

		$compare = null;
		foreach ( $normalized_variants as $v ) {
			if ( $v['compare_price'] && (float) $v['compare_price'] > $base_price ) {
				$compare = (float) $v['compare_price'];
				break;
			}
		}

		$physical = ! empty( $normalized_variants[0]['requires_shipping'] );
		$grams    = 0;
		foreach ( $normalized_variants as $v ) {
			if ( $v['grams'] > $grams ) {
				$grams = $v['grams'];
			}
		}

		$sku = $normalized_variants[0]['sku']
			? $normalized_variants[0]['sku']
			: strtoupper( $source['id'] . '-' . $handle );

		$tags = $this->clean_tags( isset( $product['tags'] ) ? $product['tags'] : [] );

		return [
			'source_id'          => $source['id'],
			'source_name'        => $source['name'],
			'source_product_id'  => isset( $product['id'] ) ? (string) $product['id'] : $handle,
			'handle'             => $handle,
			'slug'               => NC_MOCK_SLUG_PREFIX . $source['id'] . '-' . $handle,
			'name'               => wp_strip_all_tags( isset( $product['title'] ) ? $product['title'] : $handle ),
			'description'        => $this->clean_description( isset( $product['body_html'] ) ? $product['body_html'] : '' ),
			'sku'                => $this->clip( 'NCM-' . strtoupper( $source['id'] ) . '-' . $sku, 128 ),
			'base_price'         => round( $base_price, 2 ),
			'compare_price'      => $compare,
			'product_type'       => isset( $product['product_type'] ) && $product['product_type']
				? $product['product_type']
				: $source['note'],
			'vendor'             => isset( $product['vendor'] ) ? $product['vendor'] : $source['name'],
			'tags'               => $tags,
			'images'             => array_slice( array_values( array_unique( $image_urls ) ), 0, 5 ),
			'options'            => $this->normalize_options( $options, $normalized_variants ),
			'variants'           => $normalized_variants,
			'is_physical'        => $physical ? 1 : 0,
			'weight'             => $grams > 0 ? round( $grams / 1000, 3 ) : null,
		];
	}

	private function normalize_shopify_variant( array $variant, array $option_names, array $images ) {
		$choices = [];
		foreach ( $option_names as $i => $name ) {
			$key = 'option' . ( $i + 1 );
			if ( isset( $variant[ $key ] ) && null !== $variant[ $key ] && '' !== $variant[ $key ] ) {
				$choices[ $name ] = (string) $variant[ $key ];
			}
		}

		$image = null;
		if ( ! empty( $variant['featured_image']['src'] ) ) {
			$image = $variant['featured_image']['src'];
		} elseif ( ! empty( $variant['id'] ) ) {
			foreach ( $images as $img ) {
				$ids = isset( $img['variant_ids'] ) ? (array) $img['variant_ids'] : [];
				if ( in_array( $variant['id'], $ids, false ) && ! empty( $img['src'] ) ) {
					$image = $img['src'];
					break;
				}
			}
		}

		return [
			'sku'               => isset( $variant['sku'] ) ? (string) $variant['sku'] : '',
			'price'             => isset( $variant['price'] ) ? (float) $variant['price'] : 0,
			'compare_price'     => ! empty( $variant['compare_at_price'] ) ? (float) $variant['compare_at_price'] : null,
			'grams'             => isset( $variant['grams'] ) ? (int) $variant['grams'] : 0,
			'available'         => ! empty( $variant['available'] ),
			'requires_shipping' => ! isset( $variant['requires_shipping'] ) || $variant['requires_shipping'],
			'choices'           => $choices,
			'image'             => $image,
		];
	}

	private function normalize_options( array $options, array $variants ) {
		$used = [];
		foreach ( $variants as $variant ) {
			foreach ( $variant['choices'] as $name => $value ) {
				$used[ $name ][ $value ] = true;
			}
		}

		$out = [];
		foreach ( $options as $option ) {
			$name = isset( $option['name'] ) ? trim( $option['name'] ) : '';
			if ( ! $name || $this->is_default_title_option( $name, $option ) ) {
				continue;
			}
			if ( empty( $used[ $name ] ) ) {
				continue;
			}

			$values = array_keys( $used[ $name ] );
			$out[]  = [
				'name'   => $name,
				'type'   => $this->option_type( $name, $values ),
				'values' => $values,
			];
		}

		return $out;
	}

	private function is_default_title_option( $name, $option ) {
		if ( 0 !== strcasecmp( $name, 'Title' ) ) {
			return false;
		}
		$values = isset( $option['values'] ) ? $option['values'] : [];
		return 1 === count( $values ) && 0 === strcasecmp( (string) $values[0], 'Default Title' );
	}

	private function option_type( $name, array $values ) {
		$lower = strtolower( $name );

		if ( preg_match( '/color|colour|hue|shade|finish|swatch/', $lower ) ) {
			return 'color';
		}

		if ( preg_match( '/size|length|width|dimension|capacity|volume/', $lower ) ) {
			return 'size';
		}

		$size_like = 0;
		foreach ( $values as $value ) {
			if ( preg_match( '/^(xxs|xs|s|m|l|xl|xxl|xxxl|[0-9]+(\.[05])?)$/i', trim( $value ) ) ) {
				$size_like++;
			}
		}
		if ( $values && $size_like / count( $values ) >= 0.6 ) {
			return 'size';
		}

		return 'text';
	}

	private function clean_tags( $tags ) {
		if ( is_string( $tags ) ) {
			$tags = array_map( 'trim', explode( ',', $tags ) );
		}

		$out = [];
		foreach ( (array) $tags as $tag ) {
			$tag = trim( wp_strip_all_tags( (string) $tag ) );
			if ( '' === $tag ) {
				continue;
			}
			if ( false !== strpos( $tag, '::' ) || false !== strpos( $tag, '=>' ) ) {
				continue;
			}
			if ( preg_match( '/^(YCRF_|YGroup_|SF |DNAM |loop::)/i', $tag ) ) {
				continue;
			}
			if ( strlen( $tag ) > 40 ) {
				continue;
			}
			$out[] = $tag;
			if ( count( $out ) >= 4 ) {
				break;
			}
		}

		return $out;
	}

	private function clean_description( $html ) {
		$html = (string) $html;
		$html = wp_kses_post( $html );
		$html = preg_replace( '/<p>\s*<\/p>/', '', $html );
		$html = trim( $html );

		if ( '' === $html ) {
			return '';
		}

		if ( strlen( wp_strip_all_tags( $html ) ) > 4000 ) {
			$html = wp_trim_words( wp_strip_all_tags( $html ), 220, '…' );
			$html = '<p>' . esc_html( $html ) . '</p>';
		}

		return $html;
	}

	private function load_bundled( array $source ) {
		$path = $source['data_file'];
		if ( ! is_readable( $path ) ) {
			throw new \Exception( 'Bundled catalog missing: ' . basename( $path ) );
		}

		$json = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $json ) ) {
			throw new \Exception( 'Bundled catalog is not valid JSON.' );
		}

		$products = isset( $json['products'] ) ? $json['products'] : [ $json ];
		$out      = [];

		foreach ( $products as $product ) {
			$spec = $this->normalize_shopify_product( $product, $source );
			if ( $spec ) {
				$out[] = $spec;
			}
		}

		return $out;
	}

	private function imported_slugs() {
		$ea    = Plugin::ea();
		$found = $ea->list(
			'products',
			[
				'and',
				[ 'like', 'slug', NC_MOCK_SLUG_PREFIX . '%' ],
				[ 'is', 'deleted', null ],
			],
			[ 'limit' => 5000 ]
		);

		$slugs = [];
		foreach ( $found as $row ) {
			$slugs[ $row['slug'] ] = true;
		}

		return $slugs;
	}

	private function ensure_loaded() {
		if ( $this->loaded ) {
			return;
		}

		$path = $this->cache_path();
		if ( is_readable( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $json ) ) {
				$this->items  = $json;
				$this->loaded = true;
				return;
			}
		}

		$this->items  = [];
		$this->loaded = true;
	}

	private function save_cache( array $items ) {
		ImageStore::ensure_directory();
		file_put_contents(
			$this->cache_path(),
			wp_json_encode( $items ),
			LOCK_EX
		);
	}

	private function cache_path() {
		return ImageStore::directory() . '/' . self::CACHE_FILE;
	}

	private function clip( $value, $max ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max );
	}
}
