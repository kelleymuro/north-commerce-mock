<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

use NorthCommerce\Db\Collections\ProductOptionTypes;
use NorthCommerce\Db\Collections\ProductStatuses;
use NorthCommerce\Db\Collections\ProductTypes;
use NorthCommerce\Db\Collections\ProductVariantTypes;

/**
 * Create North Commerce products from a catalog spec, with locally saved images.
 */
class Generator {

	const MAX_VARIANTS = 40;

	private $catalog;
	private $images;

	public function __construct( Catalog $catalog, ImageStore $images ) {
		$this->catalog = $catalog;
		$this->images  = $images;
	}

	public function product_count() {
		return (int) Plugin::ea()->count( 'products', [ 'deleted' => null ] );
	}

	public function mock_count() {
		return (int) Plugin::ea()->count(
			'products',
			[
				'and',
				[ 'like', 'slug', NC_MOCK_SLUG_PREFIX . '%' ],
				[ 'is', 'deleted', null ],
			]
		);
	}

	/**
	 * Create one product from a spec. Images are downloaded before the DB
	 * transaction so a slow CDN does not hold a write lock.
	 */
	public function create_from_spec( array $spec ) {
		$existing = Plugin::ea()->get( 'products', [
			'slug'    => $spec['slug'],
			'deleted' => null,
		] );

		if ( $existing ) {
			return [
				'skipped'  => true,
				'id'       => $existing['id'],
				'name'     => $existing['name'],
				'slug'     => $existing['slug'],
				'variants' => 0,
				'images'   => 0,
			];
		}

		$local_gallery = [];
		foreach ( $spec['images'] as $url ) {
			$local = $this->images->localize( $url, ImageStore::PRODUCT_URL_MAX );
			if ( $local ) {
				$local_gallery[] = $local;
			}
		}

		if ( ! $local_gallery ) {
			throw new \Exception( 'Could not save any images for ' . $spec['name'] );
		}

		$variants = $this->cap_variants( $spec['variants'] );
		foreach ( $variants as $i => $variant ) {
			$variants[ $i ]['local_image'] = null;
			if ( ! empty( $variant['image'] ) ) {
				$variants[ $i ]['local_image'] = $this->images->localize(
					$variant['image'],
					ImageStore::VARIANT_URL_MAX
				);
			}
		}

		if ( empty( $variants[0]['local_image'] ) ) {
			$variants[0]['local_image'] = $this->images->localize(
				$spec['images'][0],
				ImageStore::VARIANT_URL_MAX
			);
		}

		$spec['images']   = $local_gallery;
		$spec['variants'] = $variants;

		$product = Plugin::agent()->perspectiveManager()->asAdministrator(
			function () use ( $spec ) {
				return Plugin::agent()->withTx( function () use ( $spec ) {
					return $this->insert_product( $spec );
				} );
			}
		);

		do_action( 'north-commerce/product/created', $product['id'] );

		return [
			'skipped'  => false,
			'id'       => $product['id'],
			'name'     => $product['name'],
			'slug'     => $product['slug'],
			'variants' => count( $variants ),
			'images'   => count( $local_gallery ),
			'source'   => $spec['source_name'],
		];
	}

	public function generate_next( $source_ids = null ) {
		$specs = $this->catalog->take( 1, $source_ids );
		if ( ! $specs ) {
			return null;
		}

		return $this->create_from_spec( $specs[0] );
	}

	public function archive_mock_batch( $limit = 8 ) {
		$ea       = Plugin::ea();
		$products = $ea->list(
			'products',
			[
				'and',
				[ 'like', 'slug', NC_MOCK_SLUG_PREFIX . '%' ],
				[ 'is', 'deleted', null ],
			],
			[ 'limit' => (int) $limit ]
		);

		$archived = 0;
		Plugin::agent()->perspectiveManager()->asAdministrator( function () use ( $ea, $products, &$archived ) {
			foreach ( $products as $product ) {
				$ea->update( 'products', $product, [
					'deleted'           => current_time( 'mysql' ),
					'published'         => null,
					'product_status_id' => ProductStatuses::archive()->id,
				] );
				$archived++;
			}
		} );

		return [
			'archived' => $archived,
			'more'     => $archived >= $limit,
		];
	}

