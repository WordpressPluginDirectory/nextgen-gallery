<?php
/**
 * REST API Manager
 *
 * @package NextGEN Gallery
 * @subpackage REST API
 */

namespace Imagely\NGG\REST;

use Imagely\NGG\REST\Admin\AttachToPost;
use Imagely\NGG\REST\Admin\Block;
use Imagely\NGG\REST\Admin\RolesCapabilities;
use Imagely\NGG\REST\DataMappers\AlbumREST;
use Imagely\NGG\REST\DataMappers\DisplayTypeREST;
use Imagely\NGG\REST\DataMappers\SettingsREST;
use Imagely\NGG\REST\DataMappers\GalleryREST;
use Imagely\NGG\REST\DataMappers\ImageREST;
use Imagely\NGG\REST\DataMappers\ImageOperationsREST;
use Imagely\NGG\REST\DataMappers\LicenseREST;
use Imagely\NGG\REST\DataMappers\NotificationsREST;
use Imagely\NGG\REST\DataMappers\PluginManagementREST;
use Imagely\NGG\REST\DataMappers\TagREST;

/**
 * REST API Manager
 */
class Manager {

	/**
	 * Register the REST API routes
	 */
	public static function rest_api_init() {
		$block = new Block();
		$block->register_routes();

		$atp = new AttachToPost();
		$atp->register_routes();

		$roles_capabilities = new RolesCapabilities();
		$roles_capabilities->register_routes();

		// Register the DataMappers REST endpoints.
		GalleryREST::register_routes();
		AlbumREST::register_routes();
		ImageREST::register_routes();
		ImageOperationsREST::register_routes();
		DisplayTypeREST::register_routes();
		SettingsREST::register_routes();
		TagREST::register_routes();

		$notifications = new NotificationsREST();
		$notifications->register_routes();

		$plugin_management = new PluginManagementREST();
		$plugin_management->register_routes();

		$license = new LicenseREST();
		$license->register_routes();
	}
}
