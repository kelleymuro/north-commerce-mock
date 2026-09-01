<?php
defined( 'ABSPATH' ) || exit;

$plugin = \NorthCommerceMock\Plugin::instance();
$status = $plugin->admin->status_payload();
$target = NC_MOCK_TARGET_COUNT;
$total  = (int) $status['total'];
$pct    = $target > 0 ? min( 100, round( ( $total / $target ) * 100 ) ) : 0;
$need   = max( 0, $target - $total );
?>
<div class="wrap nc-mock">
	<header class="nc-mock__header">
		<div>
			<h1><?php esc_html_e( 'North Mock', 'north-commerce-mock' ); ?></h1>
			<p class="nc-mock__lede">
				<?php esc_html_e( 'Generate test products with real names, copy, variants, and images. Photos are saved on this site so they keep working if the source store takes them down.', 'north-commerce-mock' ); ?>
			</p>
		</div>
		<a class="nc-mock__ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=north-commerce-products' ) ); ?>">
			<?php esc_html_e( 'View products', 'north-commerce-mock' ); ?>
		</a>
	</header>

	<section class="nc-mock__stats" data-nc-mock-stats>
		<div class="nc-mock__stat">
			<span class="nc-mock__stat-value" data-stat="total"><?php echo esc_html( (string) $total ); ?></span>
			<span class="nc-mock__stat-label"><?php esc_html_e( 'Products in store', 'north-commerce-mock' ); ?></span>
		</div>
		<div class="nc-mock__stat">
			<span class="nc-mock__stat-value" data-stat="mock"><?php echo esc_html( (string) $status['mock'] ); ?></span>
			<span class="nc-mock__stat-label"><?php esc_html_e( 'Mock products', 'north-commerce-mock' ); ?></span>
		</div>
		<div class="nc-mock__stat">
			<span class="nc-mock__stat-value" data-stat="remaining"><?php echo esc_html( (string) $status['remaining'] ); ?></span>
			<span class="nc-mock__stat-label"><?php esc_html_e( 'Still in catalog', 'north-commerce-mock' ); ?></span>
		</div>
		<div class="nc-mock__stat nc-mock__stat--target">
			<span class="nc-mock__stat-value"><?php echo esc_html( (string) $target ); ?></span>
			<span class="nc-mock__stat-label"><?php esc_html_e( 'Target (parent products)', 'north-commerce-mock' ); ?></span>
		</div>
	</section>

	<div class="nc-mock__progress">
		<div class="nc-mock__progress-track">
			<div class="nc-mock__progress-fill" data-progress-fill style="width: <?php echo esc_attr( (string) $pct ); ?>%"></div>
		</div>
		<p class="nc-mock__progress-caption" data-progress-caption>
			<?php
			if ( $need > 0 ) {
				printf(
					/* translators: 1: current count, 2: products still needed */
					esc_html__( '%1$d of %2$d — generate %3$d more to hit the target. Variant SKUs do not count.', 'north-commerce-mock' ),
					$total,
					$target,
					$need
				);
			} else {
				esc_html_e( 'Target reached. You can still generate more.', 'north-commerce-mock' );
			}
			?>
		</p>
	</div>

	<section class="nc-mock__card">
		<div class="nc-mock__card-head">
			<h2><?php esc_html_e( 'Sources', 'north-commerce-mock' ); ?></h2>
			<button type="button" class="button" data-action="refresh">
				<?php esc_html_e( 'Refresh catalogs', 'north-commerce-mock' ); ?>
			</button>
		</div>
		<p class="nc-mock__hint">
			<?php esc_html_e( 'Product data is pulled from each store’s public catalog, then images are downloaded into wp-content/uploads/nc-mock/.', 'north-commerce-mock' ); ?>
		</p>
		<ul class="nc-mock__sources" data-source-list>
			<?php foreach ( $status['sources'] as $source ) : ?>
				<li>
					<label>
						<input type="checkbox" name="nc_mock_source" value="<?php echo esc_attr( $source['id'] ); ?>" checked>
						<span class="nc-mock__source-name"><?php echo esc_html( $source['name'] ); ?></span>
						<span class="nc-mock__source-note"><?php echo esc_html( $source['note'] ); ?></span>
					</label>
					<span class="nc-mock__source-count" data-source-count="<?php echo esc_attr( $source['id'] ); ?>">
						<?php
						printf(
							/* translators: 1: unused products, 2: total in cache */
							esc_html__( '%1$d ready · %2$d cached', 'north-commerce-mock' ),
							(int) $source['unused'],
							(int) $source['total']
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="nc-mock__cache-meta" data-cache-meta>
			<?php
			if ( ! empty( $status['fetchedAt'] ) ) {
				printf(
					/* translators: human time difference */
					esc_html__( 'Catalog last fetched %s ago.', 'north-commerce-mock' ),
					esc_html( human_time_diff( $status['fetchedAt'] ) )
				);
			} else {
				esc_html_e( 'No catalog cached yet. Refresh, or generate and the first run will fetch it.', 'north-commerce-mock' );
			}
			?>
		</p>
	</section>

	<section class="nc-mock__card">
		<div class="nc-mock__card-head">
			<h2><?php esc_html_e( 'Generate', 'north-commerce-mock' ); ?></h2>
		</div>
		<div class="nc-mock__actions">
			<button type="button" class="button button-primary" data-action="generate" data-count="10">
				<?php esc_html_e( '10 products', 'north-commerce-mock' ); ?>
			</button>
			<button type="button" class="button button-primary" data-action="generate" data-count="25">
				<?php esc_html_e( '25 products', 'north-commerce-mock' ); ?>
			</button>
			<button type="button" class="button button-primary" data-action="generate" data-count="50">
				<?php esc_html_e( '50 products', 'north-commerce-mock' ); ?>
			</button>
			<button type="button" class="button" data-action="fill" <?php disabled( $need <= 0 ); ?>>
				<?php
				echo esc_html(
					$need > 0
						? sprintf(
							/* translators: number of products needed to reach 150 */
							__( 'Fill to 150 (%d)', 'north-commerce-mock' ),
							$need
						)
						: __( 'Target reached', 'north-commerce-mock' )
				);
				?>
			</button>
			<button type="button" class="button nc-mock__stop" data-action="stop" hidden>
				<?php esc_html_e( 'Stop', 'north-commerce-mock' ); ?>
			</button>
		</div>
		<div class="nc-mock__run" data-run hidden>
			<div class="nc-mock__progress-track nc-mock__progress-track--run">
				<div class="nc-mock__progress-fill" data-run-fill style="width:0%"></div>
			</div>
			<p class="nc-mock__run-caption" data-run-caption></p>
		</div>
		<ol class="nc-mock__log" data-log></ol>
	</section>

	<section class="nc-mock__card nc-mock__card--danger">
		<div class="nc-mock__card-head">
			<h2><?php esc_html_e( 'Remove mock products', 'north-commerce-mock' ); ?></h2>
			<button type="button" class="button" data-action="remove">
				<?php esc_html_e( 'Archive all mock products', 'north-commerce-mock' ); ?>
			</button>
		</div>
		<p class="nc-mock__hint">
			<?php esc_html_e( 'Only products created by this plugin (slug prefix ncm-) are archived. Locally saved images stay on disk.', 'north-commerce-mock' ); ?>
		</p>
	</section>

	<p class="nc-mock__legal">
		<?php esc_html_e( 'For local QA only. Names, descriptions, and photographs belong to the source brands. Do not use this catalog on a public storefront.', 'north-commerce-mock' ); ?>
	</p>
</div>
