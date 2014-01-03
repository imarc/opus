<?php
namespace iMarc\Opus;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;

/**
 * Composer processor
 *
 * @copyright iMarc, LLC
 * @author Matthew J. Sahagian [mjs] <matt@imarc.net>
 * @license MIT (See the LICENSE file at the root of this distribution)
 */
class Processor extends LibraryInstaller
{
	const PACKAGE_TYPE = 'opus-package';
	const NAME         = 'opus';
	const TAB          = '    ';

	/**
	 * The working installation map - tracks file copies
	 *
	 * @access private
	 * @var array
	 */
	private $installationMap = array();


	/**
	 * Whether or not opus support is enabled
	 *
	 * @access private
	 * @var boolean
	 */
	private $enabled = FALSE;


	/**
	 * The framework as defined by our extra options
	 *
	 * @access private
	 * @var string
	 */
	private $framework = NULL;


	/**
	 * The map file we read/write from/to
	 *
	 * @access private
	 * @var string
	 */
	private $mapFile = NULL;


	static private $separators = ['\\', '/', DIRECTORY_SEPARATOR];


	/**
	 * Get the difference between two files
	 *
	 * @static
	 * @access private
	 * @param string $a The path to the existing file
	 * @param string $b The path to the new (replacement) file
	 * @return string The unified diff between the two
	 */
	static private function diff($a, $b)
	{
		$a_code = explode("\n", file_get_contents($a));
		$b_code = explode("\n", file_get_contents($b));
		$diff   = new \Diff($b_code, $a_code, array(
			'ignoreWhitespace' => TRUE,
			'ignoreNewLines'   => TRUE
		));

		return $diff->render(new \Diff_Renderer_Text_Unified());
	}


	/**
	 * Initializes library installer.
	 *
	 * @param IOInterface $io The input/output handler
	 * @param Composer $composer The composer instance being run
	 * @param string $type The type
	 */
	 public function __construct(IOInterface $io, Composer $composer, $type = 'library')
	 {
		parent::__construct($io, $composer, $type);

		//
		// We can now parse our 'extra' configuration key for all our related information.
		//

		$extra           = $this->composer->getPackage()->getExtra();
		$this->mapFile   = getcwd() . DIRECTORY_SEPARATOR . self::NAME . '.map';
		$this->framework = $composer->getPackage()->getName();
		$this->integrity = 'medium';

		if (isset($extra[self::NAME]['options'])) {
			$options               = $extra[self::NAME]['options'];
			$this->externalMapping = isset($options['external-mapping'])
				? (bool) $options['external-mapping']
				: FALSE;

			if (isset($options['framework'])) {
				$this->framework = $options['framework'];

				//
				// Previous versions were noted as enabled if they set a framework.  This will
				// re-establish that, but will get overloaded later if enabled is explicitly set.
				//

				$this->enabled = TRUE;
			}

			if (isset($options['integrity'])) {
				$options['integrity'] = strtolower($options['integrity']);
				$valid_levels         = array('low', 'medium', 'high');
				$this->integrity      = in_array($options['integrity'], $valid_levels)
					? $options['integrity']
					: 'medium';
			}
		}

		if (isset($extra[self::NAME]['enabled'])) {
			$this->enabled = $extra[self::NAME]['enabled'];
		}
	}


	/**
	 * Installs a package
	 *
	 * @access public
	 * @param InstalledRepositoryInterface $repo The repository for installed packages
	 * @param PackageInterface $package The package to install
	 * @return void
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
	{
		parent::install($repo, $package);

		//
		// Return immediately if we have no information relevant to our installer
		//

		if (!$this->checkFrameworkSupport($package)) {
			return;
		}

		$this->loadInstallationMap();

		$this->copyMap(
			$this->buildPackageMap($package),
			$conflicts
		);

		if (count($conflicts)) {
			$this->resolve($conflicts);
		}

		$this->saveInstallationMap(array());
		$this->io->write(PHP_EOL);
	}


	/**
	 * Determine's whether or not we support the package
	 *
	 * @access public
	 * @param string $package_type The package type
	 * @return boolean TRUE if we support the package, FALSE otherwise
	 */
	public function supports($package_type)
	{
		return $package_type == self::PACKAGE_TYPE;
	}


