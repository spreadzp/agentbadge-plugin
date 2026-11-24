<?php
/**
 * Check result value object (SLICE-262-13).
 *
 * Statuses: pass | warn | fail | unknown.
 * `unknown` is a first-class honest state — the plugin could
 * not determine the fact locally; it is NEVER rendered as pass.
 *
 * @package agentbadge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One self-observed check outcome.
 */
final class Agentbadge_Check_Result {

	/**
	 * Pass status.
	 *
	 * @var string
	 */
	public const PASS = 'pass';
	/**
	 * Recommended status.
	 *
	 * @var string
	 */
	public const WARN = 'warn';
	/**
	 * Critical status.
	 *
	 * @var string
	 */
	public const FAIL = 'fail';
	/**
	 * Could-not-determine status (never rendered as pass).
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Readiness axis.
	 *
	 * @var string
	 */
	public const AXIS_READINESS = 'readiness';
	/**
	 * Exposure axis.
	 *
	 * @var string
	 */
	public const AXIS_EXPOSURE = 'exposure';

	/** Everything here is observed by the plugin itself. */
	/**
	 * Provenance label.
	 *
	 * @var string
	 */
	public const SOURCE = 'self_observed';

	/**
	 * Check id.
	 *
	 * @var string
	 */
	public string $id;
	/**
	 * Human title.
	 *
	 * @var string
	 */
	public string $title;
	/**
	 * Axis (readiness|exposure).
	 *
	 * @var string
	 */
	public string $axis;
	/**
	 * Status (pass|warn|fail|unknown).
	 *
	 * @var string
	 */
	public string $status;
	/**
	 * What was observed.
	 *
	 * @var string
	 */
	public string $reason;
	/**
	 * Optional fix hint.
	 *
	 * @var string
	 */
	public string $hint;

	/**
	 * Private ctor — use the named factories.
	 *
	 * @param string $id     Check id.
	 * @param string $title  Human title.
	 * @param string $axis   Axis.
	 * @param string $status Status.
	 * @param string $reason Observation.
	 * @param string $hint   Fix hint.
	 */
	private function __construct( string $id, string $title, string $axis, string $status, string $reason, string $hint = '' ) {
		$this->id     = $id;
		$this->title  = $title;
		$this->axis   = $axis;
		$this->status = $status;
		$this->reason = $reason;
		$this->hint   = $hint;
	}

	/**
	 * Build a pass result.
	 *
	 * @param string $id     Check id.
	 * @param string $title  Human title.
	 * @param string $axis   Axis.
	 * @param string $reason Observation.
	 * @param string $hint   Fix hint.
	 * @return self
	 */
	public static function pass( string $id, string $title, string $axis, string $reason, string $hint = '' ): self {
		return new self( $id, $title, $axis, self::PASS, $reason, $hint );
	}

	/**
	 * Build a warn result.
	 *
	 * @param string $id     Check id.
	 * @param string $title  Human title.
	 * @param string $axis   Axis.
	 * @param string $reason Observation.
	 * @param string $hint   Fix hint.
	 * @return self
	 */
	public static function warn( string $id, string $title, string $axis, string $reason, string $hint = '' ): self {
		return new self( $id, $title, $axis, self::WARN, $reason, $hint );
	}

	/**
	 * Build a fail result.
	 *
	 * @param string $id     Check id.
	 * @param string $title  Human title.
	 * @param string $axis   Axis.
	 * @param string $reason Observation.
	 * @param string $hint   Fix hint.
	 * @return self
	 */
	public static function fail( string $id, string $title, string $axis, string $reason, string $hint = '' ): self {
		return new self( $id, $title, $axis, self::FAIL, $reason, $hint );
	}

	/**
	 * Build a unknown result.
	 *
	 * @param string $id     Check id.
	 * @param string $title  Human title.
	 * @param string $axis   Axis.
	 * @param string $reason Observation.
	 * @param string $hint   Fix hint.
	 * @return self
	 */
	public static function unknown( string $id, string $title, string $axis, string $reason, string $hint = '' ): self {
		return new self( $id, $title, $axis, self::UNKNOWN, $reason, $hint );
	}

	/**
	 * Serializable form stored in the last-scan option.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->id,
			'title'  => $this->title,
			'axis'   => $this->axis,
			'status' => $this->status,
			'reason' => $this->reason,
			'hint'   => $this->hint,
			'source' => self::SOURCE,
		);
	}
}
