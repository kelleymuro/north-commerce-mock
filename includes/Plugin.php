<?php
namespace NorthCommerceMock;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance;
	private static $booted = false;

	/** @var Admin */
	public $admin;

	/** @var Ajax */
	public $ajax;

	/** @var Catalog */
	public $catalog;

	/** @var ImageStore */
	public $images;

	/** @var Generator */
	public $generator;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function booted() {
		return self::$booted;
	}

	public function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		$this->images    = new ImageStore();
		$this->catalog   = new Catalog( $this->images );
		$this->generator = new Generator( $this->catalog, $this->images );
		$this->admin     = new Admin( $this->catalog, $this->generator, $this->images );
		$this->ajax      = new Ajax( $this->catalog, $this->generator, $this->images );

		$this->admin->hooks();
		$this->ajax->hooks();
		$this->images->hooks();
	}

	public static function ea() {
		return \North_Commerce_Db_Agent::instance()->entityAccess();
	}

	public static function agent() {
		return \North_Commerce_Db_Agent::instance();
	}
}