	/**
	 * Updates a package (removing unused files, adding new ones in)
	 *
	 * @access public
	 * @param InstalledRepositoryInterface $repo The repository for installed packages
	 * @param PackageInterface $initial The initial package (originally installed)
	 * @param PackageInterface $target The target package (the new one)
	 * @return void
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
	{
		parent::update($repo, $initial, $target);

		//
		// Don't attempt to remove anything if the initial package didn't have Opus support.
		// If it did, however, we will iterate through the installation map and remove package
		// names which the old package handled installation for.
		//
		// We will then save the installation map without writing checksums, so that some paths
		// may be completely empty arrays (i.e. have no packages handling them) and so that the
		// checksums are still the original file checksums.
		//

		if ($this->checkFrameworkSupport($initial)) {
			$this->loadInstallationMap();

			$old_handled_packages = array_keys($this->buildPackageMap($initial));

			foreach ($this->installationMap as $path => $current_package_names) {
				$this->installationMap[$path] = array_diff(
					$current_package_names,
					$old_handled_packages
				);
			}

			$this->saveInstallationMap(TRUE);
		}

		//
		// At this point, if the new package supports opus it will redo-copying and re-add itself
		// to the paths it handles.  This will leave any files it no longer handles with empty
		// arrays from previous action.  In the event of a conflict, the checksum of the
		// destination file will be checked against the original checksums in the installation
		// map and the user will be prompted with additional actions based on their integrity
		// level.
		//

		if ($this->checkFrameworkSupport($target)) {
			$this->loadInstallationMap();

			$this->copyMap(
				$this->buildPackageMap($target),
				$conflicts
			);

			$check_excluded_files = array();

			if (count($conflicts)) {
				$original_checksums = isset($this->installationMap['__CHECKSUMS__'])
					? $this->installationMap['__CHECKSUMS__']
					: array();

				foreach ($conflicts as $a => $b) {
					$current_directory = str_replace(DIRECTORY_SEPARATOR, '/', $b);
					$destination_path  = str_replace($current_directory, '', $b);
					$current_checksum  = md5(file_get_contents($b));

					$has_changed = isset($original_checksums[$destination_path])
						? $original_checksums[$destination_path] != $current_checksum
						: FALSE;

					switch ($this->integrity) {
						case 'low':
							copy ($a, $b);
							break;

						case 'medium':
							if (!$has_changed) {
								copy($a, $b);

							} elseif (!$this->resolve(array($a => $b))) {
								$check_excluded_files[] = $destination_path;
							}
							break;

						case 'high':
							if (!$this->resolve(array($a => $b))) {
								$check_excluded_files[] = $destination_path;
							}
							break;
					}
				}
			}

			$this->saveInstallationMap($check_excluded_files);
		}

		//
		// Lastly, when the remove package has been removed from the installation map and the
		// new package has re-added itself for any files it handles, we will run cleanup.  This
		// will actually remove any unhandled files and directories.
		//

		$this->cleanup();
		$this->saveInstallationMap(TRUE);

		$this->io->write(PHP_EOL);
	}


	/**
	 * Uninstalls a package
	 *
	 * @access public
	 * @param InstalledRepositoryInterface $repo The repository for installed packages
	 * @param PackageInterface $package The package to uninstall
	 * @return void
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
	{
		//
		// Return immediately if we have no information relevant to our installer
		//

		if (!$this->checkFrameworkSupport($package)) {
			return;
		}

		$this->loadInstallationMap();

		foreach ($this->installationMap as $path => $packages) {
			if (($key = array_search($package->getName(), $packages)) !== FALSE) {
				unset($this->installationMap[$path][$key]);
			}
		}

		$this->cleanup();
		$this->saveInstallationMap(TRUE);

		parent::uninstall($repo, $package);
	}


	/**
	 * Builds a package map
	 *
	 * The package map is an array of packages to their installation sources and destinations.
	 * This will resolve any globs for external integration packages.  An external integration
	 * package *cannot* handle installation of a package it does not depend on, so it looks
	 * through the requires to determine this.
	 *
	 * @access private
	 * @param PackageInterface $package The package to build a map for
	 * @return array The package map
	 */
	private function buildPackageMap($package)
	{
		$package_map  = array();
		$requirements = $package->getRequires();
		$extra        = $package->getExtra();
		$mappings     = $extra[self::NAME][$this->framework];

		foreach ($mappings as $element => $value) {
			if (is_array($value)) {

				//
				// Check for external mapping support
				//

				if ($element != $package->getName() && !$this->externalMapping) {
					throw new \Exception(sprintf(
						'Cannot perform external mapping for %s, disabled',
						$element
					));
				}

				if (strpos($element, '*') !== FALSE) {
					$parts   = explode('*', $element);
					$parts   = array_map(function($v) { return preg_quote($v, '#'); }, $parts);
					$element = implode('(.*)', $parts);
				}

				foreach ($requirements as $link) {
					if (preg_match('#' . $element . '#', $link->getTarget())) {
						$package_map[$link->getTarget()] = $value;
					}
				}

				$package_map[$element] = $value;

			} elseif (is_string($value)) {

				if (!isset($package_map[$package->getName()])) {
					$package_map[$package->getName()] = array();
				}

				$package_map[$package->getName()][$element] = $value;

			} else {
				throw new \Exception (sprintf(
					'Ivalid element %s of type %s',
					$element,
					gettype($value)
				));
			}
		}

		return $package_map;
	}


