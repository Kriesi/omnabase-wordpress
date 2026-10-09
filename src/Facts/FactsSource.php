<?php
/**
 * One place customer data comes from (the profile, a shop, a licence plugin), each behind its own switch.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * A source reads one plugin's records through that plugin's public functions only, bounded to the newest 20 so the answer comes well
 * inside OmnaBase's 5 seconds, and hands them over in the contract's field names, raw (the shaper fits them). A source that has nothing to
 * say about the person answers null, so nobody found stays `{}`; a user with no records answers an empty list.
 */
interface FactsSource {

	/** The id stored with the switches (`profile`, `woocommerce`, `edd_software_licensing` …). */
	public function id(): string;

	/** The name on the settings screen. */
	public function label(): string;

	/** The section it fills: `profile`, `orders`, `subscriptions` or `licences`. */
	public function section(): string;

	/** Whether the plugin it reads is installed and active. */
	public function isAvailable(): bool;

	/**
	 * The person's records (the profile as one map, the other sections as a list, newest first), or null.
	 *
	 * @param Person $person Who OmnaBase asks about.
	 * @return array<int|string,mixed>|null
	 */
	public function collect( Person $person ): ?array;
}
