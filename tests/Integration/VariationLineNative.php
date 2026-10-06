<?php
/**
 * Opt-in native proof: variable products in the returned cart, against real WooCommerce.
 *
 * Run via WP-CLI eval requiring this file on a disposable local WordPress/WooCommerce with this
 * candidate installed: POW_NATIVE_TESTS=disposable, an actual administrator, no unit bootstrap.
 * Creates its own global attribute (with terms), three variable products and their variations,
 * adds chosen variations to a real WC_Cart in a throwaway native session, maps them with the real
 * PoomMapper and builds the PunchOutOrderMessage with the real Builder. Then deletes everything
 * it created (products, variations, terms, attribute) and restores the request's session objects.
 *
 * Proves, with WooCommerce's own variation titles and formatter:
 *  - SupplierPartID is the variation's SKU and SupplierPartAuxiliaryID is "<parent>|<variation>";
 *  - a two-attribute variation (WooCommerce titles it "<parent> - <values>") is returned unchanged;
 *  - a three-attribute variation and an "Any …" value chosen in the cart reach Description/ShortName;
 *  - ALL CAPS applies to the completed text; the document is DTD-valid where a DTD file is supplied.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'disposable' !== getenv( 'POW_NATIVE_TESTS' ) || 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) || ! current_user_can( 'manage_woocommerce' ) || get_current_user_id() <= 0 ) {
	throw new RuntimeException( 'Requires opted-in disposable local WP/Woo CLI and an actual fixture administrator.' );
}

final class VariationLineNative {
	private int $passed = 0;
	/** @var list<int> */
	private array $products = [];
	private int $attribute_id = 0;
	private string $taxonomy = '';

	private function check( bool $ok, string $label, string $detail = '' ): void {
		if ( ! $ok ) { throw new RuntimeException( $label . ( '' !== $detail ? ' — got: ' . $detail : '' ) ); }
		++$this->passed;
		echo 'PASS ' . $label . "\n";
	}

	/** A global attribute with terms, registered for this request the way WooCommerce does after creation. */
	private function attribute(): void {
		$slug = 'powvar' . substr( bin2hex( random_bytes( 3 ) ), 0, 5 );
		$id   = wc_create_attribute( [ 'name' => 'Colour ' . $slug, 'slug' => $slug, 'type' => 'select', 'has_archives' => false ] );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'attribute: ' . $id->get_error_message() ); }
		$this->attribute_id = (int) $id;
		$this->taxonomy     = wc_attribute_taxonomy_name( $slug );
		register_taxonomy( $this->taxonomy, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
		foreach ( [ 'Black', 'Red', 'Navy', 'As Supplied' ] as $term ) {
			$made = wp_insert_term( $term, $this->taxonomy );
			if ( is_wp_error( $made ) ) { throw new RuntimeException( 'term: ' . $made->get_error_message() ); }
		}
	}

	private function term_slug( string $name ): string {
		$term = get_term_by( 'name', $name, $this->taxonomy );
		if ( ! $term ) { throw new RuntimeException( 'term missing: ' . $name ); }
		return $term->slug;
	}

	/**
	 * @param array<string, list<string>> $attributes name => options; the first is the global colour.
	 * @param list<array{sku: string, price: string, attributes: array<string, string>}> $variations
	 * @return array{parent: int, variations: list<int>}
	 */
	private function variable( string $name, string $sku, array $attributes, array $variations ): array {
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$product->set_sku( $sku . '-' . substr( bin2hex( random_bytes( 2 ) ), 0, 4 ) );
		$product->set_status( 'publish' );
		$list = [];
		$position = 0;
		foreach ( $attributes as $attribute_name => $options ) {
			$attribute = new WC_Product_Attribute();
			if ( $this->taxonomy === $attribute_name ) {
				$attribute->set_id( $this->attribute_id );
				$attribute->set_name( $this->taxonomy );
				$attribute->set_options( array_map( fn( string $o ): int => (int) get_term_by( 'name', $o, $this->taxonomy )->term_id, $options ) );
			} else {
				$attribute->set_name( $attribute_name );
				$attribute->set_options( $options );
			}
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$list[] = $attribute;
		}
		$product->set_attributes( $list );
		$parent = $product->save();
		$this->products[] = $parent;
		$ids = [];
		foreach ( $variations as $spec ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent );
			$variation->set_sku( $spec['sku'] . '-' . substr( bin2hex( random_bytes( 2 ) ), 0, 4 ) );
			$variation->set_regular_price( $spec['price'] );
			$variation->set_status( 'publish' );
			$variation->set_stock_status( 'instock' );
			$variation->set_attributes( $spec['attributes'] );
			$ids[] = $variation->save();
		}
		WC_Product_Variable::sync( $parent );
		return [ 'parent' => $parent, 'variations' => $ids ];
	}

	/** @param array<string, string> $chosen attribute_* => value, as the add-to-cart form posts it. */
	private function add( int $parent, int $variation, int $quantity, array $chosen ): void {
		if ( ! WC()->cart->add_to_cart( $parent, $quantity, $variation, $chosen ) ) {
			throw new RuntimeException( 'add_to_cart refused ' . $variation . ' ' . wp_json_encode( wc_get_notices() ) );
		}
		wc_clear_notices();
	}

	private function poom( array $mapped ): DOMXPath {
		$xml = ( new POW\Cxml\Builder() )->poom(
			[
				'version' => '1.2.008', 'payload_id' => 'native-variation@shop', 'timestamp' => gmdate( 'Y-m-d\TH:i:sP' ), 'deployment_mode' => 'test',
				'from' => [ 'domain' => 'NetworkID', 'identity' => 'supplier' ], 'to' => [ 'domain' => 'NetworkID', 'identity' => 'buyer' ], 'sender' => [ 'domain' => 'NetworkID', 'identity' => 'supplier' ],
				'buyer_cookie' => 'native-variation', 'currency' => $mapped['currency'], 'total_cents' => $mapped['total_cents'], 'supplier_order_info' => null, 'items' => $mapped['items'],
			]
		);
		$dtd = getenv( 'POW_NATIVE_DTD' );
		if ( is_string( $dtd ) && '' !== $dtd && is_file( $dtd ) ) {
			$local = str_replace( 'http://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd', 'file://' . $dtd, $xml );
			$doc   = new DOMDocument();
			$prev  = libxml_use_internal_errors( true );
			$valid = $doc->loadXML( $local, LIBXML_DTDLOAD ) && $doc->validate();
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			$this->check( $valid, 'POOM with variation lines is valid against the cXML 1.2.008 DTD' );
		}
		$doc = new DOMDocument();
		$this->check( $doc->loadXML( $xml, LIBXML_NONET ), 'POOM is well-formed' );
		return new DOMXPath( $doc );
	}

	private function run_cases(): void {
		$this->attribute();
		$tax = $this->taxonomy;
		// Two attributes (global colour + custom size): WooCommerce titles each variation "<parent> - <values>".
		$cooler = $this->variable( 'Ice Bucket', 'NAT-CC', [ $tax => [ 'As Supplied', 'Black' ], 'Size' => [ 'As Supplied' ] ], [
			[ 'sku' => 'NAT-CC-BLK', 'price' => '600.00', 'attributes' => [ $tax => $this->term_slug( 'Black' ), 'size' => 'As Supplied' ] ],
		] );
		// Three attributes: WooCommerce titles the variation with the parent name only.
		$cap = $this->variable( 'Deluxe Cap', 'NAT-DC', [ $tax => [ 'Red' ], 'Size' => [ 'M', 'L' ], 'Fit' => [ 'Curved' ] ], [
			[ 'sku' => 'NAT-DC-RMC', 'price' => '80.00', 'attributes' => [ $tax => $this->term_slug( 'Red' ), 'size' => 'M', 'fit' => 'Curved' ] ],
			[ 'sku' => 'NAT-DC-RLC', 'price' => '80.00', 'attributes' => [ $tax => $this->term_slug( 'Red' ), 'size' => 'L', 'fit' => 'Curved' ] ],
		] );
		// "Any size": the variation fixes the colour only; the buyer picks the size on the product page.
		$pullover = $this->variable( 'Pullover', 'NAT-PO', [ $tax => [ 'Navy' ], 'Size' => [ 'S', 'M' ] ], [
			[ 'sku' => 'NAT-PO-NVY', 'price' => '99.00', 'attributes' => [ $tax => $this->term_slug( 'Navy' ), 'size' => '' ] ],
		] );

		$titles = [ wc_get_product( $cooler['variations'][0] )->get_name(), wc_get_product( $cap['variations'][0] )->get_name(), wc_get_product( $pullover['variations'][0] )->get_name() ];
		$this->check( 'Ice Bucket - Black, As Supplied' === $titles[0], 'WooCommerce titles a two-attribute variation with its values', $titles[0] );
		$this->check( 'Deluxe Cap' === $titles[1], 'WooCommerce titles a three-attribute variation with the parent name only', $titles[1] );
		$this->check( 'Pullover - Navy' === $titles[2], 'WooCommerce titles an "Any size" variation with its fixed value only', $titles[2] );

		$this->add( $cooler['parent'], $cooler['variations'][0], 2, [ 'attribute_' . $tax => $this->term_slug( 'Black' ), 'attribute_size' => 'As Supplied' ] );
		$this->add( $cap['parent'], $cap['variations'][0], 1, [ 'attribute_' . $tax => $this->term_slug( 'Red' ), 'attribute_size' => 'M', 'attribute_fit' => 'Curved' ] );
		$this->add( $cap['parent'], $cap['variations'][1], 2, [ 'attribute_' . $tax => $this->term_slug( 'Red' ), 'attribute_size' => 'L', 'attribute_fit' => 'Curved' ] );
		$this->add( $pullover['parent'], $pullover['variations'][0], 3, [ 'attribute_' . $tax => $this->term_slug( 'Navy' ), 'attribute_size' => 'M' ] );
		WC()->cart->calculate_totals();

		$settings = POW\Plugin::instance()->settings();
		$logger   = POW\Plugin::instance()->logger();
		$plain    = ( new POW\Cart\PoomMapper( $settings, $logger ) )->from_cart( POW\Partners\Partner::from_row( [ 'id' => 1, 'name' => 'Native variation', 'allcaps_transform' => '0' ] ) );
		$x        = $this->poom( $plain );

		$skus = []; $aux = []; $names = [];
		foreach ( $x->query( '//ItemIn' ) as $item ) {
			$skus[]  = $x->evaluate( 'string(ItemID/SupplierPartID)', $item );
			$aux[]   = $x->evaluate( 'string(ItemID/SupplierPartAuxiliaryID)', $item );
			$names[] = $x->evaluate( 'string(ItemDetail/Description/ShortName)', $item );
		}
		$this->check( 4 === count( $skus ), 'one ItemIn per chosen variation', (string) count( $skus ) );
		$expected_skus = array_map( static fn( int $id ): string => wc_get_product( $id )->get_sku(), [ $cooler['variations'][0], $cap['variations'][0], $cap['variations'][1], $pullover['variations'][0] ] );
		$this->check( $expected_skus === $skus, 'SupplierPartID is each variation\'s own SKU', implode( ',', $skus ) );
		$this->check( [ $cooler['parent'] . '|' . $cooler['variations'][0], $cap['parent'] . '|' . $cap['variations'][0], $cap['parent'] . '|' . $cap['variations'][1], $pullover['parent'] . '|' . $pullover['variations'][0] ] === $aux, 'SupplierPartAuxiliaryID is parent|variation', implode( ',', $aux ) );
		$this->check( 'Ice Bucket - Black, As Supplied' === $names[0], 'two-attribute variation text unchanged (values not repeated)', $names[0] );
		$this->check( 'Deluxe Cap - Red, M, Curved' === $names[1] && 'Deluxe Cap - Red, L, Curved' === $names[2], 'three-attribute variations carry every value, so two sizes differ', $names[1] . ' / ' . $names[2] );
		$this->check( 'Pullover - Navy, M' === $names[3], 'a size chosen under "Any size" reaches the text', $names[3] );
		$this->check( '600.00' === $x->evaluate( 'string(//ItemIn[1]/ItemDetail/UnitPrice/Money)' ) && '2' === $x->evaluate( 'string(//ItemIn[1]/@quantity)' ), 'variation unit price and quantity' );
		$this->check( $x->evaluate( 'string(//ItemIn[2]/ItemDetail/Description/ShortName)' ) . $x->evaluate( 'string(//ItemIn[2]/ItemDetail/Description/ShortName)' ) === $x->evaluate( 'string(//ItemIn[2]/ItemDetail/Description)' ), 'Description text equals ShortName' );

		$caps = $this->poom( ( new POW\Cart\PoomMapper( $settings, $logger ) )->from_cart( POW\Partners\Partner::from_row( [ 'id' => 1, 'name' => 'Native variation', 'allcaps_transform' => '1' ] ) ) );
		$this->check( 'DELUXE CAP - RED, M, CURVED' === $caps->evaluate( 'string(//ItemIn[2]/ItemDetail/Description/ShortName)' ), 'ALL CAPS applies to the completed variation text' );
		$this->check( strtoupper( $expected_skus[1] ) === $caps->evaluate( 'string(//ItemIn[2]/ItemID/SupplierPartID)' ), 'ALL CAPS applies to the variation SKU as to any SKU' );
	}

	private function cleanup(): void {
		foreach ( array_reverse( $this->products ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				foreach ( $product->get_children() as $child ) { wp_delete_post( $child, true ); }
				wp_delete_post( $id, true );
			}
		}
		if ( '' !== $this->taxonomy ) {
			foreach ( (array) get_terms( [ 'taxonomy' => $this->taxonomy, 'hide_empty' => false, 'fields' => 'ids' ] ) as $term ) { wp_delete_term( (int) $term, $this->taxonomy ); }
		}
		if ( $this->attribute_id > 0 ) { wc_delete_attribute( $this->attribute_id ); }
	}

	public function run(): void {
		$original = [ WC()->session, WC()->customer, WC()->cart ];
		ob_start();
		$disable_persistent_cart = static fn(): bool => false;
		add_filter( 'woocommerce_persistent_cart_enabled', $disable_persistent_cart, 999 );
		try {
			WC()->session = new WC_Session_Handler();
			WC()->session->init();
			WC()->customer = new WC_Customer( get_current_user_id(), true );
			WC()->cart = new WC_Cart();
			$this->run_cases();
			echo 'Passed: ' . $this->passed . " Failed: 0 Skipped: 0\n";
		} finally {
			if ( WC()->cart ) { WC()->cart->empty_cart( false ); }
			$this->cleanup();
			remove_filter( 'woocommerce_persistent_cart_enabled', $disable_persistent_cart, 999 );
			[ WC()->session, WC()->customer, WC()->cart ] = $original;
			$output = ob_get_clean();
			if ( is_string( $output ) ) { echo $output; }
		}
	}
}

( new VariationLineNative() )->run();