	/**
	 * Checks whether or not our framework is supported by this opus package
	 *
	 * @access private
	 * @param PackageInterface $package The package to build a map for
	 * @return boolean TRUE if the package supports our framework, FALSE otherwise
	 */
	private function checkFrameworkSupport($package)
	{
		$extra = $package->getExtra();

		if (!isset($extra[self::NAME])) {
			return FALSE;
		}

		if (!isset($extra[self::NAME][$this->framework])) {
			return FALSE;
		}

		return $this->enabled;
	}


	/**
	 * Cleans up our map and installations, removing any files no longer mapped to packages
	 *
	 * @access private
	 * @return void
	 */
	private function cleanup()
	{
		foreach ($this->installationMap as $path => $packages) {
			if ($path == '__CHECKSUMS__' || count($packages)) {
				continue;
			}

			$installation_path = ltrim($path, '/\\' . DIRECTORY_SEPARATOR);
			$installation_path = getcwd() . DIRECTORY_SEPARATOR . $installation_path;

			if (is_dir($installation_path)) {

				//
				// Here's what to do with a directory
				//

				$children = array_merge(
					glob($installation_path . DIRECTORY_SEPARATOR . '/*'),
					glob($installation_path . DIRECTORY_SEPARATOR . '/.*')
				);

				//
				// Children should be at least two (one entry for current dir and another)
				// for parent dir.  Our install map is sorted by the length of the path
				// so we should only be trying to remove directories which have already had
				// other opus related/created children removed.
				//

				if (count($children) > 2) {
					continue;
				}

				if (!@rmdir($installation_path)) {
					switch ($this->integrity) {
						case 'low':
							break;

						case 'medium':
							$this->io->write(sprintf(
								self::TAB . 'Warning: unable to remove empty directory %s',
								$path
							));
							break;

						case 'high':
							throw new \Exception(sprintf(
								'Error removing empty directory %s',
								$path
							));
					}
				}

			} else {

				//
				// Here's what to do with a file
				//

				if (!@unlink($installation_path)) {
					switch ($this->integrity) {
						case 'low':
							break;

						case 'medium':
							$this->io->write(sprintf(
								self::TAB . 'Warning: unable to remove unused file %s; remove manually',
								$path
							));
							break;

						case 'high':
							throw new \Exception(sprintf(
								'Error removing unused file %s, restore file or check permissions and try again',
								$path
							));
					}
				}
			}

			unset($this->installationMap[$path]);
			unset($this->installationMap['__CHECKSUMS__'][$path]);
		}
	}


