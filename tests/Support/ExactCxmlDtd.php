<?php
/** Offline validation of the unchanged DTD named by generated XML. */
declare( strict_types = 1 );

final class ExactCxmlDtd {

	/** @return array{valid:bool,errors:list<string>,resolved:list<string>} */
	public static function validate( string $xml ): array {
		$files = [
			'1.2.008' => [ 'c1da190a44885cb01a7afd286a59683b18208c08ebaaf453ad8d6d669c460f57', dirname( __DIR__ ) . '/fixtures/cxml/1.2.008.dtd' ],
			'1.2.071' => [ 'd267ad7b19cbd6608b972821daacab0f4d94a8ec78610b7127dba23222198a64', dirname( __DIR__ ) . '/fixtures/cxml/1.2.071.dtd' ],
		];
		$map = [];
		foreach ( $files as $version => [ $hash, $path ] ) {
			if ( ! is_file( $path ) || hash_file( 'sha256', $path ) !== $hash ) { throw new RuntimeException( 'Exact cXML DTD fixture missing or changed.' ); }
			foreach ( [ 'http', 'https' ] as $scheme ) { $map[ $scheme . '://xml.cxml.org/schemas/cXML/' . $version . '/cXML.dtd' ] = $path; }
		}
		$resolved = [];
		$previous = libxml_use_internal_errors( true );
		$loader = libxml_get_external_entity_loader();
		libxml_clear_errors();
		libxml_set_external_entity_loader( static function ( $public, $system ) use ( $map, &$resolved ) {
			if ( ! isset( $map[ $system ] ) ) { return null; }
			$resolved[] = $system;
			return fopen( $map[ $system ], 'rb' );
		} );
		try {
			$doc = new DOMDocument();
			$loaded = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_DTDLOAD );
			$valid = $loaded && count( $resolved ) === 1 && $doc->validate();
			$errors = array_map( static fn( $error ): string => trim( $error->message ), libxml_get_errors() );
			return [ 'valid' => $valid && [] === $errors, 'errors' => $errors, 'resolved' => $resolved ];
		} finally {
			libxml_set_external_entity_loader( $loader );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}
}
