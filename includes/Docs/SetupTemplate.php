<?php
/**
 * Complete buyer-system setup request templates.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Docs;

use POW\Partners\Partner;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a Dynamics external-catalog configuration without accepting secret material.
 *
 * The child order inside PunchOutSetupRequest (SupplierSetup, BuyerCookie,
 * BrowserFormPost) mirrors what Dynamics 365 for Operations sends, not the
 * DTD's content model (BuyerCookie first, SupplierSetup after BrowserFormPost).
 * Every live request from the certified buyer arrives in the Dynamics order,
 * the parser accepts it, and the working production configuration was pasted
 * in it; tests/Unit/SetupTemplateTest.php pins the order and proves the filled
 * template parses.
 */
final class SetupTemplate {

	public const SHARED_SECRET = 'REPLACE-WITH-ISSUED-SHARED-SECRET';

	public static function for_partner( Partner $partner, string $supplier_url, bool $allow_http = false ): string {
		return self::render(
			[
				'version'           => $partner->cxml_version,
				'deployment_mode'   => $partner->deployment_mode,
				'from_domain'       => $partner->from_domain,
				'from_identity'     => $partner->from_identity,
				'sender_domain'     => $partner->sender_domain,
				'sender_identity'   => $partner->sender_identity,
				'to_domain'         => $partner->to_domain,
				'to_identity'       => $partner->to_identity,
			],
			$supplier_url,
			$allow_http
		);
	}

	/**
	 * @param array{version:string,deployment_mode:string,from_domain:string,from_identity:string,sender_domain:string,sender_identity:string,to_domain:string,to_identity:string} $connection Safe connection fields only; no secret slot is accepted.
	 */
	public static function render( array $connection, string $supplier_url, bool $allow_http = false ): string {
		$version = self::version( $connection['version'] ?? '' );
		$mode = $connection['deployment_mode'] ?? '';
		if ( ! in_array( $mode, [ 'test', 'production' ], true ) ) {
			throw new \DomainException( 'Invalid setup template deployment mode.' );
		}
		$scheme = strtolower( (string) parse_url( $supplier_url, PHP_URL_SCHEME ) );
		if ( ! filter_var( $supplier_url, FILTER_VALIDATE_URL ) || ( 'https' !== $scheme && ! ( $allow_http && 'http' === $scheme ) ) ) {
			throw new \DomainException( 'Invalid supplier setup URL.' );
		}

		$from_domain = self::value( $connection['from_domain'] ?? '', 190 );
		$from_identity = self::value( $connection['from_identity'] ?? '', 190 );
		$sender_domain = self::value( $connection['sender_domain'] ?? '', 190 );
		$sender_identity = self::value( $connection['sender_identity'] ?? '', 190 );
		$to_domain = self::value( $connection['to_domain'] ?? '', 190 );
		$to_identity = self::value( $connection['to_identity'] ?? '', 190 );
		$supplier_url = self::value( $supplier_url, 2048 );

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/' . $version . '/cXML.dtd">' . "\n"
			. '<cXML payloadID="" timestamp="" version="' . $version . '" xml:lang="en-US">' . "\n"
			. ' <Header>' . "\n"
			. '  <From><Credential domain="' . $from_domain . '"><Identity>' . $from_identity . '</Identity></Credential></From>' . "\n"
			. '  <To><Credential domain="' . $to_domain . '"><Identity>' . $to_identity . '</Identity></Credential></To>' . "\n"
			. '  <Sender><Credential domain="' . $sender_domain . '"><Identity>' . $sender_identity . '</Identity><SharedSecret>' . self::SHARED_SECRET . '</SharedSecret></Credential><UserAgent>Dynamics 365 for Operations</UserAgent></Sender>' . "\n"
			. ' </Header>' . "\n"
			. ' <Request deploymentMode="' . $mode . '">' . "\n"
			. '  <PunchOutSetupRequest operation="create">' . "\n"
			. '   <SupplierSetup><URL>' . $supplier_url . '</URL></SupplierSetup>' . "\n"
			. '   <BuyerCookie />' . "\n"
			. '   <BrowserFormPost><URL /></BrowserFormPost>' . "\n"
			. '  </PunchOutSetupRequest>' . "\n"
			. ' </Request>' . "\n"
			. '</cXML>' . "\n";
	}

	private static function version( string $version ): string {
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/D', $version ) ) {
			throw new \DomainException( 'Invalid setup template cXML version.' );
		}
		return $version;
	}

	private static function value( string $value, int $limit ): string {
		if ( '' === $value || strlen( $value ) > 4 * $limit || 1 !== preg_match( '/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]{1,' . $limit . '}\z/u', $value ) ) {
			throw new \DomainException( 'Invalid setup template value.' );
		}
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
