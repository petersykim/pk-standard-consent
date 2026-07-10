<?php

declare(strict_types=1);

namespace PK\StandardConsent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScanResults
{
	/**
	 * Inserts or updates scan rows, intentionally excluding category_override so
	 * manual overrides set via setOverride() are never clobbered by a fresh scan.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	public function upsert( array $rows ): void
	{
		global $wpdb;

		$table = $this->tableName();

		foreach ( $rows as $row ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix . literal string; never user input.
				"INSERT INTO `{$table}` (cookie_name, provider, category, duration, purpose, source, compliance_flag, first_seen_url, scanned_at)
				VALUES (%s, %s, %s, %s, %s, %s, %d, %s, NOW())
				ON DUPLICATE KEY UPDATE
				  provider = VALUES(provider),
				  category = VALUES(category),
				  duration = VALUES(duration),
				  purpose = VALUES(purpose),
				  source = VALUES(source),
				  compliance_flag = VALUES(compliance_flag),
				  first_seen_url = VALUES(first_seen_url),
				  scanned_at = NOW()",
				$row['cookie_name'],
				$row['provider'],
				$row['category'],
				$row['duration'],
				$row['purpose'],
				$row['source'],
				$row['compliance_flag'],
				$row['first_seen_url']
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL is built via $wpdb->prepare() above.
			$wpdb->query( $sql );
		}
	}

	/**
	 * Loads all scan results, resolving category_override when present.
	 *
	 * @return array<int,object>
	 */
	public function loadAll(): array
	{
		global $wpdb;

		$table = $this->tableName();

		// Table name is $wpdb->prefix . literal string — never user input. Full table read; no per-row caching needed.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT id, cookie_name, provider,
			        COALESCE(category_override, category) AS effective_category,
			        category, category_override, duration, purpose, source,
			        compliance_flag, first_seen_url, scanned_at
			FROM `{$table}`"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Sets a manual category override for a single cookie.
	 * Validates against the enum values so only known categories are stored.
	 * Returns false (without writing) when the category is invalid; true on update.
	 */
	public function setOverride( string $cookie_name, string $category ): bool
	{
		global $wpdb;

		$valid = array_map( static fn( Category $c ) => $c->value, Category::cases() );
		if ( ! in_array( $category, $valid, true ) ) {
			return false;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row update by primary key; caching not applicable.
			$this->tableName(),
			[ 'category_override' => $category ],
			[ 'cookie_name' => $cookie_name ]
		);

		return true;
	}

	private function tableName(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'pk_sc_scan_results';
	}
}