	private function insert_product( array $spec ) {
		$ea       = Plugin::ea();
		$options  = $spec['options'];
		$variants = $spec['variants'];
		$price    = (float) $spec['base_price'];
		$cost     = round( $price * 0.4, 2 );

		$description = $spec['description'];
		if ( '' === $description ) {
			$description = '<p>' . esc_html(
				sprintf(
					'%s from %s.',
					$spec['name'],
					$spec['source_name']
				)
			) . '</p>';
		}

		$product = $ea->create( 'products', [
			'created_by_wp_user_id' => get_current_user_id(),
			'sku'                   => $spec['sku'],
			'product_type_id'       => ProductTypes::oneTime()->id,
			'name'                  => $spec['name'],
			'description'           => $description,
			'slug'                  => $spec['slug'],
			'product_types'         => $spec['is_physical'] ? 'physical' : 'digital',
			'downl_limit'           => 0,
			'published'             => current_time( 'mysql' ),
			'product_status_id'     => ProductStatuses::published()->id,
			'base_price'            => $price,
			'compare_price'         => $spec['compare_price'],
			'cost_of_goods_price'   => $cost,
			'profit'                => round( $price - $cost, 2 ),
			'cost_margin'           => 60,
			'quantity'              => $this->product_quantity( $variants ),
			'weight'                => $spec['weight'],
			'is_physical_product'   => $spec['is_physical'] ? 1 : 0,
			'has_product_variants'  => empty( $options ) ? 0 : 1,
			'id_salt'               => wp_generate_password( 4, false ),
		] );

		foreach ( $spec['images'] as $sequence => $image_url ) {
			$ea->create( 'product_images', [
				'product_id' => $product['id'],
				'image_url'  => $image_url,
				'sequence'   => $sequence,
			] );
		}

		if ( empty( $options ) ) {
			$this->create_solo_variant( $product, $spec, $variants );
		} else {
			$this->create_options_and_variants( $product, $spec, $options, $variants );
		}

		$this->attach_taxonomy( $product, $spec );

		return $product;
	}

	private function create_solo_variant( array $product, array $spec, array $variants ) {
		$first = $variants[0];
		$image = ! empty( $first['local_image'] ) ? $first['local_image'] : $spec['images'][0];

		if ( $image && strlen( $image ) > ImageStore::VARIANT_URL_MAX ) {
			$image = null;
		}

		Plugin::ea()->create( 'product_variants', [
			'product_id'              => $product['id'],
			'product_variant_type_id' => ProductVariantTypes::solo()->id,
			'price'                   => $product['base_price'],
			'quantity'                => $this->variant_quantity( $first, 0, $variants ),
			'visible'                 => true,
			'slug'                    => $product['slug'] . '-solo',
			'sku'                     => $this->clip( $product['sku'] . '-SOLO', 128 ),
			'image_url'               => $image,
			'sequence'                => 1,
		] );
	}

	private function create_options_and_variants( array $product, array $spec, array $options, array $variants ) {
		$ea          = Plugin::ea();
		$value_index = [];

		foreach ( $options as $option_sequence => $option_spec ) {
			$option = $ea->create( 'product_options', [
				'product_id'             => $product['id'],
				'name'                   => $option_spec['name'],
				'product_option_type_id' => $this->option_type_id( $option_spec['type'] ),
				'sequence'               => $option_sequence,
			] );

			foreach ( $option_spec['values'] as $value_sequence => $raw ) {
				$display = (string) $raw;
				$value   = 'color' === $option_spec['type']
					? ColorMap::hex( $display )
					: $this->clip( $display, 128 );

				$row = $ea->create( 'product_option_values', [
					'product_option_id'       => $option['id'],
					'display_value'           => $this->clip( $display, 255 ),
					'value'                   => $value,
					'price_offset'            => 0,
					'is_price_offset_percent' => 0,
					'sequence'                => $value_sequence,
				] );

				$key = strtolower( $option_spec['name'] . "\0" . $display );
				$value_index[ $key ] = $row;
			}
		}

		$any_in_stock = false;
		foreach ( $variants as $variant ) {
			if ( $this->variant_quantity( $variant, 0, $variants ) > 0 ) {
				$any_in_stock = true;
				break;
			}
		}

		foreach ( $variants as $sequence => $variant ) {
			$qty = $this->variant_quantity( $variant, $sequence, $variants );
			if ( ! $any_in_stock && 0 === $sequence ) {
				$qty = 24;
			}

			$image = ! empty( $variant['local_image'] ) ? $variant['local_image'] : null;
			if ( $image && strlen( $image ) > ImageStore::VARIANT_URL_MAX ) {
				$image = null;
			}

			$sku = $variant['sku']
				? $this->clip( $variant['sku'], 128 )
				: $this->clip( $product['sku'] . '-' . str_pad( (string) ( $sequence + 1 ), 3, '0', STR_PAD_LEFT ), 128 );

			$row = $ea->create( 'product_variants', [
				'product_id'              => $product['id'],
				'product_variant_type_id' => ProductVariantTypes::standard()->id,
				'sku'                     => $sku,
				'slug'                    => sanitize_title( $product['slug'] . '-' . ( $sequence + 1 ) ),
				'price'                   => round( (float) $variant['price'], 2 ),
				'quantity'                => $qty,
				'visible'                 => true,
				'image_url'               => $image,
				'sequence'                => $sequence,
			] );

			foreach ( $variant['choices'] as $option_name => $display ) {
				$key = strtolower( $option_name . "\0" . $display );
				if ( empty( $value_index[ $key ] ) ) {
					continue;
				}
				$ea->create( 'product_variant_option_values', [
					'product_variant_id'      => $row['id'],
					'product_option_value_id' => $value_index[ $key ]['id'],
				] );
			}
		}
	}

