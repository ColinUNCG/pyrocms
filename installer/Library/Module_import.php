<?php

include PYROPATH.'Core/MY_Model.php';

class Module_import
{
	public function __construct(array $params)
	{
		ci()->pdb = $this->pdb = $params['pdb'];

		// create the site specific addon folder
		is_dir(ADDONPATH.'modules') or mkdir(ADDONPATH.'modules', DIR_READ_MODE, true);
		is_dir(ADDONPATH.'themes') or mkdir(ADDONPATH.'themes', DIR_READ_MODE, true);
		is_dir(ADDONPATH.'widgets') or mkdir(ADDONPATH.'widgets', DIR_READ_MODE, true);
		is_dir(ADDONPATH.'field_types') or mkdir(ADDONPATH.'field_types', DIR_READ_MODE, true);

		// create the site specific upload folder
		if ( ! is_dir(dirname(FCPATH).'/uploads/default')) 
		{
			mkdir(dirname(FCPATH).'/uploads/default', DIR_WRITE_MODE, true);
		}
	}

	/**
	 * Installs a module
	 *
	 * @param string $slug The module slug
	 * @param bool   $is_core
	 *
	 * @return bool
	 */
	public function install($slug, $is_core = false)
	{
		if ( ! ($details_class = $this->_spawn_class($slug, $is_core))) {
			throw new Exception("The module $slug is missing a Module.php");
		}

		// Get some basic info
		$module = $details_class->info();

		// Now lets set some details ourselves
		$module['version'] = $details_class->version;
		$module['is_core'] = $is_core;
		$module['enabled'] = true;
		$module['installed'] = true;
		$module['slug'] = $slug;

		// set the site_ref and upload_path for third-party devs
		$details_class->site_ref = 'default';
		$details_class->upload_path = 'uploads/default/';

		// Run the install method to get it into the database
		$details_class->install();

		// Looks like it installed ok, add a record
		return $this->add($module);
	}

	/**
	 * Add
	 *
	 * Insert the database record for a single module
	 *
	 * @param     array     Array of module informaiton.
	 * @return    boolean
	 */
	public function add($module)
	{
		return $this->pdb
			->table('modules')
			->insert(array(
				'name' => serialize($module['name']),
				'slug' => $module['slug'],
				'version' => $module['version'],
				'description' => serialize($module['description']),
				'skip_xss' => ! empty($module['skip_xss']),
				'is_frontend' => ! empty($module['frontend']),
				'is_backend' => ! empty($module['backend']),
				'menu' => ! empty($module['menu']) ? $module['menu'] : false,
				'enabled' => (bool) $module['enabled'],
				'installed' => (bool) $module['installed'],
				'is_core' => (bool) $module['is_core']
			)
		);
	}

	/**
	 * Import All
	 *
	 * Create settings and streams core, and run the install() method for all modules
	 *
	 * @return    boolean
	 */
	public function import_all()
	{
		// Install settings and streams core first. Other modules may need them.
		$this->install('settings', true);
		$this->install('streams_core', true);

		// Are there any modules to install on this path?
		if ($modules = glob(PYROPATH.'Module/*', GLOB_ONLYDIR))
		{
			// Loop through modules
			foreach ($modules as $module_name)
			{
				$slug = basename($module_name);

				if ($slug == 'Streams_core' or $slug == 'Settings')
				{
					continue;
				}

				// invalid details class?
				if ( ! $details_class = $this->_spawn_class($slug, true))
				{
					continue;
				}

				$this->install($slug, true);
			}
		}

		// After modules are imported we need to modify the settings table
		// This allows regular admins to upload addons on the first install but not on multi
		$this->pdb
			->table('settings')
			->where('slug', '=', 'addons_upload')
			->update(array('value' => true));

		return true;
	}

	/**
	 * Spawn Class
	 *
	 * Checks to see if a Module.php exists and returns a class
	 *
	 * @param string $slug    The folder name of the module
	 * @param bool   $is_core
	 *
	 * @return    Library\Module
	 */
	private function _spawn_class($slug, $is_core = false)
	{
		$path = $is_core ? PYROPATH : ADDONPATH;

		// Before we can install anything we need to know some details about the module<<<<<<< HEAD
		$details_file = "{$path}Module/{$slug}/Module.php";

		// If it didn't exist as a core module or an addon then check shared_addons
		if ( ! is_file($details_file))
		{
			$details_file = "{SHARED_ADDONPATH}Module/{$slug}/Module.php";

			if ( ! is_file($details_file))
			{
				return false;
			}
		}

		// Sweet, include the file
		include_once $details_file;

		// Now call the details class
		$class = 'Module\\'.ucfirst(strtolower($slug)).'\\Module';

		// Now we need to talk to it
		return class_exists($class) ? new $class($this->pdb) : false;
	}
}