	/**
	 * Copies a source directory to a destination directory
	 *
	 * @access private
	 * @param string $source The source file/directory
	 * @param string|array $destination The destination file/directory
	 * @param string $entry_name The name for the entry in the installation map
	 * @return array An array of conflicts
	 */
	private function copy($source, $dest, $entry_name = NULL)
	{
		$conflicts   = array();
		$source      = rtrim(realpath($source), '/\\' . DIRECTORY_SEPARATOR);

		if (!$source) {
			throw new \Exception(sprintf(
				'Cannot install, bad source entry while trying to install %s',
				$entry_name
			));
		}

		if (is_file($source)) {
			if (in_array($dest[strlen($dest) - 1], self::$separators) || is_dir($dest)) {
				$target_dir = rtrim($dest, '/\\' . DIRECTORY_SEPARATOR);
				$file_name  = pathinfo($source, PATHINFO_BASENAME);
			} else {
				$target_dir = pathinfo($dest, PATHINFO_DIRNAME);
				$file_name  = pathinfo($dest, PATHINFO_BASENAME);
			}

			$this->createDirectory($target_dir, $entry_name);

			$conflict = FALSE;
			$dest     = $target_dir . DIRECTORY_SEPARATOR . $file_name;

			if (file_exists($dest)) {
				if (!is_writable($dest)) {
					throw new \Exception(sprintf(
						'Cannot install, cannot write to file at "%s"',
						$dest
					));
				}

				$a = md5(preg_replace('/\s/', '', file_get_contents($source)));
				$b = md5(preg_replace('/\s/', '', file_get_contents($dest)));

				if ($a !== $b) {
					$conflict           = TRUE;
					$conflicts[$source] = $dest;
				}
			}

			if (!$conflict) {
				copy($source, $dest);
			}

			$this->map($dest, $entry_name);

		} elseif (is_dir($source)) {
			if (is_file($dest)) {
				throw new \Exception(sprintf(
					'Cannot copy source "%s" (directory) to "%s" (file)',
					$source,
					$dest
				));
			}

			$target_dir = rtrim($dest, '/\\' . DIRECTORY_SEPARATOR);

			if (in_array($dest[strlen($dest) -1], self::$separators)) {
				$target_dir .= DIRECTORY_SEPARATOR . pathinfo($source, PATHINFO_BASENAME);
			}

			$this->createDirectory($target_dir, $entry_name);

			foreach (glob($source . DIRECTORY_SEPARATOR . '*') as $path) {
				$conflicts = array_merge(
					$this->copy(
						realpath($path),
						$target_dir . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_BASENAME),
						$entry_name
					),
					$conflicts
				);
			}

		} else {
			throw new \Exception(sprintf(
				'Cannot copy source "%s", not readable or not a normal file or directory',
				$source
			));
		}