	private function attach_taxonomy( array $product, array $spec ) {
		$ea = Plugin::ea();

		$categories = [
			$this->taxonomy_slug( $spec['source_id'] )     => $spec['source_name'],
			$this->taxonomy_slug( $spec['product_type'] )  => $this->title_case( $spec['product_type'] ),
		];

		foreach ( $categories as $slug => $name ) {
			if ( '' === $slug || '' === $name ) {
				continue;
			}
			$category = $this->ensure_term( 'categories', $slug, $name );
			$ea->create( 'product_categories', [
				'product_id'  => $product['id'],
				'category_id' => $category['id'],
			] );
		}

		$tags = array_merge( [ 'Mock', $spec['source_name'] ], $spec['tags'] );
		$seen = [];
		foreach ( $tags as $name ) {
			$name = trim( (string) $name );
			$slug = $this->taxonomy_slug( $name );
			if ( '' === $name || '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$tag = $this->ensure_term( 'tags', $slug, $name );
			$ea->create( 'product_tags', [
				'product_id' => $product['id'],
				'tag_id'     => $tag['id'],
			] );
		}
	}

	private function ensure_term( $table, $slug, $name ) {
		$ea       = Plugin::ea();
		$existing = $ea->get( $table, [ 'slug' => $slug ] );
		if ( $existing ) {
			return $existing;
		}

		return $ea->create( $table, [
			'name' => $this->clip( $name, 128 ),
			'slug' => $this->clip( $slug, 128 ),
		] );
	}

	private function taxonomy_slug( $value ) {
		$slug = sanitize_title( (string) $value );
		if ( '' === $slug ) {
			return '';
		}
		if ( 0 !== strpos( $slug, 'ncm-' ) ) {
			$slug = 'ncm-' . $slug;
		}
		return $slug;
	}

	private function title_case( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $value;
		}
		if ( $value === strtoupper( $value ) ) {
			return ucwords( strtolower( $value ) );
		}
		return $value;
	}

	private function option_type_id( $type ) {
		switch ( $type ) {
			case 'color':
				return ProductOptionTypes::color()->id;
			case 'size':
				return ProductOptionTypes::size()->id;
			case 'image':
				return ProductOptionTypes::image()->id;
			default:
				return ProductOptionTypes::text()->id;
		}
	}

	private function cap_variants( array $variants ) {
		if ( count( $variants ) <= self::MAX_VARIANTS ) {
			return array_values( $variants );
		}

		$step   = count( $variants ) / self::MAX_VARIANTS;
		$picked = [];
		for ( $i = 0; $i < self::MAX_VARIANTS; $i++ ) {
			$picked[] = $variants[ (int) floor( $i * $step ) ];
		}

		return $picked;
	}

	private function product_quantity( array $variants ) {
		$sum = 0;
		foreach ( $variants as $i => $variant ) {
			$sum += $this->variant_quantity( $variant, $i, $variants );
		}
		return $sum > 0 ? $sum : 24;
	}

	private function variant_quantity( array $variant, $sequence, array $all ) {
		if ( ! empty( $variant['available'] ) ) {
			return 12 + ( ( $sequence * 7 ) % 37 );
		}

		$any_available = false;
		foreach ( $all as $v ) {
			if ( ! empty( $v['available'] ) ) {
				$any_available = true;
				break;
			}
		}

		return $any_available ? 0 : ( 0 === $sequence ? 24 : 0 );
	}

	private function clip( $value, $max ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max );
	}
}
