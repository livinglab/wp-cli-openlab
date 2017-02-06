<?php

require 'vendor/autoload.php';

// Bail if WP-CLI is not present.
defined( 'WP_CLI' ) || die();

/**
 * Tools for managing the City Tech OpenLab.
 */
class OpenLab_Command extends WP_CLI_Command {
	protected $update_blacklist = array(
		'plugin' => array(),
		'theme' => array(),
	);

	/**
	 * Default blacklist values.
	 *
	 * Can be overridden with exclude-plugin and exclude-theme flags.
	 */
	protected $do_not_update = array(
		'plugin' => array(
			'buddypress-group-documents',
			'buddypress-docs',
			'event-organiser',
		),
		'theme' => array(),
	);

	/**
	 * Prepare a JSON manifest file and human-readable description for a planned update.
	 *
	 * ## OPTIONS
	 *
	 * [--version=<version>]
	 * : The version number of the major release. If not provided, will be
	 * inferred from OL_VERSION.
	 */
	public function prepare_update( $args, $assoc_args ) {
		$types = array( 'plugin', 'theme' );

		if ( isset( $assoc_args['version'] ) ) {
			$new_ol_version = $assoc_args['version'];
		} else {
			$version = 'x.y.z';
			if ( defined( 'OL_VERSION' ) ) {
				if ( preg_match( '/^[0-9]+\.[0-9]+\.([0-9]+)/', OL_VERSION, $matches ) ) {
					$z = $matches[1];
					$new_z = (string) $z + 1;

					$ol_v_a = explode( '.', OL_VERSION );
					$new_ol_version = $ol_v_a[0] . '.' . $ol_v_a[1] . '.' . $new_z;
				}
			}
		}

		$this->new_ol_version = $new_ol_version;

		WP_CLI::log( "Preparing update for OpenLab version $new_ol_version." );

		$this->set_up_blacklist( $assoc_args );

		foreach ( $types as $type ) {
			$data = $this->prepare_update_for_type( $type );
			WP_CLI::log( sprintf( "Identified %s items of type '%s' with updates available.", count( $data ), $type ) );
			$update_data['data'][ $type ] = $data;
		}

		$csv = $this->generate_csv( $update_data['data'] );
		WP_CLI::log( "Generated CSV output at $csv." );

		$json_path = ABSPATH . '.ol-update.json';

		$update_data['header'] = sprintf( 'OpenLab upgrades for %s', $new_ol_version );
		file_put_contents( $json_path, json_encode( $update_data, JSON_PRETTY_PRINT ) );
		WP_CLI::log( sprintf( 'Generated JSON output at %s.', $json_path ) );
	}

	protected function generate_csv( $update_data ) {
		$header_row = array(
			0 => 'Item Type',
			1 => 'Item Name',
			2 => 'Item Slug',
			3 => 'Current Version',
			4 => 'New Version',
			5 => 'Update Type',
		);

		$rows = array();
		foreach ( $update_data as $_ => $_update_data ) {
			foreach ( $_update_data as $item ) {
				$row = array(
					0 => $item['type'],
					1 => $item['title'],
					2 => $item['name'],
					3 => $item['current_version'],
					4 => $item['new_version'],
					5 => $item['update_type'],
				);

				$rows[] = $row;
			}
		}

		$file = ABSPATH . "/openlab-{$this->new_ol_version}-update.csv";
		$fh = fopen( $file, 'w' );

		fprintf( $fh, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $fh, $header_row );

		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}

		fclose( $fh );