		return $conflicts;
	}


	/**
	 * Copies files over from a package map
	 *
	 * @access private
	 * @param array $package_map A package map to lookup sources and destinations
	 * @param array $conflicts A reference which will be loaded up with conflicts
	 * @return void
	 */
	private function copyMap($package_map, &$conflicts = NULL)
	{
		$conflicts = array();
		$manager   = $this->composer->getRepositoryManager();
		$packages  = $manager->getLocalRepository()->getPackages();

		foreach ($packages as $package) {
			$package_name = $package->getName();
			$package_root = rtrim($this->getInstallPath($package), '/\\' . DIRECTORY_SEPARATOR);

			if (!isset($package_map[$package_name])) {
				continue;
			}

			$this->io->write(sprintf(
				self::TAB . 'Copying files from %s',
				substr($package_root, strlen(getcwd()))
			));

			foreach ($package_map[$package_name] as $source => $dest) {
				$source    = trim($source,  '/\\' . DIRECTORY_SEPARATOR);
				$dest      = ltrim($dest,   '/\\' . DIRECTORY_SEPARATOR);
				$conflicts = array_merge(
					$this->copy(
						$package_root . DIRECTORY_SEPARATOR . $source,
						getcwd() . DIRECTORY_SEPARATOR . $dest,
						$package_name
					),
					$conflicts
				);
			}
		}
	}


	/**
	 * Attempts to create a directory and make sure it's writable while mapping
	 * any created directories.
	 *
	 * @param string $directory The directory to try and create
	 * @param string $entry_name The entry name to map under
	 * @return void
	 */
	private function createDirectory($directory, $entry_name = NULL)
	{
		$directory = str_replace(DIRECTORY_SEPARATOR, '/', $directory);
		$directory = str_replace('\\', '/', $directory);
		$directory = str_replace('/',  '/', $directory);
		$directory = rtrim($directory, '/\\' . DIRECTORY_SEPARATOR);

		if (!file_exists($directory)) {
			$this->createDirectory(
				pathinfo($directory, PATHINFO_DIRNAME),
				$entry_name
			);

			if (!mkdir($directory)) {
				throw new \Exception(sprintf(
					'Cannot install, failure while creating requisite directory "%s"',
					$directory
				));
			}

		} else {
			if (!is_dir($directory)) {
				throw new \Exception(sprintf(
					'Cannot install, requisite path "%s" exists, but is not a directory',
					$directory
				));
			}

			if (!is_writable($directory)) {
				throw new \Exception(sprintf(
					'Cannot install, requisite directory "%s" is not writable',
					$directory
				));
			}
		}

		$this->map($directory, $entry_name);
	}


	/**
	 * Loads the Opus installation map
	 *
	 * @access private
	 * @return void
	 */
	private function loadInstallationMap()
	{
		if (file_exists($this->mapFile)) {
			if (!is_readable($this->mapFile) || is_dir($this->mapFile)) {
				throw new \Exception(sprintf(
					'Cannot read map file at %s',
					$this->mapFile
				));
			}

			$this->installationMap = json_decode(file_get_contents($this->mapFile), TRUE);

			if ($this->installationMap === NULL) {
				throw new \Exception(sprintf(
					'Broken map file at %s',
					$this->mapFile
				));
			}
		}
	}


	/**
	 * Maps a destination under a given entry name
	 *
	 * The destination is taken relative to the current working directory
	 *
	 * @access private
	 * @param string $dest The Destination to map
	 * @param string $entry_name The entry name to map under
	 * @return void
	 */
	private function map($dest, $entry_name)
	{
		$current_directory = str_replace(DIRECTORY_SEPARATOR, '/', getcwd());
		$relative_path     = str_replace($current_directory, '', $dest);

		if (!isset($this->installationMap[$relative_path])) {
			$this->installationMap[$relative_path] = array();
		}

		if (!in_array($entry_name, $this->installationMap[$relative_path])) {
			$this->installationMap[$relative_path][] = $entry_name;
		} else {
			//
			// It's already mapped, what more do you want?
			//
		}
	}


	/**
	 * Resolve conflicts by prompting the user for action
	 *
	 * @access private
	 * @param array $conflicts A list of conflicts $source => $destination
	 * @return boolean TRUE if the user overwrite with the new version, FALSE otherwise
	 */
	private function resolve($conflicts)
	{
		$this->io->write(
			PHP_EOL . self::TAB . 'The following conflicts were found:' . PHP_EOL
		);

		foreach ($conflicts as $a => $b) {
			$answer = NULL;

			while (!$answer || $answer == 'd') {
				$answer = $this->io->ask(sprintf(
					self::TAB . '- %s [o=overwrite (default), k=keep, d=diff]: ',
					substr($b, strlen(getcwd()))
				), 'o');

				switch(strtolower($answer[0])) {
					case 'o':
						copy($a, $b);
						return TRUE;
					case 'k':
						return FALSE;
					case 'd':
						$this->io->write(PHP_EOL . self::diff($a, $b) . PHP_EOL);
						break;
					default:
						$answer = NULL;
						break;
				}
			}
		}
	}


	/**
	 * Saves the Opus installation map
	 *
	 * You can limit how checksum recalulation is handled by passing an array of files to
	 * exclude calculations on, or by passing TRUE, which indicates that all old checksums
	 * should be maintained.
	 *
	 * @access private
	 * @param $check_excluded_files An array of files to exclude from hash calcs
	 * @return void
	 */
	private function saveInstallationMap($check_excluded_files = array())
	{
		//
		// Temporarily remove original checksums
		//

		if (isset($this->installationMap['__CHECKSUMS__'])) {
			$original_checksums = $this->installationMap['__CHECKSUMS__'];

			unset($this->installationMap['__CHECKSUMS__']);

		} else {
			$original_checksums = array();
		}

		//
		// Sort our paths by length
		//

		uksort($this->installationMap, function($a, $b) {
			if (strlen($a) == strlen($b)) {
				return 0;
			}

			if (strlen($a) > strlen($b)) {
				return -1;
			}

			return 1;
		});

		//
		// Re-generate checksums or add original back in
		//

		if ($check_excluded_files !== TRUE) {
			foreach ($this->installationMap as $path => $packages) {
				if (!in_array($path, $check_excluded_files)) {
					$checksum = is_file(getcwd() . DIRECTORY_SEPARATOR . $path)
						? md5(file_get_contents(getcwd() . DIRECTORY_SEPARATOR . $path))
						: md5('');

				} else {
					$checksum = $original_checksums[$path];
				}
				

				$this->installationMap['__CHECKSUMS__'][$path] = $checksum;
			}

		} else {
			$this->installationMap['__CHECKSUMS__'] = $original_checksums;
		}

		//
		// Write our map
		//

		if (file_exists($this->mapFile)) {
			if (!is_writable($this->mapFile) || is_dir($this->mapFile)) {
				throw new \Exception(sprintf(
					'Cannot write to map file at %s',
					$this->mapFile
				));
			}

		}

		if (!file_put_contents($this->mapFile, json_encode($this->installationMap))) {
			throw new \Exception(sprintf(
				'Error while saving map file at %s',
				$this->mapFile
			));
		}
	}
}
