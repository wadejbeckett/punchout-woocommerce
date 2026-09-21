<?php
/**
 * Who punched in — buyer identity as data, not as an account.
 *
 * @package POW
 * @license AGPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace POW\Buyers;

use POW\Cxml\SetupMessage;

defined( 'ABSPATH' ) || exit;

/**
 * The person behind one visit, read off the setup request.
 *
 * The plugin signs every buyer of a connection in as that connection's own
 * customer account, so the identity is never an account and never a
 * capability: it is the evidence stored on the visit row and stamped on the
 * quote order, and the thing a later visit of the same person supersedes
 * itself by. Both halves may legitimately be empty — a purchasing system is
 * free to send neither a name nor an e-mail, and such a visit is held only
 * by the per-connection cap.
 *
 * Pure value object: no wpdb, no user functions, no hooks, no filter over
 * the resolved identity. The identity ends up on an order as permanent
 * evidence, so nothing outside the request may rewrite it.
 */
final class Identity {

	/** Extrinsics that carry the buyer's identity, in descending precedence. */
	private const IDENTITY_EXTRINSICS = [ 'UserEmail', 'UniqueUsername', 'UniqueName' ];

	/** Extrinsics that carry the buyer's display name, in descending precedence. */
	private const NAME_EXTRINSICS = [ 'UserPrintableName', 'UserFullName', 'UniqueUsername', 'User' ];

	private function __construct(
		/** Lower-cased, trimmed; usually an e-mail address. '' when the request named nobody. */
		public readonly string $identity,
		/** The buyer's own spelling of their name, trimmed. '' when the request named nobody. */
		public readonly string $name,
	) {}

	public static function from_message( SetupMessage $message ): self {
		$identity = self::first( $message, self::IDENTITY_EXTRINSICS );

		if ( '' === $identity ) {
			$identity = trim( (string) $message->contact_email );
		}

		return new self( strtolower( $identity ), self::first( $message, self::NAME_EXTRINSICS ) );
	}

	/**
	 * The indexed, non-reversible form: sha256 of the connection id and the
	 * identity. Scoped by connection so two customers' buyers sharing an
	 * e-mail address never collide, and so the hash of one connection's
	 * buyer says nothing about another's.
	 *
	 * An unidentified buyer hashes to '' rather than to the hash of an
	 * empty string: a shared non-empty hash would make every anonymous
	 * visit of one connection supersede every other.
	 */
	public function hash( int $partner_id ): string {
		return '' !== $this->identity ? hash( 'sha256', $partner_id . '|' . $this->identity ) : '';
	}

	/**
	 * The first extrinsic in $names carrying a usable value.
	 *
	 * SetupMessage::extrinsic() answers '' for an extrinsic that is present
	 * but empty, so a `??` chain over it would let `<Extrinsic
	 * name="UserEmail"/>` discard every later candidate. A value that trims
	 * to nothing is absent here.
	 *
	 * @param list<string> $names
	 */
	private static function first( SetupMessage $message, array $names ): string {
		foreach ( $names as $name ) {
			$value = trim( (string) $message->extrinsic( $name ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
