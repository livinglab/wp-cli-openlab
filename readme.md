## `wp openlab`

wp-cli tools for managing the City Tech OpenLab.

## Commands

### `$ wp cac prepare_update`

Generate a JSON manifest to describe an upcoming OpenLab update, as well as a human-readable CSV file describing the update.

### `$ wp cac do_update`

Perform updates as specified in the `.ol-major-update.json` manifest file created by `wp cac prepare_update`.

__Note__: this command requires the PECL `svn` package, as well as [wp-cli-git-helper](https://github.com/boonebgorges/wp-cli-git-helper/).
__Note__: this command currently does not have a dry-run version, so use at your own risk.

## License

Available under the terms of the GNU General Public License v2 or greater.
