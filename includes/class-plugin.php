<?php
/**
 * Plugin singleton orchestrator.
 *
 * @package AK_Set
 */

declare(strict_types=1);

namespace AK_Set;

/**
 * Class Plugin
 *
 * Central bootstrap. Instantiates handler classes and calls register_hooks()
 * on each one.
 */
final class Plugin {

	private static ?self $instance = null;

	private Set_Validator    $validator;
	private CPT_Set          $cpt;
	private Participant_Form $participant_form;
	private Cart_Handler     $cart_handler;
	private Order_Handler    $order_handler;

	private function __construct() {}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Instantiate handlers and register all WordPress hooks.
	 */
	public function boot(): void {
		$this->validator        = new Set_Validator();
		$this->cpt              = new CPT_Set();
		$this->participant_form = new Participant_Form( $this->validator );
		$this->cart_handler     = new Cart_Handler( $this->validator );
		$this->order_handler    = new Order_Handler();

		$this->cpt->register_hooks();
		$this->participant_form->register_hooks();
		$this->cart_handler->register_hooks();
		$this->order_handler->register_hooks();
	}
}