		return $file;
	}

	/**
	 * Perform updates as previously prepared by prepare_update.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-plugins=<plugins>]
	 * : Comma-separated list of plugin slugs to be excluded.
	 *
	 * [--exclude-themes=<themes>]
	 * : Comma-separated list of theme slugs to be excluded.
	 */
	public function do_update( $args, $assoc_args ) {
		WP_CLI::error( 'Not yet implemented.' );
		return;

		$json_path = ABSPATH . '.cac-major-update.json';

		if ( ! file_exists( $json_path ) ) {
			WP_CLI::error( sprintf( 'Could not find a manifest at %s.', $json_path ) );
			return;
		}

		$update_data = json_decode( file_get_contents( $json_path ) );

		foreach ( $update_data->data as $type => $items ) {
			$this->do_major_update_for_type( $type, $items );
		}

		unlink( $json_path );
		WP_CLI::log( sprintf( 'Deleted %s.', $json_path ) );
		WP_CLI::success( 'Major updates completed.' );
	}

	/**
	 * Fetch a formatted list of items with available updates.
	 *
	 * @param string $type Item type. 'plugin' or 'theme'.
	 * @return array
	 */
	protected function get_available_updates_for_type( $type ) {
		$command = "$type list";

		$assoc_args = array(
			'update' => 'available',
			'format' => 'csv',
			'fields' => 'name,title,update_version,version',
		);

		$results = WP_CLI::launch_self( $command, array(), $assoc_args, true, true );

		if ( ! empty( $results->stderr ) ) {
			return false;
		}

		$raw_items = explode( "\n", trim( $results->stdout ) );

		$items = array();
		foreach ( $raw_items as $i => $raw_item ) {
			// Discard title row.
			if ( 0 === $i ) {
				continue;
			}

			$item_data = explode( ',', $raw_item );

			$items[ $item_data[0] ] = array(
				'name' => $item_data[0],

				// Titles have been csv-encoded, so strip the quotes.
				'title' => preg_replace( '/^"?([^"]+)"?$/', '\1', $item_data[1] ),
				'update_version' => $item_data[2],
				'version' => $item_data[3],
			);
		}

		return $items;
	}

	/**
	 * Compare version numbers and determine whether it's a major update + the whitelisted update series.
	 *
	 * @param string $new_version
	 * @param string $old_version
	 * @return array
	 */
	protected function version_compare( $new_version, $old_version ) {
		// "Major" means that either x or y is different. Blargh.
		$new_version_a = explode( '.', $new_version );
		$old_version_a = explode( '.', $old_version );

		$is_major_update = false;
		$update_series = array();
		for ( $i = 0; $i <= 1; $i++ ) {
			$new_version_place = isset( $new_version_a[ $i ] ) ? intval( $new_version_a[ $i ] ) : 0;
			$old_version_place = isset( $old_version_a[ $i ] ) ? intval( $old_version_a[ $i ] ) : 0;

			$update_series[] = $new_version_place;
			if ( $new_version_place != $old_version_place ) {
				$is_major_update = true;
			}
		}

		return array(
			'is_major_update' => $is_major_update,
			'update_series' => implode( '.', $update_series ),
		);
	}

	/**
	 * Prepare update data for an item type.
	 *
	 * @param string $type Item type. 'plugin' or 'theme'.
	 * @return array
	 */
	protected function prepare_update_for_type( $type ) {
		if ( 'theme' !== $type ) {
			$type = 'plugin';
		}

		$items = $this->get_available_updates_for_type( $type );

		if ( false === $items ) {
			WP_CLI::error( $results->stderr );
			return;
		}

		$updates = array();
		foreach ( $items as $item_data ) {
			// Ignore items from blacklist.
			if ( in_array( $item_data['name'], $this->update_blacklist[ $type ] ) ) {
				continue;
			}

			$new_version = $item_data['update_version'];
			$old_version = $item_data['version'];

			$version_compare = $this->version_compare( $new_version, $old_version );

			$item_update = array(
				'type' => $type,
				'name' => $item_data['name'],
				'title' => $item_data['title'],
				'current_version' => $old_version,
				'update_type' => $version_compare['is_major_update'] ? 'major' : 'minor',
				'update_series' => $version_compare['update_series'],
				'new_version' => $new_version,
			);

			$updates[ $item_data['name'] ] = $item_update;
		}

		return $updates;
	}

	protected function do_major_update_for_type( $type, $items ) {
		$this->maybe_register_gh_command();

		// Get a list of available updates. If whitelisted series matches, no need to check svn.
		$available_updates = $this->get_available_updates_for_type( $type );

		$updates = array();
		foreach ( $items as $item ) {
			if ( ! isset( $available_updates[ $item->name ] ) ) {
				continue;
			}

			$available_version = $available_updates[ $item->name ]['update_version'];

			$version_compare = $this->version_compare( $available_version, $item->update_series );

			if ( ! $version_compare['is_major_update'] ) {
				$updates[ $item->name ] = 'latest';
				continue;
			}

			// There's a mismatch, so we have to scrape wordpress.org for versions. Whee!
			// @todo Get someone to implement this in the API.
			// Used to use `svn_ls()` for this, but PECL broke for me. Let the fun begin.
			$url = "http://{$type}s.svn.wordpress.org/{$item->name}/tags/";
			$f = wp_remote_get( $url );
			$body = wp_remote_retrieve_body( $f );

			$dom = new DomDocument();
			$dom->loadHTML( $body );
			$tags = $dom->getElementsByTagName( 'li' );
			$versions = array();
			foreach ( $tags as $tag ) {
				$versions[] = rtrim( $tag->nodeValue, '/' );
			}

			// If a plugin has been closed or whatever.
			if ( ! $versions ) {
				continue;
			}

			rsort( $versions );

			foreach ( $versions as $v ) {
				$v_version_compare = $this->version_compare( $v, $item->update_series );

				if ( ! $v_version_compare['is_major_update'] ) {
					$updates[ $item->name ] = $v;
					break;
				}
			}
		}

		foreach ( $updates as $plugin_name => $update_version ) {
			$args = array( 'gh', $type, 'update', $plugin_name );

			$assoc_args = array();
			if ( 'latest' !== $update_version ) {
				$assoc_args['version'] = $update_version;
			}

			// Override locale so we can skip translation updates.
			add_filter( 'locale', array( $this, 'set_locale' ) );

			WP_CLI::run_command( $args, $assoc_args );

			remove_filter( 'locale', array( $this, 'set_locale' ) );
		}
	}

	public function set_locale( $locale ) {
		return 'en_US';
	}

	/**
	 * Change a site's domain.
	 *
	 * ## OPTIONS
	 *
	 * --from=<from>
	 * : The current domain of the site being changed.
	 *
	 * --to=<date>
	 * : The domain that the site is being changed to.
	 *
	 * [--dry-run]
	 * : Whether this should be a dry run.
	 */
	public function change_domain( $args, $assoc_args ) {
		global $wpdb;

		if ( empty( $assoc_args['from'] ) || empty( $assoc_args['to'] ) ) {
			WP_CLI::error( "The 'from' and 'to' parameters are required." );
			return;
		}

		$from_domain = $assoc_args['from'];
		$to_domain   = $assoc_args['to'];

		$from_site = get_site_by_path( $from_domain, '/' );
		if ( ! $from_site ) {
			WP_CLI::error( sprintf( 'No site with the domain %s was found. Aborting.', $from_domain ) );
			return;
		}

		$to_site = get_site_by_path( $to_domain, '/' );
		if ( $to_site ) {
			WP_CLI::error( sprintf( 'An existing site was found with the domain %s. Aborting.', $to_domain ) );
		}

		// Blog-specific tables first.
		$base_args = array( 'search-replace', $from_domain, $to_domain );
		$base_assoc_args = array( 'skip-columns' => 'guid', 'precise' => 1 );
		if ( isset( $assoc_args['dry-run'] ) ) {
			$base_assoc_args['dry-run'] = 1;
		}

		$blog_tables = $wpdb->get_col( "SHOW TABLES LIKE '" . like_escape( $wpdb->get_blog_prefix( $from_site->blog_id ) ) . "%'" );
		$_args = array_merge( $base_args, $blog_tables );
		$_assoc_args = $base_assoc_args;

		WP_CLI::run_command( $_args, $_assoc_args );

		// Global tables next.
		$global_tables = array_merge( $wpdb->global_tables, $wpdb->ms_global_tables );
		foreach ( $global_tables as &$global_table ) {
			$global_table = $wpdb->base_prefix . $global_table;
		}

		if ( function_exists( 'buddypress' ) ) {
			$bp_prefix = bp_core_get_table_prefix() . 'bp_';
			$bp_prefix = esc_sql( $bp_prefix ); // just in case....
			$bp_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s%', $bp_prefix ) );

			if ( $bp_tables ) {
				$global_tables = array_merge( $global_tables, $bp_tables );
			}
		}

		$_args = array_merge( $base_args, $global_tables );
		$_assoc_args = $base_assoc_args;

		WP_CLI::run_command( $_args, $_assoc_args );

		WP_CLI::success( 'Domains switched!' );
		WP_CLI::error( 'wp-cli cannot flush site caches, so make sure to do it yourself!' );
	}

	/**
	 * Set up the update blacklist, based on arguments passed to the command.
	 *
	 * @param array $assoc_args Associative argument array.
	 */
	protected function set_up_blacklist( $assoc_args ) {
		if ( isset( $assoc_args['exclude-plugins'] ) ) {
			$this->update_blacklist['plugin'] = array_filter( explode( ',', $assoc_args['exclude-plugins'] ) );
		} else {
			$this->update_blacklist['plugin'] = $this->do_not_update['plugin'];
		}

		if ( isset( $assoc_args['exclude-themes'] ) ) {
			$this->update_blacklist['theme'] = array_filter( explode( ',', $assoc_args['exclude-themes'] ) );
		} else {
			$this->update_blacklist['theme'] = $this->do_not_update['theme'];
		}
	}

	protected function maybe_register_gh_command() {
		$root = WP_CLI::get_root_command();
		$commands = $root->get_subcommands();
		if ( ! isset( $commands['gh'] ) ) {
			WP_CLI::add_command( 'gh', '\boonebgorges\WPCLIGitHelper\Command' );
		}
	}
}

WP_CLI::add_command( 'openlab', 'OpenLab_Command' );
